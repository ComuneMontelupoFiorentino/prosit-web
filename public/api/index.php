<?php
/**
 * Punto di ingresso unico dell'API Prosit: /lizmap/prosit/api/v1/...
 * (e, per compatibilita', la vecchia /lizmap/API/index.php/...).
 * Tutta la logica e' in src/: questo file non va modificato.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

Prosit\Api\Kernel::handle();
