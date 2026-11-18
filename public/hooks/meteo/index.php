<?php
/**
 * Ricezione dati della stazione meteo (ex /lizmap/09-ambiente/get_data_station.php).
 *
 * Prima: chiunque poteva scrivere nel file di log dentro la web root, senza limiti.
 * Ora:  - accettata solo dall'IP della stazione (local/api_keys.php: meteo.trusted_ips),
 *         perche' la stazione non puo' inviare una chiave;
 *       - solo POST, al massimo meteo.max_bytes byte;
 *       - il file sta in var/data/meteo (fuori dalla web root), scritto con lock.
 * La risposta resta "Dati ricevuti!" come prima, per non cambiare nulla sulla stazione.
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use Prosit\Auth\Auth;
use Prosit\Http\ApiException;
use Prosit\Http\Request;
use Prosit\Support\Config;
use Prosit\Support\Log;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (Request::method() !== 'POST') {
        header('Allow: POST');
        throw new ApiException(405, 'Metodo non consentito');
    }
    Auth::requireKey((string) Config::get('meteo.key', 'meteo'));

    $max = (int) Config::get('meteo.max_bytes', 65536);
    $body = (string) file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($body) > $max) {
        throw new ApiException(413, 'Dati troppo grandi');
    }
    $file = (string) Config::get('meteo.log_file');
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cartella non creabile: $dir");
    }
    $entry = sprintf("[%s] - IP: %s\n%s\n--------------------\n", date('Y-m-d H:i:s'), Request::ip(), $body);
    if (@file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException("Scrittura non riuscita: $file");
    }
    echo 'Dati ricevuti!';
} catch (ApiException $e) {
    Log::warn('meteo ' . Request::ip() . ': ' . $e->getMessage(), 'hooks');
    http_response_code($e->status);
    echo $e->getMessage();
} catch (Throwable $e) {
    Log::error('meteo: ' . $e->getMessage(), 'hooks');
    http_response_code(500);
    echo 'Errore interno';
}
