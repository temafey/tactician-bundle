<?php

declare(strict_types=1);

namespace League\Tactician\Bundle\Middleware;

use Doctrine\DBAL\Connection;
use League\Tactician\Middleware;

/**
 * Database transaction middleware for Tactician Command Bus.
 *
 * Wraps command handler execution in a database transaction.
 * DBAL handles nested transactions via savepoints.
 */
readonly class DatabaseTransactionMiddleware implements Middleware
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param object $command The command or query object being executed
     * @param callable $next The next middleware in the chain
     *
     * @return mixed The result from the command handler
     *
     * @throws \Throwable Re-throws any exception after rollback
     */
    public function execute($command, callable $next): mixed
    {
        $this->connection->beginTransaction();

        try {
            $result = $next($command);
            $this->connection->commit();

            return $result;
        } catch (\Throwable $throwable) {
            $this->connection->rollBack();

            throw $throwable;
        }
    }
}
