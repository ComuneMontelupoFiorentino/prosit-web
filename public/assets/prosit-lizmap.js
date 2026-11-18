/* ==========================================================================
   PROSIT dentro Lizmap (caricato in tutte le mappe da
   <progetti>/media/js/default/prosit-lizmap.js, generato da install.sh)
   --------------------------------------------------------------------------
   1. Popup QGIS: i segnaposto  <div class="p-embed" data-src="/lizmap/prosit/schede/...">
      diventano un iframe con la scheda esterna in modalita' incorporata (?embed=1).
   2. Libreria per i JS personalizzati dei progetti (cartelle media/js/...):
      sessione e gruppi, indirizzi di Prosit, pulsanti, colori di stato, operatore
      nei moduli di modifica. Cosi' i JS dei progetti non contengono IP, gruppi o
      colori scritti a mano.

   Uso in un JS di progetto (l'ordine di caricamento dei file non conta):

     (window.prositReady = window.prositReady || []).push(function (P) {
       P.me().then(function (me) { if (me && me.apps['registro-tvcc']) { ... } });
     });

   Perche' l'iframe dei popup lo crea questo script e non il popup QGIS:
   Lizmap filtra l'HTML dei popup con DOMPurify e mette in sandbox ogni iframe il
   cui indirizzo non inizia con l'origine della pagina: una scheda in sandbox non
   riceverebbe la sessione Lizmap. Qui l'iframe viene creato dopo il filtro, con
   l'indirizzo completo dello stesso sito, e SOLO per pagine sotto /prosit/schede/.
   ========================================================================== */
(function () {
  'use strict';
  if (window.PrositLizmap) return;

  var script = document.currentScript;
  var BASE = script ? new URL('..', script.src).pathname.replace(/\/$/, '') : '/lizmap/prosit';
  var LIZMAP = BASE.replace(/\/prosit$/, '');          // es. /lizmap
  var ALLOWED = BASE + '/schede/';
  var API = BASE + '/api/index.php/v1/';

  function onLizmap(handlers) {
    if (window.lizMap && lizMap.events) lizMap.events.on(handlers);
  }

  /* ---------- 1. schede esterne nei popup QGIS ---------- */

  function upgrade(node) {
    if (!node || node.dataset.pDone) return;
    node.dataset.pDone = '1';
    var src = node.getAttribute('data-src') || '';
    var url;
    try { url = new URL(src, location.origin); } catch (e) { return; }
    if (url.origin !== location.origin || url.pathname.indexOf(ALLOWED) !== 0) {
      node.classList.add('p-empty');
      node.textContent = 'Scheda non consentita: ' + src;
      return;
    }
    url.searchParams.set('embed', '1');
    var frame = document.createElement('iframe');
    frame.className = 'p-frame';
    frame.src = url.href;
    frame.title = node.getAttribute('data-title') || 'Scheda';
    frame.loading = 'lazy';
    if (node.getAttribute('data-height')) frame.style.minHeight = node.getAttribute('data-height');
    node.replaceChildren(frame);
  }

  function scan(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('.p-embed[data-src]')) upgrade(root);
    root.querySelectorAll('.p-embed[data-src]').forEach(upgrade);
  }

  /** Ricarica le schede incorporate (dopo una modifica in Lizmap i dati cambiano). */
  function refreshFrames() {
    document.querySelectorAll('iframe.p-frame, iframe#reklaFrame').forEach(function (f) {
      try { f.contentWindow.location.reload(); } catch (e) { f.src = f.src; }
    });
  }

  // i popup di Lizmap compaiono in punti diversi (pannello, mappa, tabella attributi):
  // si osserva tutta la pagina, cosi' funziona anche con versioni future di Lizmap
  var observer = new MutationObserver(function (mutations) {
    mutations.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) scan(n); }); });
  });
  function start() {
    observer.observe(document.body, { childList: true, subtree: true });
    scan(document.body);
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);

  onLizmap({
    lizmapeditionfeaturecreated: refreshFrames,
    lizmapeditionfeaturemodified: refreshFrames,
    lizmapeditionfeaturedeleted: refreshFrames
  });

  /* ---------- 2. libreria per i JS dei progetti ---------- */

  /**
   * Interfaccia di Lizmap pronta (evento "uicreated"), anche se l'evento e' gia' passato
   * quando questo file viene caricato: P.uiReady().then(function () { ... })
   */
  var uiPromise = new Promise(function (resolve) {
    onLizmap({ uicreated: function () { resolve(); } });
    // gia' creata? Lizmap attiva il pannello dei layer subito prima di "uicreated"
    var tries = 0;
    (function poll() {
      if (document.querySelector('#mapmenu li.active, #mapmenu li.switcher.active')) { setTimeout(resolve, 50); return; }
      if (++tries < 120) setTimeout(poll, 500);
    })();
  });
  function uiReady() { return uiPromise; }

  /** Indirizzo dentro Prosit:  P.url('app/registro-tvcc/')  ->  /lizmap/prosit/app/registro-tvcc/ */
  function url(path) { return BASE + '/' + String(path || '').replace(/^\/+/, ''); }

  /** Indirizzo dentro Lizmap (vecchie cartelle):  P.lizmapUrl('ainop/api/ainop.php') */
  function lizmapUrl(path) { return LIZMAP + '/' + String(path || '').replace(/^\/+/, ''); }

  /** Chiamata all'API di Prosit; restituisce "data" o lancia un errore con il messaggio del server. */
  function api(path, options) {
    var o = Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, options || {});
    if (o.json !== undefined) {
      o.method = o.method || 'POST';
      o.headers = Object.assign({ 'Content-Type': 'application/json' }, o.headers);
      o.body = JSON.stringify(o.json);
      delete o.json;
    }
    return fetch(API + String(path).replace(/^\/+/, ''), o).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (b) {
        if (!r.ok || b.success === false) {
          var err = new Error(b.error || ('Errore ' + r.status));
          err.status = r.status;
          throw err;
        }
        return b.data;
      });
    });
  }

  var mePromise = null;
  /**
   * Utente collegato, una sola chiamata per pagina. null se non collegato.
   * { user:{login,name,firstname,lastname,email}, groups:{ids,names}, apps:{'registro-tvcc':true,...} }
   * "apps" viene da config 'apps' di Prosit: la stessa regola che controlla il server.
   */
  function me() {
    if (!mePromise) {
      mePromise = api('auth/me').catch(function (e) {
        if (e.status !== 401) console.warn('[prosit] sessione:', e.message);
        return null;
      });
    }
    return mePromise;
  }

  /** true se l'utente puo' aprire l'applicazione (vedi config 'apps' di Prosit). */
  function can(app) {
    return me().then(function (m) { return !!(m && m.apps && m.apps[app]); });
  }

  /** true se l'utente e' in uno dei gruppi (id o nome, senza differenze di maiuscole). */
  function inGroup(groups) {
    var want = [].concat(groups).map(function (g) { return String(g).trim().toLowerCase(); });
    return me().then(function (m) {
      if (!m) return false;
      var have = (m.groups.ids || []).concat(m.groups.names || []).map(function (g) { return String(g).trim().toLowerCase(); });
      return want.some(function (g) { return have.indexOf(g) !== -1; });
    });
  }

  /**
   * Voce nel menu di Lizmap che apre una pagina in una nuova scheda (invece di un pannello).
   *   P.menuLink({ id:'tvcc', label:'Registro controlli TVCC', icon:'icon-facetime-video',
   *                href: P.url('app/registro-tvcc/'), app:'registro-tvcc', dock:'bottomdock' })
   * Con "app" la voce compare solo a chi puo' aprire l'applicazione.
   */
  function menuLink(o) {
    var show = o.app ? can(o.app) : Promise.resolve(true);
    return Promise.all([show, uiPromise]).then(function (r) {
      var ok = r[0];
      if (!ok || !window.lizMap) return false;
      lizMap.addDock(o.id, o.label, o.dock || 'dock', '', o.icon || 'icon-share');
      var a = document.getElementById('button-' + o.id);
      if (!a) return false;
      a.setAttribute('href', o.href);
      // fase di cattura: Lizmap non apre il pannello vuoto
      a.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        window.open(o.href, o.target || '_blank', 'noopener');
      }, true);
      return true;
    });
  }

  /** Stato -> codice comune ("Presa in carico (Complessa)" -> "presa-in-carico"). */
  function statusSlug(text) {
    return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '')
      .toLowerCase().replace(/\(complessa\)/g, '').trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  // stati con un colore in prosit-components.css (--st-*)
  var STATI = ['accettata', 'convalidata', 'assegnata', 'presa-in-carico', 'programmata', 'eseguita',
    'completata', 'verificata-e-chiusa', 'sospesa', 'annullata'];

  /** Colora una cella con i colori di stato comuni (gli stessi delle schede); altri valori: nessun colore. */
  function paintStatus(cell, text) {
    var slug = statusSlug(text === undefined ? cell.textContent : text);
    cell.classList.add('p-st');
    if (STATI.indexOf(slug) !== -1) cell.setAttribute('data-status', slug); else cell.removeAttribute('data-status');
  }

  /** Colora una cella con un tono: 'ok' | 'warn' | 'ko' | '' (nessuno). */
  function paintTone(cell, tone) {
    cell.classList.add('p-tone');
    if (tone) cell.setAttribute('data-tone', tone); else cell.removeAttribute('data-tone');
  }

  /**
   * Chiama fn(table) per ogni tabella che corrisponde al selettore, ora e ogni volta che
   * le sue righe cambiano (paginazione, filtri, ordinamento, aggiornamento).
   *   P.watchTables('#attribute-layer-table-contratti', function (t) { ... })
   */
  function watchTables(selector, fn) {
    var seen = new WeakSet();
    var pending = new WeakSet();
    function run(t) {
      if (pending.has(t)) return;
      pending.add(t);
      requestAnimationFrame(function () {
        pending.delete(t);
        try { fn(t); } catch (e) { console.error('[prosit] tabella:', e); }
      });
    }
    function attach(t) {
      if (seen.has(t)) return;
      seen.add(t);
      run(t);
      new MutationObserver(function () { run(t); }).observe(t, { childList: true, subtree: true, characterData: false });
    }
    function look(root) {
      if (root.matches && root.matches(selector)) attach(root);
      if (root.querySelectorAll) root.querySelectorAll(selector).forEach(attach);
    }
    look(document);
    new MutationObserver(function (ms) {
      ms.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) look(n); }); });
    }).observe(document.body, { childList: true, subtree: true });
  }

  /**
   * Pulsante nella barra della tabella attributi di un layer.
   *   P.tableButton('contr_godim_imm', { icon:'icon-print', title:'Stampa', onClick: fn })
   *   P.tableButton('trasparenza', { icon:'icon-print', title:'Stampa', menu: [{label, href}, ...] })
   */
  function tableButton(layer, o) {
    var sel = '#attribute-layer-main-' + layer + ' .attribute-layer-action-bar';
    var mark = 'p-btn-' + (o.id || o.icon || 'x');
    watchTables(sel, function (bar) {
      if (bar.querySelector('.' + mark)) return;
      // gruppo a destra, come il menu "Esporta" di Lizmap
      var group = document.createElement('div');
      group.className = 'btn-group pull-right ' + mark;
      group.style.marginLeft = '4px';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-mini';
      btn.title = o.title || '';
      btn.setAttribute('aria-label', o.title || '');
      var i = document.createElement('i');
      i.className = o.icon || 'icon-share';
      btn.appendChild(i);
      group.appendChild(btn);
      if (o.menu) {
        // menu a tendina di Bootstrap 2
        btn.className += ' dropdown-toggle';
        btn.setAttribute('data-toggle', 'dropdown');
        btn.insertAdjacentHTML('beforeend', ' <span class="caret"></span>');
        var ul = document.createElement('ul');
        ul.className = 'dropdown-menu pull-right';
        o.menu.forEach(function (it) {
          var li = document.createElement('li');
          var a = document.createElement('a');
          a.href = it.href;
          a.target = '_blank';
          a.rel = 'noopener';
          a.textContent = it.label;
          li.appendChild(a);
          ul.appendChild(li);
        });
        group.appendChild(ul);
      } else {
        btn.addEventListener('click', o.onClick);
      }
      bar.appendChild(group);
    });
  }

  /**
   * Voce aggiunta al menu "Esporta" della tabella attributi (se l'esportazione e' attiva).
   *   P.exportItem({ id:'ainop', label:'csv - ainop', layers: null | ['nome_layer'], href: function (layerId) { return '...'; } })
   * layerId e' il nome usato da Lizmap negli id (attribute-layer-main-<layerId>).
   */
  function exportItem(o) {
    var mark = 'p-exp-' + o.id;
    watchTables('div[id^="attribute-layer-main-"] .export-formats ul.dropdown-menu', function (ul) {
      if (ul.querySelector('.' + mark)) return;
      var main = ul.closest('div[id^="attribute-layer-main-"]');
      var layerId = main ? main.id.replace('attribute-layer-main-', '') : '';
      if (o.layers && o.layers.indexOf(layerId) === -1) return;
      var li = document.createElement('li');
      li.className = mark;
      var a = document.createElement('a');
      a.href = o.href(layerId);
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = o.label;
      li.appendChild(a);
      ul.appendChild(li);
    });
  }

  /**
   * Nei moduli di modifica di Lizmap scrive l'utente collegato nei campi indicati
   * (se presenti nel modulo aperto) e li rende non modificabili.
   *   P.operatore(['seg_s_ac_op', 'op_insert'])
   * Valore: "Nome Cognome" dalla sessione (se mancano, il login).
   * Attenzione: e' una comodita' per l'utente, non una garanzia. Per un dato affidabile
   * usare in QGIS il valore predefinito @lizmap_user (calcolato dal server di Lizmap).
   */
  function operatore(fields, options) {
    var prefix = (options && options.prefix) || 'jforms_view_edition';
    onLizmap({
      lizmapeditionformdisplayed: function () {
        me().then(function (m) {
          if (!m) return;
          var name = (m.user.name || m.user.login || '').trim();
          if (!name) return;
          fields.forEach(function (f) {
            var el = document.getElementById(prefix + '_' + f);
            if (!el) return;
            el.value = name;
            el.setAttribute('value', name);
            el.readOnly = true;
            el.classList.add('p-locked');
            el.title = 'Compilato automaticamente con l\'utente collegato';
          });
        });
      }
    });
  }

  var PrositLizmap = {
    base: BASE, version: '1.3.0',
    scan: scan, refreshFrames: refreshFrames,
    uiReady: uiReady, url: url, lizmapUrl: lizmapUrl, api: api, me: me, can: can, inGroup: inGroup,
    menuLink: menuLink, tableButton: tableButton, exportItem: exportItem, watchTables: watchTables,
    stati: STATI.slice(), statusSlug: statusSlug, paintStatus: paintStatus, paintTone: paintTone,
    operatore: operatore
  };
  window.PrositLizmap = PrositLizmap;

  // coda dei JS dei progetti caricati prima di questo file
  function runQueued(fn) {
    try { fn(PrositLizmap); } catch (e) { console.error('[prosit] JS personalizzato:', e); }
  }
  var queued = Array.isArray(window.prositReady) ? window.prositReady : [];
  window.prositReady = { push: runQueued };
  queued.forEach(runQueued);
})();
