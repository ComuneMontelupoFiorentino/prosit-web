<?php
declare(strict_types=1);

namespace Prosit\Http;

use Prosit\Support\Log;

/**
 * Formato unico di TUTTE le risposte JSON:
 *   successo: {"success":true, "status":200, "data":..., "meta":{...}}
 *   errore:   {"success":false,"status":403,"error":"messaggio","request_id":"a1b2c3d4"}
 * Il request_id e' lo stesso scritto nei log: basta cercarlo per trovare il dettaglio.
 */
final class Response
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function json(mixed $data, int $status = 200, array $meta = []): never
    {
        $body = ['success' => true, 'status' => $status, 'data' => $data];
        if ($meta) {
            $body['meta'] = $meta;
        }
        self::send($body, $status);
    }

    public static function error(string $message, int $status = 400, array $details = []): never
    {
        $body = ['success' => false, 'status' => $status, 'error' => $message, 'request_id' => Log::requestId()];
        if ($details) {
            $body['details'] = $details;
        }
        self::send($body, $status);
    }

    public static function send(array $body, int $status): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('X-Request-Id: ' . Log::requestId());
        }
        echo json_encode($body, self::JSON_FLAGS);
        exit;
    }

    /** Pagina HTML minimale di errore (per le pagine applicative, non per l'API). */
    public static function htmlError(int $status, string $title, string $message): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $t = htmlspecialchars($title, ENT_QUOTES);
        $m = htmlspecialchars($message, ENT_QUOTES);
        $rid = Log::requestId();
        echo <<<HTML
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t}</title><style>body{font-family:system-ui,sans-serif;background:#f4f5f7;display:flex;min-height:90vh;align-items:center;justify-content:center;margin:0}
.box{background:#fff;border:1px solid #dee2e6;border-left:6px solid #cc334d;border-radius:8px;padding:1.5rem 2rem;max-width:520px}
h1{font-size:1.25rem;margin:0 0 .5rem;color:#cc334d}small{color:#6c757d}</style></head>
<body><div class="box"><h1>{$t}</h1><p>{$m}</p><small>Codice richiesta: {$rid}</small></div></body></html>
HTML;
        exit;
    }
}
