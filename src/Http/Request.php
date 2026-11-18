<?php
declare(strict_types=1);

namespace Prosit\Http;

use Prosit\Support\Config;

/** Lettura uniforme della richiesta HTTP. */
final class Request
{
    private static ?array $jsonBody = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isUnsafe(): bool
    {
        return !in_array(self::method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public static function path(): string
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return '/' . ltrim(rawurldecode($uri), '/');
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $_SERVER[$key] ?? null;
        if ($v === null && strcasecmp($name, 'Authorization') === 0) {
            $v = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        return $v === null ? null : (string) $v;
    }

    /** Parametri di query (stringhe; gli array vengono scartati). */
    public static function query(): array
    {
        $out = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }

    /** Corpo JSON (oggetto o lista). Limite di dimensione da configurazione. */
    public static function json(): array
    {
        if (self::$jsonBody !== null) {
            return self::$jsonBody;
        }
        $max = (int) Config::get('api.max_body_bytes', 1048576);
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > $max) {
            throw new ApiException(413, 'Corpo della richiesta troppo grande.');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) {
            throw new ApiException(413, 'Corpo della richiesta troppo grande.');
        }
        if (trim($raw) === '') {
            return self::$jsonBody = [];
        }
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($type !== '' && !str_contains($type, 'json')) {
            throw new ApiException(415, 'Il corpo deve essere JSON (Content-Type: application/json).');
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::badRequest('JSON non valido.');
        }
        if (!is_array($data)) {
            throw ApiException::badRequest('Il corpo JSON deve essere un oggetto o una lista di oggetti.');
        }
        return self::$jsonBody = $data;
    }

    /** Origine della richiesta (schema://host[:porta]) come la vede il browser. */
    public static function selfOrigin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /**
     * Protezione CSRF per le richieste che modificano dati con la sessione del browser:
     * l'intestazione Origin (o, se manca, Referer) deve essere questo stesso sito
     * o uno di quelli in security.allowed_origins.
     */
    public static function assertSameOrigin(): void
    {
        $allowed = array_map(
            static fn($o) => rtrim(strtolower((string) $o), '/'),
            array_merge([self::selfOrigin()], (array) Config::get('security.allowed_origins', []))
        );
        $origin = self::header('Origin');
        if ($origin !== null && $origin !== '' && $origin !== 'null') {
            if (in_array(rtrim(strtolower($origin), '/'), $allowed, true)) {
                return;
            }
            throw ApiException::forbidden('Richiesta da un sito non autorizzato (Origin).');
        }
        $ref = self::header('Referer');
        if ($ref !== null && $ref !== '') {
            $p = parse_url($ref);
            $o = strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
            if (in_array($o, $allowed, true)) {
                return;
            }
        }
        throw ApiException::forbidden('Richiesta senza origine verificabile: operazione bloccata (CSRF).');
    }
}
