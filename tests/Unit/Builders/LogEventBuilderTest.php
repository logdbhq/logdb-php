<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Builders;

use DateTimeImmutable;
use LogDB\Builders\LogEventBuilder;
use LogDB\Models\Log;
use LogDB\Models\LogLevel;
use LogDB\Models\LogResponseStatus;
use LogDB\Tests\Support\InMemoryClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogEventBuilderTest extends TestCase
{
    public function testBuildersAreImmutable(): void
    {
        $client = new InMemoryClient();
        $base = LogEventBuilder::create($client)->setMessage('base');
        $extended = $base->setUserEmail('alice@example.com');

        $this->assertNull($base->build()->userEmail);
        $this->assertSame('alice@example.com', $extended->build()->userEmail);
    }

    public function testAddAttributeRoutesByValueType(): void
    {
        $client = new InMemoryClient();
        $log = LogEventBuilder::create($client)
            ->setMessage('m')
            ->addAttribute('s', 'string-value')
            ->addAttribute('n_int', 42)
            ->addAttribute('n_float', 3.14)
            ->addAttribute('b', true)
            ->addAttribute('d', new DateTimeImmutable('2026-01-01'))
            ->build();

        $this->assertSame(['s' => 'string-value'], $log->attributesS);
        $this->assertSame(['n_int' => 42, 'n_float' => 3.14], $log->attributesN);
        $this->assertSame(['b' => true], $log->attributesB);
        $this->assertNotNull($log->attributesD);
        $this->assertArrayHasKey('d', $log->attributesD);
    }

    public function testAddLabelAppends(): void
    {
        $client = new InMemoryClient();
        $log = LogEventBuilder::create($client)
            ->setMessage('m')
            ->addLabel('a')
            ->addLabel('b')
            ->build();

        $this->assertSame(['a', 'b'], $log->label);
    }

    public function testSetExceptionPopulatesExceptionAndStack(): void
    {
        $client = new InMemoryClient();
        $err = new RuntimeException('payment gateway 500');
        $log = LogEventBuilder::create($client)
            ->setMessage('failure')
            ->setException($err)
            ->build();

        $this->assertNotNull($log->exception);
        $this->assertStringContainsString('RuntimeException', $log->exception);
        $this->assertStringContainsString('payment gateway 500', $log->exception);
        $this->assertNotNull($log->stackTrace);
        $this->assertSame(LogLevel::Error, $log->level);
    }

    public function testLogSendsViaClientAndReturnsStatus(): void
    {
        $client = new InMemoryClient();
        $status = LogEventBuilder::create($client)
            ->setMessage('hello')
            ->setLogLevel(LogLevel::Info)
            ->log();

        $this->assertSame(LogResponseStatus::Success, $status);
        $this->assertCount(1, $client->logs);
        $this->assertSame('hello', $client->logs[0]->message);
    }
}
