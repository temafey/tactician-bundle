<?php

declare(strict_types=1);

namespace League\Tactician\Bundle\Tests\Middleware;

use Doctrine\DBAL\Connection;
use League\Tactician\Bundle\Middleware\DatabaseTransactionMiddleware;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseTransactionMiddleware::class)]
final class DatabaseTransactionMiddlewareTest extends TestCase
{
    private Connection&MockInterface $connection;
    private DatabaseTransactionMiddleware $middleware;

    protected function setUp(): void
    {
        $this->connection = Mockery::mock(Connection::class);
        $this->middleware = new DatabaseTransactionMiddleware($this->connection);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    #[Test]
    public function itWrapsCommandInTransaction(): void
    {
        $command = new \stdClass();
        $expectedResult = 'result';

        $this->connection->shouldReceive('beginTransaction')->once();
        $this->connection->shouldReceive('commit')->once();
        $this->connection->shouldNotReceive('rollBack');

        $result = $this->middleware->execute($command, fn ($cmd) => $expectedResult);

        self::assertSame($expectedResult, $result);
    }

    #[Test]
    public function itRollsBackOnException(): void
    {
        $command = new \stdClass();
        $exception = new \RuntimeException('Handler failed');

        $this->connection->shouldReceive('beginTransaction')->once();
        $this->connection->shouldNotReceive('commit');
        $this->connection->shouldReceive('rollBack')->once();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler failed');

        $this->middleware->execute($command, function () use ($exception): never {
            throw $exception;
        });
    }

    #[Test]
    public function itReturnsHandlerResult(): void
    {
        $command = new \stdClass();
        $expectedResult = ['data' => 'value'];

        $this->connection->shouldReceive('beginTransaction')->once();
        $this->connection->shouldReceive('commit')->once();

        $result = $this->middleware->execute($command, fn ($cmd) => $expectedResult);

        self::assertSame($expectedResult, $result);
    }

    #[Test]
    public function itPassesCommandToNextMiddleware(): void
    {
        $command = new \stdClass();
        $receivedCommand = null;

        $this->connection->shouldReceive('beginTransaction')->once();
        $this->connection->shouldReceive('commit')->once();

        $this->middleware->execute($command, function ($cmd) use (&$receivedCommand) {
            $receivedCommand = $cmd;

            return null;
        });

        self::assertSame($command, $receivedCommand);
    }
}
