<?php

declare(strict_types=1);

/**
 * Sketch of a Laravel controller using LogDB. This file is not standalone-runnable
 * — it shows how to wire LogDB into a real Laravel application.
 *
 * 1. composer require logdbhq/logdb-php
 * 2. (auto-discovered) LogDBServiceProvider registers the client.
 * 3. php artisan vendor:publish --tag=logdb-config
 * 4. Set LOGDB_API_KEY (and LOGDB_ENDPOINT if not the default) in your .env
 * 5. Add a 'logdb' channel to config/logging.php (see below).
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogDB\Builders\LogEventBuilder;
use LogDB\LogDBClient;
use LogDB\Models\LogLevel;

final class CheckoutController
{
    public function __construct(private readonly LogDBClient $logdb)
    {
    }

    public function store(Request $request): array
    {
        // Approach 1: PSR-3 via the Laravel Log facade.
        // After registering 'logdb' as a Monolog channel:
        Log::channel('logdb')->info('checkout started', [
            'user_email' => $request->user()?->email,
            'correlation_id' => $request->header('x-trace-id'),
            'cart_size' => count($request->input('items', [])),
        ]);

        // Approach 2: typed builder API for full-fidelity attributes.
        LogEventBuilder::create($this->logdb)
            ->setMessage('order placed')
            ->setLogLevel(LogLevel::Info)
            ->setUserEmail((string) $request->user()?->email)
            ->setCorrelationId((string) $request->header('x-trace-id'))
            ->setRequestPath($request->path())
            ->setHttpMethod($request->method())
            ->addAttribute('amount_eur', (float) $request->input('total'))
            ->addAttribute('currency', 'EUR')
            ->addLabel('checkout')
            ->log();

        return ['ok' => true];
    }
}

/*
|--------------------------------------------------------------------------
| config/logging.php — add this channel
|--------------------------------------------------------------------------

return [
    'channels' => [
        // ... your existing channels ...

        'logdb' => [
            'driver' => 'monolog',
            'handler' => \LogDB\Integrations\Monolog\LogDBHandler::class,
            // 'with' is optional — the handler is bound in the container with
            // the LogDBClient already injected by the service provider.
        ],
    ],
];
*/
