# hkm-plugin-query

A portable SQL query builder behind `QueryFactoryContract`. One fluent API, compiled
to MySQL, PostgreSQL, SQLite or SQL Server by a per-dialect grammar.

```json
{ "requires": ["database.query"] }
```

| | |
|---|---|
| Solves | `database.query` |
| Namespace | `Plugins\Query\` |
| Contract | `Plugins\Query\API\Contracts\QueryFactoryContract` |
| Kernel | `^1.14` |
| Requires | nothing |

## Why it exists

`DatabasePort` is deliberately narrow: `query()`, `queryOne()`, `execute()`, `upsert()`.
That is enough to be portable, and portability is the point — but it means a repository
with a conditional `WHERE`, a dynamic `ORDER BY` or a paginated list ends up building
SQL by string concatenation, which is where both injection and dialect drift come from.

This plugin gives that repository a builder instead, without widening the port.

## Use

```php
use Plugins\Query\API\Contracts\QueryFactoryContract;

$rows = $this->query->table('loans')
    ->select('id', 'customer_id', 'balance')
    ->where('branch_id', '=', $branchId)
    ->whereNull('deleted_at')
    ->orderBy('created_at', 'desc')
    ->limit(50)
    ->get();
```

Values are always bound, never interpolated. The grammar decides identifier quoting
(`` `x` `` / `"x"` / `[x]`) and the `LIMIT`/`OFFSET` vs `OFFSET … FETCH` form, so the
same builder call is correct on every supported driver.

## Configuration

| Variable | Default | Notes |
|---|---|---|
| `DB_DRIVER` | `mysql` | selects the grammar. `mysql`/`mariadb`, `pgsql`/`postgres`/`postgresql`, `sqlite`/`sqlite3`, `sqlsrv`/`mssql`/`sqlserver`; an unknown value is refused rather than defaulted |
| `QUERY_TABLE_PREFIX` | *(none)* | prepended to every table name |

## Tests

```
HKM_KERNEL_PATH=/path/to/hkm-kernel vendor/bin/phpunit
```

`DialectPortabilityTest` compiles the same builder against all four grammars and pins
the SQL each one produces — a dialect regression fails there rather than in production.

## Licence

MIT.
