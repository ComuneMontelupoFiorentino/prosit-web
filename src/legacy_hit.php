<?php
/**
 * Registro dei vecchi indirizzi ancora usati (file ponte nella www di Lizmap).
 * Volutamente indipendente dal resto di Prosit: lo includono anche i ponti verso
 * codice esterno (sopralluoghi) che definisce costanti proprie.
 * Scrive in var/log/legacy-AAAA-MM.log; riepilogo con:  bin/prosit legacy:report
 */
declare(strict_types=1);

if (!function_exists('prosit_legacy_hit')) {
    function prosit_legacy_hit(string $old): void
    {
        static $done = false;          // una riga per richiesta (ponte + vecchia API)
        if ($done) {
            return;
        }
        $done = true;
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));
        $clean = static fn ($v, int $n) => substr(preg_replace('/[\x00-\x1f"]+/', ' ', (string) $v), 0, $n);
        $line = sprintf(
            "%s INFO  [ponte] vecchio=\"%s\" da=%s pagina=\"%s\" client=\"%s\"\n",
            $now->format('Y-m-d H:i:s'),
            $clean($old, 200),
            $clean($_SERVER['REMOTE_ADDR'] ?? '-', 45),
            $clean($_SERVER['HTTP_REFERER'] ?? '-', 300),
            $clean($_SERVER['HTTP_USER_AGENT'] ?? '-', 120)
        );
        @file_put_contents(dirname(__DIR__) . '/var/log/legacy-' . $now->format('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
