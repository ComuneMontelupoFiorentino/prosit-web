<?php
declare(strict_types=1);

namespace Prosit\Auth;

use Prosit\Support\Config;
use Prosit\Support\Log;

/**
 * Utente collegato a Lizmap chiesto a Lizmap stesso, tramite la Prosit API v2:
 *   GET <auth.lizmap_api.url>   (predefinito http://127.0.0.1/lizmap/api.php/prosit/v2/auth/me)
 * inoltrando SOLO il cookie di sessione del browser.
 *
 * Prosit non legge ne' i file di sessione di PHP ne' le tabelle degli utenti di Lizmap:
 * funziona con gli utenti in SQLite o in PostgreSQL e agli aggiornamenti di Jelix; gli utenti
 * disattivati e i gruppi sono quelli che vede Lizmap in quel momento.
 *
 * - Cache breve (auth.lizmap_api.cache_secondi, predefinito 30) per non chiamare Lizmap a
 *   ogni richiesta; la chiave e' l'hash del cookie, il file e' leggibile solo da PHP.
 * - Se Lizmap non risponde o risponde in modo inatteso: utente NON collegato (si nega).
 * - Il cookie "ricordami" (jauthSession) NON viene inoltrato: come prima, se la sessione di
 *   Lizmap e' scaduta l'utente deve rientrare da Lizmap.
 */
final class LizmapApi
{
    /** @return array{login:string,email:string,firstname:string,lastname:string,organization:string,groupIds:string[],groupNames:string[]}|null */
    public static function read(): ?array
    {
        $names = (array) Config::get('auth.session.cookie_names', ['PHPSESSID']);
        $cookies = [];
        foreach ($names as $name) {
            $id = $_COOKIE[$name] ?? null;
            if (is_string($id) && preg_match('/^[A-Za-z0-9,-]{16,256}$/', $id)) {
                $cookies[] = $name . '=' . $id;
            }
        }
        if ($cookies === []) {
            return null;
        }
        $cookie = implode('; ', $cookies);
        $ttl = (int) Config::get('auth.lizmap_api.cache_secondi', 30);
        $file = $ttl > 0 ? self::cacheFile($cookie) : null;

        if ($file !== null && is_file($file) && (time() - (int) @filemtime($file)) < $ttl) {
            $c = json_decode((string) @file_get_contents($file), true);
            if (is_array($c) && array_key_exists('u', $c)) {
                return $c['u'];
            }
        }

        $u = self::ask($cookie);
        if ($u !== false && $file !== null) {
            // si memorizza anche "non collegato" (null), non gli errori
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, json_encode(['u' => $u]), LOCK_EX) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $file);
            }
        }
        return $u === false ? null : $u;
    }

    /** @return array|null|false  false = errore (non va in cache) */
    private static function ask(string $cookie): array|null|false
    {
        $url = (string) Config::get('auth.lizmap_api.url', 'http://127.0.0.1/lizmap/api.php/prosit/v2/auth/me');
        $timeout = (float) Config::get('auth.lizmap_api.timeout', 3);
        $headers = ['Cookie: ' . $cookie, 'Accept: application/json', 'User-Agent: prosit-web'];
        $host = (string) Config::get('auth.lizmap_api.host', '');
        if ($host !== '') {
            $headers[] = 'Host: ' . $host;
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
                'ignore_errors' => true,          // leggere anche il 401
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $t0 = microtime(true);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];   // l'ultima riga di stato
            }
        }
        if ($body === false || $status === 0) {
            Log::error(sprintf('Lizmap non risponde (%s, %.1fs)', $url, microtime(true) - $t0), 'auth');
            return false;
        }
        if ($status === 401) {
            return null;                              // sessione assente o scaduta
        }
        $d = json_decode($body, true);
        if ($status !== 200 || !is_array($d) || trim((string) ($d['login'] ?? '')) === ''
            || ($d['tipo_accesso'] ?? '') !== 'sessione') {
            Log::error('Risposta inattesa da Lizmap auth/me: HTTP ' . $status . ' ' . substr(preg_replace('/\s+/', ' ', $body), 0, 200), 'auth');
            return false;
        }
        $g = (array) ($d['gruppi'] ?? []);
        $first = (string) ($d['nome_proprio'] ?? '');
        $last = (string) ($d['cognome'] ?? '');
        if (!array_key_exists('nome_proprio', $d)) {
            // modulo prositapi 2.0.0: solo il nome completo
            $n = (string) ($d['nome'] ?? '');
            $first = $n !== $d['login'] ? $n : '';
        }
        return [
            'login' => trim((string) $d['login']),
            'email' => (string) ($d['email'] ?? ''),
            'firstname' => $first,
            'lastname' => $last,
            'organization' => (string) ($d['organizzazione'] ?? ''),
            'groupIds' => array_values(array_map('strval', (array) ($g['id'] ?? []))),
            'groupNames' => array_values(array_map('strval', (array) ($g['nome'] ?? []))),
        ];
    }

    private static function cacheFile(string $cookie): ?string
    {
        try {
            $dir = rtrim(Config::varPath('cache'), '/') . '/auth';
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return null;
            }
            return $dir . '/' . hash('sha256', $cookie) . '.json';
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Per /health?full=1: Lizmap risponde all'indirizzo configurato? */
    public static function check(): string
    {
        $url = (string) Config::get('auth.lizmap_api.url', 'http://127.0.0.1/lizmap/api.php/prosit/v2/auth/me');
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $line = ($http_response_header ?? [''])[0] ?? '';
        if ($body === false) {
            return 'non risponde: ' . $url;
        }
        // senza cookie la risposta corretta e' 401 in formato problem+json
        return str_contains($line, ' 401') && str_contains($body, '"status":401') ? 'ok' : 'risposta inattesa: ' . $line;
    }
}
