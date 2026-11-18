<?php /* filtro date comune ai report (incluso, non raggiungibile da solo: vedi controllo in testa) */
if (!isset($from, $to)) { http_response_code(404); exit; } ?>
<div class="filter-box">
  <h1><?= h($titolo ?? 'TICKETS') ?></h1>
  <form method="get">
    <label for="dateFrom">Dal:</label>
    <input type="date" id="dateFrom" name="dateFrom" value="<?= h($from) ?>">
    <label for="dateTo">Al:</label>
    <input type="date" id="dateTo" name="dateTo" value="<?= h($to) ?>">
    <button type="submit" class="btn btn-primary">Filtra</button>
  </form>
</div>
