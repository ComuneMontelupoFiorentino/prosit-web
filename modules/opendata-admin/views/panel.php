<?php

// jQuery e Bootstrap 2 di Lizmap (gli stessi della mappa): un solo framework per tutto Prosit
$jsLizmap = [
    LIZMAP_ASSETS_URL . 'js/jquery/jquery-3.5.1.min.js',
    LIZMAP_ASSETS_URL . 'js/jquery/jquery-migrate-3.3.1.min.js',
    LIZMAP_ASSETS_URL . 'js/bootstrap.min.js',
];

$initialServicesJson = json_encode(loadServices(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// Config lato server passata al JS client tramite un piccolo blob JSON
// (stesso pattern gia' usato per i dati iniziali dei servizi), invece di
// interpolare direttamente i tag del linguaggio server dentro il file .js esterno.
$adminConfigJson = json_encode([
    'testHostMarker' => TEST_HOST_MARKER,
    'prodRelayPrefix' => PROD_RELAY_PREFIX,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="<?php echo htmlspecialchars(LIZMAP_ASSETS_URL . 'favicon/favicon.ico', ENT_QUOTES); ?>">
<title>Amministrazione services.json</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_URL . 'prosit.css', ENT_QUOTES); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL . 'assets/services-admin.css', ENT_QUOTES); ?>">
</head>
<body>

<header class="app-header py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h1 class="h5 mb-0"><i class="icon-hdd me-2"></i>Amministrazione services.json</h1>
    <div class="d-flex align-items-center gap-2">
      <span class="badge text-bg-light text-dark"><i class="icon-user me-1"></i>Sessione verificata (gruppo <?php echo htmlspecialchars(REQUIRED_GROUP, ENT_QUOTES); ?>)</span>
      <span class="badge badge-warn"><i class="icon-warning-sign me-1"></i>Pagina interna  non esporre pubblicamente senza autenticazione</span>
    </div>
  </div>
</header>

<main class="container pb-5">

  <div class="d-flex gap-2 mb-3 flex-wrap">
    <button type="button" class="btn btn-primary" id="btn-new"><i class="icon-plus-sign me-1"></i>Nuovo servizio</button>
    <button type="button" class="btn btn-outline-secondary" id="btn-export-prod"><i class="icon-download-alt me-1"></i>Esporta per produzione</button>
    <button type="button" class="btn btn-outline-secondary" id="btn-export-debug"><i class="icon-wrench me-1"></i>Esporta per debug</button>
  </div>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>ID</th><th>Titolo</th><th>Dev</th><th>Dataset</th><th class="text-end">Azioni</th></tr>
        </thead>
        <tbody id="table-body"></tbody>
      </table>
    </div>
  </div>
</main>

<!-- ================= MODALE EDITOR ================= -->
<div class="modal modal-lg hide fade" id="editor-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editor-title">Servizio</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Chiudi">&times;</button>
      </div>
      <div class="modal-body">
        <div id="editor-issues"></div>

        <ul class="nav nav-tabs mb-3" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-tab="form" type="button">Form guidato</button></li>
          <li class="nav-item"><button class="nav-link" data-tab="json" type="button">JSON completo</button></li>
        </ul>

        <div class="tab-panel-content tab-pane-scroll">
          <div class="tab-panel active" id="tab-form">
            <div class="row g-3 mb-2">
              <div class="col-md-4">
                <label class="form-label">
                  Tipo
                  <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Servizio: pagina generica (views/service/). Scheda: pagina di dettaglio di UN record, sempre aperta con ?sheet=...&obj_id=... (views/sheet/)."></i>
                </label>
                <select class="form-select" id="f-type">
                  <option value="service">Servizio</option>
                  <option value="sheet">Scheda</option>
                </select>
              </div>
              <div class="col-md-8">
                <label class="form-label">
                  ID
                  <i class="icon-question-sign help-icon" data-toggle="tooltip" data-placement="top" title="Identificativo univoco usato negli URL. Generato automaticamente dal titolo (slug in minuscolo); modificalo solo se sai perche'."></i>
                </label>
                <div class="input-group">
                  <input type="text" class="form-control" id="f-id">
                  <button type="button" class="btn btn-outline-secondary" id="btn-regen-id" title="Rigenera l'id dal titolo"><i class="icon-refresh"></i></button>
                </div>
                <div class="form-text" id="f-id-hint"></div>
              </div>
            </div>
            <div class="row g-3 mb-2">
              <div class="col-md-12">
                <label class="form-label">
                  Titolo
                  <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Nome mostrato in testata e nell'elenco servizi. Digitandolo, l'ID sopra si aggiorna da solo finche' non lo modifichi a mano. Per le Schede e' il titolo di fallback, usato solo se 'Titolo dal record' sotto non e' configurato o non trova dati."></i>
                </label>
                <input type="text" class="form-control" id="f-title">
              </div>
            </div>
            <div class="row g-3 mb-2">
              <div class="col-md-6">
                <label class="form-label">
                  Icona
                  <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Nome dell'icona nello sprite SVG del progetto, es. it-calendar, it-map. Vuoto = nessuna icona."></i>
                </label>
                <input type="text" class="form-control" id="f-icona" placeholder="es. it-calendar">
              </div>
              <div class="col-md-6">
                <label class="form-label">
                  Link
                  <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Generato automaticamente da Tipo + ID + opzioni sotto. Non editabile qui: per una Scheda l'obj_id del record specifico va aggiunto a runtime da chi genera il link (es. un elenco o una mappa), non e' parte di questo template."></i>
                </label>
                <input type="text" class="form-control" id="f-link" disabled>
              </div>
            </div>
            <div class="row g-3 mb-3 align-items-end">
              <div class="col-md-4 form-check ms-1">
                <input type="checkbox" class="form-check-input" id="f-opt-map">
                <label class="form-check-label" for="f-opt-map">map <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Se selezionato, aggiunge &map=true al link: la pagina mostra la mappa."></i></label>
              </div>
              <div class="col-md-4 form-check ms-1">
                <input type="checkbox" class="form-check-input" id="f-opt-scan">
                <label class="form-check-label" for="f-opt-scan">qr-scan <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Se selezionato, aggiunge &scan=true al link: abilita la scansione QR nella pagina."></i></label>
              </div>
              <div class="col-md-4 form-check ms-1">
                <input type="checkbox" class="form-check-input" id="f-opt-breadcrumb">
                <label class="form-check-label" for="f-opt-breadcrumb">breadcrumb <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Se selezionato, aggiunge &breadCrumb=true al link: mostra il percorso di navigazione nella pagina."></i></label>
              </div>
            </div>

            <fieldset class="level-1 border rounded p-3 mb-3" id="sheet-title-fields" style="display:none;">
              <legend class="float-none w-auto px-2 h6 mb-0">
                Titolo dal record (solo per Scheda)
                <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Per una Scheda, il titolo in testata viene letto dal record specifico (obj_id), non dal campo 'Titolo' sopra. Serve che il dataset indicato abbia gia' un filtro con source:query su obj_id (vedi README)."></i>
              </legend>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Alias dataset <i class="icon-question-sign help-icon" data-toggle="tooltip" title="L'alias del dataset (tra quelli dichiarati sotto) da cui leggere il record per il titolo."></i></label>
                  <input type="text" class="form-control" id="f-titleDataset" placeholder="es. plan">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Campo titolo <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Nome del campo, dentro il record trovato, da usare come titolo (es. 'location')."></i></label>
                  <input type="text" class="form-control" id="f-titleField" placeholder="es. location">
                </div>
              </div>
              <div class="form-text mt-1">Se una delle due e' vuota, o il record non viene trovato, si usa il "Titolo" statico sopra come fallback.</div>
            </fieldset>

            <fieldset class="level-1 border rounded p-3 mb-3" id="category-fields" style="display:none;">
              <legend class="float-none w-auto px-2 h6 mb-0">
                Categoria (solo per Scheda)
                <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Popola lo &lt;span id=&quot;categoryOBJ&quot;&gt; in testata (es. la specie botanica di un albero, la tipologia di un intervento, ecc.). Serve che il dataset indicato abbia gia' un filtro con source:query su obj_id (stesso meccanismo di 'Titolo dal record')."></i>
              </legend>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Alias dataset <i class="icon-question-sign help-icon" data-toggle="tooltip" title="L'alias del dataset (tra quelli dichiarati sotto) da cui leggere il record per la categoria."></i></label>
                  <select class="form-select" id="f-category-alias"><option value="">-- nessuno --</option></select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Campo <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Nome del campo, dentro il record trovato, da mostrare come categoria (es. 'specie')."></i></label>
                  <input type="text" class="form-control" id="f-category-field" placeholder="es. specie">
                </div>
              </div>
              <div class="form-text mt-1">Se uno dei due campi e' vuoto, "category" non viene salvato: lo span in testata resta vuoto.</div>
            </fieldset>

            <div class="mb-2">
              <label class="form-label">
                Descrizione breve
                <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Sottotitolo mostrato sotto il titolo in testata. Una frase, non il testo esteso (quello va in 'Descrizione estesa' piu' sotto)."></i>
              </label>
              <input type="text" class="form-control" id="f-desc">
            </div>
            <div class="row g-3 mb-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label">
                  Dev
                  <i class="icon-question-sign help-icon" data-toggle="tooltip" title="'prod' = visibile nell'export di produzione. 'debug' = escluso dall'export prod, visibile solo nell'export debug."></i>
                </label>
                <select class="form-select" id="f-dev"><option value="prod">prod</option><option value="debug">debug</option></select>
              </div>
              <div class="col-md-4 form-check ms-1">
                <input type="checkbox" class="form-check-input" id="f-new">
                <label class="form-check-label" for="f-new">new <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Mostra un badge 'novita'' accanto al servizio nell'elenco pubblico."></i></label>
              </div>
              <div class="col-md-4 form-check ms-1">
                <input type="checkbox" class="form-check-input" id="f-allowEmbed">
                <label class="form-check-label" for="f-allowEmbed">allowEmbed <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Consente di incorporare questo servizio in un iframe su un dominio pubblico esterno."></i></label>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label">
                Tags
                <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Parole chiave separate da virgola, usate per filtrare/cercare i servizi nell'elenco pubblico."></i>
              </label>
              <input type="text" class="form-control" id="f-tags" placeholder="Manutenzioni, Interventi, Verde Pubblico">
            </div>

            <fieldset class="level-1 border rounded p-3 mb-3">
              <legend class="float-none w-auto px-2 h6 mb-0">
                Alert
                <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Banner mostrato in testata. Puoi averne piu' di uno."></i>
              </legend>
              <div id="alert-container"></div>
              <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-alert"><i class="icon-plus me-1"></i>Aggiungi alert</button>
            </fieldset>

            <fieldset class="level-1 border rounded p-3 mb-3">
              <legend class="float-none w-auto px-2 h6 mb-0">
                Descrizione estesa
                <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Testo mostrato nel modale info (icona accanto al titolo). Aggiungi un paragrafo per volta; il tag e' normalmente 'p', ma puoi cambiarlo per titoli/elenchi."></i>
              </legend>
              <div id="desc-container"></div>
              <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-desc"><i class="icon-plus me-1"></i>Aggiungi paragrafo</button>
            </fieldset>

            <fieldset class="level-1 border rounded p-3">
              <legend class="float-none w-auto px-2 h6 mb-0">
                Dataset
                <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Le fonti dati (CKAN o relay) che questo servizio espone tramite OpenData.getData(alias)."></i>
              </legend>
              <div id="datasets-container"></div>
              <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-dataset"><i class="icon-plus me-1"></i>Aggiungi dataset</button>
            </fieldset>

            <fieldset class="level-1 border rounded p-3 mt-3">
              <legend class="float-none w-auto px-2 h6 mb-0">
                Ultimo aggiornamento
                <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Popola #last_update in testata. Indica sempre l'alias del dataset e il campo che contiene la data. 'Campo data diretto': valore letto direttamente dal dato scaricato (per un Servizio tipicamente una proprieta' in cima al file, es. generated_at; per una Scheda, un campo del record stesso). recordsPath+dateField: fallback per calcolare la piu' recente tra piu' record raggruppati (stesso pattern di Dataset)."></i>
              </legend>
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label">Dataset (alias)</label>
                  <select class="form-select" id="f-lu-alias"><option value="">-- nessuno --</option></select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Campo data diretto</label>
                  <input type="text" class="form-control" id="f-lu-field" placeholder="es. generated_at">
                </div>
                <div class="col-md-3">
                  <label class="form-label">recordsPath (fallback)</label>
                  <input type="text" class="form-control" id="f-lu-recordsPath" placeholder="es. giorni.*.interventi">
                </div>
                <div class="col-md-3">
                  <label class="form-label">dateField (fallback)</label>
                  <input type="text" class="form-control" id="f-lu-dateField" placeholder="es. date-add-plan">
                </div>
              </div>
              <div class="form-text mt-1">"-- nessuno --" = questo servizio non mostra "Ultimo aggiornamento" in testata. Se scegli un dataset, indica anche almeno un campo (diretto, oppure recordsPath+dateField): senza nessuno dei due il salvataggio segnala un errore di conformita'.</div>
              <div class="form-text mt-1" id="lu-sheet-hint" style="display:none;">
                <i class="icon-info-sign me-1"></i>Per una Scheda: il dataset scelto qui deve avere gia' un filtro <code>obj_id</code> da query string dichiarato nella sezione <strong>Dataset</strong> sopra (stesso meccanismo del "Titolo dal record"). Con quel filtro attivo, <code>OpenData.getData()</code> restituisce automaticamente solo il record della pagina corrente: "Campo data diretto" legge allora il campo data direttamente da quel record.
              </div>
            </fieldset>
          </div>

          <div class="tab-panel" id="tab-json" style="display:none;">
            <label class="form-label">JSON completo del record (modificabile: "Applica" lo riporta nel form guidato)</label>
            <textarea class="form-control font-monospace" id="raw-json" rows="20"></textarea>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="btn-apply-json">Applica al form guidato</button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Annulla</button>
        <button type="button" class="btn btn-primary" id="btn-save"><i class="icon-ok me-1"></i>Salva</button>
      </div>
    </div>
  </div>
</div>

<!-- ================= MODALE TEST ================= -->
<div class="modal modal-xl hide fade" id="test-modal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="test-title">Test servizio</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Chiudi">&times;</button>
      </div>
      <div class="modal-body">
        <div id="test-header-sim" class="mb-3"></div>
        <div id="test-conformance" class="mb-3"></div>
        <div id="test-datasets" class="mb-3"></div>
        <h6>Log completo</h6>
        <pre class="console-log" id="test-log"></pre>
      </div>
    </div>
  </div>
</div>

<script id="initial-data" type="application/json"><?php echo $initialServicesJson; ?></script>
<?php foreach ($jsLizmap as $js): ?>
<script src="<?php echo htmlspecialchars($js, ENT_QUOTES); ?>"></script>
<?php endforeach; ?>
<script id="admin-config" type="application/json"><?php echo $adminConfigJson; ?></script>
<script src="<?php echo htmlspecialchars(BASE_URL . 'assets/services-admin.js?v=1', ENT_QUOTES); ?>"></script>

</body>
</html>