<?php
/**
 * Pagina iniziale dell'app: Lizmap configura Apache con "DirectoryIndex index.php",
 * quindi senza questo file /lizmap/prosit/app/calendario-verde/ risponderebbe 403.
 * Controlla anche, lato server, chi puo' aprire l'app (config 'apps' > 'calendario-verde'),
 * la stessa regola che decide se il pulsante compare nella mappa.
 */
declare(strict_types=1);
require dirname(__DIR__, 3) . '/src/bootstrap.php';

use Prosit\Api\Controllers\MeController;
use Prosit\Auth\Auth;

Auth::pageUser(MeController::appRule('calendario-verde'));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile(__DIR__ . '/index.html');
