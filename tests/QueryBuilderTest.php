<?php

declare(strict_types=1);

namespace Plugins\Query\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Query\Exceptions\QueryException;
use Plugins\Query\Query\{QueryBuilder, QueryFactory};
use Plugins\Query\Tests\Support\FakeDatabase;

#[CoversClass(QueryBuilder::class)]
#[CoversClass(QueryFactory::class)]
final class QueryBuilderTest extends TestCase
{
    private FakeDatabase $db;
    private QueryFactory $query;

    protected function setUp(): void
    {
        $this->db    = new FakeDatabase('mysql');
        $this->query = new QueryFactory($this->db, 'mysql');
    }

    private function table(string $table = 'users'): QueryBuilder
    {
        return $this->query->table($table);
    }

    // ── Safety: identifiers ──────────────────────────────────────────────────

    /**
     * Identifiers are VALIDATED, not escaped. Escaping is a guess about the
     * dialect's quoting rules; refusing is a fact.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield 'quote break-out'   => ["name` = 1; drop table users; -- "];
        yield 'double quote'      => ['name" = 1 or "1'];
        yield 'bracket'           => ['name] = 1 or [1'];
        yield 'space'             => ['name or 1=1'];
        yield 'semicolon'         => ['name; drop table users'];
        yield 'comment'           => ['name-- '];
        yield 'parens'            => ['count(*)'];
        yield 'leading digit'     => ['1name'];
        yield 'empty'             => [''];
    }

    #[DataProvider('hostileIdentifiers')]
    public function testAHostileColumnNameIsRefusedNotEscaped(string $column): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Refusing to use|only letters, digits/');

        $this->table()->select($column)->toSql();
    }

    #[DataProvider('hostileIdentifiers')]
    public function testAHostileTableNameIsRefused(string $table): void
    {
        $this->expectException(QueryException::class);

        $this->table($table)->toSql();
    }

    /** Operators come from a closed allowlist — no path escapes it. */
    public function testAnUnsupportedOperatorIsRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Unsupported SQL operator/');

        $this->table()->where('id', 'UNION SELECT', 1)->toSql();
    }

    /** Every value is a placeholder. There is no interpolation path at all. */
    public function testValuesAreAlwaysPlaceholders(): void
    {
        $builder = $this->table()
            ->where('name', "'; drop table users; --")
            ->where('age', '>', 18);

        self::assertSame(
            'select * from `users` where `name` = ? and `age` > ?',
            $builder->toSql(),
        );
        self::assertSame(["'; drop table users; --", 18], $builder->bindings());
    }

    // ── SELECT shapes ────────────────────────────────────────────────────────

    public function testSelectColumnsAndAliases(): void
    {
        self::assertSame(
            'select `id`, `name` as `label` from `users`',
            $this->table()->select('id', 'name as label')->toSql(),
        );
    }

    public function testDottedColumnsAreWrappedPerSegment(): void
    {
        self::assertSame(
            'select `users`.`id`, `posts`.* from `users`',
            $this->table()->select('users.id', 'posts.*')->toSql(),
        );
    }

    public function testDistinct(): void
    {
        self::assertSame(
            'select distinct `email` from `users`',
            $this->table()->select('email')->distinct()->toSql(),
        );
    }

    public function testJoins(): void
    {
        self::assertSame(
            'select * from `users` inner join `posts` on `users`.`id` = `posts`.`user_id`',
            $this->table()->join('posts', 'users.id', '=', 'posts.user_id')->toSql(),
        );

        self::assertSame(
            'select * from `users` left join `posts` on `users`.`id` = `posts`.`user_id`',
            $this->table()->leftJoin('posts', 'users.id', '=', 'posts.user_id')->toSql(),
        );
    }

    public function testGroupByAndHaving(): void
    {
        $builder = $this->table()
            ->select('tenant_id')
            ->groupBy('tenant_id')
            ->having('tenant_id', '>', 0);

        self::assertSame(
            'select `tenant_id` from `users` group by `tenant_id` having `tenant_id` > ?',
            $builder->toSql(),
        );
        self::assertSame([0], $builder->bindings());
    }

    // ── WHERE semantics ──────────────────────────────────────────────────────

    public function testTwoArgumentWhereAssumesEquals(): void
    {
        self::assertSame('select * from `users` where `id` = ?', $this->table()->where('id', 5)->toSql());
    }

    /**
     * `col = NULL` is never true in SQL, so a two-argument where() with null must
     * become IS NULL — otherwise it silently returns nothing.
     */
    public function testANullValueBecomesIsNullRatherThanEqualsNull(): void
    {
        self::assertSame(
            'select * from `users` where `deleted_at` is null',
            $this->table()->where('deleted_at', null)->toSql(),
        );

        self::assertSame(
            'select * from `users` where `deleted_at` is not null',
            $this->table()->where('deleted_at', '!=', null)->toSql(),
        );
    }

    public function testWhereInAndNotIn(): void
    {
        $builder = $this->table()->whereIn('id', [1, 2, 3]);

        self::assertSame('select * from `users` where `id` in (?, ?, ?)', $builder->toSql());
        self::assertSame([1, 2, 3], $builder->bindings());
    }

    /**
     * An empty IN () is a syntax error in most dialects and "match everything" in
     * the rest. Nothing is in an empty set.
     */
    public function testAnEmptyWhereInMatchesNothing(): void
    {
        self::assertSame('select * from `users` where 0 = 1', $this->table()->whereIn('id', [])->toSql());
        self::assertSame('select * from `users` where 1 = 1', $this->table()->whereNotIn('id', [])->toSql());
    }

    public function testWhereBetween(): void
    {
        $builder = $this->table()->whereBetween('age', 18, 65);

        self::assertSame('select * from `users` where `age` between ? and ?', $builder->toSql());
        self::assertSame([18, 65], $builder->bindings());
    }

    public function testWhereColumnComparesTwoColumns(): void
    {
        $builder = $this->table()->whereColumn('created_at', '<', 'updated_at');

        self::assertSame(
            'select * from `users` where `created_at` < `updated_at`',
            $builder->toSql(),
        );
        self::assertSame([], $builder->bindings(), 'a column comparison binds nothing');
    }

    /**
     * The classic precedence bug: `active = 1 AND role = a OR role = b` returns
     * every 'b' regardless of active. Grouping is what prevents it.
     */
    public function testNestedWhereGroupsAreParenthesised(): void
    {
        $builder = $this->table()
            ->where('active', 1)
            ->where(static function (QueryBuilder $q): void {
                $q->where('role', 'admin')->orWhere('role', 'owner');
            });

        self::assertSame(
            'select * from `users` where `active` = ? and (`role` = ? or `role` = ?)',
            $builder->toSql(),
        );
        self::assertSame([1, 'admin', 'owner'], $builder->bindings());
    }

    public function testOrWhere(): void
    {
        self::assertSame(
            'select * from `users` where `id` = ? or `email` = ?',
            $this->table()->where('id', 1)->orWhere('email', 'a@b.c')->toSql(),
        );
    }

    public function testWhereRawKeepsItsBindings(): void
    {
        $builder = $this->table()->whereRaw('lower(name) = ?', ['ada']);

        self::assertSame('select * from `users` where lower(name) = ?', $builder->toSql());
        self::assertSame(['ada'], $builder->bindings());
    }

    // ── Ordering ─────────────────────────────────────────────────────────────

    public function testOrderByRejectsAnythingButAscAndDesc(): void
    {
        $this->expectException(QueryException::class);

        $this->table()->orderBy('id', 'asc; drop table users');
    }

    public function testNegativeLimitAndOffsetAreRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->table()->limit(-1);
    }

    // ── Terminal operations ──────────────────────────────────────────────────

    public function testFirstDoesNotMutateTheBuilder(): void
    {
        $builder = $this->table();
        $builder->first();

        self::assertNull($builder->limit, 'first() must not leave a limit behind');
        self::assertSame('select * from `users` limit 1', $this->db->lastSql());
    }

    public function testCountIgnoresPagingAndOrdering(): void
    {
        $this->db->rows = [['aggregate' => 12]];

        $count = $this->table()->orderBy('id')->forPage(2, 10)->count();

        self::assertSame(12, $count);
        self::assertSame('select count(*) as aggregate from `users`', $this->db->lastSql());
    }

    public function testPaginateReturnsDataAndTotals(): void
    {
        $this->db->rows = [['aggregate' => 25, 'id' => 1]];

        $page = $this->table()->orderBy('id')->paginate(2, 10);

        self::assertSame(25, $page['total']);
        self::assertSame(2, $page['page']);
        self::assertSame(10, $page['perPage']);
        self::assertSame(3, $page['lastPage']);
    }

    public function testExistsUsesALimitedProbe(): void
    {
        $this->db->rows = [['1' => 1]];

        self::assertTrue($this->table()->where('id', 1)->exists());
        self::assertSame('select 1 from `users` where `id` = ? limit 1', $this->db->lastSql());
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    public function testInsertASingleRow(): void
    {
        $this->table()->insert(['name' => 'Ada', 'email' => 'ada@example.test']);

        self::assertSame('insert into `users` (`name`, `email`) values (?, ?)', $this->db->lastSql());
        self::assertSame(['Ada', 'ada@example.test'], $this->db->lastBindings());
    }

    public function testInsertABatch(): void
    {
        $this->table()->insert([
            ['name' => 'Ada', 'email' => 'a@x.test'],
            ['name' => 'Grace', 'email' => 'g@x.test'],
        ]);

        self::assertSame('insert into `users` (`name`, `email`) values (?, ?), (?, ?)', $this->db->lastSql());
        self::assertSame(['Ada', 'a@x.test', 'Grace', 'g@x.test'], $this->db->lastBindings());
    }

    /** A missing key becomes null rather than shifting every later binding. */
    public function testABatchRowMissingAColumnIsFilledWithNull(): void
    {
        $this->table()->insert([
            ['name' => 'Ada', 'email' => 'a@x.test'],
            ['name' => 'Grace'],
        ]);

        self::assertSame(['Ada', 'a@x.test', 'Grace', null], $this->db->lastBindings());
    }

    /** An EXTRA column cannot be silently dropped — the caller meant to store it. */
    public function testABatchRowWithAnExtraColumnFailsLoudly(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/column\(s\) \[role\] that the first row does not/');

        $this->table()->insert([
            ['name' => 'Ada'],
            ['name' => 'Grace', 'role' => 'admin'],
        ]);
    }

    public function testUpdateBindsSetValuesBeforeWhereValues(): void
    {
        $this->table()->where('id', 7)->update(['name' => 'Ada']);

        self::assertSame('update `users` set `name` = ? where `id` = ?', $this->db->lastSql());
        self::assertSame(['Ada', 7], $this->db->lastBindings());
    }

    public function testDelete(): void
    {
        $this->table()->where('id', 7)->delete();

        self::assertSame('delete from `users` where `id` = ?', $this->db->lastSql());
        self::assertSame([7], $this->db->lastBindings());
    }

    /** The guard that turns a catastrophe into an error message. */
    public function testAnUnconditionalDeleteIsRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Refusing to run an unconditional DELETE/');

        $this->table()->delete();
    }

    public function testTruncateIsTheExplicitWayToEmptyATable(): void
    {
        $this->table()->truncate();

        self::assertSame('delete from `users`', $this->db->lastSql());
    }

    /** Upsert delegates to the PORT, which already compiles it per driver. */
    public function testUpsertDelegatesToThePort(): void
    {
        $this->table()->upsert(['id' => 1, 'name' => 'Ada'], ['id'], ['name']);

        self::assertSame('UPSERT users', $this->db->lastSql());
        self::assertSame(
            ['values' => ['id' => 1, 'name' => 'Ada'], 'conflict' => ['id'], 'update' => ['name']],
            $this->db->lastBindings(),
        );
    }

    // ── raw ──────────────────────────────────────────────────────────────────

    public function testRawIsPassedThroughAndKeepsItsBindings(): void
    {
        $builder = $this->table()->select(QueryFactory::raw('coalesce(name, ?) as label', ['anon']));

        self::assertSame('select coalesce(name, ?) as label from `users`', $builder->toSql());
        self::assertSame(['anon'], $builder->bindings());
    }

    // ── Table prefix ─────────────────────────────────────────────────────────

    public function testATablePrefixIsAppliedToTablesButNotColumns(): void
    {
        $query = new QueryFactory(new FakeDatabase('mysql'), 'mysql', tablePrefix: 'hkm_');

        self::assertSame(
            'select `id` from `hkm_users` inner join `hkm_posts` on `hkm_users`.`id` = `hkm_posts`.`user_id`',
            $query->table('users')->select('id')->join('posts', 'users.id', '=', 'posts.user_id')->toSql(),
        );
    }
}
