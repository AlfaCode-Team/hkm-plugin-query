<?php

declare(strict_types=1);

namespace Plugins\Query\Query;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{DatabasePort, DriverAware};
use Plugins\Query\API\Contracts\QueryFactoryContract;
use Plugins\Query\Exceptions\QueryException;
use Plugins\Query\Query\Grammar\{
    Grammar, MySqlGrammar, PostgresGrammar, SqliteGrammar, SqlServerGrammar
};

/**
 * QueryFactory — hands out builders bound to the right dialect.
 *
 * HOW THE DIALECT IS DECIDED, IN ORDER
 * ------------------------------------
 *   1. an explicit $driver passed in (tests, and a project that knows better)
 *   2. the bound DatabasePort, if it implements DriverAware
 *   3. the DB_DRIVER config value
 *   4. mysql
 *
 * Step 2 is why {@see DriverAware} exists. Before it, `driver()` lived only on
 * the concrete adapter, so nothing typed against DatabasePort — which is
 * everything a repository is allowed to depend on — could find out what dialect
 * it was talking to. Steps 3 and 4 keep the factory usable against the fifteen
 * or so in-memory DatabasePort fakes in the plugin test suites, none of which
 * know or care what dialect they are pretending to be.
 *
 * The factory is STATELESS and app-lifetime safe: it holds a grammar (immutable)
 * and a port, and creates a fresh builder per call. Nothing accumulates.
 */
final class QueryFactory implements QueryFactoryContract
{
    private readonly Grammar $grammar;

    public function __construct(
        private readonly DatabasePort $db,
        ?string $driver = null,
        string $tablePrefix = '',
    ) {
        $this->grammar = self::grammarFor(
            $driver ?? self::detect($db),
            $tablePrefix,
        );
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table, $this->db, $this->grammar);
    }

    public function driver(): string
    {
        return $this->grammar->name();
    }

    /** Raw SQL, re-exported so a repository needs only this one import. */
    public static function raw(string $sql, array $bindings = []): Expression
    {
        return new Expression($sql, $bindings);
    }

    private static function detect(DatabasePort $db): string
    {
        if ($db instanceof DriverAware) {
            return $db->driver();
        }

        $configured = \function_exists('env') ? (string) (env('DB_DRIVER') ?? '') : '';

        // mysql is the default rather than an error because a port that is not
        // DriverAware is usually a test fake, and failing there would make the
        // builder unusable in exactly the place it is most worth testing.
        return $configured !== '' ? $configured : 'mysql';
    }

    private static function grammarFor(string $driver, string $prefix): Grammar
    {
        return match (strtolower(trim($driver))) {
            'mysql', 'mariadb'          => new MySqlGrammar($prefix),
            'pgsql', 'postgres',
            'postgresql'                => new PostgresGrammar($prefix),
            'sqlite', 'sqlite3'         => new SqliteGrammar($prefix),
            'sqlsrv', 'mssql',
            'sqlserver'                 => new SqlServerGrammar($prefix),
            default                     => throw new QueryException(sprintf(
                'No query grammar for driver [%s]. Supported: mysql, pgsql, sqlite, sqlsrv.',
                $driver,
            )),
        };
    }
}
