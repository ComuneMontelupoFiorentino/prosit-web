<?php
/**
 * Riceve la segnalazione dei file ponte .html (vecchie schede) prima del reindirizzamento.
 * Registra solo il vecchio indirizzo, la pagina di provenienza e il client; nessun dato utente.
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/legacy_hit.php';

$old = (string) ($_GET['p'] ?? '');
if ($old !== '' && preg_match('#^/[A-Za-z0-9._/-]{1,200}$#', $old)) {
    prosit_legacy_hit($old);
}
http_response_code(204);
header('Cache-Control: no-store');
