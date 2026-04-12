<?php

declare(strict_types=1);

namespace League\Tactician\Bundle\Tests\Middleware;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use League\Tactician\Bundle\Middleware\TransientErrorRetryMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(TransientErrorRetryMiddleware::class)]
final class TransientErrorRetryMiddlewareTest extends TestCase
{
    private object $command;

    protected function setUp(): void
    {
        $this->command = new \stdClass();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function itRunsCommandOnceAndPropagatesReturnValueWhenNoException(): void
    {
        $callCount = 0;
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);

        $result = $middleware->execute($this->command, function () use (&$callCount) {
            ++$callCount;

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(1, $callCount);
    }

    // -------------------------------------------------------------------------
    // Retry once then succeed
    // -------------------------------------------------------------------------

    #[Test]
    public function itRetriesOnceOnDeadlockAndReturnsValueOnSecondAttempt(): void
    {
        $callCount = 0;
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);
        $deadlock = $this->makeDeadlock();

        $result = $middleware->execute($this->command, function () use (&$callCount, $deadlock) {
            ++$callCount;
            if ($callCount === 1) {
                throw $deadlock;
            }

            return 'recovered';
        });

        self::assertSame('recovered', $result);
        self::assertSame(2, $callCount);
    }

    // -------------------------------------------------------------------------
    // Max retries exceeded — throws after maxRetries+1 total attempts
    // -------------------------------------------------------------------------

    #[Test]
    public function itMakesExactlyMaxRetriesPlusOneCallBeforeGiving(): void
    {
        $callCount = 0;
        $maxRetries = 3;
        $middleware = new TransientErrorRetryMiddleware(maxRetries: $maxRetries, initialBackoffMs: 0);
        $deadlock = $this->makeDeadlock();

        try {
            $middleware->execute($this->command, function () use (&$callCount, $deadlock): never {
                ++$callCount;
                throw $deadlock;
            });
        } catch (\Throwable) {
            // expected after all retries exhausted
        }

        // 1 initial attempt + 3 retries = 4 total
        self::assertSame($maxRetries + 1, $callCount);
    }

    #[Test]
    public function itRethrowsRetryableExceptionAfterMaxRetriesExhausted(): void
    {
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);
        $deadlock = $this->makeDeadlock();

        $this->expectException(DeadlockException::class);

        $middleware->execute($this->command, function () use ($deadlock): never {
            throw $deadlock;
        });
    }

    // -------------------------------------------------------------------------
    // Non-retryable exception — thrown immediately without retry
    // -------------------------------------------------------------------------

    #[Test]
    public function itThrowsImmediatelyForNonRetryableException(): void
    {
        $callCount = 0;
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bad input');

        $middleware->execute($this->command, function () use (&$callCount): never {
            ++$callCount;
            throw new \InvalidArgumentException('bad input');
        });
    }

    #[Test]
    public function itCallsNonRetryableHandlerExactlyOnce(): void
    {
        $callCount = 0;
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);

        try {
            $middleware->execute($this->command, function () use (&$callCount): never {
                ++$callCount;
                throw new \RuntimeException('generic');
            });
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(1, $callCount);
    }

    // -------------------------------------------------------------------------
    // Chain walking: wrapped retryable (defense-in-depth for VP-1)
    // -------------------------------------------------------------------------

    #[Test]
    public function itDetectsRetryableWhenWrappedDirectlyInRuntimeException(): void
    {
        $callCount = 0;
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);
        $deadlock = $this->makeDeadlock();
        $wrapped = new \RuntimeException('wrapped', 0, $deadlock);

        $result = $middleware->execute($this->command, function () use (&$callCount, $wrapped) {
            ++$callCount;
            if ($callCount === 1) {
                throw $wrapped;
            }

            return 'chain-recovered';
        });

        self::assertSame('chain-recovered', $result);
        self::assertSame(2, $callCount);
    }

    #[Test]
    public function itDetectsRetryableInDeeplyNestedChain(): void
    {
        $callCount = 0;
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);

        // RuntimeException -> LogicException -> DeadlockException (depth 2)
        $deadlock = $this->makeDeadlock();
        $logic = new \LogicException('logic', 0, $deadlock);
        $runtime = new \RuntimeException('runtime', 0, $logic);

        $result = $middleware->execute($this->command, function () use (&$callCount, $runtime) {
            ++$callCount;
            if ($callCount === 1) {
                throw $runtime;
            }

            return 'deep-recovered';
        });

        self::assertSame('deep-recovered', $result);
        self::assertSame(2, $callCount);
    }

    // -------------------------------------------------------------------------
    // Depth limit: retryable at position >= 10 is NOT detected, no infinite loop
    // -------------------------------------------------------------------------

    #[Test]
    public function itDoesNotRetryWhenRetryableIsBeyondDepthLimit(): void
    {
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);

        // Build a chain where the DeadlockException is at depth exactly 10 (0-indexed),
        // meaning 10 generic wrappers sit in front of it — beyond the MAX_CHAIN_DEPTH=10 walk limit.
        $deepDeadlock = $this->makeDeadlock();
        $chain = $deepDeadlock;
        for ($i = 0; $i < 10; ++$i) {
            $chain = new \RuntimeException('wrapper-' . $i, 0, $chain);
        }
        // $chain is now a RuntimeException at depth 0; DeadlockException is at depth 10.
        // The walker checks depths 0..9 (10 iterations), so depth-10 is never reached.

        $callCount = 0;

        try {
            $middleware->execute($this->command, function () use (&$callCount, $chain): never {
                ++$callCount;
                throw $chain;
            });
        } catch (\Throwable) {
            // expected: treated as non-retryable
        }

        // Should NOT retry; the DeadlockException is at depth 10 which is beyond MAX_CHAIN_DEPTH
        self::assertSame(1, $callCount);
    }

    #[Test]
    public function itDoesRetryWhenRetryableIsAtLastAllowedDepth(): void
    {
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0);
        $callCount = 0;

        // DeadlockException at depth 9 (9 wrappers in front) — still within the 10-step walk.
        $deadlock = $this->makeDeadlock();
        $chain = $deadlock;
        for ($i = 0; $i < 9; ++$i) {
            $chain = new \RuntimeException('wrapper-' . $i, 0, $chain);
        }

        $result = $middleware->execute($this->command, function () use (&$callCount, $chain) {
            ++$callCount;
            if ($callCount === 1) {
                throw $chain;
            }

            return 'depth-9-recovered';
        });

        self::assertSame('depth-9-recovered', $result);
        self::assertSame(2, $callCount);
    }

    // -------------------------------------------------------------------------
    // Logger assertions
    // -------------------------------------------------------------------------

    #[Test]
    public function itLogsWarningOnEachRetryWithExpectedContext(): void
    {
        $logMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context) use (&$logMessages): void {
                $logMessages[] = ['message' => $message, 'context' => $context];
            });

        $callCount = 0;
        $middleware = new TransientErrorRetryMiddleware(maxRetries: 3, initialBackoffMs: 0, logger: $logger);
        $deadlock = $this->makeDeadlock();

        $middleware->execute($this->command, function () use (&$callCount, $deadlock) {
            ++$callCount;
            if ($callCount <= 2) {
                throw $deadlock;
            }

            return 'done';
        });

        self::assertCount(2, $logMessages);
        self::assertSame('Retryable error, retrying command', $logMessages[0]['message']);
        self::assertSame(1, $logMessages[0]['context']['attempt']);
        self::assertSame(2, $logMessages[1]['context']['attempt']);
        self::assertSame(3, $logMessages[0]['context']['max']);
        self::assertSame(\stdClass::class, $logMessages[0]['context']['command']);
        self::assertSame(DeadlockException::class, $logMessages[0]['context']['error']);
    }

    #[Test]
    public function itUsesNullLoggerByDefault(): void
    {
        $middleware = new TransientErrorRetryMiddleware();

        $result = $middleware->execute($this->command, fn () => 'no-logger-ok');

        self::assertSame('no-logger-ok', $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a DeadlockException using an anonymous Driver\Exception stub.
     *
     * DeadlockException extends ServerException extends DriverException,
     * whose constructor requires a Doctrine\DBAL\Driver\Exception (the interface).
     */
    private function makeDeadlock(): DeadlockException
    {
        $driverException = new class ('Deadlock found when trying to get lock') extends \RuntimeException implements DriverException {
            public function getSQLState(): ?string
            {
                return '40001';
            }
        };

        return new DeadlockException($driverException, null);
    }
}
