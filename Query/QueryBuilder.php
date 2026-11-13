<?php

declare(strict_types=1);

namespace Plugins\Query\Query;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Query\Exceptions\QueryException;
use Plugins\Query\Query\Grammar\Grammar;
use Plugins\Query\Query\Grammar\PostgresGrammar;

/**
 * QueryBuilder — one fluent API, four dialects, zero string concatenation at the
 * call site.
 *
 * WHAT PROBLEM THIS SOLVES
 * ------------------------
 * Before this plugin, every repository in every project hand-wrote SQL against
 * DatabasePort. That works until the query needs anything the four supported
 * drivers spell differently — paging, upserts, identifier quoting, booleans —
 * at which point the repository silently becomes single-driver. The framework's
 * own rules already forbid the symptoms ("no ON DUPLICATE KEY", "no CONCAT") but
 * offered nothing to write instead.
 *
 * IT IS NOT AN ORM
 * ----------------
 * No models, no entities, no hydration, no lazy loading, no relationships, no
 * identity map. It returns ARRAYS. Mapping rows to domain objects is the
 * repository's job and stays there — the Domain layer imports nothing, and an
 * ORM's whole value proposition is putting persistence concerns into the objects
 * this architecture keeps clean.
 *
 * WHO MAY USE IT
 * --------------
 * Repositories. See {@see \Plugins\Query\API\Contracts\QueryFactoryContract}.
 *
 * SAFETY
 * ------
 * Values are ALWAYS placeholders — there is no method on this class that
 * interpolates a value into SQL. Identifiers are validated against a strict
 * pattern and rejected if they are anything but a plain name. Operators come
 * from a closed allowlist. The one escape hatch is {@see raw()}, which is a
 * distinct type precisely so it is visible in review.
 *
 * IT IS MUTABLE, AND REQUEST-SCOPED
 * ---------------------------------
 * Unlike most of this framework, a builder is a mutable accumulator — that is
 * what makes the fluent API readable. It is safe because a builder is created per
 * query, from a factory, and never shared: nothing holds one across requests, and
 * the factory itself is stateless.
 */
final class QueryBuilder
{
    /** Operators a caller may use. Anything else is rejected, not escaped. */
    private const OPERATORS = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'ilike', 'not ilike',
    ];

    /** @var list<string|Expression> */
    public array $columns = [];

    public bool $distinct = false;

    /** @var list<array<string, mixed>> */
    public array $joins = [];

    /** @var list<array<string, mixed>> */
    public array $wheres = [];

    /** @var list<string|Expression> */
    public array $groups = [];

    /** @var list<array<string, mixed>> */
    public array $havings = [];

    /** @var list<array<string, mixed>> */
    public array $orders = [];

    public ?int $limit = null;

    public ?int $offset = null;

    /** @var list<mixed> collected in compile order: joins → wheres → havings */
    private array $bindings = [];

    public function __construct(
        public readonly string $table,
        private readonly DatabasePort $db,
        private readonly Grammar $grammar,
    ) {}

    /** Raw SQL, visible at the call site. @param list<mixed> $bindings */
    public static function raw(string $sql, array $bindings = []): Expression
    {
        return new Expression($sql, $bindings);
    }

    // ── SELECT ───────────────────────────────────────────────────────────────

    public function select(string|Expression ...$columns): self
    {
        $this->columns = array_values($columns);

        return $this;
    }

    public function addSelect(string|Expression ...$columns): self
    {
        $this->columns = [...$this->columns, ...array_values($columns)];

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): self
    {
        if (!in_array($type, ['inner', 'left', 'right', 'full'], true)) {
            throw new QueryException("Unsupported join type [{$type}].");
        }

        $this->joins[] = [
            'table'    => $table,
            'first'    => $first,
            'operator' => $this->operator($operator),
            'second'   => $second,
            'type'     => $type,
        ];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    // ── WHERE ────────────────────────────────────────────────────────────────

    /**
     * where('id', 5) · where('age', '>=', 18) · where(fn($q) => …) for a group.
     *
     * The two-argument form assumes '=', which is the overwhelmingly common case
     * and the one place a little magic earns its keep.
     */
    public function where(string|\Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        if ($column instanceof \Closure) {
            return $this->whereNested($column, $boolean);
        }

        // where('col', $value) — shift the arguments.
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        // A null value with '=' means IS NULL. Compiling it as `= ?` with a null
        // binding produces `col = NULL`, which is never true in SQL — a silent
        // empty result set that reads like missing data.
        if ($value === null && in_array($operator, ['=', '<>', '!='], true)) {
            return $operator === '='
                ? $this->whereNull($column, $boolean)
                : $this->whereNotNull($column, $boolean);
        }

        $this->wheres[] = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => $this->operator((string) $operator),
            'boolean'  => $boolean,
        ];
        $this->bindings[] = $value;

        return $this;
    }

    public function orWhere(string|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return func_num_args() === 2
            ? $this->where($column, '=', $operator, 'or')
            : $this->where($column, $operator, $value, 'or');
    }

    /** Compare two COLUMNS, not a column and a value. */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'type'     => 'column',
            'first'    => $first,
            'operator' => $this->operator($operator),
            'second'   => $second,
            'boolean'  => $boolean,
        ];

        return $this;
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = [
            'type'    => $not ? 'notIn' : 'in',
            'column'  => $column,
            'values'  => array_values($values),
            'boolean' => $boolean,
        ];

        foreach ($values as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereIn($column, $values, $boolean, not: true);
    }

    public function whereNull(string $column, string $boolean = 'and'): self
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column, 'boolean' => $boolean];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        $this->wheres[] = ['type' => 'notNull', 'column' => $column, 'boolean' => $boolean];

        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'and'): self
    {
        $this->wheres[] = ['type' => 'between', 'column' => $column, 'boolean' => $boolean];
        $this->bindings[] = $from;
        $this->bindings[] = $to;

        return $this;
    }

    /** Raw SQL condition — visible, and still parameterised. @param list<mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];

        foreach ($bindings as $binding) {
            $this->bindings[] = $binding;
        }

        return $this;
    }

    /**
     * A parenthesised group: `where(...)->where(fn($q) => $q->where(..)->orWhere(..))`.
     *
     * Without grouping, mixing and/or produces a condition that parses but means
     * something else — the classic `WHERE active = 1 AND role = 'a' OR role = 'b'`
     * that returns every 'b' regardless of active.
     */
    private function whereNested(\Closure $callback, string $boolean): self
    {
        $nested = new self($this->table, $this->db, $this->grammar);
        $callback($nested);

        if ($nested->wheres !== []) {
            $this->wheres[] = [
                'type'       => 'nested',
                'conditions' => $nested->wheres,
                'boolean'    => $boolean,
            ];

            foreach ($nested->bindings() as $binding) {
                $this->bindings[] = $binding;
            }
        }

        return $this;
    }

    // ── GROUP / ORDER / PAGE ─────────────────────────────────────────────────

    public function groupBy(string|Expression ...$columns): self
    {
        $this->groups = [...$this->groups, ...array_values($columns)];

        return $this;
    }

    public function having(string $column, string $operator, mixed $value, string $boolean = 'and'): self
    {
        $this->havings[] = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => $this->operator($operator),
            'boolean'  => $boolean,
        ];
        $this->bindings[] = $value;

        return $this;
    }

    public function orderBy(string|Expression $column, string $direction = 'asc'): self
    {
        $direction = strtolower(trim($direction));

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new QueryException("Order direction must be 'asc' or 'desc', got [{$direction}].");
        }

        $this->orders[] = ['type' => 'basic', 'column' => $column, 'direction' => $direction];

        return $this;
    }

    public function orderByDesc(string|Expression $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new QueryException("Limit must not be negative, got [{$limit}].");
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryException("Offset must not be negative, got [{$offset}].");
        }

        $this->offset = $offset;

        return $this;
    }

    /** Page numbers are 1-based, because that is how they appear in a URL. */
    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->offset((max(1, $page) - 1) * $perPage)->limit($perPage);
    }

    // ── Terminal operations ──────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function get(): array
    {
        return $this->db->query($this->toSql(), $this->bindings());
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        // A clone, so calling first() does not silently leave a limit on a
        // builder the caller may still be composing.
        $query = clone $this;
        $query->limit = 1;

        return $this->db->queryOne($query->toSql(), $query->bindings());
    }

    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();

        return $row === null ? null : reset($row);
    }

    /** @return list<mixed> one column, flattened */
    public function pluck(string $column): array
    {
        return array_map(
            static fn(array $row): mixed => reset($row),
            (clone $this)->select($column)->get(),
        );
    }

    public function exists(): bool
    {
        $query = clone $this;
        $query->columns = [self::raw('1')];
        $query->limit   = 1;

        return $this->db->queryOne($query->toSql(), $query->bindings()) !== null;
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('count', $column);
    }

    public function sum(string $column): float    { return (float) $this->aggregate('sum', $column); }
    public function avg(string $column): float    { return (float) $this->aggregate('avg', $column); }
    public function min(string $column): mixed    { return $this->aggregate('min', $column); }
    public function max(string $column): mixed    { return $this->aggregate('max', $column); }

    private function aggregate(string $function, string $column): mixed
    {
        $query = clone $this;
        // An aggregate ignores paging — `count()` on a paged query means "how
        // many are there", not "how many are on this page".
        $query->limit   = null;
        $query->offset  = null;
        $query->orders  = [];
        $query->columns = [self::raw(
            $function . '(' . ($column === '*' ? '*' : $this->grammar->wrap($column)) . ') as aggregate',
        )];

        $row = $this->db->queryOne($query->toSql(), $query->bindings());

        return $row['aggregate'] ?? null;
    }

    /**
     * One page plus the total, in the shape a JSON API actually returns.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);

        $total = (clone $this)->count();
        $data  = (clone $this)->forPage($page, $perPage)->get();

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'perPage'  => $perPage,
            'lastPage' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * INSERT one row or many.
     *
     * A batch normalises every row to the FIRST row's columns, filling missing
     * keys with null. Letting rows differ would compile a statement whose
     * placeholder count does not match its bindings — a PDO error that names
     * neither the row nor the column.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     * @return int rows affected
     */
    public function insert(array $values): int
    {
        $rows = $this->normalizeRows($values);

        if ($rows === []) {
            return 0;
        }

        return $this->db->execute(
            $this->grammar->compileInsert($this, $rows),
            $this->flatten($rows),
        );
    }

    /**
     * INSERT one row and return its primary key.
     *
     * PostgreSQL uses `RETURNING`, because `lastInsertId()` there needs a
     * sequence name the caller would otherwise have to know. Everywhere else the
     * port's lastInsertId() is correct.
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $sequence = 'id'): string
    {
        $rows = $this->normalizeRows($values);

        if ($this->grammar instanceof PostgresGrammar) {
            $row = $this->db->queryOne(
                $this->grammar->compileInsertGetId($this, $rows, $sequence),
                $this->flatten($rows),
            );

            return (string) ($row[$sequence] ?? '');
        }

        $this->insert($values);

        return $this->db->lastInsertId();
    }

    /**
     * UPDATE the rows this query matches.
     *
     * @param array<string, mixed> $values
     * @return int rows affected
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        // Bindings order matters: SET values are placed before WHERE values in
        // the compiled statement, so they must lead the binding list.
        return $this->db->execute(
            $this->grammar->compileUpdate($this, $values),
            [...array_values($values), ...$this->bindings()],
        );
    }

    /**
     * DELETE the rows this query matches.
     *
     * REFUSES AN UNCONDITIONAL DELETE. `delete()` with no where clause empties
     * the table, and the times that is intended are vastly outnumbered by the
     * times a where clause was meant to be there. Say so explicitly with
     * truncate().
     */
    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new QueryException(
                "Refusing to run an unconditional DELETE on [{$this->table}] — it would remove "
                . 'every row. Add a where clause, or call truncate() if emptying the table is '
                . 'genuinely what you mean.'
            );
        }

        return $this->db->execute($this->grammar->compileDelete($this), $this->bindings());
    }

    /** Empty the table. Deliberately separate from delete(). */
    public function truncate(): int
    {
        return $this->db->execute('delete from ' . $this->grammar->wrapTable($this->table));
    }

    /**
     * Atomic INSERT-or-UPDATE, delegated to the PORT.
     *
     * DatabasePort::upsert() already compiles `ON DUPLICATE KEY` / `ON CONFLICT`
     * per driver — reimplementing that here would create a second, competing
     * answer to the same question, and the framework's rules name the port method
     * as the one to call.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     * @param list<string>                                    $conflictColumns
     * @param list<string>|null                               $updateColumns
     */
    public function upsert(array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        return $this->db->upsert($this->table, $values, $conflictColumns, $updateColumns);
    }

    // ── Compilation ──────────────────────────────────────────────────────────

    /** The SQL this builder currently describes. Safe to call for debugging. */
    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Bindings in COMPILE ORDER.
     *
     * Values are recorded as they are added, and every clause that takes values
     * is compiled in the same order it was declared — so positional placeholders
     * line up. An Expression's own bindings are interleaved at the position its
     * clause occupies.
     *
     * @return list<mixed>
     */
    public function bindings(): array
    {
        $out = [];

        foreach ($this->columns as $column) {
            if ($column instanceof Expression) {
                $out = [...$out, ...$column->bindings];
            }
        }

        return [...$out, ...$this->bindings];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function operator(string $operator): string
    {
        $normalized = strtolower(trim($operator));

        if (!in_array($normalized, self::OPERATORS, true)) {
            throw new QueryException(sprintf(
                'Unsupported SQL operator [%s]. Allowed: %s. Anything else must go through '
                . 'raw(), where it is visible at the call site.',
                $operator,
                implode(' ', self::OPERATORS),
            ));
        }

        return $normalized;
    }

    /**
     * One row or many → a uniform list of rows with identical column order.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(array $values): array
    {
        if ($values === []) {
            return [];
        }

        // A list of rows, or a single associative row?
        $rows = array_is_list($values) ? $values : [$values];

        $columns = array_keys($rows[0]);
        $out     = [];

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                throw new QueryException("Insert row #{$i} is not an array.");
            }

            $normalized = [];
            foreach ($columns as $column) {
                $normalized[$column] = $row[$column] ?? null;
            }

            if (count($row) > count($columns)) {
                $extra = array_diff(array_keys($row), $columns);

                throw new QueryException(sprintf(
                    'Insert row #%d has column(s) [%s] that the first row does not. Every row in '
                    . 'a batch insert must have the same columns — otherwise the compiled '
                    . 'statement and its bindings cannot line up.',
                    $i,
                    implode(', ', $extra),
                ));
            }

            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<mixed>
     */
    private function flatten(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            foreach ($row as $value) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
