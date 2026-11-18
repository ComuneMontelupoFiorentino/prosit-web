<?php
declare(strict_types=1);

namespace Prosit\Support;

/**
 * Log su file, una riga per evento:
 *   2026-10-20 19:30:01 INFO  [a1b2c3] mario.rossi GET /v1/segnalazioni 200 12ms
 * Un file al mese per canale: var/log/<canale>-AAAA-MM.log
 * Mai password, token o corpi completi delle richieste.
 */
final class Log
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(4));
    }

    public static function file(string $channel = 'prosit'): string
    {
        $dir = Config::varPath('log');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?: 'prosit';
        return $dir . '/' . $channel . '-' . date('Y-m') . '.log';
    }

    public static function write(string $level, string $message, string $channel = 'prosit'): void
    {
        $message = str_replace(["\r", "\n"], ['', ' | '], $message);
        $line = sprintf("%s %-5s [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), self::requestId(), $message);
        @file_put_contents(self::file($channel), $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, string $c = 'prosit'): void { self::write('info', $m, $c); }
    public static function warn(string $m, string $c = 'prosit'): void { self::write('warn', $m, $c); }
    public static function error(string $m, string $c = 'prosit'): void { self::write('error', $m, $c); }
}
