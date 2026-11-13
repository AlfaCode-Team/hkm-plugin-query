<?php

declare(strict_types=1);

namespace Plugins\Query\Tests\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{DatabasePort, DriverAware};

/** Records statements; answers with whatever the test scripted. */
final class FakeDatabase implements DatabasePort, DriverAware
{
    /** @var list<array{sql: string, bindings: array}> */
    public array $statements = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function __construct(private readonly string $driver = 'mysql') {}

    public function driver(): string { return $this->driver; }

    public function query(string $sql, array $params = []): array
    {
        $this->statements[] = ['sql' => $sql, 'bindings' => $params];

        return $this->rows;
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->statements[] = ['sql' => $sql, 'bindings' => $params];

        return $this->rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->statements[] = ['sql' => $sql, 'bindings' => $params];

        return 1;
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        $this->statements[] = [
            'sql'      => "UPSERT {$table}",
            'bindings' => ['values' => $values, 'conflict' => $conflictColumns, 'update' => $updateColumns],
        ];

        return 1;
    }

    public function lastInsertId(?string $sequence = null): string { return '7'; }

    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function inTransaction(): bool { return false; }

    public function lastSql(): string { return $this->statements[count($this->statements) - 1]['sql'] ?? ''; }

    public function lastBindings(): array { return $this->statements[count($this->statements) - 1]['bindings'] ?? []; }
}
