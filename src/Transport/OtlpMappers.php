<?php

declare(strict_types=1);

namespace LogDB\Transport;

use LogDB\Models\Log;
use LogDB\Models\LogBeat;
use LogDB\Models\LogCache;
use LogDB\Models\LogMeta;

/**
 * OTLP/JSON request body builders.
 *
 * Resource attributes are stamped per scope (one scope per call) — this lets the
 * SDK send heterogeneous logs in a single request even if their `application` /
 * `environment` differ from one another.
 */
final class OtlpMappers
{
    public const DEFAULT_SCOPE_NAME = 'logdbhq/logdb-php';

    /**
     * @param Log[]                                                                  $logs
     * @param array{apiKey?: ?string, application?: ?string, environment?: ?string, collection?: ?string} $defaults
     * @return array{resourceLogs: array<int, array<string, mixed>>}
     */
    public static function buildLogsRequest(array $logs, array $defaults): array
    {
        $groups = [];
        foreach ($logs as $l) {
            $app = $l->application ?? ($defaults['application'] ?? '');
            $env = $l->environment ?? ($defaults['environment'] ?? '');
            $coll = $l->collection ?? ($defaults['collection'] ?? '');
            $key = "{$app}\x00{$env}\x00{$coll}";
            $groups[$key][] = $l;
        }

        $resourceLogs = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $resourceLogs[] = [
                'resource' => [
                    'attributes' => self::buildResourceAttrs([
                        'apiKey' => $defaults['apiKey'] ?? null,
                        'application' => $first->application ?? ($defaults['application'] ?? null),
                        'environment' => $first->environment ?? ($defaults['environment'] ?? null),
                        'collection' => $first->collection ?? ($defaults['collection'] ?? null),
                    ]),
                ],
                'scopeLogs' => [
                    [
                        'scope' => ['name' => $first->source ?? self::DEFAULT_SCOPE_NAME],
                        'logRecords' => array_map([self::class, 'toOtlpLogRecord'], $group),
                    ],
                ],
            ];
        }

        return ['resourceLogs' => $resourceLogs];
    }

    /**
     * @param LogBeat[]                                                              $beats
     * @param array{apiKey?: ?string, application?: ?string, environment?: ?string, collection?: ?string} $defaults
     * @return array{resourceMetrics: array<int, array<string, mixed>>}
     */
    public static function buildMetricsRequest(array $beats, array $defaults): array
    {
        $metrics = array_map([self::class, 'toMetric'], $beats);

        return [
            'resourceMetrics' => [
                [
                    'resource' => [
                        'attributes' => self::buildResourceAttrs([
                            'apiKey' => $defaults['apiKey'] ?? null,
                            'application' => $defaults['application'] ?? null,
                            'environment' => $defaults['environment'] ?? null,
                            'collection' => $defaults['collection'] ?? null,
                        ]),
                    ],
                    'scopeMetrics' => [
                        [
                            'scope' => ['name' => self::DEFAULT_SCOPE_NAME],
                            'metrics' => $metrics,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Cache entries ride the logs pipeline with a routing attribute the server picks up.
     *
     * @param LogCache[]                                                             $caches
     * @param array{apiKey?: ?string, application?: ?string, environment?: ?string, collection?: ?string} $defaults
     * @return array{resourceLogs: array<int, array<string, mixed>>}
     */
    public static function buildCacheRequest(array $caches, array $defaults): array
    {
        $synthetic = [];
        foreach ($caches as $c) {
            $attrs = [
                '_logdb.kind' => 'cache',
                '_logdb.cache.key' => $c->key,
            ];
            if ($c->ttlSeconds !== null) {
                $attrs['_logdb.cache.ttl_seconds'] = (string) $c->ttlSeconds;
            }
            $synthetic[] = new Log(
                message: $c->value,
                guid: $c->guid,
                attributesS: $attrs,
            );
        }
        return self::buildLogsRequest($synthetic, $defaults);
    }

    /** @return array<string, mixed> */
    private static function toOtlpLogRecord(Log $log): array
    {
        $ts = $log->timestamp ?? new \DateTimeImmutable();
        $nano = self::nanoTimestamp($ts);
        $level = $log->level;

        return [
            'timeUnixNano' => $nano,
            'observedTimeUnixNano' => $nano,
            'severityNumber' => $level !== null ? $level->toOtelSeverity() : 9,
            'severityText' => $level !== null ? $level->toString() : 'Info',
            'body' => ['stringValue' => $log->message],
            'attributes' => self::buildLogAttributes($log),
        ];
    }

    /** @return array<int, array{key: string, value: array<string, mixed>}> */
    private static function buildLogAttributes(Log $log): array
    {
        $attrs = [];
        self::pushString($attrs, 'guid', $log->guid);
        self::pushString($attrs, 'exception', $log->exception);
        self::pushString($attrs, 'stack_trace', $log->stackTrace);
        self::pushString($attrs, 'source', $log->source);
        self::pushInt($attrs, 'user.id', $log->userId);
        self::pushString($attrs, 'user.email', $log->userEmail);
        self::pushString($attrs, 'correlation_id', $log->correlationId);
        self::pushString($attrs, 'http.method', $log->httpMethod);
        self::pushString($attrs, 'http.target', $log->requestPath);
        self::pushInt($attrs, 'http.status_code', $log->statusCode);
        self::pushString($attrs, 'client.address', $log->ipAddress);
        self::pushString($attrs, 'logdb.additional_data', $log->additionalData);
        self::pushString($attrs, 'logdb.description', $log->description);

        if ($log->label !== null) {
            foreach ($log->label as $i => $value) {
                self::pushString($attrs, "logdb.label.{$i}", $value);
            }
        }

        if ($log->attributesS !== null) {
            foreach ($log->attributesS as $k => $v) {
                self::pushString($attrs, $k, $v);
            }
        }
        if ($log->attributesN !== null) {
            foreach ($log->attributesN as $k => $v) {
                $attrs[] = ['key' => $k, 'value' => ['doubleValue' => (float) $v]];
            }
        }
        if ($log->attributesB !== null) {
            foreach ($log->attributesB as $k => $v) {
                $attrs[] = ['key' => $k, 'value' => ['boolValue' => $v]];
            }
        }
        if ($log->attributesD !== null) {
            foreach ($log->attributesD as $k => $v) {
                self::pushString($attrs, $k, $v->format('c'));
            }
        }

        return $attrs;
    }

    /** @return array<string, mixed> */
    private static function toMetric(LogBeat $beat): array
    {
        $ts = $beat->timestamp ?? new \DateTimeImmutable();
        $nano = self::nanoTimestamp($ts);

        $tagAttrs = [];
        foreach ($beat->tag ?? [] as $t) {
            self::pushString($tagAttrs, $t->key, $t->value);
        }
        self::pushString($tagAttrs, 'guid', $beat->guid);

        $dataPoints = [];
        foreach ($beat->field ?? [] as $f) {
            $point = [
                'timeUnixNano' => $nano,
                'attributes' => array_merge(
                    $tagAttrs,
                    [['key' => 'field.name', 'value' => ['stringValue' => $f->key]]],
                ),
            ];
            if (is_numeric($f->value) && trim($f->value) !== '') {
                $point['asDouble'] = (float) $f->value;
            }
            $dataPoints[] = $point;
        }

        if ($dataPoints === []) {
            $dataPoints[] = [
                'timeUnixNano' => $nano,
                'asDouble' => 1.0,
                'attributes' => $tagAttrs,
            ];
        }

        return [
            'name' => $beat->measurement,
            'gauge' => ['dataPoints' => $dataPoints],
        ];
    }

    /**
     * @param array{apiKey?: ?string, application?: ?string, environment?: ?string, collection?: ?string} $attrs
     * @return array<int, array{key: string, value: array<string, mixed>}>
     */
    private static function buildResourceAttrs(array $attrs): array
    {
        $out = [];
        self::pushString($out, 'service.name', $attrs['application'] ?? null);
        self::pushString($out, 'deployment.environment', $attrs['environment'] ?? null);
        self::pushString($out, 'logdb.apikey', $attrs['apiKey'] ?? null);
        self::pushString($out, 'logdb.collection', $attrs['collection'] ?? null);
        return $out;
    }

    /** @param array<int, array{key: string, value: array<string, mixed>}> $target */
    private static function pushString(array &$target, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $target[] = ['key' => $key, 'value' => ['stringValue' => $value]];
    }

    /** @param array<int, array{key: string, value: array<string, mixed>}> $target */
    private static function pushInt(array &$target, string $key, ?int $value): void
    {
        if ($value === null) {
            return;
        }
        $target[] = ['key' => $key, 'value' => ['intValue' => (string) $value]];
    }

    private static function nanoTimestamp(\DateTimeImmutable $ts): string
    {
        $micros = (int) $ts->format('Uu');
        return ((string) $micros) . '000';
    }
}
