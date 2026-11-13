<?php

declare(strict_types=1);

namespace Plugins\Query\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;

/**
 * A query could not be built.
 *
 * Extends RepositoryException because a malformed query is a repository-layer
 * fault — it maps to HTTP 500 with severity critical, which is right: the caller
 * cannot fix it, and it means code shipped that cannot run.
 */
final class QueryException extends RepositoryException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, layer: 'repository.query', previous: $previous);
    }
}
