<?php
declare(strict_types=1);

namespace Prosit\Support;

/**
 * Configurazione a due livelli:
 *   1. config/defaults.php       valori predefiniti, versionati con il codice
 *   2. PROSIT_ETC/prosit.php     valori della macchina (percorsi, segreti): sovrascrive il punto 1
 *                                (PROSIT_ETC = /etc/prosit se esiste, altrimenti <prosit>/local)
 * Il percorso del punto 2 si puo' cambiare con la variabile d'ambiente PROSIT_CONFIG.
 */
final class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        $data = require PROSIT_ROOT . '/config/defaults.php';
        $local = getenv('PROSIT_CONFIG') ?: ($_SERVER['PROSIT_CONFIG'] ?? PROSIT_ETC . '/prosit.php');
        if ($local !== '' && is_file($local) && is_readable($local)) {
            $override = require $local;
            if (is_array($override)) {
                $data = self::merge($data, $override);
            }
        }
        self::$data = $data;
        self::$loaded = true;
    }

    /** Lettura con percorso puntato: Config::get('auth.session.key'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** Solo per i test. */
    public static function set(string $path, mixed $value): void
    {
        $ref = &self::$data;
        foreach (explode('.', $path) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    /** Merge ricorsivo: gli array associativi si fondono, le liste vengono sostituite. */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v) && !array_is_list($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /** Percorso dentro var/ (log, cache, dati). */
    public static function varPath(string $sub = ''): string
    {
        $base = rtrim((string) self::get('paths.var', PROSIT_ROOT . '/var'), '/');
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }
}
