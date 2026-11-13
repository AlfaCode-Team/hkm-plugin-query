<?php

declare(strict_types=1);

namespace Plugins\Query\Query\Grammar;

use Plugins\Query\Query\QueryBuilder;

/**
 * PostgreSQL: double-quoted identifiers, LIMIT … OFFSET ….
 *
 * The one shape that differs from MySQL here is INSERT: PostgreSQL has no
 * `lastInsertId()` without a sequence name, so the adapter needs `RETURNING` to
 * hand an id back reliably. That is added by {@see compileInsertGetId()}, which
 * the builder calls only when it actually needs the key.
 */
final class PostgresGrammar extends Grammar
{
    public function name(): string { return 'pgsql'; }

    protected function quotes(): array { return ['"', '"']; }

    /**
     * INSERT … RETURNING "id".
     *
     * On MySQL/SQLite the builder reads lastInsertId() instead; here the id comes
     * back as a row, which is both correct under concurrency and correct when the
     * table's key is not an integer.
     */
    public function compileInsertGetId(QueryBuilder $query, array $rows, string $sequence): string
    {
        return $this->compileInsert($query, $rows) . ' returning ' . $this->wrap($sequence);
    }
}
