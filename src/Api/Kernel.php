<?php
declare(strict_types=1);

namespace Prosit\Api;

use Prosit\Auth\Auth;
use Prosit\Http\ApiException;
use Prosit\Http\Request;
use Prosit\Http\Response;
use Prosit\Support\Config;
use Prosit\Support\Log;

/**
 * Servizi di Prosit che la Prosit API v2 (/lizmap/api.php/prosit/v2, PostgREST) non copre:
 *
 *   GET /v1/health                  stato del servizio (senza autenticazione)
 *   GET /v1/auth/me                 utente, gruppi e applicazioni (pulsanti nelle mappe Lizmap)
 *   GET /v1/documenti/rekla         proxy autenticato verso gli allegati di Rekla
 *
 * I DATI (letture e scritture sulle tabelle) passano SOLO dalla API v2: qui non ci sono
 * piu' risorse generiche, ne' la vecchia /lizmap/API.
 */
final class Kernel
{
    /** Controller con route dedicate: ognuno espone static routes(): array. */
    private const CONTROLLERS = [
        Controllers\MeController::class,
        Controllers\DocumentController::class,
    ];

    public static function handle(): never
    {
        $t0 = microtime(true);
        register_shutdown_function(static function () use ($t0): void {
            $status = http_response_code() ?: 200;
            $who = Auth::isResolved() ? Auth::current()->login : '-';
            Log::info(sprintf('%s %s %s %d %dms', $who ?: '-', Request::method(), Request::path(), $status, (int) ((microtime(true) - $t0) * 1000)), 'access');
        });

        try {
            self::dispatch(Request::method(), self::routePath());
        } catch (\Throwable $e) {
            $ex = ErrorMapper::fromThrowable($e);
            if ($ex->status === 405 && isset($ex->details['allowed'])) {
                header('Allow: ' . implode(', ', $ex->details['allowed']));
            }
            Response::error($ex->getMessage(), $ex->status, $ex->status === 405 ? [] : $ex->details);
        }
    }

    /** Percorso relativo all'API, senza i prefissi configurati (/lizmap/prosit/api). */
    public static function routePath(): string
    {
        $path = Request::path();
        foreach ((array) Config::get('api.prefixes', []) as $prefix) {
            $prefix = rtrim((string) $prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }
        $path = preg_replace('#^/index\.php#', '', $path) ?? $path;
        return '/' . trim($path, '/');
    }

    private static function dispatch(string $method, string $path): never
    {
        if ($method === 'OPTIONS') {
            header('Allow: GET, OPTIONS');
            http_response_code(204);
            exit;
        }
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        if (!str_starts_with($path, '/v1/')) {
            throw ApiException::notFound('Percorso non trovato. I dati sono sulla Prosit API v2: /lizmap/api.php/prosit/v2');
        }
        $path = substr($path, 3); // toglie "/v1"

        // --- route dedicate ------------------------------------------------
        $methodMismatch = [];
        foreach (self::CONTROLLERS as $class) {
            foreach ($class::routes() as [$m, $regex, $handler]) {
                if (preg_match('#^' . $regex . '$#', $path, $args)) {
                    if ($m !== $method) {
                        $methodMismatch[] = $m;
                        continue;
                    }
                    $handler(...array_slice($args, 1));
                    exit;
                }
            }
        }
        if ($methodMismatch !== []) {
            throw ApiException::methodNotAllowed(array_values(array_unique($methodMismatch)));
        }

        throw ApiException::notFound('Percorso non trovato. I dati sono sulla Prosit API v2: /lizmap/api.php/prosit/v2');
    }
}
