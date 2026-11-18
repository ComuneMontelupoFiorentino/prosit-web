(function () {
  'use strict';

  var services = JSON.parse(document.getElementById('initial-data').textContent || '[]');
  var currentRecord = null;
  var editingOriginalId = null; // null = stiamo creando un nuovo servizio

  function $(id) { return document.getElementById(id); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  // stessa identica logica di slugify() lato PHP: tenuta sincronizzata a mano,
  // cosi' il controllo "id = slug(titolo)" da' lo stesso risultato in preview
  // (JS, mentre si digita) e in validazione reale (PHP, al salvataggio)
  function slugify(text) {
    return (text || '')
      .toString()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // rimuove accenti
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  // Config lato server (host di test, prefisso relay di produzione), passata
  // dal template views/panel.php tramite un piccolo blob JSON invece di
  // interpolare <?php ?> direttamente in questo file esterno.
  var ADMIN_CONFIG = (function () {
    var el = document.getElementById('admin-config');
    try { return el ? JSON.parse(el.textContent || '{}') : {}; }
    catch (e) { console.error('[services-admin] admin-config non leggibile:', e); return {}; }
  })();

  var DEFAULT_RELAY_PREFIX = ADMIN_CONFIG.prodRelayPrefix || 'api/opendata_relay.php?u=';

  // contatore globale per generare id/indici univoci in elementi creati
  // dinamicamente (righe alert/descrizione/filtro, blocchi dataset): non deve
  // corrispondere a posizioni in un array, serve solo a non collidere mai
  var uidCounter = 0;
  function uid() { return 'u' + (uidCounter++); }

  var ALERT_TYPES = [
    { value: 'danger', label: 'Errore' },
    { value: 'success', label: 'Successo' },
    { value: 'warning', label: 'Attenzione' }
  ];

  var DESC_TAGS = ['p', 'h3', 'h4', 'strong', 'em', 'b', 'i', 'span', 'li'];

  var KNOWN_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'startsWith', 'endsWith', 'in', 'notIn', 'isNull', 'isNotNull'];

  /* ---------------------------------------------------------------------
   * Alert: righe {typeAlert, text}
   * ------------------------------------------------------------------ */
  function renderAlertRow(a, idx) {
    a = a || {};
    var options = ALERT_TYPES.map(function (t) {
      return '<option value="' + t.value + '" ' + (a.typeAlert === t.value ? 'selected' : '') + '>' + t.label + '</option>';
    }).join('');
    return ''
      + '<div class="row g-2 mb-2 align-items-start alert-row" data-idx="' + idx + '">'
      + '  <div class="col-md-3"><select class="form-select alert-type">' + options + '</select></div>'
      + '  <div class="col-md-8"><textarea class="form-control alert-text" rows="2" placeholder="Testo del messaggio">' + esc(a.text || '') + '</textarea></div>'
      + '  <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-alert title="Rimuovi"><i class="icon-remove"></i></button></div>'
      + '</div>';
  }

  function renderAlertContainer(items) {
    $('alert-container').innerHTML = (items || []).map(function (a) { return renderAlertRow(a, uid()); }).join('');
    initTooltips($('alert-container'));
  }

  /* ---------------------------------------------------------------------
   * Descrizione estesa: righe {tag: testo}, di default {p: testo}
   * ------------------------------------------------------------------ */
  function renderDescRow(item, idx) {
    item = item || { p: '' };
    var tag = Object.keys(item)[0] || 'p';
    var text = item[tag] || '';
    var options = DESC_TAGS.map(function (t) {
      return '<option value="' + t + '" ' + (tag === t ? 'selected' : '') + '>' + t + '</option>';
    }).join('');
    return ''
      + '<div class="row g-2 mb-2 align-items-start desc-row" data-idx="' + idx + '">'
      + '  <div class="col-md-2"><select class="form-select desc-tag">' + options + '</select></div>'
      + '  <div class="col-md-9"><textarea class="form-control desc-text" rows="2" placeholder="Testo del paragrafo">' + esc(text) + '</textarea></div>'
      + '  <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-desc title="Rimuovi"><i class="icon-remove"></i></button></div>'
      + '</div>';
  }

  function renderDescContainer(items) {
    $('desc-container').innerHTML = (items || []).map(function (item) { return renderDescRow(item, uid()); }).join('');
    initTooltips($('desc-container'));
  }

  /* ---------------------------------------------------------------------
   * Filtri: righe {field, operator, value} oppure {field, operator, source:'query', parameter}
   * ------------------------------------------------------------------ */
  function renderFilterRow(f, idx) {
    f = f || { field: '', operator: '=' };
    var mode = f.source === 'query' ? 'query' : 'value';
    var opNoValue = f.operator === 'isNull' || f.operator === 'isNotNull';
    var opOptions = KNOWN_OPERATORS.map(function (op) {
      return '<option value="' + op + '" ' + (f.operator === op ? 'selected' : '') + '>' + op + '</option>';
    }).join('');
    var valueDisplay = Array.isArray(f.value) ? f.value.join(', ') : (f.value != null ? f.value : '');

    var valueCell;
    if (opNoValue) {
      valueCell = '<span class="text-muted fst-italic small">nessun valore richiesto</span>';
    } else if (mode === 'query') {
      valueCell = '<input type="text" class="form-control form-control-sm filter-parameter" placeholder="nome parametro (es. obj_id)" value="' + esc(f.parameter || '') + '">';
    } else {
      valueCell = '<input type="text" class="form-control form-control-sm filter-value" placeholder="valore (o lista separata da virgola)" value="' + esc(valueDisplay) + '">';
    }

    return ''
      + '<div class="row g-2 mb-2 align-items-start filter-row" data-idx="' + idx + '">'
      + '  <div class="col-md-3"><input type="text" class="form-control form-control-sm filter-field" placeholder="campo" value="' + esc(f.field || '') + '"></div>'
      + '  <div class="col-md-2"><select class="form-select form-select-sm filter-operator">' + opOptions + '</select></div>'
      + '  <div class="col-md-3"><select class="form-select form-select-sm filter-mode" ' + (opNoValue ? 'disabled' : '') + '>'
      + '    <option value="value" ' + (mode === 'value' ? 'selected' : '') + '>Valore fisso</option>'
      + '    <option value="query" ' + (mode === 'query' ? 'selected' : '') + '>Da query string</option>'
      + '  </select></div>'
      + '  <div class="col-md-3 filter-value-cell">' + valueCell + '</div>'
      + '  <div class="col-md-1"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-filter title="Rimuovi"><i class="icon-remove"></i></button></div>'
      + '</div>';
  }

  // legge una filter-row del DOM e ricostruisce l'oggetto filtro corrispondente
  function readFilterRowToObject(row) {
    var field = row.querySelector('.filter-field').value.trim();
    var operator = row.querySelector('.filter-operator').value;
    var f = { field: field, operator: operator };

    if (operator !== 'isNull' && operator !== 'isNotNull') {
      var mode = row.querySelector('.filter-mode').value;
      if (mode === 'query') {
        f.source = 'query';
        f.parameter = (row.querySelector('.filter-parameter') || {}).value ? row.querySelector('.filter-parameter').value.trim() : '';
      } else {
        var raw = (row.querySelector('.filter-value') || {}).value ? row.querySelector('.filter-value').value.trim() : '';
        if (operator === 'in' || operator === 'notIn') {
          f.value = raw.split(',').map(function (v) { return v.trim(); }).filter(Boolean);
        } else {
          f.value = raw;
        }
      }
    }
    return f;
  }

  // ridisegna una SOLA riga filtro dopo un cambio di operatore/modalita',
  // senza toccare il resto del form (cosi' non si perdono altre modifiche
  // in corso altrove nel form)
  function refreshFilterRow(row) {
    var idx = row.dataset.idx;
    var f = readFilterRowToObject(row);
    var wrapper = document.createElement('div');
    wrapper.innerHTML = renderFilterRow(f, idx);
    row.replaceWith(wrapper.firstElementChild);
  }

  // scompone un "fetch" gia' salvato in { url, useRelay, relayPrefix }, cosi'
  // il form mostra sempre l'URL reale e una semplice casella di spunta,
  // MAI la stringa con l'incapsulamento del relay da leggere a mano.
  function decomposeFetch(fetchValue) {
    var m = /^(.*opendata_relay\.php\?u=)(.+)$/.exec(fetchValue || '');
    if (m) {
      try { return { url: decodeURIComponent(m[2]), useRelay: true, relayPrefix: m[1] }; }
      catch (e) { /* URL malformato dopo "u=": fallback sotto, mostralo cosi' com'e' */ }
    }
    return { url: fetchValue || '', useRelay: false, relayPrefix: DEFAULT_RELAY_PREFIX };
  }

  function composeFetch(url, useRelay, relayPrefix) {
    if (!url) return '';
    return useRelay ? (relayPrefix || DEFAULT_RELAY_PREFIX) + encodeURIComponent(url) : url;
  }

  // inizializza (o re-inizializza) i tooltip Bootstrap sugli elementi
  // presenti ORA nel DOM: va richiamata dopo ogni re-render dinamico
  // (form popolato, blocchi dataset ridisegnati), altrimenti i tooltip sui
  // nuovi elementi non funzionerebbero.
  function initTooltips(container) {
    var scope = container || document;
    // tooltip di Bootstrap 2 (plugin jQuery di Lizmap)
    var $els = jQuery(scope).find('[data-toggle="tooltip"]');
    $els.tooltip('destroy');
    $els.tooltip({ trigger: 'hover focus', container: 'body' });
  }

  // modali di Bootstrap 2 (plugin jQuery di Lizmap)
  function bs2Modal(node) { return { show: function () { jQuery(node).modal('show'); }, hide: function () { jQuery(node).modal('hide'); } }; }
  var editorModal = bs2Modal($('editor-modal'));
  var testModal = bs2Modal($('test-modal'));

  async function api(action, body) {
    var opts = { method: body ? 'POST' : 'GET' };
    if (body) opts.body = JSON.stringify(body);
    var res = await fetch('?action=' + encodeURIComponent(action), opts);
    return res.json();
  }

  /* ---------------------------------------------------------------------
   * Tabella
   * ------------------------------------------------------------------ */
  function renderTable() {
    var tbody = $('table-body');
    tbody.innerHTML = services.map(function (s, i) {
      var dev = s.dev || 'prod';
      var devBadge = dev === 'prod' ? 'text-bg-success' : 'text-bg-warning';
      return ''
        + '<tr>'
        + '<td><code>' + esc(s.id) + '</code></td>'
        + '<td>' + esc(s.title) + '</td>'
        + '<td><span class="badge ' + devBadge + '">' + dev + '</span></td>'
        + '<td>' + ((s.datasets || []).length) + '</td>'
        + '<td class="text-end">'
        + '  <div class="btn-group btn-group-sm">'
        + '    <button class="btn btn-outline-primary" data-act="edit" data-i="' + i + '"><i class="icon-pencil"></i> Modifica</button>'
        + '    <button class="btn btn-outline-success" data-act="test" data-i="' + i + '"><i class="icon-play"></i> Test</button>'
        + '    <button class="btn btn-outline-danger" data-act="delete" data-i="' + i + '"><i class="icon-trash"></i></button>'
        + '  </div>'
        + '</td>'
        + '</tr>';
    }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">Nessun servizio presente.</td></tr>';
  }

  $('table-body').addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var i = parseInt(btn.dataset.i, 10);
    var record = services[i];

    if (btn.dataset.act === 'edit') openEditor(record);
    if (btn.dataset.act === 'test') openTest(record);
    if (btn.dataset.act === 'delete') deleteRecord(record);
  });

  async function deleteRecord(record) {
    if (!confirm('Eliminare definitivamente il servizio "' + record.id + '"?')) return;
    var res = await api('delete', { id: record.id });
    if (!res.ok) { alert('Errore: ' + res.error); return; }
    services = services.filter(function (s) { return s.id !== record.id; });
    renderTable();
  }

  /* ---------------------------------------------------------------------
   * Editor: form guidato <-> JSON grezzo
   * ------------------------------------------------------------------ */
  function emptyRecord() {
    return { id: '', title: '', type: 'service', icona: '', desc: '', link: '', new: false, allowEmbed: false, dev: 'prod', tags: [], alert: [], descriptiion: [], datasets: [], titleDataset: '', titleField: '' };
  }

  // legge dal link esistente SOLO le 3 opzioni booleane (map/scan/breadCrumb):
  // "type" e "id" sono ormai campi propri del record, non vanno piu' dedotti
  // dal link. Se il link non segue il pattern atteso (es. modificato a mano),
  // le opzioni vengono semplicemente considerate non attive: il link verra'
  // comunque rigenerato automaticamente al prossimo giro.
  function decomposeLinkOptions(link) {
    link = link || '';
    return {
      map: /[?&]map=true\b/.test(link),
      scan: /[?&]scan=true\b/.test(link),
      breadCrumb: /[?&]breadCrumb=true\b/.test(link)
    };
  }

  function composeLink(type, id, opts) {
    if (!id) return '';
    var param = type === 'sheet' ? 'sheet' : 'service';
    var link = '?' + param + '=' + encodeURIComponent(id);
    if (opts.map) link += '&map=true';
    if (opts.scan) link += '&scan=true';
    if (opts.breadCrumb) link += '&breadCrumb=true';
    return link;
  }

  function updateLinkPreview() {
    var type = $('f-type').value;
    var id = $('f-id').value.trim();
    $('f-link').value = composeLink(type, id, {
      map: $('f-opt-map').checked,
      scan: $('f-opt-scan').checked,
      breadCrumb: $('f-opt-breadcrumb').checked
    });
  }

  // helper tollerante: se l'elemento non esiste (es. panel.php non ancora
  // allineato a questa versione di services-admin.js), non lancia un
  // errore che bloccherebbe il resto di populateForm/updateSheetFieldsVisibility
  function setDisplayIfPresent(id, value) {
    var el = $(id);
    if (el) el.style.display = value;
  }

  function updateSheetFieldsVisibility() {
    var isSheet = $('f-type').value === 'sheet';
    setDisplayIfPresent('sheet-title-fields', isSheet ? '' : 'none');
    setDisplayIfPresent('lu-sheet-hint', isSheet ? '' : 'none');
    setDisplayIfPresent('category-fields', isSheet ? '' : 'none');
    document.querySelectorAll('.ds-infogroup-row').forEach(function (el) {
      el.style.display = isSheet ? '' : 'none';
    });
  }

  $('f-type').addEventListener('change', function () { updateLinkPreview(); updateSheetFieldsVisibility(); });
  $('f-opt-map').addEventListener('change', updateLinkPreview);
  $('f-opt-scan').addEventListener('change', updateLinkPreview);
  $('f-opt-breadcrumb').addEventListener('change', updateLinkPreview);

  function emptyDataset() {
    return { nameDataset: '', alias: '', url: '', fetch: '', cache: true, recordsPath: '', groupKeyField: '', groupCountField: '', filters: [], sort: null, limit: null, is_infoGroup: false, infoGroupPosition: null };
  }

  function renderDatasetBlock(ds, idx) {
    var decomposed = decomposeFetch(ds.fetch || '');
    return ''
      + '<div class="dataset-block" data-idx="' + idx + '" data-relay-prefix="' + esc(decomposed.relayPrefix) + '">'
      + '  <button type="button" class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2" data-remove-dataset="' + idx + '" title="Rimuovi questo dataset"><i class="icon-remove"></i></button>'
      + '  <div class="row g-2 mb-2">'
      + '    <div class="col-md-6"><label class="form-label">Nome dataset <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Etichetta mostrata nella lista dataset in testata, con link a \'url\'."></i></label><input type="text" class="form-control ds-nameDataset" value="' + esc(ds.nameDataset || '') + '"></div>'
      + '    <div class="col-md-6"><label class="form-label">Alias <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Nome breve usato dal codice per richiedere questo dataset: OpenData.getData(\'alias\'). Univoco nel servizio."></i></label><input type="text" class="form-control ds-alias" value="' + esc(ds.alias || '') + '"></div>'
      + '  </div>'
      + '  <div class="mb-2"><label class="form-label">URL pagina dataset <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Link alla scheda del dataset su CKAN (solo informativo, per il pulsante \'vedi scheda\'), non e\' l\'URL da cui si scaricano i dati."></i></label><input type="text" class="form-control ds-url" value="' + esc(ds.url || '') + '"></div>'

      + '  <div class="fetch-subblock mb-2">'
      + '    <label class="form-label mb-1">Fetch  sorgente dei dati <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Inserisci qui l\'URL reale (es. il link di download CKAN). Se il server non abilita CORS, spunta \'Usa relay\': il tool genera da solo l\'URL con l\'incapsulamento per il relay locale  non serve scriverlo a mano."></i></label>'
      + '    <input type="text" class="form-control ds-fetch-url" placeholder="https://dati.toscana.it/dataset/.../download/file.json" value="' + esc(decomposed.url) + '">'
      + '    <div class="form-check mt-2">'
      + '      <input type="checkbox" class="form-check-input ds-fetch-relay" id="relay-' + idx + '" ' + (decomposed.useRelay ? 'checked' : '') + '>'
      + '      <label class="form-check-label" for="relay-' + idx + '">Usa relay locale <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Necessario quando dati.toscana.it non restituisce l\'header CORS: il browser passa dal relay same-origin del progetto invece di chiamare CKAN direttamente."></i></label>'
      + '    </div>'
      + '    <div class="form-text ds-fetch-preview font-monospace"></div>'
      + '  </div>'

      + '  <div class="row g-2 mb-2 align-items-end">'
      + '    <div class="col-md-6 form-check"><input type="checkbox" class="form-check-input ds-cache" ' + (ds.cache !== false ? 'checked' : '') + '><label class="form-check-label">Cache <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Se attiva, OpenData mantiene i dati in memoria nel browser tra una richiesta e l\'altra invece di riscaricarli ogni volta."></i></label></div>'
      + '    <div class="col-md-6"><label class="form-label">Limit <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Numero massimo di record restituiti, dopo filtri e ordinamento. Vuoto = nessun limite."></i></label><input type="number" class="form-control ds-limit" value="' + esc(ds.limit == null ? '' : ds.limit) + '"></div>'
      + '  </div>'
      + '  <div class="mb-2"><label class="form-label">recordsPath <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Da compilare SOLO se i dati sono raggruppati (non un array semplice ne\' GeoJSON), es. giorni.*.interventi. Il segmento * indica il livello di raggruppamento."></i></label><input type="text" class="form-control ds-recordsPath" placeholder="es. giorni.*.interventi" value="' + esc(ds.recordsPath || '') + '"></div>'
      + '  <div class="row g-2 mb-2">'
      + '    <div class="col-md-6"><label class="form-label">groupKeyField <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Nome campo in cui iniettare la chiave di raggruppamento (es. la data) in ogni record, usabile poi da filtri/ordinamento. Solo con recordsPath."></i></label><input type="text" class="form-control ds-groupKeyField" value="' + esc(ds.groupKeyField || '') + '"></div>'
      + '    <div class="col-md-6"><label class="form-label">groupCountField <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Nome campo del gruppo da ricalcolare col nuovo conteggio dopo i filtri (es. \'totale\'). Solo con recordsPath."></i></label><input type="text" class="form-control ds-groupCountField" value="' + esc(ds.groupCountField || '') + '"></div>'
      + '  </div>'
      + '  <div class="mb-2">'
      + '    <label class="form-label mb-1">Filtri <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Ogni riga e\' una condizione applicata ai record. \'Valore fisso\' confronta con un valore scritto qui; \'Da query string\' legge il valore da un parametro nell\'URL della pagina (es. ?obj_id=15)."></i></label>'
      + '    <div class="filters-container" data-idx="' + idx + '">' + (ds.filters || []).map(function (f) { return renderFilterRow(f, uid()); }).join('') + '</div>'
      + '    <button type="button" class="btn btn-sm btn-outline-secondary add-filter-btn"><i class="icon-plus me-1"></i>Aggiungi filtro</button>'
      + '  </div>'
      + '  <div class="row g-2 mb-1 align-items-end">'
      + '    <div class="col-md-8"><label class="form-label">Ordina per campo <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Nome del campo su cui ordinare i record. Lascia vuoto per non ordinare."></i></label><input type="text" class="form-control ds-sort-field" value="' + esc((ds.sort && ds.sort.field) || '') + '"></div>'
      + '    <div class="col-md-4"><label class="form-label">Direzione</label><select class="form-select ds-sort-direction"><option value="asc" ' + ((!ds.sort || ds.sort.direction !== 'desc') ? 'selected' : '') + '>crescente (asc)</option><option value="desc" ' + ((ds.sort && ds.sort.direction === 'desc') ? 'selected' : '') + '>decrescente (desc)</option></select></div>'
      + '  </div>'
      + '  <div class="row g-2 mb-1 align-items-end ds-infogroup-row" style="display:' + ($('f-type').value === 'sheet' ? '' : 'none') + ';">'
      + '    <div class="col-md-5 form-check">'
      + '      <input type="checkbox" class="form-check-input ds-is-infoGroup" id="infogroup-' + idx + '" ' + (ds.is_infoGroup ? 'checked' : '') + '>'
      + '      <label class="form-check-label" for="infogroup-' + idx + '">is_infoGroup <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Solo per le Schede: segna questo dataset come una sezione informativa mostrata in testata. La posizione tra piu\' sezioni si controlla con infoGroupPosition qui accanto."></i></label>'
      + '    </div>'
      + '    <div class="col-md-4">'
      + '      <label class="form-label">infoGroupPosition <i class="icon-question-sign help-icon" data-toggle="tooltip" title="Numero d\'ordine della sezione (1, 2, 3, ...). Tra tutti i dataset con is_infoGroup attivo nello stesso servizio, i numeri devono essere univoci e coprire 1..N senza buchi: proposto automaticamente, modificabile."></i></label>'
      + '      <input type="number" min="1" step="1" class="form-control ds-infoGroupPosition" ' + (ds.is_infoGroup ? '' : 'disabled') + ' value="' + esc(ds.infoGroupPosition == null ? '' : ds.infoGroupPosition) + '">'
      + '    </div>'
      + '  </div>'
      + '</div>';
  }

  function updateFetchPreview(block) {
    var url = block.querySelector('.ds-fetch-url').value.trim();
    var useRelay = block.querySelector('.ds-fetch-relay').checked;
    var relayPrefix = block.dataset.relayPrefix || DEFAULT_RELAY_PREFIX;
    var preview = block.querySelector('.ds-fetch-preview');
    var composed = composeFetch(url, useRelay, relayPrefix);
    preview.textContent = composed ? ('fetch generato: ' + composed) : '';
  }

  function renderDatasetsContainer(record) {
    var container = $('datasets-container');
    container.innerHTML = (record.datasets || []).map(function (ds) { return renderDatasetBlock(ds, uid()); }).join('');
    container.querySelectorAll('.dataset-block').forEach(updateFetchPreview);
    initTooltips(container);
  }

  function currentDatasetAliases() {
    return Array.prototype.map.call(document.querySelectorAll('.dataset-block .ds-alias'), function (input) {
      return input.value.trim();
    }).filter(Boolean);
  }

  // ricalcola le opzioni di UNA select "per alias" (Ultimo aggiornamento,
  // Categoria, ...) in base agli alias attualmente presenti nel form
  // Dataset, conservando la scelta corrente se ancora valida
  function refreshAliasSelectOptions(sel) {
    if (!sel) return;
    var current = sel.value;
    var aliases = currentDatasetAliases();
    sel.innerHTML = '<option value="">-- nessuno --</option>' +
      aliases.map(function (a) { return '<option value="' + esc(a) + '">' + esc(a) + '</option>'; }).join('');
    sel.value = aliases.indexOf(current) !== -1 ? current : '';
  }

  // tutte le select "per alias" del form, da tenere sincronizzate insieme
  // ogni volta che l'elenco degli alias dataset cambia
  function refreshAllAliasSelects() {
    refreshAliasSelectOptions($('f-lu-alias'));
    refreshAliasSelectOptions($('f-category-alias'));
  }

  // Da qui in poi: SOLO event delegation su #datasets-container, cosi'
  // funziona automaticamente anche per blocchi/righe aggiunti dopo il primo
  // render (nuovo dataset, nuovo filtro), senza dover ri-agganciare nulla.
  var datasetsContainerEl = $('datasets-container');

  datasetsContainerEl.addEventListener('input', function (e) {
    if (e.target.classList.contains('ds-fetch-url')) {
      updateFetchPreview(e.target.closest('.dataset-block'));
    }
    if (e.target.classList.contains('ds-alias')) {
      refreshAllAliasSelects();
    }
  });

  // posizioni infoGroupPosition attualmente usate da ALTRI dataset (con
  // is_infoGroup attivo e un valore compilato), per proporre in automatico
  // il primo numero libero quando si attiva una nuova sezione
  function usedInfoGroupPositions(excludeBlock) {
    var used = {};
    document.querySelectorAll('.dataset-block').forEach(function (block) {
      if (block === excludeBlock) return;
      var cb = block.querySelector('.ds-is-infoGroup');
      var posInput = block.querySelector('.ds-infoGroupPosition');
      if (cb && cb.checked && posInput && posInput.value.trim() !== '') {
        used[parseInt(posInput.value, 10)] = true;
      }
    });
    return used;
  }

  function nextFreeInfoGroupPosition(excludeBlock) {
    var used = usedInfoGroupPositions(excludeBlock);
    var n = 1;
    while (used[n]) n++;
    return n;
  }

  datasetsContainerEl.addEventListener('change', function (e) {
    if (e.target.classList.contains('ds-fetch-relay')) {
      updateFetchPreview(e.target.closest('.dataset-block'));
    }
    if (e.target.classList.contains('filter-operator') || e.target.classList.contains('filter-mode')) {
      refreshFilterRow(e.target.closest('.filter-row'));
    }
    if (e.target.classList.contains('ds-is-infoGroup')) {
      var block = e.target.closest('.dataset-block');
      var posInput = block.querySelector('.ds-infoGroupPosition');
      if (e.target.checked) {
        posInput.disabled = false;
        if (!posInput.value.trim()) posInput.value = nextFreeInfoGroupPosition(block);
      } else {
        posInput.disabled = true;
        posInput.value = '';
      }
    }
  });

  datasetsContainerEl.addEventListener('click', function (e) {
    if (e.target.closest('[data-remove-dataset]')) {
      e.target.closest('.dataset-block').remove();
      refreshAllAliasSelects();
      return;
    }

    if (e.target.closest('.add-filter-btn')) {
      var filtersContainer = e.target.closest('.dataset-block').querySelector('.filters-container');
      var wrapper = document.createElement('div');
      wrapper.innerHTML = renderFilterRow({ field: '', operator: '=' }, uid());
      filtersContainer.appendChild(wrapper.firstElementChild);
      return;
    }

    if (e.target.closest('[data-remove-filter]')) {
      e.target.closest('.filter-row').remove();
      return;
    }
  });

  $('btn-add-dataset').addEventListener('click', function () {
    var wrapper = document.createElement('div');
    wrapper.innerHTML = renderDatasetBlock(emptyDataset(), uid());
    var newBlock = wrapper.firstElementChild;
    datasetsContainerEl.appendChild(newBlock);
    updateFetchPreview(newBlock);
    initTooltips(newBlock);
    refreshAllAliasSelects();
  });

  // Alert e descrizione seguono lo stesso pattern: aggiungi/rimuovi agiscono
  // direttamente sul DOM, la lettura avviene solo al salvataggio.
  $('btn-add-alert').addEventListener('click', function () {
    var wrapper = document.createElement('div');
    wrapper.innerHTML = renderAlertRow({ typeAlert: 'warning', text: '' }, uid());
    $('alert-container').appendChild(wrapper.firstElementChild);
  });
  $('alert-container').addEventListener('click', function (e) {
    var btn = e.target.closest('[data-remove-alert]');
    if (btn) btn.closest('.alert-row').remove();
  });

  $('btn-add-desc').addEventListener('click', function () {
    var wrapper = document.createElement('div');
    wrapper.innerHTML = renderDescRow({ p: '' }, uid());
    $('desc-container').appendChild(wrapper.firstElementChild);
  });
  $('desc-container').addEventListener('click', function (e) {
    var btn = e.target.closest('[data-remove-desc]');
    if (btn) btn.closest('.desc-row').remove();
  });

  function readFormIntoRecord() {
    var r = currentRecord;
    r.id = $('f-id').value.trim();
    r.title = $('f-title').value.trim();
    r.type = $('f-type').value;
    r.icona = $('f-icona').value.trim();
    r.desc = $('f-desc').value.trim();
    r.dev = $('f-dev').value;
    r.new = $('f-new').checked;
    r.allowEmbed = $('f-allowEmbed').checked;

    // il link non si legge mai dal suo campo (disabilitato): si ricompone
    // sempre da zero da tipo + id + opzioni, cosi' e' sempre coerente
    r.link = composeLink(r.type, r.id, {
      map: $('f-opt-map').checked,
      scan: $('f-opt-scan').checked,
      breadCrumb: $('f-opt-breadcrumb').checked
    });

    if (r.type === 'sheet') {
      r.titleDataset = $('f-titleDataset').value.trim();
      r.titleField = $('f-titleField').value.trim();

      var catAliasEl = $('f-category-alias'), catFieldEl = $('f-category-field');
      var catAlias = catAliasEl ? catAliasEl.value : '';
      var catField = catFieldEl ? catFieldEl.value.trim() : '';
      if (catAlias && catField) {
        r.category = { alias: catAlias, field: catField };
      } else {
        delete r.category;
      }
    } else {
      delete r.titleDataset;
      delete r.titleField;
      delete r.category;
    }
    r.tags = $('f-tags').value.split(',').map(function (t) { return t.trim(); }).filter(Boolean);

    r.alert = Array.prototype.map.call(document.querySelectorAll('#alert-container .alert-row'), function (row) {
      return { typeAlert: row.querySelector('.alert-type').value, text: row.querySelector('.alert-text').value.trim() };
    }).filter(function (a) { return a.text !== ''; });

    r.descriptiion = Array.prototype.map.call(document.querySelectorAll('#desc-container .desc-row'), function (row) {
      var tag = row.querySelector('.desc-tag').value;
      var text = row.querySelector('.desc-text').value;
      var obj = {};
      obj[tag] = text;
      return obj;
    }).filter(function (item) { return item[Object.keys(item)[0]].trim() !== ''; });

    var blocks = document.querySelectorAll('.dataset-block');
    r.datasets = Array.prototype.map.call(blocks, function (block) {
      var ds = {};
      ds.nameDataset = block.querySelector('.ds-nameDataset').value.trim();
      ds.alias = block.querySelector('.ds-alias').value.trim();
      ds.url = block.querySelector('.ds-url').value.trim();
      ds.cache = block.querySelector('.ds-cache').checked;

      var recordsPath = block.querySelector('.ds-recordsPath').value.trim();
      if (recordsPath) ds.recordsPath = recordsPath;
      var groupKeyField = block.querySelector('.ds-groupKeyField').value.trim();
      if (groupKeyField) ds.groupKeyField = groupKeyField;
      var groupCountField = block.querySelector('.ds-groupCountField').value.trim();
      if (groupCountField) ds.groupCountField = groupCountField;

      var limitVal = block.querySelector('.ds-limit').value.trim();
      if (limitVal !== '') ds.limit = parseInt(limitVal, 10);

      var fetchUrl = block.querySelector('.ds-fetch-url').value.trim();
      var useRelay = block.querySelector('.ds-fetch-relay').checked;
      var relayPrefix = block.dataset.relayPrefix || DEFAULT_RELAY_PREFIX;
      ds.fetch = composeFetch(fetchUrl, useRelay, relayPrefix);

      var filterRows = block.querySelectorAll('.filter-row');
      var filters = Array.prototype.map.call(filterRows, readFilterRowToObject).filter(function (f) { return f.field !== ''; });
      if (filters.length) ds.filters = filters;

      var sortField = block.querySelector('.ds-sort-field').value.trim();
      if (sortField) {
        ds.sort = { field: sortField, direction: block.querySelector('.ds-sort-direction').value };
      }

      var isInfoGroup = block.querySelector('.ds-is-infoGroup').checked;
      ds.is_infoGroup = isInfoGroup;
      if (isInfoGroup) {
        var posVal = block.querySelector('.ds-infoGroupPosition').value.trim();
        if (posVal !== '') ds.infoGroupPosition = parseInt(posVal, 10);
      }

      return ds;
    });

    var luAlias = $('f-lu-alias').value;
    if (luAlias) {
      var lu = { alias: luAlias };
      var luField = $('f-lu-field').value.trim();
      var luRecordsPath = $('f-lu-recordsPath').value.trim();
      var luDateField = $('f-lu-dateField').value.trim();
      if (luField) lu.field = luField;
      if (luRecordsPath) lu.recordsPath = luRecordsPath;
      if (luDateField) lu.dateField = luDateField;
      r.lastUpdate = lu;
    } else {
      delete r.lastUpdate;
    }

    return r;
  }

  var lastAutoSlug = ''; // ultimo slug generato automaticamente dal titolo, per capire se l'utente ha poi personalizzato l'id a mano

  function updateIdHint() {
    var expected = slugify($('f-title').value);
    var current = $('f-id').value.trim();
    var hint = $('f-id-hint');
    if (!current) {
      hint.textContent = '';
    } else if (expected && current !== expected) {
      hint.innerHTML = '<span class="text-warning"><i class="icon-warning-sign"></i> non corrisponde allo slug del titolo (atteso "' + esc(expected) + '")</span>';
    } else {
      hint.innerHTML = '<span class="text-success"><i class="icon-ok-sign"></i> coerente con il titolo</span>';
    }
  }

  $('f-title').addEventListener('input', function () {
    var newSlug = slugify($('f-title').value);
    // aggiorna l'id automaticamente SOLO se era vuoto o ancora allineato
    // all'ultimo slug generato: se l'utente lo ha personalizzato a mano,
    // digitare nel titolo non deve piu' sovrascriverlo silenziosamente
    if ($('f-id').value.trim() === '' || $('f-id').value.trim() === lastAutoSlug) {
      $('f-id').value = newSlug;
    }
    lastAutoSlug = newSlug;
    updateIdHint();
    updateLinkPreview();
  });

  $('f-id').addEventListener('input', function () { updateIdHint(); updateLinkPreview(); });

  $('btn-regen-id').addEventListener('click', function () {
    $('f-id').value = slugify($('f-title').value);
    lastAutoSlug = $('f-id').value;
    updateIdHint();
    updateLinkPreview();
  });

  function populateForm(r) {
    $('f-id').value = r.id || '';
    $('f-title').value = r.title || '';
    $('f-type').value = r.type === 'sheet' ? 'sheet' : 'service'; // default "service" per record pre-esistenti senza questo campo
    $('f-icona').value = r.icona || '';
    $('f-desc').value = r.desc || '';
    $('f-dev').value = r.dev || 'prod';
    $('f-new').checked = !!r.new;
    $('f-allowEmbed').checked = !!r.allowEmbed;
    $('f-tags').value = (r.tags || []).join(', ');
    $('f-titleDataset').value = r.titleDataset || '';
    $('f-titleField').value = r.titleField || '';
    renderAlertContainer(r.alert || []);
    renderDescContainer(r.descriptiion || []);

    // le 3 opzioni si deducono dal link esistente (compatibilita' con record
    // gia' presenti), poi il link viene comunque rigenerato subito dopo
    var opts = decomposeLinkOptions(r.link || '');
    $('f-opt-map').checked = opts.map;
    $('f-opt-scan').checked = opts.scan;
    $('f-opt-breadcrumb').checked = opts.breadCrumb;

    // se l'id esistente e' gia' coerente con lo slug del titolo, consideralo
    // "auto-generato" per continuare a sincronizzarlo mentre si modifica il
    // titolo; se e' un id personalizzato, lascialo stabile (vedi listener sopra)
    lastAutoSlug = slugify(r.title || '');
    updateIdHint();
    updateLinkPreview();
    updateSheetFieldsVisibility();

    renderDatasetsContainer(r);
    refreshAllAliasSelects();
    var lu = r.lastUpdate || {};
    $('f-lu-field').value = lu.field || '';
    $('f-lu-recordsPath').value = lu.recordsPath || '';
    $('f-lu-dateField').value = lu.dateField || '';
    $('f-lu-alias').value = lu.alias || ''; // eseguito DOPO il refresh, cosi' l'opzione esiste gia'

    var cat = r.category || {};
    var catFieldEl = $('f-category-field'), catAliasEl = $('f-category-alias');
    if (catFieldEl) catFieldEl.value = cat.field || '';
    if (catAliasEl) catAliasEl.value = cat.alias || ''; // idem: dopo il refresh

    initTooltips(document);
  }

  function syncRawJsonFromForm() {
    try { currentRecord = readFormIntoRecord(); } catch (e) { /* mostriamo comunque l'ultimo record valido noto */ }
    $('raw-json').value = JSON.stringify(currentRecord, null, 2);
  }

  $('btn-apply-json').addEventListener('click', function () {
    try {
      currentRecord = JSON.parse($('raw-json').value);
      populateForm(currentRecord);
      switchTab('form');
    } catch (e) {
      alert('JSON non valido: ' + e.message);
    }
  });

  function switchTab(name) {
    document.querySelectorAll('.nav-link[data-tab]').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
    $('tab-form').style.display = name === 'form' ? '' : 'none';
    $('tab-json').style.display = name === 'json' ? '' : 'none';
    if (name === 'json') syncRawJsonFromForm();
  }

  document.querySelectorAll('.nav-link[data-tab]').forEach(function (b) {
    b.addEventListener('click', function () { switchTab(b.dataset.tab); });
  });

  function openEditor(record) {
    currentRecord = record ? JSON.parse(JSON.stringify(record)) : emptyRecord();
    editingOriginalId = record ? record.id : null;
    $('editor-title').textContent = record ? ('Modifica: ' + record.id) : 'Nuovo servizio';
    $('editor-issues').innerHTML = '';
    populateForm(currentRecord);
    switchTab('form');
    editorModal.show();
  }

  $('btn-new').addEventListener('click', function () { openEditor(null); });

  $('btn-save').addEventListener('click', async function () {
    var record;
    try {
      record = $('tab-json').style.display !== 'none' ? JSON.parse($('raw-json').value) : readFormIntoRecord();
    } catch (e) {
      alert('Impossibile salvare: ' + e.message);
      return;
    }

    var res = await api('save', { originalId: editingOriginalId, record: record });

    $('editor-issues').innerHTML = (res.issues || []).map(function (i) {
      return '<div class="alert alert-' + (i.level === 'error' ? 'danger' : 'warning') + ' py-2 px-3 mb-2">' + esc(i.message) + '</div>';
    }).join('') + (!res.ok && res.error ? '<div class="alert alert-danger py-2 px-3 mb-2"><strong>' + esc(res.error) + '</strong></div>' : '');

    if (!res.ok) return;

    var idx = services.findIndex(function (s) { return s.id === editingOriginalId; });
    if (idx === -1) services.push(record); else services[idx] = record;
    renderTable();
    editorModal.hide();
  });

  /* ---------------------------------------------------------------------
   * Test: conformita' + raggiungibilita' + record campione + log verboso
   * ------------------------------------------------------------------ */
  function renderHeaderSimBlock(label, sim) {
    if (!sim) return '';
    var hasText = sim.text && sim.text !== '';
    var valueHtml = hasText
      ? '<span class="fw-bold">' + esc(sim.text) + '</span>'
      : '<span class="text-muted fst-italic">(vuoto)</span>';
    var noteHtml = sim.note
      ? '<div class="form-text mt-1"><i class="icon-info-sign me-1"></i>' + esc(sim.note) + '</div>'
      : '';
    return ''
      + '<div class="mb-2">'
      + '  <div class="small text-muted">' + esc(label) + '</div>'
      + '  <div>' + valueHtml + '</div>'
      + noteHtml
      + '</div>';
  }

  function openTest(record) {
    $('test-title').textContent = 'Test: ' + record.id;
    $('test-header-sim').innerHTML = '';
    $('test-conformance').innerHTML = '<p class="text-muted">Verifica in corso...</p>';
    $('test-datasets').innerHTML = '';
    $('test-log').textContent = '';
    testModal.show();

    api('test', { id: record.id }).then(function (res) {
      if (!res.ok) {
        $('test-conformance').innerHTML = '<p class="status-fail">Errore: ' + esc(res.error) + '</p>';
        return;
      }

      $('test-header-sim').innerHTML = '<h6>Simulazione testata</h6><div class="test-dataset">'
        + renderHeaderSimBlock('Titolo', res.titleSimulation)
        + renderHeaderSimBlock('Categoria', res.categorySimulation)
        + renderHeaderSimBlock('Ultimo aggiornamento', res.lastUpdateSimulation)
        + '</div>';

      $('test-conformance').innerHTML = '<h6>Conformita\'</h6>' + (
        res.conformance.length
          ? res.conformance.map(function (i) {
              return '<div class="alert alert-' + (i.level === 'error' ? 'danger' : 'warning') + ' py-2 px-3 mb-2">' + esc(i.message) + '</div>';
            }).join('')
          : '<p class="status-ok"><i class="icon-ok-sign me-1"></i>Nessun problema di conformita\' rilevato.</p>'
      );

      $('test-datasets').innerHTML = '<h6>Dataset</h6>' + res.datasets.map(function (d) {
        var reach = d.reachability || {};
        var timing = reach.timing || {};
        var reachHtml = reach.ok
          ? '<span class="status-ok"><i class="icon-ok-sign"></i> raggiungibile</span>  HTTP ' + reach.status
            + ' - dns ' + timing.dnsMs + 'ms - connessione ' + timing.connectMs + 'ms - primo byte ' + timing.ttfbMs + 'ms - totale ' + timing.totalMs + 'ms - '
            + (reach.sizeBytes || 0) + ' byte'
          : '<span class="status-fail"><i class="icon-remove-sign"></i> non raggiungibile</span>  ' + esc(reach.error || ('HTTP ' + reach.status));

        var sampleHtml = d.sample
          ? '<pre class="sample">' + esc(JSON.stringify(d.sample, null, 2)) + '</pre>'
          : '<p class="text-muted fst-italic">' + esc(d.sampleError || 'nessun campione disponibile') + '</p>';

        return ''
          + '<div class="test-dataset">'
          + '  <h6><code>' + esc(d.alias) + '</code></h6>'
          + '  <p class="mb-1">' + reachHtml + '</p>'
          + (d.formatDetected ? '<p class="mb-1"><strong>Formato riconosciuto:</strong> ' + esc(d.formatDetected) + '</p>' : '')
          + '  <p class="mb-1"><strong>Record campione:</strong></p>'
          + sampleHtml
          + '</div>';
      }).join('');

      $('test-log').textContent = (res.log || []).join('\n');
    });
  }

  /* ---------------------------------------------------------------------
   * Export
   * ------------------------------------------------------------------ */
  async function checkExportHosts(mode) {
    var res = await api('checkExport', { mode: mode });
    return (res && res.ok && res.issues) || [];
  }

  async function runExport(mode) {
    var issues = await checkExportHosts(mode);

    if (issues.length) {
      var list = issues.map(function (i) {
        return '- ' + i.service + ' / ' + i.alias;
      }).join('\n');

      var proceed = confirm(
        'Controlla che il fetch sia indirizzato per il servizio online.\n\n' +
        'Trovati ' + issues.length + ' dataset che puntano ancora all\'host di test (' + (ADMIN_CONFIG.testHostMarker || '?') + '):\n\n' +
        list +
        '\n\nContinuare comunque con l\'esportazione?'
      );
      if (!proceed) return;
    }

    window.location = '?action=export&mode=' + encodeURIComponent(mode);
  }

  $('btn-export-prod').addEventListener('click', function () { runExport('prod'); });
  $('btn-export-debug').addEventListener('click', function () { runExport('debug'); });

  renderTable();
})();