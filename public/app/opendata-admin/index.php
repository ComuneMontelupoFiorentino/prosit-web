<?php
/**
 * Pannello di amministrazione di services.json (open data).
 * Ex /lizmap/opendata/admin-service/services-admin.php: stessa interfaccia e stesse azioni,
 * ma la logica sta in modules/opendata-admin (fuori dalla parte pubblica), i dati in
 * var/data/opendata (non scaricabili dal browser) e l'accesso passa dal core Prosit
 * (sessione Lizmap + gruppo, controllo di origine sulle modifiche).
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use Prosit\Support\Config;

$cfg = (array) Config::get('opendata', []);
$base = rtrim((string) Config::get('paths.base_url', '/lizmap/prosit'), '/');

define('BASE_URL', $base . '/app/opendata-admin/');
define('ASSETS_URL', $base . '/assets/');
define('LIZMAP_ASSETS_URL', (preg_replace('#/prosit$#', '', $base) ?: '/lizmap') . '/assets/');
define('ROOT', PROSIT_ROOT . '/modules/opendata-admin');
define('SERVICES_PATH', (string) $cfg['services_path']);
define('BACKUP_DIR', (string) $cfg['backup_dir']);
define('REQUIRED_GROUP', (string) ($cfg['group'] ?? 'opnd'));
define('TEST_HOST_MARKER', (string) ($cfg['test_host_marker'] ?? ''));
define('PROD_RELAY_PREFIX', (string) ($cfg['prod_relay_prefix'] ?? ''));
define('PROD_HOST_BASE', (string) ($cfg['prod_host_base'] ?? ''));
define('TEST_HOST_BASE', (string) ($cfg['test_host_base'] ?? ''));

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require ROOT . '/includes/lib.php';

$session = checkSession();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (!$session['ok']) {
    if ($action !== '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Accesso negato: ' . $session['reason']]);
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    require ROOT . '/views/denied.php';
    exit;
}

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require ROOT . '/includes/actions.php';
        \Prosit\Support\Log::info($session['user']->login . ' OPENDATA ' . preg_replace('/[^a-z0-9_-]/i', '', (string) $action), 'audit');
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
require ROOT . '/views/panel.php';
