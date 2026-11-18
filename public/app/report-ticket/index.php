<?php
/** Report segnalazioni per ufficio/manutentore (ex popup-ticket-data-report/rekla.php). */
declare(strict_types=1);
require dirname(__DIR__, 3) . '/src/bootstrap.php';
require PROSIT_ROOT . '/modules/report-ticket/report.php';

use Prosit\Auth\Auth;
use Prosit\Db\Db;
use Prosit\Support\Config;

$me = Auth::pageUser();
[$from, $to] = report_dates();
$r = report_rekla(Db::connect((string) Config::get('report.service', 'segnalazioni_23')), $me, $from, $to);
$data = $r['data'];
$adm = $r['admin'];
$righe = [
    // chiave, competenza, etichetta
    ['accettati', 'URP', 'Accettato'], ['sospesi', 'Ufficio', 'Sospeso'], ['convalidati', 'Ufficio', 'Convalidato'],
    ['assegnati', 'Ufficio', 'Assegnato'], ['presi', 'Manutentore', 'Preso in carico'], ['programmati', 'Manutentore', 'Programmato'],
    ['eseguiti', 'Manutentore', 'Eseguito'], ['chiusi', 'Ufficio', 'Completato'],
];
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="../../../assets/favicon/favicon.ico">
  <title>Segnalazioni per ufficio</title>
  <link rel="stylesheet" href="../../assets/prosit.css">
  <link rel="stylesheet" href="style.css?v=8">
</head>
<body>
  <?php include __DIR__ . '/_form.php'; ?>
  <div class="stat-row">
    <div class="main-number">Competenza</div>
    <div class="main-number">Stato</div>
    <div><span class="main-number">Tot.</span> <span class="complex">AP&amp;R</span> <span class="complex">Complex</span></div>
  </div>
  <?php foreach ($righe as [$k, $comp, $label]): ?>
  <div class="stat-row">
    <div><?= h($comp) ?></div>
    <div class="stat-<?= h($k) ?>"><?= h($label) ?></div>
    <div>
      <span class="main-number"><?= (int) $data[$k] ?></span>
      <span class="complex"><?= (int) $adm[$k] ?></span>
      <span class="complex"><?= isset($data[$k . '_complessi']) ? (int) $data[$k . '_complessi'] : '-' ?></span>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="stat-row">
    <div> </div>
    <div class="total"><b>TOTALE</b></div>
    <div><span class="main-number"><b><?= (int) $r['total'] ?></b></span></div>
  </div>
  <h2 class="errori">Errori: <?= $data['errori'] === null ? 'n.d.' : (int) $data['errori'] ?></h2>
</body>
</html>
