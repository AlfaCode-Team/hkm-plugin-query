<?php

declare(strict_types=1);

namespace Plugins\Query\Query\Grammar;

use Plugins\Query\Query\QueryBuilder;

/**
 * SQL Server: [bracketed] identifiers, and paging that looks nothing like the
 * other three.
 *
 * THIS IS THE DIALECT THAT BREAKS HAND-WRITTEN SQL
 * ------------------------------------------------
 *   LIMIT 10 OFFSET 20                       everywhere else
 *   OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY   here
 *
 * …and `OFFSET` is only legal after an `ORDER BY`. A repository that hand-writes
 * `LIMIT` does not merely produce different results on SQL Server, it produces a
 * syntax error — and a repository that pages without ordering produces a syntax
 * error here while silently returning arbitrary rows everywhere else.
 *
 * Both are handled below: paging with no explicit order gets a deterministic
 * `ORDER BY (SELECT 0)`, and a bare `LIMIT` compiles to `TOP`.
 */
final class SqlServerGrammar extends Grammar
{
    public function name(): string { return 'sqlsrv'; }

    protected function quotes(): array { return ['[', ']']; }

    /**
     * TOP goes in the column list, not at the end — so a limit with no offset is
     * compiled here rather than in compileLimit().
     */
    protected function compileColumns(QueryBuilder $query): string
    {
        $columns = $query->columns === []
            ? '*'
            : implode(', ', array_map($this->wrap(...), $query->columns));

        $top = $query->limit !== null && $query->offset === null
            ? 'top ' . $query->limit . ' '
            : '';

        return 'select ' . ($query->distinct ? 'distinct ' : '') . $top . $columns;
    }

    /**
     * OFFSET/FETCH requires an ORDER BY. `(select 0)` is the standard no-op that
     * satisfies the parser without imposing an order the caller did not ask for.
     */
    protected function compileOrders(QueryBuilder $query): string
    {
        $orders = parent::compileOrders($query);

        if ($orders === '' && $query->offset !== null) {
            return 'order by (select 0)';
        }

        return $orders;
    }

    protected function compileLimit(QueryBuilder $query): string
    {
        if ($query->offset === null) {
            // Already emitted as TOP in the column list.
            return '';
        }

        $sql = 'offset ' . $query->offset . ' rows';

        if ($query->limit !== null) {
            $sql .= ' fetch next ' . $query->limit . ' rows only';
        }

        return $sql;
    }
}
