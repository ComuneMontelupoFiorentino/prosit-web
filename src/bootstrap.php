<?php
/**
 * Bootstrap comune a TUTTI i punti di ingresso PHP di Prosit
 * (API, pagine applicative, hook per macchine).
 *
 *   require dirname(__DIR__, N) . '/src/bootstrap.php';
 *
 * - carica le classi Prosit\* da src/
 * - carica la configurazione (config/defaults.php + PROSIT_ETC/prosit.php)
 * - imposta fuso orario, errori mai a schermo, log in var/log
 */
declare(strict_types=1);

if (defined('PROSIT_ROOT')) {
    return;
}
define('PROSIT_ROOT', dirname(__DIR__));
define('PROSIT_VERSION', '2.1.1');

/*
 * Cartella della configurazione locale (password, chiavi, impostazioni della macchina):
 *   1. variabile d'ambiente PROSIT_ETC, se impostata
 *   2. /etc/prosit, se esiste (installazione da root, deploy/install-root.sh)
 *   3. <prosit>/local  (installazione senza root, install.sh): fuori da public/, mai servita
 */
define('PROSIT_ETC', rtrim((string) (getenv('PROSIT_ETC') ?: (is_dir('/etc/prosit') ? '/etc/prosit' : dirname(__DIR__) . '/local')), '/'));

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Prosit\\', 7) !== 0) {
        return;
    }
    $file = PROSIT_ROOT . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

Prosit\Support\Config::load();
date_default_timezone_set((string) Prosit\Support\Config::get('timezone', 'Europe/Rome'));
ini_set('error_log', Prosit\Support\Log::file('php'));

// Intestazioni di sicurezza per tutte le risposte PHP (valgono anche senza configurazione Apache)
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

// Pagine HTML: un errore imprevisto (es. database non raggiungibile) diventa una pagina
// di errore leggibile, mai il testo dell'eccezione. L'API ha la sua gestione (JSON).
if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $e): void {
        Prosit\Support\Log::error(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'php');
        $db = $e instanceof PDOException;
        Prosit\Http\Response::htmlError(
            $db ? 503 : 500,
            $db ? 'Database non raggiungibile' : 'Errore interno',
            $db ? 'Il database non risponde in questo momento. Riprovare tra poco; se il problema continua avvisare il SIT.'
                : 'Si e\' verificato un errore imprevisto. Riprovare; se il problema continua avvisare il SIT.'
        );
    });
}
