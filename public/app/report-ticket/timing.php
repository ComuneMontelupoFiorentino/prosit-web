<?php
/**
 * Riepilogo di un ticket edifici: aggiornamenti e allegati (ex popup-ticket-data-report/report_timing.php).
 * Prima era senza autenticazione e puntava a un get_db.php inesistente.
 *   timing.php?id=123
 */
declare(strict_types=1);
require dirname(__DIR__, 3) . '/src/bootstrap.php';
require PROSIT_ROOT . '/modules/report-ticket/report.php';

use Prosit\Auth\Auth;
use Prosit\Db\Db;
use Prosit\Http\Response;
use Prosit\Support\Config;

Auth::pageUser();
$id = (string) ($_GET['id'] ?? '');
if (!ctype_digit($id)) {
    Response::htmlError(400, 'Parametro non valido', 'Indicare l\'identificativo numerico del ticket (?id=...).');
}
$db = Db::connect((string) Config::get('report.service', 'segnalazioni_23'));
$st = $db->prepare('SELECT 1 FROM buildings.list_tickets_v WHERE id = :id LIMIT 1');
$st->execute([':id' => $id]);
if ($st->fetchColumn() === false) {
    Response::htmlError(404, 'Ticket non trovato', 'Nessun dato per il ticket ' . $id . '.');
}
$st = $db->prepare('SELECT action_description, action_user, action_date, action_note FROM buildings.reporting_ticket
                     WHERE id_ticket = :id ORDER BY action_date DESC, id DESC');
$st->execute([':id' => $id]);
$updates = $st->fetchAll();
$st = $db->prepare('SELECT DISTINCT doc_name, doc_url FROM buildings.attached_docs_ticket
                     WHERE id_ticket = :id AND doc_name IS NOT NULL AND doc_url IS NOT NULL');
$st->execute([':id' => $id]);
$docs = $st->fetchAll();
$safeUrl = static fn(string $u): string => preg_match('#^https?://#i', $u) ? $u : '#';
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="../../../assets/favicon/favicon.ico">
  <title>Ticket #<?= h($id) ?></title>
  <link rel="stylesheet" href="../../assets/prosit.css">
  <link rel="stylesheet" href="style.css?v=7">
</head>
<body class="timing">
  <h5>Aggiornamenti</h5>
  <table class="timing-table">
    <thead><tr><th>Azione</th><th>Utente</th><th>Data</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($updates as $u): ?>
      <tr>
        <td><?= h($u['action_description']) ?></td>
        <td><?= h($u['action_user']) ?></td>
        <td><?= $u['action_date'] ? h(date('d/m/Y H:i', strtotime((string) $u['action_date']))) : '' ?></td>
        <td><?= nl2br(h($u['action_note'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$updates): ?><tr><td colspan="4"><em>Nessun aggiornamento</em></td></tr><?php endif; ?>
    </tbody>
  </table>
  <h5>Allegati</h5>
  <?php if ($docs): ?>
    <ul class="timing-docs">
      <?php foreach ($docs as $d): ?>
        <li><a href="<?= h($safeUrl((string) $d['doc_url'])) ?>" target="_blank" rel="noopener"><?= h($d['doc_name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p><em>Nessun allegato</em></p>
  <?php endif; ?>
</body>
</html>
