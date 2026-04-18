<?php

declare(strict_types=1);

namespace LogDB\Integrations\Monolog;

use DateTimeImmutable;
use LogDB\LogDBClientLike;
use LogDB\Models\Log;
use LogDB\Models\LogLevel;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Monolog handler that ships records to LogDB via a `LogDBClient`.
 *
 * Maps Monolog levels to LogDB severity, copies `$record->context` into typed
 * attribute maps (split by value type), and lifts well-known fields
 * (`exception`, `user_email`, `correlation_id`, etc.) into top-level columns.
 *
 * Requires `monolog/monolog: ^3.0`. The class is autoloaded but only usable when
 * Monolog is installed in the consuming application.
 *
 * @example
 *   $logger = new \Monolog\Logger('app');
 *   $logger->pushHandler(new LogDBHandler($logdbClient, \Monolog\Level::Info));
 *   $logger->info('user logged in', ['user_email' => 'alice@example.com']);
 */
final class LogDBHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogDBClientLike $client,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $this->client->logEntry($this->recordToLog($record));
    }

    private function recordToLog(LogRecord $record): Log
    {
        $log = new Log(
            message: $record->message,
            timestamp: DateTimeImmutable::createFromInterface($record->datetime),
            level: self::monologLevelToLogDb($record->level),
            source: $record->channel,
        );

        $context = $record->context;

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            /** @var Throwable $e */
            $e = $context['exception'];
            $log->exception = $e::class . ': ' . $e->getMessage();
            $log->stackTrace = $e->getTraceAsString();
            unset($context['exception']);
        }

        $stringMap = [];
        $numberMap = [];
        $boolMap = [];
        $dateMap = [];

        foreach ($context as $k => $v) {
            $ks = (string) $k;
            switch ($ks) {
                case 'user_id':
                case 'userId':
                    if (is_int($v)) {
                        $log->userId = $v;
                    }
                    break;
                case 'user_email':
                case 'userEmail':
                    if (is_string($v)) {
                        $log->userEmail = $v;
                    }
                    break;
                case 'correlation_id':
                case 'correlationId':
                    if (is_string($v)) {
                        $log->correlationId = $v;
                    }
                    break;
                case 'request_path':
                case 'http.target':
                    if (is_string($v)) {
                        $log->requestPath = $v;
                    }
                    break;
                case 'http_method':
                case 'http.method':
                    if (is_string($v)) {
                        $log->httpMethod = $v;
                    }
                    break;
                case 'status_code':
                case 'http.status_code':
                    if (is_int($v)) {
                        $log->statusCode = $v;
                    }
                    break;
                case 'ip_address':
                case 'client.address':
                    if (is_string($v)) {
                        $log->ipAddress = $v;
                    }
                    break;
                default:
                    if (is_int($v) || is_float($v)) {
                        $numberMap[$ks] = $v;
                    } elseif (is_bool($v)) {
                        $boolMap[$ks] = $v;
                    } elseif ($v instanceof DateTimeImmutable) {
                        $dateMap[$ks] = $v;
                    } elseif (is_string($v)) {
                        $stringMap[$ks] = $v;
                    } elseif ($v !== null) {
                        $encoded = json_encode($v);
                        if ($encoded !== false) {
                            $stringMap[$ks] = $encoded;
                        }
                    }
            }
        }

        // Treat Monolog `extra` entries as labels — they are typically
        // host/processor metadata that LogDB users want to filter by.
        $labels = [];
        foreach ($record->extra as $k => $v) {
            if (is_scalar($v)) {
                $labels[] = (string) $k . '=' . (string) $v;
            }
        }
        if ($labels !== []) {
            $log->label = $labels;
        }

        if ($stringMap !== []) {
            $log->attributesS = $stringMap;
        }
        if ($numberMap !== []) {
            $log->attributesN = $numberMap;
        }
        if ($boolMap !== []) {
            $log->attributesB = $boolMap;
        }
        if ($dateMap !== []) {
            $log->attributesD = $dateMap;
        }

        return $log;
    }

    private static function monologLevelToLogDb(Level $level): LogLevel
    {
        return match ($level) {
            Level::Debug => LogLevel::Debug,
            Level::Info, Level::Notice => LogLevel::Info,
            Level::Warning => LogLevel::Warning,
            Level::Error => LogLevel::Error,
            Level::Critical, Level::Alert, Level::Emergency => LogLevel::Critical,
        };
    }
}
