<?php
/**
 * Conteggi dei report ticket (ex popup-ticket-data-report/scripts/controller_rekla*.php).
 * Stesse query di prima; cambiano solo sessione (Auth), connessione (Db) e validazione delle date.
 */
declare(strict_types=1);

use Prosit\Auth\Identity;
use Prosit\Support\Config;
use Prosit\Support\Log;

const REPORT_STATI = [
    'accettati' => 'accettata', 'convalidati' => 'convalidata', 'assegnati' => 'assegnata',
    'presi' => 'presa in carico', 'programmati' => 'programmata', 'eseguiti' => 'eseguita',
    'chiusi' => 'verificata e chiusa', 'sospesi' => 'sospesa',
];

/** Intervallo date dalla query string, validato (predefinito: dal 1 gennaio a oggi). */
function report_dates(): array
{
    $ok = static function ($v): ?string {
        $d = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    };
    $from = $ok($_GET['dateFrom'] ?? null) ?? date('Y-01-01');
    $to = $ok($_GET['dateTo'] ?? null) ?? date('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

function report_count(PDO $db, string $sql, array $params): int
{
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        Log::error('report: ' . $e->getMessage(), 'report');
        return 0;
    }
}

function report_relation_exists(PDO $db, string $rel): bool
{
    static $cache = [];
    if (!array_key_exists($rel, $cache)) {
        try {
            $st = $db->prepare('SELECT to_regclass(?) IS NOT NULL');
            $st->execute([$rel]);
            $cache[$rel] = (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            $cache[$rel] = false;
        }
    }
    return $cache[$rel];
}

/** Report segnalazioni per ufficio / manutentore (rekla). */
function report_rekla(PDO $db, Identity $me, string $from, string $to): array
{
    $manutentori = (array) Config::get('report.manutentori', []);
    $isManutentore = false;
    foreach ($manutentori as $g) {
        if ($me->inGroup($g)) {
            $isManutentore = true;
        }
    }
    // manutentori: filtro sul gruppo assegnatario (id); uffici: sul nome dell'ufficio
    $values = $isManutentore ? $me->groupIds : $me->groupNames;
    $officeSql = '';
    $officeParams = [];
    if ($values !== []) {
        $officeSql = ($isManutentore ? 'man_es' : 'office_manager') . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        $officeParams = array_values($values);
    } else {
        $officeSql = 'FALSE'; // nessun gruppo: nessun dato (prima mostrava tutto)
    }

    $data = [];
    $admin = [];
    foreach (REPORT_STATI as $k => $stato) {
        $sospeso = $stato === 'sospesa';             // le sospese non si filtrano per data
        $where = $sospeso ? [$officeSql] : ['seg_s_ac_d BETWEEN ? AND ?', $officeSql];
        $params = $sospeso ? $officeParams : array_merge([$from, $to], $officeParams);
        $base = 'SELECT count(*) FROM segn.segn_id WHERE ' . implode(' AND ', $where) . ' AND lower(stato_segn) = ?';
        $data[$k] = report_count($db, $base, array_merge($params, [$stato]));
        if ($k !== 'accettati') {
            $data[$k . '_complessi'] = report_count($db, $base . ' AND high_complexity = TRUE', array_merge($params, [$stato]));
        }
        $admin[$k] = report_count($db, $base . " AND seg_t_mat = 'Attività preventive'", array_merge($params, [$stato]));
    }
    // audit.errors_rekla non esiste nel database attuale (non c'era nemmeno nel dump della vecchia VM):
    // il vecchio report mostrava sempre 0 nascondendo l'errore. Se manca: "n.d." e nessun errore nel log.
    $data['errori'] = report_relation_exists($db, 'audit.errors_rekla')
        ? report_count($db, 'SELECT count(*) FROM audit.errors_rekla WHERE seg_s_ac_d BETWEEN ? AND ? AND ' . $officeSql,
            array_merge([$from, $to], $officeParams))
        : null;
    $total = 0;
    foreach (REPORT_STATI as $k => $_) {
        $total += $data[$k];
    }
    return ['data' => $data, 'admin' => $admin, 'total' => $total];
}

/** Report ticket edifici (BIT). */
function report_bit(PDO $db, string $from, string $to): array
{
    $codes = ['accettati' => 1, 'convalidati' => 2, 'assegnati' => 3, 'presi' => 4, 'programmati' => 5, 'eseguiti' => 6, 'chiusi' => 7, 'sospesi' => 8];
    $data = [];
    foreach ($codes as $k => $code) {
        $data[$k] = report_count($db, 'SELECT count(id) FROM buildings.tickets WHERE detail_status = ? AND entry_date BETWEEN ? AND ?', [$code, $from, $to]);
    }
    return ['data' => $data, 'total' => array_sum($data)];
}

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
