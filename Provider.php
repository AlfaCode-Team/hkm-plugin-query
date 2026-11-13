<?php

declare(strict_types=1);

namespace Plugins\Query;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Query\API\Contracts\QueryFactoryContract;
use Plugins\Query\Query\QueryFactory;

/**
 * The query plugin.
 *
 * requires[] is EMPTY on purpose. It depends on DatabasePort, and ports resolve
 * from the CoreContainer rather than the module graph — so listing
 * `database.management` here would force a specific adapter plugin on every
 * consumer, when any bound DatabasePort will do (including an in-memory one).
 */
final class Provider implements ModuleContract
{
    public function solves(): string { return 'database.query'; }

    public function requires(): array { return []; }

    public function exposes(): array { return [QueryFactoryContract::class]; }

    public function register(ModuleContainer $container): void
    {
        $container->bind(QueryFactoryContract::class, static fn($c) => new QueryFactory(
            db:          $c->make(DatabasePort::class),
            // null means "detect" — see QueryFactory::detect(). A project that
            // needs to force a dialect sets DB_DRIVER.
            driver:      null,
            tablePrefix: (string) (env('QUERY_TABLE_PREFIX') ?? ''),
        ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Nothing to hook: the builder is a dependency, not a request stage.
    }
}
