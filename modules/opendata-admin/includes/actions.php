<?php

        switch ($action) {

            case 'list':
                echo json_encode(['ok' => true, 'services' => loadServices()]);
                break;

            case 'save': {
                $payload = json_decode((string) file_get_contents('php://input'), true);
                if (!is_array($payload) || !is_array($payload['record'] ?? null)) {
                    throw new RuntimeException('payload non valido (atteso {originalId, record})');
                }

                $originalId = $payload['originalId'] ?? null; // null = creazione di un nuovo servizio
                $record = $payload['record'];

                $issues = validateRecord($record);
                $blocking = array_values(array_filter($issues, fn($i) => $i['level'] === 'error'));
                if ($blocking) {
                    echo json_encode(['ok' => false, 'error' => 'Il record contiene errori bloccanti', 'issues' => $issues]);
                    break;
                }

                $services = loadServices();

                // indice del record ESISTENTE che stiamo modificando (se e' un update)
                $existingIndex = null;
                if ($originalId !== null) {
                    foreach ($services as $i => $s) {
                        if (($s['id'] ?? null) === $originalId) { $existingIndex = $i; break; }
                    }
                }

                // collisione: un ALTRO record ha gia' il nuovo id richiesto
                // (rilevante sia in creazione sia in rename durante una modifica)
                $collisionIndex = null;
                foreach ($services as $i => $s) {
                    if (($s['id'] ?? null) === $record['id'] && $i !== $existingIndex) { $collisionIndex = $i; break; }
                }

                if ($collisionIndex !== null) {
                    echo json_encode([
                        'ok' => false,
                        'error' => 'Esiste gia\' un servizio con id "' . $record['id'] . '": scegli un id diverso',
                        'issues' => $issues,
                    ]);
                    break;
                }

                if ($existingIndex !== null) {
                    $services[$existingIndex] = $record; // update (anche con rename dell'id)
                } else {
                    $services[] = $record; // create
                }

                saveServices($services);
                echo json_encode(['ok' => true, 'issues' => $issues]);
                break;
            }

            case 'delete': {
                $body = json_decode((string) file_get_contents('php://input'), true);
                $id = (string) ($body['id'] ?? '');
                if ($id === '') throw new RuntimeException('id mancante');

                $services = loadServices();
                $services = array_values(array_filter($services, fn($s) => ($s['id'] ?? null) !== $id));
                saveServices($services);
                echo json_encode(['ok' => true]);
                break;
            }

            case 'test': {
                $body = json_decode((string) file_get_contents('php://input'), true);
                $id = (string) ($body['id'] ?? '');

                $record = null;
                foreach (loadServices() as $s) {
                    if (($s['id'] ?? null) === $id) { $record = $s; break; }
                }
                if ($record === null) throw new RuntimeException('Servizio non trovato: ' . $id);

                $fullLog = [];
                $fullLog[] = nowStamp() . " ===== Avvio test verboso per servizio \"$id\" =====";

                $conformance = validateRecord($record);
                $fullLog[] = nowStamp() . '  conformita\': ' . count($conformance) . ' segnalazioni ('
                    . count(array_filter($conformance, fn($i) => $i['level'] === 'error')) . ' errori, '
                    . count(array_filter($conformance, fn($i) => $i['level'] === 'warning')) . ' warning)';
                foreach ($conformance as $issue) {
                    $fullLog[] = nowStamp() . '   [' . strtoupper($issue['level']) . '] ' . $issue['message'];
                }

                $datasetsResults = [];
                $rawByAlias = [];
                foreach (($record['datasets'] ?? []) as $ds) {
                    if (!is_array($ds)) continue;
                    $dsResult = testDataset($ds);
                    $fullLog = array_merge($fullLog, $dsResult['log']);
                    unset($dsResult['log']); // gia' incorporato nel fullLog, evitiamo duplicazione nel JSON strutturato

                    // il dato grezzo serve solo per la simulazione qui sotto:
                    // non va mai restituito al client (potrebbe essere grande)
                    if (array_key_exists('raw', $dsResult)) {
                        $rawByAlias[$dsResult['alias']] = $dsResult['raw'];
                        unset($dsResult['raw']);
                    }

                    $datasetsResults[] = $dsResult;
                }

                // Simulazione testata: titolo (statico, oppure "titolo #campo"
                // per le Schede), categoria (solo Schede) e "Ultimo
                // aggiornamento", con la STESSA logica usata realmente da
                // service-head.js (vedi doc in lib.php).
                $titleSimulation = simulateTitle($record, $rawByAlias);
                $categorySimulation = simulateCategory($record, $rawByAlias);
                $lastUpdateSimulation = simulateLastUpdate($record, $rawByAlias);

                $fullLog[] = '';
                $fullLog[] = nowStamp() . ' ===== Simulazione testata (titolo / categoria / ultimo aggiornamento) =====';
                $fullLog[] = nowStamp() . '  titolo: "' . ($titleSimulation['text'] !== '' ? $titleSimulation['text'] : '(vuoto)') . '"'
                    . ($titleSimulation['note'] ? '  ' . $titleSimulation['note'] : '');
                $fullLog[] = nowStamp() . '  categoria: "' . ($categorySimulation['text'] !== '' ? $categorySimulation['text'] : '(vuoto)') . '"'
                    . ($categorySimulation['note'] ? '  ' . $categorySimulation['note'] : '');
                $fullLog[] = nowStamp() . '  ultimo aggiornamento: "' . ($lastUpdateSimulation['text'] !== '' ? $lastUpdateSimulation['text'] : '(vuoto)') . '"'
                    . ($lastUpdateSimulation['note'] ? '  ' . $lastUpdateSimulation['note'] : '');

                $fullLog[] = nowStamp() . ' ===== Test completato =====';

                // Promemoria pratico: come usare QUESTI dataset (con i loro
                // alias reali) in una pagina frontend. Inserito nel log cosi'
                // e' sempre a portata di mano subito dopo aver verificato che
                // il dataset funziona, senza dover andare a cercare altrove.
                $aliases = [];
                foreach (($record['datasets'] ?? []) as $ds) {
                    if (is_array($ds) && !empty($ds['alias'])) $aliases[] = $ds['alias'];
                }

                $fullLog[] = '';
                $fullLog[] = '';
                $fullLog[] = '===== PROMEMORIA - come usare questi dati in una pagina frontend';
                $fullLog[] = '';
                $fullLog[] = '';
                $fullLog[] = '  // 1) registra i dataset di questo servizio (una volta per pagina)';
                $fullLog[] = "  await OpenData.loadService('" . $id . "');";
                $fullLog[] = '';
                if ($aliases) {
                    $fullLog[] = '  // 2) recupera i dati (filtri/ordinamento/limit dichiarati in';
                    $fullLog[] = '  //    services.json vengono gia\' applicati automaticamente)';
                    foreach ($aliases as $alias) {
                        $varName = preg_replace('/[^a-zA-Z0-9_]/', '_', $alias);
                        $fullLog[] = "  const $varName = await OpenData.getData('" . $alias . "');";
                    }
                    $fullLog[] = '';
                    $fullLog[] = '  // 3) usa i dati per compilare la pagina, es.:';
                    $fullLog[] = "  //    " . $varName . ".forEach(function (record) { /* renderizza qui */ });";
                    $fullLog[] = '';
                    $fullLog[] = '  // per forzare un nuovo fetch ignorando la cache del browser:';
                    $fullLog[] = "  await OpenData.refresh('" . $aliases[0] . "');";
                    $fullLog[] = '';
                    $fullLog[] = '  // per leggere tempo di risposta / provenienza (cache o rete)';
                    $fullLog[] = "  // dell'ultimo fetch riuscito:";
                    $fullLog[] = "  const info = OpenData.getInfo('" . $aliases[0] . "');";
                } else {
                    $fullLog[] = '  // (nessun dataset con alias valido: aggiungine uno per vedere qui';
                    $fullLog[] = '  //  gli esempi pronti all\'uso)';
                }
                $fullLog[] = '';
                $fullLog[] = '  Guida completa: vedi README.md, sezione "Usare OpenData in una pagina".';
                $fullLog[] = '';
                echo json_encode([
                    'ok' => true,
                    'conformance' => $conformance,
                    'datasets' => $datasetsResults,
                    'titleSimulation' => $titleSimulation,
                    'categorySimulation' => $categorySimulation,
                    'lastUpdateSimulation' => $lastUpdateSimulation,
                    'log' => $fullLog,
                ]);
                break;
            }

            case 'checkExport': {
                $body = json_decode((string) file_get_contents('php://input'), true);
                $mode = (is_array($body) ? ($body['mode'] ?? null) : null) ?? ($_GET['mode'] ?? 'debug');

                $services = loadServices();
                if ($mode === 'prod') {
                    $services = array_values(array_filter($services, fn($s) => ($s['dev'] ?? 'prod') === 'prod'));
                }

                $issues = [];
                foreach ($services as $s) {
                    $sid = (string) ($s['id'] ?? '(senza id)');
                    foreach (($s['datasets'] ?? []) as $ds) {
                        if (!is_array($ds) || empty($ds['fetch']) || !is_string($ds['fetch'])) continue;
                        if (strpos($ds['fetch'], TEST_HOST_MARKER) !== false) {
                            $issues[] = [
                                'service' => $sid,
                                'alias' => (string) ($ds['alias'] ?? '(senza alias)'),
                                'fetch' => $ds['fetch'],
                            ];
                        }
                    }
                }

                echo json_encode(['ok' => true, 'issues' => $issues]);
                break;
            }

            case 'export': {
                $mode = $_GET['mode'] ?? 'debug';
                $services = loadServices();

                if ($mode === 'prod') {
                    $filtered = [];
                    foreach ($services as $s) {
                        if (($s['dev'] ?? 'prod') !== 'prod') continue; // assenza campo "dev" = prod di default
                        unset($s['dev']);
                        $filtered[] = $s;
                    }
                    $services = $filtered;
                    $filename = 'services.prod.json';
                } else {
                    $filename = 'services.debug.json';
                }

                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'azione sconosciuta: ' . $action]);
        }