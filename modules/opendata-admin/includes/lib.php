<?php

declare(strict_types=1);

/* ===========================================================================
 * CONTROLLO SESSIONE / AUTORIZZAZIONE  eseguito PRIMA di qualunque azione
 * o rendering, incluse le chiamate AJAX (non solo il caricamento pagina).
 * ===========================================================================
 * Chiamata server-to-server (curl): nessun problema di CORS. I cookie della
 * richiesta in arrivo vengono inoltrati a SessionCheckAPI.php, perche' e'
 * l'unico modo per quell'endpoint di sapere QUALE sessione verificare (senza
 * inoltro, curl farebbe una richiesta "anonima", senza sessione allegata).
 *
 * Fail-closed: qualunque anomalia (rete, timeout, risposta malformata,
 * gruppo mancante) viene trattata come NON autenticato. Un pannello che
 * legge/scrive services.json non deve mai aprirsi "per errore" solo perche'
 * il servizio di verifica sessione e' irraggiungibile.
 */
/**
 * Verifica sessione e gruppo tramite il core Prosit (prima: curl verso SessionCheckAPI.php).
 * Fail-closed: qualunque anomalia = accesso negato.
 */
function checkSession(): array
{
    try {
        $me = \Prosit\Auth\Auth::current();
        if (!$me->isSessionUser()) {
            return ['ok' => false, 'reason' => 'Sessione Lizmap assente o scaduta'];
        }
        if (!$me->inGroup(REQUIRED_GROUP)) {
            return ['ok' => false, 'reason' => 'Utente autenticato ma privo del gruppo "' . REQUIRED_GROUP . '" richiesto per questo pannello'];
        }
        if (\Prosit\Http\Request::isUnsafe()) {
            \Prosit\Http\Request::assertSameOrigin();
        }
        return ['ok' => true, 'user' => $me];
    } catch (\Throwable $e) {
        return ['ok' => false, 'reason' => $e->getMessage()];
    }
}



function loadServices(): array
{
    if (!is_file(SERVICES_PATH)) return [];

    $json = file_get_contents(SERVICES_PATH);
    if ($json === false) return [];

    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (JsonException $e) {
        return [];
    }
}

function saveServices(array $services): void
{
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0775, true);
    }

    if (is_file(SERVICES_PATH)) {
        @copy(SERVICES_PATH, BACKUP_DIR . '/services_' . date('Ymd_His') . '.json');
    }

    $json = json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Impossibile serializzare i servizi in JSON');
    }

    $fp = fopen(SERVICES_PATH, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Impossibile aprire services.json in scrittura');
    }

    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function findFirstExisting(array $relativeCandidates): ?string
{
    foreach ($relativeCandidates as $rel) {
        if (is_file(ROOT . '/' . $rel)) return $rel;
    }
    return null;
}

/* ===========================================================================
 * VALIDATOR: conformita' di un record servizio
 * =========================================================================== */

const KNOWN_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'startsWith', 'endsWith', 'in', 'notIn', 'isNull', 'isNotNull'];

// converte un testo libero nello slug corrispondente: minuscolo, accenti
// rimossi, tutto cio' che non e' [a-z0-9] diventa un trattino singolo.
// Stessa identica logica implementata anche in JS lato form, per tenere
// id e titolo sincronizzati mentre si digita.
function slugify(string $text): string
{
    $text = trim($text);

    if (function_exists('transliterator_transliterate')) {
        $translit = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        if ($translit !== false) $text = $translit;
    } elseif (function_exists('iconv')) {
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($translit !== false) $text = $translit;
    }

    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim((string) $text, '-');
}

function validateRecord(array $record): array
{
    $issues = [];

    $id = (string) ($record['id'] ?? '');
    if ($id === '') {
        $issues[] = ['level' => 'error', 'message' => 'Campo "id" mancante o vuoto'];
    } elseif (!preg_match('/^[a-z0-9_-]+$/', $id)) {
        $issues[] = ['level' => 'error', 'message' => 'Il campo "id" contiene caratteri non ammessi dal router (solo a-z 0-9 _ -): "' . $id . '"'];
    }

    // controllo: l'id dovrebbe corrispondere allo slug del titolo. Non e'
    // bloccante (a volte serve mantenere un id stabile anche dopo aver
    // corretto il titolo, per non rompere link gia' pubblicati), ma va
    // segnalato chiaramente: se non e' intenzionale, e' un errore comune.
    $expectedSlug = slugify((string) ($record['title'] ?? ''));
    if ($id !== '' && $expectedSlug !== '' && $id !== $expectedSlug) {
        $issues[] = ['level' => 'warning', 'message' => 'Il campo "id" ("' . $id . '") non corrisponde allo slug del titolo (atteso "' . $expectedSlug . '"): verifica che sia intenzionale'];
    }

    if (empty($record['title'])) {
        $issues[] = ['level' => 'warning', 'message' => 'Campo "title" mancante: la testata mostrera\' un titolo vuoto'];
    }

    if (isset($record['dev']) && !in_array($record['dev'], ['debug', 'prod'], true)) {
        $issues[] = ['level' => 'warning', 'message' => 'Campo "dev" con valore inatteso: "' . $record['dev'] . '" (atteso "debug" o "prod")'];
    }

    if (isset($record['tags']) && !is_array($record['tags'])) {
        $issues[] = ['level' => 'warning', 'message' => 'Campo "tags" non e\' un array'];
    }

    if (isset($record['alert'])) {
        if (!is_array($record['alert'])) {
            $issues[] = ['level' => 'warning', 'message' => 'Campo "alert" non e\' un array'];
        } else {
            foreach ($record['alert'] as $i => $a) {
                if (!is_array($a) || empty($a['text'])) {
                    $issues[] = ['level' => 'warning', 'message' => 'alert[' . $i . '] senza campo "text": non verra\' mostrato'];
                }
            }
        }
    }

    if (isset($record['descriptiion']) && !is_array($record['descriptiion'])) {
        $issues[] = ['level' => 'warning', 'message' => 'Campo "descriptiion" non e\' un array'];
    }

    $type = $record['type'] ?? 'service'; // assente = "service", per compatibilita' con record creati prima di questo campo

    $datasets = $record['datasets'] ?? [];
    if (!is_array($datasets)) {
        $issues[] = ['level' => 'error', 'message' => 'Campo "datasets" non e\' un array'];
        $datasets = [];
    }

    $seenAliases = [];
    $infoGroupPositions = []; // posizione (int) => [alias, alias, ...] che la usano

    foreach ($datasets as $i => $ds) {
        $label = 'datasets[' . $i . ']';

        if (!is_array($ds)) {
            $issues[] = ['level' => 'error', 'message' => $label . ' non e\' un oggetto valido'];
            continue;
        }

        $alias = (string) ($ds['alias'] ?? '');
        if ($alias === '') {
            $issues[] = ['level' => 'error', 'message' => $label . ': campo "alias" mancante  OpenData.getData() non potra\' mai raggiungerlo'];
        } elseif (isset($seenAliases[$alias])) {
            $issues[] = ['level' => 'error', 'message' => $label . ': alias "' . $alias . '" duplicato nello stesso servizio (il secondo sovrascrive il primo nel Registry)'];
        } else {
            $seenAliases[$alias] = true;
        }

        if (empty($ds['fetch'])) {
            $issues[] = ['level' => 'error', 'message' => $label . ' (' . $alias . '): campo "fetch" mancante'];
        }

        if (empty($ds['nameDataset']) || empty($ds['url'])) {
            $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): "nameDataset"/"url" mancanti  il link nella lista dataset in testata sara\' incompleto'];
        }

        if (isset($ds['recordsPath']) && (!is_string($ds['recordsPath']) || strpos($ds['recordsPath'], '*') === false)) {
            $issues[] = ['level' => 'error', 'message' => $label . ' (' . $alias . '): "recordsPath" deve essere una stringa con un segmento jolly "*"'];
        }

        if (isset($ds['filters'])) {
            if (!is_array($ds['filters'])) {
                $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): "filters" non e\' un array'];
            } else {
                foreach ($ds['filters'] as $fi => $f) {
                    if (!is_array($f) || empty($f['field']) || empty($f['operator'])) {
                        $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): filtro[' . $fi . '] senza "field"/"operator"'];
                    } elseif (!in_array($f['operator'], KNOWN_OPERATORS, true)) {
                        $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): operatore sconosciuto "' . $f['operator'] . '" nel filtro[' . $fi . ']'];
                    }
                }
            }
        }

        if (isset($ds['sort'])) {
            if (!is_array($ds['sort']) || empty($ds['sort']['field'])) {
                $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): "sort" malformato (attesi "field" e "direction")'];
            } elseif (isset($ds['sort']['direction']) && !in_array($ds['sort']['direction'], ['asc', 'desc'], true)) {
                $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): "sort.direction" deve essere "asc" o "desc"'];
            }
        }

        if (isset($ds['limit']) && (!is_int($ds['limit']) || $ds['limit'] < 1)) {
            $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): "limit" deve essere un intero positivo'];
        }

        // --- is_infoGroup / infoGroupPosition (solo per servizi type: "sheet") ---
        if (isset($ds['is_infoGroup']) && !is_bool($ds['is_infoGroup'])) {
            $issues[] = ['level' => 'error', 'message' => $label . ' (' . $alias . '): "is_infoGroup" deve essere booleano'];
        }

        $isInfoGroup = !empty($ds['is_infoGroup']);

        if ($isInfoGroup && $type !== 'sheet') {
            $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): "is_infoGroup" e\' pensato solo per servizi di tipo Scheda; su un Servizio il frontend lo ignora'];
        }

        if ($isInfoGroup) {
            if (!array_key_exists('infoGroupPosition', $ds)) {
                $issues[] = ['level' => 'error', 'message' => $label . ' (' . $alias . '): "is_infoGroup" attivo ma "infoGroupPosition" mancante'];
            } elseif (!is_int($ds['infoGroupPosition']) || $ds['infoGroupPosition'] < 1) {
                $issues[] = ['level' => 'error', 'message' => $label . ' (' . $alias . '): "infoGroupPosition" deve essere un numero intero >= 1'];
            } else {
                $infoGroupPositions[$ds['infoGroupPosition']][] = $alias;
            }
        } elseif (array_key_exists('infoGroupPosition', $ds)) {
            $issues[] = ['level' => 'warning', 'message' => $label . ' (' . $alias . '): "infoGroupPosition" presente ma "is_infoGroup" non e\' true: verra\' ignorato'];
        }
    }

    // --- coerenza complessiva delle infoGroupPosition tra tutti i dataset ---
    // devono essere un numero univoco da 1 al totale dei dataset con
    // is_infoGroup=true (nessun buco, nessun duplicato): serve al frontend
    // per ordinare automaticamente le sezioni informative della Scheda.
    if ($infoGroupPositions) {
        foreach ($infoGroupPositions as $pos => $aliasesAtPos) {
            if (count($aliasesAtPos) > 1) {
                $issues[] = ['level' => 'error', 'message' => 'infoGroupPosition duplicata (' . $pos . '), usata da: ' . implode(', ', $aliasesAtPos)];
            }
        }

        $totalInfoGroups = array_sum(array_map('count', $infoGroupPositions));
        $flatPositions = [];
        foreach ($infoGroupPositions as $pos => $aliasesAtPos) {
            foreach ($aliasesAtPos as $a) { $flatPositions[] = $pos; }
        }
        sort($flatPositions);
        $expectedPositions = range(1, $totalInfoGroups);

        if ($flatPositions !== $expectedPositions) {
            $issues[] = [
                'level' => 'error',
                'message' => 'Le "infoGroupPosition" dei dataset con is_infoGroup=true devono coprire, senza buchi ne\' duplicati, i numeri da 1 a '
                    . $totalInfoGroups . ' (trovate: ' . implode(', ', $flatPositions) . ')',
            ];
        }
    }

    // --- controlli su "lastUpdate" ------------------------------------------
    if (isset($record['lastUpdate'])) {
        $lu = $record['lastUpdate'];

        if (!is_array($lu)) {
            $issues[] = ['level' => 'error', 'message' => 'Campo "lastUpdate" non e\' un oggetto valido'];
        } else {
            if (empty($lu['alias'])) {
                $issues[] = ['level' => 'error', 'message' => 'lastUpdate: campo "alias" mancante'];
            } elseif (!isset($seenAliases[$lu['alias']])) {
                $issues[] = ['level' => 'error', 'message' => 'lastUpdate: alias "' . $lu['alias'] . '" non corrisponde a nessun dataset dichiarato in questo servizio'];
            }

            $hasField = !empty($lu['field']);
            $hasGrouped = !empty($lu['recordsPath']) && !empty($lu['dateField']);

            if (!$hasField && !$hasGrouped) {
                $issues[] = ['level' => 'error', 'message' => 'lastUpdate: serve almeno "field" (data diretta) oppure "recordsPath"+"dateField" (calcolo su record raggruppati)'];
            }

            if (isset($lu['recordsPath']) && (!is_string($lu['recordsPath']) || strpos($lu['recordsPath'], '*') === false)) {
                $issues[] = ['level' => 'error', 'message' => 'lastUpdate: "recordsPath" deve essere una stringa con un segmento jolly "*" (come per i dataset)'];
            }

            if (isset($lu['recordsPath']) && empty($lu['dateField'])) {
                $issues[] = ['level' => 'warning', 'message' => 'lastUpdate: "recordsPath" presente ma "dateField" mancante  il calcolo sui record raggruppati non produrra\' nulla'];
            }
        }
    }

    // --- controlli su "category" (solo per Schede) --------------------------
    // Popola <span id="categoryOBJ"> in testata (vedi service-head.js,
    // P.renderCategory). Stesso pattern di lastUpdate: alias di un dataset
    // gia' dichiarato + nome campo, ma senza fallback recordsPath (la
    // categoria e' sempre un singolo valore diretto sul record).
    if (isset($record['category'])) {
        $cat = $record['category'];

        if ($type !== 'sheet') {
            $issues[] = ['level' => 'warning', 'message' => 'Campo "category" e\' pensato solo per servizi di tipo Scheda; su un Servizio il frontend lo ignora'];
        }

        if (!is_array($cat)) {
            $issues[] = ['level' => 'error', 'message' => 'Campo "category" non e\' un oggetto valido'];
        } else {
            if (empty($cat['alias'])) {
                $issues[] = ['level' => 'error', 'message' => 'category: campo "alias" mancante'];
            } elseif (!isset($seenAliases[$cat['alias']])) {
                $issues[] = ['level' => 'error', 'message' => 'category: alias "' . $cat['alias'] . '" non corrisponde a nessun dataset dichiarato in questo servizio'];
            }

            if (empty($cat['field'])) {
                $issues[] = ['level' => 'error', 'message' => 'category: campo "field" mancante'];
            }
        }
    }

    // --- controlli su "type" e coerenza con "link" -------------------------
    if (isset($record['type']) && !in_array($record['type'], ['service', 'sheet'], true)) {
        $issues[] = ['level' => 'warning', 'message' => 'Campo "type" con valore inatteso: "' . $record['type'] . '" (atteso "service" o "sheet")'];
    }

    $expectedLinkParam = $type === 'sheet' ? 'sheet' : 'service';
    if (!empty($record['link']) && strpos($record['link'], '?' . $expectedLinkParam . '=') !== 0) {
        $issues[] = ['level' => 'warning', 'message' => 'Il campo "link" non sembra coerente con "type" (atteso che inizi con "?' . $expectedLinkParam . '="): verifica se e\' stato modificato manualmente fuori dal pannello.'];
    }

    // --- controlli specifici per le "sheet" ---------------------------------
    // (una sheet mostra il titolo di UN record specifico, non quello statico)
    if ($type === 'sheet') {
        if (empty($record['titleField'])) {
            $issues[] = ['level' => 'warning', 'message' => 'Tipo "sheet" senza "titleField": il titolo in testata rester� quello statico del servizio invece di quello letto dal record (obj_id).'];
        }
        if (!empty($record['titleDataset']) && !isset($seenAliases[$record['titleDataset']])) {
            $issues[] = ['level' => 'warning', 'message' => '"titleDataset" ("' . $record['titleDataset'] . '") non corrisponde ad alcun alias dichiarato tra i dataset di questo servizio.'];
        }
    }

    return $issues;
}

/* ===========================================================================
 * TESTER: raggiungibilita' reale + record campione + log verboso
 * =========================================================================== */

function nowStamp(): string
{
    $dt = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
    return $dt ? $dt->format('H:i:s.v') : date('H:i:s');
}

function resolveFetchUrl(string $fetch): string
{
    // Mapping SOLO per il test server-side: un fetch salvato con l'host di
    // produzione viene rimappato sull'host di test prima della curl.
    if (strpos($fetch, PROD_HOST_BASE) === 0) {
        $fetch = TEST_HOST_BASE . substr($fetch, strlen(PROD_HOST_BASE));
    }

    if (preg_match('#^https?://#i', $fetch)) return $fetch;

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/' . ltrim(BASE_URL, '/') . ltrim($fetch, '/');
}

// esegue la richiesta HTTP catturando anche header di risposta e timing dettagliato
function fetchRemoteVerbose(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,   // include gli header nella risposta grezza
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json, application/geo+json, */*'],
        CURLOPT_USERAGENT      => 'comune-in-chiaro-admin-tester/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'info' => $info, 'error' => $err !== '' ? $err : 'nessuna risposta'];
    }

    $headerSize = $info['header_size'] ?? 0;
    $rawHeaders = trim(substr($response, 0, $headerSize));
    $body = substr($response, $headerSize);
    $code = (int) ($info['http_code'] ?? 0);

    if ($code === 0 || $code >= 400) {
        return ['ok' => false, 'info' => $info, 'headers' => $rawHeaders, 'body' => $body, 'error' => 'HTTP ' . $code];
    }

    return ['ok' => true, 'info' => $info, 'headers' => $rawHeaders, 'body' => $body];
}

function getPathValue($obj, string $path)
{
    if ($path === '') return $obj;
    $value = $obj;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return null;
        $value = $value[$part];
    }
    return $value;
}

// estrae UN record campione + etichetta del formato riconosciuto
function extractSampleRecord($data, array $ds): array
{
    if (!empty($ds['recordsPath']) && is_string($ds['recordsPath']) && strpos($ds['recordsPath'], '*') !== false) {
        $parts = array_map('trim', explode('*', $ds['recordsPath'], 2));
        $beforePath = trim($parts[0] ?? '', '.');
        $afterPath  = trim($parts[1] ?? '', '.');

        $groupsContainer = getPathValue($data, $beforePath);
        if (is_array($groupsContainer)) {
            foreach ($groupsContainer as $groupKey => $group) {
                $records = $afterPath !== '' ? getPathValue($group, $afterPath) : $group;
                if (is_array($records) && !empty($records)) {
                    $first = reset($records);
                    if (!empty($ds['groupKeyField']) && is_array($first)) {
                        $first[$ds['groupKeyField']] = $groupKey;
                    }
                    return ['format' => 'raggruppato (recordsPath: ' . $ds['recordsPath'] . ')', 'sample' => $first];
                }
            }
        }
        return ['format' => 'raggruppato (recordsPath: ' . $ds['recordsPath'] . ')', 'sample' => null];
    }

    if (is_array($data) && ($data['type'] ?? null) === 'FeatureCollection') {
        return ['format' => 'GeoJSON FeatureCollection', 'sample' => !empty($data['features']) ? $data['features'][0] : null];
    }

    if (is_array($data) && array_is_list($data)) {
        return ['format' => 'Array JSON semplice', 'sample' => !empty($data) ? $data[0] : null];
    }

    return ['format' => 'non riconosciuto', 'sample' => null];
}

// testa un singolo dataset producendo sia un risultato strutturato sia un log verboso testuale
function testDataset(array $ds): array
{
    $alias = $ds['alias'] ?? '(senza alias)';
    $log = [];
    $log[] = nowStamp() . "  [$alias] avvio test dataset";

    if (empty($ds['fetch']) || !is_string($ds['fetch'])) {
        $log[] = nowStamp() . "  [$alias] ERRORE: nessun campo \"fetch\" valido da testare";
        return ['alias' => $alias, 'reachability' => ['ok' => false, 'error' => 'nessun campo "fetch" valido'], 'log' => $log];
    }

    $log[] = nowStamp() . "  [$alias] fetch dichiarato: {$ds['fetch']}";
    $url = resolveFetchUrl($ds['fetch']);
    $log[] = nowStamp() . "  [$alias] URL risolto: $url";
    $log[] = nowStamp() . "  [$alias] invio richiesta HTTP GET...";

    $result = fetchRemoteVerbose($url);
    $info = $result['info'] ?? [];

    $toMs = fn($sec) => isset($info[$sec]) ? round($info[$sec] * 1000, 1) : null;
    $timing = [
        'dnsMs' => $toMs('namelookup_time'),
        'connectMs' => $toMs('connect_time'),
        'ttfbMs' => $toMs('starttransfer_time'),
        'totalMs' => $toMs('total_time'),
    ];

    if (!$result['ok']) {
        $log[] = nowStamp() . "  [$alias] FALLITO dopo {$timing['totalMs']}ms: {$result['error']}";
        return [
            'alias' => $alias,
            'fetchDeclared' => $ds['fetch'],
            'fetchResolved' => $url,
            'reachability' => ['ok' => false, 'status' => (int) ($info['http_code'] ?? 0), 'error' => $result['error'], 'timing' => $timing],
            'log' => $log,
        ];
    }

    $log[] = nowStamp() . "  [$alias] risposta HTTP {$info['http_code']}  dns {$timing['dnsMs']}ms, connessione {$timing['connectMs']}ms, primo byte {$timing['ttfbMs']}ms, totale {$timing['totalMs']}ms";
    $log[] = nowStamp() . "  [$alias] content-type: " . ($info['content_type'] ?? 'n/d') . ", dimensione: " . strlen($result['body']) . " byte";
    if (!empty($result['headers'])) {
        foreach (explode("\r\n", $result['headers']) as $hLine) {
            if (trim($hLine) !== '') $log[] = nowStamp() . "  [$alias]   header: " . trim($hLine);
        }
    }

    $log[] = nowStamp() . "  [$alias] parsing JSON...";
    $decoded = json_decode($result['body'], true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $log[] = nowStamp() . "  [$alias] ERRORE: risposta non e' JSON valido (" . json_last_error_msg() . ")";
        return [
            'alias' => $alias,
            'fetchDeclared' => $ds['fetch'],
            'fetchResolved' => $url,
            'reachability' => ['ok' => true, 'status' => (int) $info['http_code'], 'timing' => $timing, 'sizeBytes' => strlen($result['body'])],
            'sampleError' => 'risposta non JSON valido: ' . json_last_error_msg(),
            'log' => $log,
        ];
    }

    $extracted = extractSampleRecord($decoded, $ds);
    $log[] = nowStamp() . "  [$alias] formato riconosciuto: {$extracted['format']}";

    if ($extracted['sample'] === null) {
        $log[] = nowStamp() . "  [$alias] nessun record trovato con la configurazione dichiarata";
    } else {
        $log[] = nowStamp() . "  [$alias] record campione estratto con successo (" . count($extracted['sample']) . ' campi)';
    }

    return [
        'alias' => $alias,
        'fetchDeclared' => $ds['fetch'],
        'fetchResolved' => $url,
        'reachability' => ['ok' => true, 'status' => (int) $info['http_code'], 'timing' => $timing, 'sizeBytes' => strlen($result['body'])],
        'formatDetected' => $extracted['format'],
        'sample' => $extracted['sample'],
        'sampleError' => $extracted['sample'] === null ? 'nessun record trovato con la configurazione dichiarata (recordsPath/formato dati)' : null,
        'log' => $log,
        // dato grezzo completo, usato SOLO per la simulazione titolo/ultimo
        // aggiornamento (case 'test' in actions.php): non va mai restituito
        // cosi' com'e' al client, viene rimosso prima del json_encode finale.
        'raw' => $decoded,
    ];
}

/* ===========================================================================
 * SIMULAZIONE TESTATA (titolo + categoria + ultimo aggiornamento)
 * ===========================================================================
 * Riproduce in PHP, sul dato campione gia' scaricato dal tester, la STESSA
 * logica che service-head.js applica davvero in pagina:
 *
 *  - Titolo:
 *      "service"  sempre il titolo statico (record["title"]).
 *      "sheet"    titolo statico + " #" + valore di titleField letto dal
 *                 record di titleDataset. Se manca la config, il dataset non
 *                 e' raggiungibile, o il campo non c'e' nel record  fallback
 *                 al titolo statico (stesso comportamento del client).
 *
 *  - Categoria (solo "sheet"): valore di category.field letto dal record di
 *      category.alias, scritto in <span id="categoryOBJ">. Nessun fallback
 *      testuale: se manca la config o il campo, il testo resta vuoto (stesso
 *      comportamento di P.renderCategory in service-head.js).
 *
 *  - Ultimo aggiornamento: stessa priorita' di computeLastUpdateIso() in
 *      service-head.js  prima "field" (diretto sull'oggetto, o il piu'
 *      recente tra un array di record), poi fallback "recordsPath"+"dateField".
 *
 * LIMITE NOTO: il tester non ha un obj_id di pagina reale, quindi per i
 * dataset filtrati "source: query, parameter: obj_id" (tipico delle Schede)
 * non puo' applicare quel filtro: usa il PRIMO record del dataset cosi' come
 * arriva da CKAN, esattamente come fa gia' extractSampleRecord() per il
 * record campione. Il testo restituito include sempre una nota che lo rende
 * esplicito.
 * =========================================================================== */

const MONTHS_SHORT_IT = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

// mirror di ymd() in service-head.js: estrae {y,mo,d,iso} da una stringa
// contenente una data YYYY-M-D, o null se non valida.
function phpYmd($value): ?array
{
    if ($value === null) return null;
    if (!preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', (string) $value, $m)) return null;
    $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
    return ['y' => $y, 'mo' => $mo, 'd' => $d, 'iso' => sprintf('%04d-%02d-%02d', $y, $mo, $d)];
}

// mirror di fmtDate() in service-head.js
function phpFmtDate($value): string
{
    $p = phpYmd($value);
    if (!$p) return '';
    return sprintf('%02d %s %d', $p['d'], MONTHS_SHORT_IT[$p['mo'] - 1], $p['y']);
}

// mirror di collectDatesFromPath() in service-head.js (un solo segmento jolly "*")
function phpCollectDatesFromPath($raw, string $recordsPath, string $dateField): array
{
    $parts = explode('.', $recordsPath);
    $starIndex = array_search('*', $parts, true);
    if ($starIndex === false) return [];

    $beforePath = implode('.', array_slice($parts, 0, $starIndex));
    $afterPath = implode('.', array_slice($parts, $starIndex + 1));

    $container = getPathValue($raw, $beforePath);
    $dates = [];

    if (is_array($container)) {
        foreach ($container as $group) {
            $records = $afterPath !== '' ? getPathValue($group, $afterPath) : $group;
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (is_array($r) && array_key_exists($dateField, $r) && $r[$dateField] !== null) {
                        $dates[] = $r[$dateField];
                    }
                }
            }
        }
    }

    return $dates;
}

// mirror di computeLastUpdateIso() in service-head.js
function computeLastUpdateIsoPhp($raw, array $cfg): ?string
{
    if (!empty($cfg['field'])) {
        if (is_array($raw) && array_is_list($raw)) {
            $latest = '';
            foreach ($raw as $item) {
                if (!is_array($item)) continue;
                $p = phpYmd($item[$cfg['field']] ?? null);
                if ($p && $p['iso'] > $latest) $latest = $p['iso'];
            }
            if ($latest !== '') return $latest;
        } else {
            $p = phpYmd(getPathValue($raw, $cfg['field']));
            if ($p) return $p['iso'];
        }
    }

    if (!empty($cfg['recordsPath']) && !empty($cfg['dateField'])) {
        $latest = '';
        foreach (phpCollectDatesFromPath($raw, $cfg['recordsPath'], $cfg['dateField']) as $v) {
            $p = phpYmd($v);
            if ($p && $p['iso'] > $latest) $latest = $p['iso'];
        }
        if ($latest !== '') return $latest;
    }

    return null;
}

// simula il titolo che verrebbe mostrato in testata (vedi doc del blocco sopra)
function simulateTitle(array $record, array $rawByAlias): array
{
    $staticTitle = (string) ($record['title'] ?? '');
    $type = $record['type'] ?? 'service';

    if ($type !== 'sheet') {
        return ['text' => $staticTitle, 'note' => null];
    }

    $alias = (string) ($record['titleDataset'] ?? '');
    $field = (string) ($record['titleField'] ?? '');

    if ($alias === '' || $field === '') {
        return ['text' => $staticTitle, 'note' => 'Scheda senza "titleDataset"/"titleField" configurati: verrebbe mostrato solo il titolo statico.'];
    }

    if (!array_key_exists($alias, $rawByAlias)) {
        return ['text' => $staticTitle, 'note' => 'Dataset "' . $alias . '" non raggiungibile durante il test: fallback al titolo statico.'];
    }

    $raw = $rawByAlias[$alias];
    $rec = (is_array($raw) && array_is_list($raw)) ? ($raw[0] ?? null) : $raw;
    $value = is_array($rec) ? ($rec[$field] ?? null) : null;

    if ($value === null || $value === '') {
        return [
            'text' => $staticTitle,
            'note' => 'Campo "' . $field . '" assente nel record di esempio (primo record del dataset, non filtrato per obj_id): fallback al titolo statico.',
        ];
    }

    return [
        'text' => $staticTitle . ' #' . $value,
        'note' => 'Simulato sul primo record del dataset "' . $alias . '" (non filtrato per obj_id: la pagina reale userebbe il record specifico della query string).',
    ];
}

// simula il testo di "Ultimo aggiornamento" che verrebbe mostrato in testata
function simulateLastUpdate(array $record, array $rawByAlias): array
{
    $cfg = $record['lastUpdate'] ?? null;
    if (!is_array($cfg) || empty($cfg['alias'])) {
        return ['text' => '', 'note' => 'Nessuna configurazione "lastUpdate" per questo servizio.'];
    }

    $alias = (string) $cfg['alias'];
    if (!array_key_exists($alias, $rawByAlias)) {
        return ['text' => '', 'note' => 'Dataset "' . $alias . '" non raggiungibile durante il test.'];
    }

    $iso = computeLastUpdateIsoPhp($rawByAlias[$alias], $cfg);
    if ($iso === null) {
        return [
            'text' => '',
            'note' => 'Nessuna data valida trovata (campo diretto assente/non valido, e nessun fallback recordsPath+dateField configurato o valido).',
        ];
    }

    return ['text' => 'Ultimo aggiornamento ' . phpFmtDate($iso), 'note' => null];
}

// simula il testo di "categoria" che verrebbe mostrato in <span id="categoryOBJ">
// (vedi P.renderCategory in service-head.js). Attivo solo per "sheet": su
// "service" la feature non esiste lato client, quindi qui viene solo segnalato
// nella nota, senza calcolare nulla.
function simulateCategory(array $record, array $rawByAlias): array
{
    $type = $record['type'] ?? 'service';
    if ($type !== 'sheet') {
        return ['text' => '', 'note' => 'Campo "category" e\' attivo solo per servizi di tipo Scheda.'];
    }

    $cfg = $record['category'] ?? null;
    if (!is_array($cfg) || empty($cfg['alias']) || empty($cfg['field'])) {
        return ['text' => '', 'note' => 'Nessuna configurazione "category" (alias+field) per questo servizio.'];
    }

    $alias = (string) $cfg['alias'];
    if (!array_key_exists($alias, $rawByAlias)) {
        return ['text' => '', 'note' => 'Dataset "' . $alias . '" non raggiungibile durante il test.'];
    }

    $raw = $rawByAlias[$alias];
    $rec = (is_array($raw) && array_is_list($raw)) ? ($raw[0] ?? null) : $raw;
    $value = is_array($rec) ? ($rec[$cfg['field']] ?? null) : null;

    if ($value === null || $value === '') {
        return [
            'text' => '',
            'note' => 'Campo "' . $cfg['field'] . '" assente nel record di esempio (primo record del dataset, non filtrato per obj_id).',
        ];
    }

    return [
        'text' => (string) $value,
        'note' => 'Simulato sul primo record del dataset "' . $alias . '" (non filtrato per obj_id: la pagina reale userebbe il record specifico della query string).',
    ];
}