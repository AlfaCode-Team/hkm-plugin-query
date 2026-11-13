<?php

declare(strict_types=1);

namespace Plugins\Query\API\Contracts;

use Plugins\Query\Query\QueryBuilder;

/**
 * The published surface of the query plugin.
 *
 * WHO MAY INJECT THIS
 * -------------------
 * REPOSITORIES ONLY. The five access rules put data access behind the repository
 * layer, and a query builder is data access: injecting it into a Service would
 * put SQL in the layer that is supposed to express business rules, and injecting
 * it into a Controller would skip both.
 *
 * That is not enforced mechanically — the contract is published, so any module
 * that declares `database.query` in its requires[] can resolve it. It is a rule
 * this framework already states, and this contract is the one place a reader is
 * guaranteed to look. The gda-guardian agent flags violations.
 */
interface QueryFactoryContract
{
    /**
     * Start a query against a table.
     *
     * The name is validated as an identifier, never interpolated raw — see
     * {@see \Plugins\Query\Query\Grammar\Grammar::wrap()}.
     */
    public function table(string $table): QueryBuilder;

    /** The dialect this factory compiles to: 'mysql' | 'pgsql' | 'sqlite' | 'sqlsrv'. */
    public function driver(): string;
}
