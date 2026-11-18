<?php
/** Report ticket edifici per stato (ex popup-ticket-data-report/rekla_bit.php). */
declare(strict_types=1);
require dirname(__DIR__, 3) . '/src/bootstrap.php';
require PROSIT_ROOT . '/modules/report-ticket/report.php';

use Prosit\Auth\Auth;
use Prosit\Db\Db;
use Prosit\Support\Config;

Auth::pageUser(\Prosit\Api\Controllers\MeController::appRule('report-ticket-bit'));
[$from, $to] = report_dates();
$r = report_bit(Db::connect((string) Config::get('report.service', 'segnalazioni_23')), $from, $to);
$data = $r['data'];
$righe = [
    ['accettati', 'UTC', 'Accettato'], ['sospesi', 'UTC', 'Sospeso'], ['convalidati', 'UTC', 'Convalidato'],
    ['assegnati', 'UTC', 'Assegnato'], ['presi', 'Manutentore', 'Preso in carico'], ['programmati', 'Manutentore', 'Programmato'],
    ['eseguiti', 'Manutentore', 'Eseguito'], ['chiusi', 'UTC', 'Completato'],
];
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="../../../assets/favicon/favicon.ico">
  <title>Segnalazioni per UTC</title>
  <link rel="stylesheet" href="../../assets/prosit.css">
  <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
  <?php include __DIR__ . '/_form.php'; ?>
  <div class="stat-row header"><div>Competenza</div><div>Stato</div><div>N. ticket</div></div>
  <?php foreach ($righe as [$k, $comp, $label]): ?>
  <div class="stat-row"><div><?= h($comp) ?></div><div class="stat-<?= h($k) ?>"><?= h($label) ?></div><div><?= (int) $data[$k] ?></div></div>
  <?php endforeach; ?>
  <div class="stat-row"><div> </div><div class="total"><b>TOTALE</b></div><div><b><?= (int) $r['total'] ?></b></div></div>
</body>
</html>
