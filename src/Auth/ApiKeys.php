<?php
declare(strict_types=1);

namespace Prosit\Auth;

use Prosit\Http\Request;
use Prosit\Support\Config;

/**
 * Chiavi per le macchine (app QField, stazione meteo, sincronizzazioni).
 *
 * File local/api_keys.php (permessi 0640 root:www-data):
 *   return [
 *     'meteo' => [
 *       'sha256' => '<hash della chiave>',   // MAI la chiave in chiaro: bin/prosit key:new la genera
 *       'ips' => ['10.0.35.0/24'],           // opzionale: la chiave vale solo da questi IP/reti
 *       'trusted_ips' => ['10.0.33.15'],     // opzionale: da questi IP si entra anche SENZA chiave
 *                                            // (dispositivi che non si possono riconfigurare)
 *       'query_param' => true,               // opzionale: ammette ?key=... (dispositivi che non inviano intestazioni)
 *       'groups' => [],                      // opzionale: gruppi "virtuali" per i permessi delle risorse
 *     ],
 *   ];
 *
 * La chiave arriva con l'intestazione  X-Api-Key: <chiave>  oppure  Authorization: Bearer <chiave>.
 */
final class ApiKeys
{
    private static ?array $keys = null;

    public static function all(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }
        $file = (string) Config::get('auth.api_keys_file', PROSIT_ETC . '/api_keys.php');
        $keys = (is_file($file) && is_readable($file)) ? require $file : [];
        return self::$keys = is_array($keys) ? $keys : [];
    }

    /** Chiave presentata nella richiesta (null se assente). */
    public static function presented(bool $allowQuery = false): ?string
    {
        $k = Request::header('X-Api-Key');
        if ($k === null) {
            $auth = Request::header('Authorization');
            if ($auth !== null && stripos($auth, 'Bearer ') === 0) {
                $k = trim(substr($auth, 7));
            }
        }
        if ($k === null && $allowQuery && isset($_GET['key']) && is_string($_GET['key'])) {
            $k = $_GET['key'];
        }
        $k = $k !== null ? trim($k) : null;
        return ($k === null || $k === '' || strlen($k) > 256) ? null : $k;
    }

    /** Identita' della macchina, oppure null. Se $only e' indicato accetta solo quella chiave. */
    public static function identify(?string $only = null): ?Identity
    {
        $ip = Request::ip();
        foreach (self::all() as $name => $entry) {
            if (!is_array($entry) || ($only !== null && $name !== $only)) {
                continue;
            }
            $presented = self::presented((bool) ($entry['query_param'] ?? false));
            $hash = strtolower((string) ($entry['sha256'] ?? ''));
            $ips = array_map('strval', (array) ($entry['ips'] ?? []));
            $keyOk = $presented !== null && $hash !== '' && hash_equals($hash, hash('sha256', $presented))
                && ($ips === [] || self::ipMatches($ip, $ips));
            $trusted = array_map('strval', (array) ($entry['trusted_ips'] ?? []));
            $ipOnlyOk = $presented === null && $trusted !== [] && self::ipMatches($ip, $trusted);
            if ($keyOk || $ipOnlyOk) {
                return new Identity(
                    type: 'apikey',
                    login: 'key:' . $name,
                    groupIds: array_map('strval', (array) ($entry['groups'] ?? [])),
                    keyName: (string) $name
                );
            }
        }
        return null;
    }

    /** IP singoli o reti CIDR IPv4 (es. 10.0.35.0/24). */
    public static function ipMatches(string $ip, array $list): bool
    {
        foreach ($list as $rule) {
            if ($rule === $ip) {
                return true;
            }
            if (str_contains($rule, '/') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                [$net, $bits] = explode('/', $rule, 2);
                $bits = (int) $bits;
                if ($bits >= 0 && $bits <= 32 && filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
                    if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
