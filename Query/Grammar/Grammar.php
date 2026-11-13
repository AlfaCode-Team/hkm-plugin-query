<?php

declare(strict_types=1);

namespace Plugins\Query\Query\Grammar;

use Plugins\Query\Query\Expression;
use Plugins\Query\Query\QueryBuilder;
use Plugins\Query\Exceptions\QueryException;

/**
 * Grammar — turns a QueryBuilder's state into SQL for ONE dialect.
 *
 * This class is the entire reason the plugin exists. The framework already
 * forbids driver-specific SQL in a repository:
 *
 *   ✗ Hand-writing ON DUPLICATE KEY / ON CONFLICT
 *   ✗ CONCAT / SUBSTRING / driver-specific functions in repository SQL
 *
 * …but until now the only way to obey those rules was to avoid the features. The
 * differences are not exotic; they start at the first page of results:
 *
 *   LIMIT 10 OFFSET 20                       MySQL, PostgreSQL, SQLite
 *   OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY   SQL Server (and it REQUIRES an ORDER BY)
 *
 *   `identifier`   MySQL          "identifier"  PostgreSQL, SQLite
 *   [identifier]   SQL Server
 *
 * A repository that writes `LIMIT` by hand is a repository that works on three of
 * the four drivers this framework advertises. Compiling it here is what makes the
 * multi-driver claim true rather than aspirational.
 *
 * IDENTIFIERS ARE VALIDATED, NOT ESCAPED
 * --------------------------------------
 * {@see wrap()} rejects anything that is not a plain identifier rather than
 * trying to escape it. Escaping is a guess about the dialect's quoting rules;
 * refusing is a fact. Anything that genuinely needs to be arbitrary SQL goes
 * through {@see Expression}, where it is visible.
 */
abstract class Grammar
{
    /** A bare identifier: letters, digits, underscore. Nothing else, ever. */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(protected readonly string $tablePrefix = '') {}

    /** 'mysql' | 'pgsql' | 'sqlite' | 'sqlsrv' */
    abstract public function name(): string;

    /** The characters that open and close a quoted identifier in this dialect. */
    abstract protected function quotes(): array;

    // ── Identifiers ──────────────────────────────────────────────────────────

    /**
     * Quote an identifier, a dotted path, or an aliased expression.
     *
     *   'users'              → `users`
     *   'users.id'           → `users`.`id`
     *   'users.*'            → `users`.*
     *   'id as user_id'      → `id` as `user_id`
     *   new Expression('..') → passed through untouched
     *
     * Anything else THROWS. A column name that reached here from user input and
     * is not a plain identifier is either a bug or an injection attempt, and both
     * deserve the same answer.
     */
    public function wrap(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return $value->sql;
        }

        // "column as alias" — split on the keyword, wrap both halves.
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', trim($value), $m) === 1) {
            return $this->wrap(trim($m[1])) . ' as ' . $this->wrapSegment(trim($m[2]));
        }

        $segments = explode('.', trim($value));
        $wrapped  = [];

        foreach ($segments as $i => $segment) {
            $segment = trim($segment);

            // `table.*` — the star is not an identifier and must not be quoted.
            if ($segment === '*') {
                $wrapped[] = '*';

                continue;
            }

            // The table position of a dotted path gets the configured prefix.
            $wrapped[] = $this->wrapSegment(
                $i === 0 && count($segments) > 1 ? $this->prefixed($segment) : $segment,
            );
        }

        return implode('.', $wrapped);
    }

    /** A table name — the one place the configured prefix is applied. */
    public function wrapTable(string|Expression $table): string
    {
        if ($table instanceof Expression) {
            return $table->sql;
        }

        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', trim($table), $m) === 1) {
            return $this->wrapSegment($this->prefixed(trim($m[1])))
                . ' as ' . $this->wrapSegment(trim($m[2]));
        }

        return $this->wrapSegment($this->prefixed(trim($table)));
    }

    protected function prefixed(string $name): string
    {
        return $this->tablePrefix === '' ? $name : $this->tablePrefix . $name;
    }

    /** Validate one identifier segment and wrap it in this dialect's quotes. */
    protected function wrapSegment(string $segment): string
    {
        if (preg_match(self::IDENTIFIER, $segment) !== 1) {
            throw new QueryException(sprintf(
                'Refusing to use [%s] as an SQL identifier — only letters, digits and '
                . 'underscores are allowed, and it may not start with a digit. If this '
                . 'is genuinely meant to be SQL, wrap it in raw() so it is visible at '
                . 'the call site.',
                $segment,
            ));
        }

        [$open, $close] = $this->quotes();

        return $open . $segment . $close;
    }

    // ── SELECT ───────────────────────────────────────────────────────────────

    public function compileSelect(QueryBuilder $query): string
    {
        $sql = array_filter([
            $this->compileColumns($query),
            'from ' . $this->wrapTable($query->table),
            $this->compileJoins($query),
            $this->compileWheres($query),
            $this->compileGroups($query),
            $this->compileHavings($query),
            $this->compileOrders($query),
            $this->compileLimit($query),
        ], static fn(string $part): bool => $part !== '');

        return implode(' ', $sql);
    }

    protected function compileColumns(QueryBuilder $query): string
    {
        $columns = $query->columns === []
            ? '*'
            : implode(', ', array_map($this->wrap(...), $query->columns));

        return 'select ' . ($query->distinct ? 'distinct ' : '') . $columns;
    }

    protected function compileJoins(QueryBuilder $query): string
    {
        $parts = [];

        foreach ($query->joins as $join) {
            $parts[] = sprintf(
                '%s join %s on %s %s %s',
                $join['type'],
                $this->wrapTable($join['table']),
                $this->wrap($join['first']),
                $join['operator'],
                $this->wrap($join['second']),
            );
        }

        return implode(' ', $parts);
    }

    /**
     * WHERE, including nested groups.
     *
     * Every value is a placeholder. There is no code path in this class that
     * interpolates a value into SQL — the only strings that reach the output are
     * identifiers (validated above), operators (from a closed allowlist in the
     * builder), and Expression bodies the caller wrote by hand.
     */
    protected function compileWheres(QueryBuilder $query, string $keyword = 'where'): string
    {
        if ($query->wheres === []) {
            return '';
        }

        return $keyword . ' ' . $this->compileConditions($query->wheres);
    }

    /** @param list<array<string, mixed>> $conditions */
    protected function compileConditions(array $conditions): string
    {
        $sql = '';

        foreach ($conditions as $i => $condition) {
            $boolean = $i === 0 ? '' : $condition['boolean'] . ' ';
            $sql    .= $boolean . $this->compileCondition($condition) . ' ';
        }

        return rtrim($sql);
    }

    /** @param array<string, mixed> $where */
    protected function compileCondition(array $where): string
    {
        return match ($where['type']) {
            'basic'   => $this->wrap($where['column']) . ' ' . $where['operator'] . ' ?',
            'column'  => $this->wrap($where['first']) . ' ' . $where['operator'] . ' ' . $this->wrap($where['second']),
            'in'      => $where['values'] === []
                // An empty IN () is a syntax error in most dialects and a silent
                // "match everything" in the rest. `0 = 1` is the honest reading:
                // nothing is in an empty set.
                ? '0 = 1'
                : $this->wrap($where['column']) . ' in (' . $this->placeholders(count($where['values'])) . ')',
            'notIn'   => $where['values'] === []
                ? '1 = 1'
                : $this->wrap($where['column']) . ' not in (' . $this->placeholders(count($where['values'])) . ')',
            'null'    => $this->wrap($where['column']) . ' is null',
            'notNull' => $this->wrap($where['column']) . ' is not null',
            'between' => $this->wrap($where['column']) . ' between ? and ?',
            'raw'     => $where['sql'],
            'nested'  => '(' . $this->compileConditions($where['conditions']) . ')',
            'exists'  => 'exists (' . $where['sql'] . ')',
            default   => throw new QueryException("Unknown where type [{$where['type']}]."),
        };
    }

    protected function compileGroups(QueryBuilder $query): string
    {
        return $query->groups === []
            ? ''
            : 'group by ' . implode(', ', array_map($this->wrap(...), $query->groups));
    }

    protected function compileHavings(QueryBuilder $query): string
    {
        return $query->havings === []
            ? ''
            : 'having ' . $this->compileConditions($query->havings);
    }

    protected function compileOrders(QueryBuilder $query): string
    {
        if ($query->orders === []) {
            return '';
        }

        $parts = array_map(
            fn(array $o): string => $o['type'] === 'raw'
                ? $o['sql']
                : $this->wrap($o['column']) . ' ' . $o['direction'],
            $query->orders,
        );

        return 'order by ' . implode(', ', $parts);
    }

    /**
     * LIMIT / OFFSET — the single biggest dialect difference, and the reason a
     * hand-written repository query stops being portable at page two.
     */
    protected function compileLimit(QueryBuilder $query): string
    {
        $parts = [];

        if ($query->limit !== null) {
            $parts[] = 'limit ' . $query->limit;
        }

        if ($query->offset !== null) {
            $parts[] = 'offset ' . $query->offset;
        }

        return implode(' ', $parts);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * INSERT, single or batch.
     *
     * @param list<array<string, mixed>> $rows already normalised by the builder
     *        so every row has the same columns in the same order.
     */
    public function compileInsert(QueryBuilder $query, array $rows): string
    {
        $columns = array_keys($rows[0]);

        $tuples = implode(', ', array_fill(
            0,
            count($rows),
            '(' . $this->placeholders(count($columns)) . ')',
        ));

        return sprintf(
            'insert into %s (%s) values %s',
            $this->wrapTable($query->table),
            implode(', ', array_map($this->wrap(...), $columns)),
            $tuples,
        );
    }

    /** @param array<string, mixed> $values */
    public function compileUpdate(QueryBuilder $query, array $values): string
    {
        $assignments = implode(', ', array_map(
            fn(string $column): string => $this->wrap($column) . ' = ?',
            array_keys($values),
        ));

        return trim(sprintf(
            'update %s set %s %s',
            $this->wrapTable($query->table),
            $assignments,
            $this->compileWheres($query),
        ));
    }

    public function compileDelete(QueryBuilder $query): string
    {
        return trim(sprintf(
            'delete from %s %s',
            $this->wrapTable($query->table),
            $this->compileWheres($query),
        ));
    }

    public function compileExists(QueryBuilder $query): string
    {
        return 'select exists(' . $this->compileSelect($query) . ') as ' . $this->wrapSegment('present');
    }

    protected function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
