<?php

declare(strict_types=1);

namespace Plugins\Query\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Query\Query\Grammar\{MySqlGrammar, PostgresGrammar, SqliteGrammar, SqlServerGrammar};
use Plugins\Query\Query\{QueryBuilder, QueryFactory};
use Plugins\Query\Tests\Support\FakeDatabase;

/**
 * The reason this plugin exists: ONE query, compiled correctly for FOUR dialects.
 *
 * Every case here is something a hand-written repository query gets wrong the
 * moment the driver changes. They are asserted as exact SQL rather than "it
 * contains OFFSET", because the whole value proposition is that the output is
 * right, not merely different.
 */
#[CoversClass(QueryBuilder::class)]
#[CoversClass(MySqlGrammar::class)]
#[CoversClass(PostgresGrammar::class)]
#[CoversClass(SqliteGrammar::class)]
#[CoversClass(SqlServerGrammar::class)]
final class DialectPortabilityTest extends TestCase
{
    private function on(string $driver): QueryFactory
    {
        return new QueryFactory(new FakeDatabase($driver), $driver);
    }

    // ── Identifier quoting ───────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function quoting(): iterable
    {
        yield 'mysql'  => ['mysql',  'select * from `users`'];
        yield 'pgsql'  => ['pgsql',  'select * from "users"'];
        yield 'sqlite' => ['sqlite', 'select * from "users"'];
        yield 'sqlsrv' => ['sqlsrv', 'select * from [users]'];
    }

    #[DataProvider('quoting')]
    public function testIdentifiersAreQuotedPerDialect(string $driver, string $expected): void
    {
        self::assertSame($expected, $this->on($driver)->table('users')->toSql());
    }

    // ── Paging: the big one ──────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function paging(): iterable
    {
        yield 'mysql' => [
            'mysql',
            'select * from `users` order by `id` asc limit 15 offset 30',
        ];
        yield 'pgsql' => [
            'pgsql',
            'select * from "users" order by "id" asc limit 15 offset 30',
        ];
        yield 'sqlite' => [
            'sqlite',
            'select * from "users" order by "id" asc limit 15 offset 30',
        ];
        // Completely different syntax — and a syntax ERROR if written the other way.
        yield 'sqlsrv' => [
            'sqlsrv',
            'select * from [users] order by [id] asc offset 30 rows fetch next 15 rows only',
        ];
    }

    #[DataProvider('paging')]
    public function testPagingCompilesToEachDialectsSyntax(string $driver, string $expected): void
    {
        self::assertSame(
            $expected,
            $this->on($driver)->table('users')->orderBy('id')->forPage(3, 15)->toSql(),
        );
    }

    /** SQL Server puts a bare limit in the column list as TOP, not at the end. */
    public function testSqlServerUsesTopForALimitWithNoOffset(): void
    {
        self::assertSame(
            'select top 5 * from [users]',
            $this->on('sqlsrv')->table('users')->limit(5)->toSql(),
        );
    }

    /**
     * SQL Server's OFFSET is only legal after an ORDER BY. Paging without one is
     * a syntax error there and silently arbitrary everywhere else, so the grammar
     * supplies a no-op order rather than letting either happen.
     */
    public function testSqlServerSuppliesANoOpOrderWhenPagingWithoutOne(): void
    {
        self::assertSame(
            'select * from [users] order by (select 0) offset 10 rows fetch next 5 rows only',
            $this->on('sqlsrv')->table('users')->forPage(3, 5)->toSql(),
        );
    }

    /** SQLite rejects OFFSET without LIMIT; -1 is its documented "no limit". */
    public function testSqliteHandlesOffsetWithoutLimit(): void
    {
        self::assertSame(
            'select * from "users" limit -1 offset 20',
            $this->on('sqlite')->table('users')->offset(20)->toSql(),
        );
    }

    // ── insertGetId ──────────────────────────────────────────────────────────

    /**
     * PostgreSQL has no lastInsertId() without a sequence name, so the id must
     * come back via RETURNING.
     */
    public function testPostgresUsesReturningToFetchTheInsertedId(): void
    {
        $db      = new FakeDatabase('pgsql');
        $db->rows = [['id' => '42']];

        $id = (new QueryFactory($db, 'pgsql'))->table('users')->insertGetId(['name' => 'Ada']);

        self::assertSame('42', $id);
        self::assertSame('insert into "users" ("name") values (?) returning "id"', $db->lastSql());
    }

    public function testMysqlUsesLastInsertId(): void
    {
        $db = new FakeDatabase('mysql');

        $id = (new QueryFactory($db, 'mysql'))->table('users')->insertGetId(['name' => 'Ada']);

        self::assertSame('7', $id);
        self::assertSame('insert into `users` (`name`) values (?)', $db->lastSql());
    }

    // ── Driver detection ─────────────────────────────────────────────────────

    /** The whole point of DriverAware: a port can name its own dialect. */
    public function testTheDialectIsTakenFromADriverAwarePort(): void
    {
        self::assertSame('pgsql', (new QueryFactory(new FakeDatabase('pgsql')))->driver());
        self::assertSame('sqlsrv', (new QueryFactory(new FakeDatabase('sqlsrv')))->driver());
    }

    public function testDriverAliasesResolve(): void
    {
        self::assertSame('pgsql', (new QueryFactory(new FakeDatabase(), 'postgresql'))->driver());
        self::assertSame('mysql', (new QueryFactory(new FakeDatabase(), 'mariadb'))->driver());
        self::assertSame('sqlsrv', (new QueryFactory(new FakeDatabase(), 'mssql'))->driver());
    }

    public function testAnUnknownDriverFailsLoudly(): void
    {
        $this->expectException(\Plugins\Query\Exceptions\QueryException::class);
        $this->expectExceptionMessageMatches('/No query grammar for driver \[oracle\]/');

        new QueryFactory(new FakeDatabase(), 'oracle');
    }
}
