<?php

declare(strict_types=1);

namespace League\Tactician\Bundle\Middleware;

use Doctrine\DBAL\Exception\RetryableException;
use League\Tactician\Middleware;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Retry middleware for transient DBAL errors (VP-6).
 *
 * Retries command execution when a RetryableException (e.g. deadlock, serialization failure)
 * is detected anywhere in the exception chain. Walking the chain via getPrevious() is
 * defense-in-depth: even if some layer wraps the retryable exception in a generic wrapper,
 * this middleware will still detect it and retry transparently.
 *
 * MUST be placed BEFORE DatabaseTransactionMiddleware in the middleware stack so that
 * each retry attempt opens a fresh database transaction.
 *
 * Backoff strategy: exponential starting at $initialBackoffMs (default 50 ms),
 * doubling on every retry: 50 ms, 100 ms, 200 ms for maxRetries=3 (approx 350 ms total).
 */
final class TransientErrorRetryMiddleware implements Middleware
{
    private const int MAX_CHAIN_DEPTH = 10;

    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly int $initialBackoffMs = 50,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param object   $command
     * @param callable $next
     *
     * @return mixed
     *
     * @throws \Throwable when a non-retryable exception is thrown or all retries are exhausted
     */
    public function execute($command, callable $next): mixed
    {
        $attempt = 0;
        $backoffMs = $this->initialBackoffMs;

        while (true) {
            try {
                return $next($command);
            } catch (\Throwable $e) {
                if (!$this->isRetryable($e) || $attempt >= $this->maxRetries) {
                    throw $e;
                }

                ++$attempt;

                $this->logger->warning('Retryable error, retrying command', [
                    'command' => $command::class,
                    'attempt' => $attempt,
                    'max'     => $this->maxRetries,
                    'error'   => $e::class,
                    'message' => $e->getMessage(),
                ]);

                usleep($backoffMs * 1_000);
                $backoffMs *= 2;
            }
        }
    }

    /**
     * Walk the exception chain looking for a RetryableException.
     *
     * The depth limit prevents an infinite loop in the unlikely event that
     * the exception chain is circular (defensive programming).
     */
    private function isRetryable(\Throwable $e): bool
    {
        $current = $e;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_CHAIN_DEPTH) {
            if ($current instanceof RetryableException) {
                return true;
            }

            $current = $current->getPrevious();
            ++$depth;
        }

        return false;
    }
}
