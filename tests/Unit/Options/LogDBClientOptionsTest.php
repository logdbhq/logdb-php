<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Options;

use LogDB\Errors\LogDBConfigError;
use LogDB\Options\LogDBClientOptions;
use PHPUnit\Framework\TestCase;

final class LogDBClientOptionsTest extends TestCase
{
    public function testEndpointIsRequired(): void
    {
        $this->expectException(LogDBConfigError::class);
        new LogDBClientOptions(endpoint: '');
    }

    public function testEndpointMustBeUrlOrAbsolutePath(): void
    {
        $this->expectException(LogDBConfigError::class);
        new LogDBClientOptions(endpoint: 'not-a-url');
    }

    public function testNormalisedEndpointStripsTrailingSlash(): void
    {
        $opts = new LogDBClientOptions(endpoint: 'https://otlp.logdb.site/');
        $this->assertSame('https://otlp.logdb.site', $opts->normalisedEndpoint());
    }

    public function testRelativeEndpointAccepted(): void
    {
        $opts = new LogDBClientOptions(endpoint: '/api/logdb-relay');
        $this->assertSame('/api/logdb-relay', $opts->normalisedEndpoint());
    }

    public function testDefaultsApplied(): void
    {
        $opts = new LogDBClientOptions(
            endpoint: 'https://otlp.logdb.site',
            apiKey: 'k',
        );
        $this->assertSame('production', $opts->defaultEnvironment);
        $this->assertSame('logs', $opts->defaultCollection);
        $this->assertTrue($opts->enableBatching);
        $this->assertSame(100, $opts->batchSize);
        $this->assertSame(5_000, $opts->flushInterval);
        $this->assertSame(3, $opts->maxRetries);
        $this->assertSame(30_000, $opts->requestTimeout);
        $this->assertTrue($opts->flushOnShutdown);
    }
}
