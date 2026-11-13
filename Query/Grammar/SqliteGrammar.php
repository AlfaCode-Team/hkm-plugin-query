<?php

declare(strict_types=1);

namespace Plugins\Query\Query\Grammar;

use Plugins\Query\Query\QueryBuilder;

/**
 * SQLite: double-quoted identifiers, LIMIT … OFFSET ….
 *
 * The trap is OFFSET WITHOUT LIMIT, which SQLite rejects outright. Every other
 * dialect here tolerates it, so a query written and tested against MySQL fails
 * only once someone runs the suite on SQLite — which, since SQLite is the obvious
 * choice for a fast test database, is exactly backwards.
 */
final class SqliteGrammar extends Grammar
{
    public function name(): string { return 'sqlite'; }

    protected function quotes(): array { return ['"', '"']; }

    protected function compileLimit(QueryBuilder $query): string
    {
        if ($query->offset !== null && $query->limit === null) {
            // -1 is SQLite's documented "no limit", which is what the caller
            // meant. Refusing here would make offset-only paging a dialect
            // difference the caller has to know about — the thing this plugin
            // exists to remove.
            return 'limit -1 offset ' . $query->offset;
        }

        return parent::compileLimit($query);
    }
}
