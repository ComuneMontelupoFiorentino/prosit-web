<?php
/**
 * Ricezione rilievi dall'app QField/Android (ex /lizmap/ws-censimento-qfield/).
 * Autenticazione: token del client, verificato dal modulo (whitelist in qfield.json).
 * Configurazione: local/qfield.json (o /etc/prosit/qfield.json), fuori da public/; oppure variabile UPLOADER_CONFIG.
 */
declare(strict_types=1);

if (!getenv('UPLOADER_CONFIG') && empty($_SERVER['UPLOADER_CONFIG'])) {
    $etc = getenv('PROSIT_ETC') ?: (is_dir('/etc/prosit') ? '/etc/prosit' : dirname(__DIR__, 3) . '/local');
    putenv('UPLOADER_CONFIG=' . $etc . '/qfield.json');
}
require dirname(__DIR__, 3) . '/modules/qfield/index.php';
