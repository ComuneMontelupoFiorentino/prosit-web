<?php
/**
 * Web service sopralluoghi (ex /lizmap/attivita-sul-territorio/web-service-sopralluoghi/open_todos.php).
 *
 * Volutamente NON carica il core Prosit: il codice esterno (autenticazione, query,
 * chiamate a Twproject) vive in /usr/local/bin/prosit/TWPROJECT-PostgreSQL-API-sync-pg2twprj
 * e usa una propria costante PROSIT_ROOT, come prima con _prosit_path.php.
 * Per spostarlo: SetEnv PROSIT_SOPRALLUOGHI_ROOT /nuovo/percorso (deploy/apache/prosit.inc).
 */
declare(strict_types=1);

define('PROSIT_ROOT', getenv('PROSIT_SOPRALLUOGHI_ROOT') ?: ($_SERVER['PROSIT_SOPRALLUOGHI_ROOT'] ?? '/usr/local/bin/prosit/TWPROJECT-PostgreSQL-API-sync-pg2twprj'));
$target = PROSIT_ROOT . '/http/open_todos.php';
if (!is_file($target)) {
    error_log('[prosit-sopralluoghi] file mancante: ' . $target);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'status' => 503, 'error' => 'Servizio non configurato.']);
    exit;
}
require $target;
