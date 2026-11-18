/* ==========================================================================
   PROSIT — libreria comune per schede e applicazioni
   --------------------------------------------------------------------------
   Prosit.api      dati: la definisce assets/prosit-api-v2.js (Prosit API v2), da caricare
                   SUBITO DOPO questo file
   Prosit.fmt      formattazione (date, booleani, testo sicuro)
   Prosit.ui       blocchi di interfaccia (campi, timeline, allegati, badge)
   Prosit.page     ciclo di vita della scheda (caricamento, errore, contenuto)
   Prosit.urls     indirizzi di Lizmap (media) e proxy documenti

   Regola di sicurezza: i dati del database non finiscono MAI in innerHTML
   senza passare da Prosit.fmt.esc (lo fanno gia' tutte le funzioni di ui).
   ========================================================================== */
(function () {
  'use strict';

  // Base di pubblicazione ricavata da dove e' caricato questo file
  // (/lizmap/prosit/assets/prosit.js -> /lizmap/prosit)
  const script = document.currentScript;
  const BASE = script ? new URL('..', script.src).pathname.replace(/\/$/, '') : '/lizmap/prosit';
  // servizi di Prosit fuori dalla API v2 (proxy documenti Rekla); forma .../index.php/v1:
  // funziona anche senza regole Apache (come index.php/view/... di Lizmap)
  const SERVIZI = BASE + '/api/index.php/v1';
  const LIZMAP = BASE.replace(/\/prosit$/, '') || '/lizmap';
  // stesso indirizzo usato dalla vista audit.frontend_communication (link_twprj)
  const TWPROJECT_ISSUE = 'https://mfnotwpro.ddns.net:8443/tw/applications/teamwork/issue/issueList.jsp?CM=GUESS&OBJID=';

  /* ------------------------------------------------------- formattazione */

  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  const fmt = {
    esc: v => (v === null || v === undefined ? '' : String(v)).replace(/[&<>"']/g, c => ESC[c]),
    /** Valore "vuoto"? (null, undefined, stringa vuota/spazi) */
    empty: v => v === null || v === undefined || String(v).trim() === '',
    val: v => (v === null || v === undefined ? '' : String(v).trim()),
    date(v, withTime = false) {
      if (fmt.empty(v)) return '';
      // formati PostgreSQL: "2026-10-20", "2026-10-20 19:42:42.580168+02", "2026-10-20T19:42:42"
      let s = String(v).trim();
      if (/^\d{4}-\d{2}-\d{2}$/.test(s)) s += 'T00:00:00';
      s = s.replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1').replace(/([+-]\d{2})$/, '$1:00');
      const d = new Date(s);
      if (isNaN(d.getTime())) return String(v);
      return withTime
        ? d.toLocaleString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString('it-IT');
    },
    bool(v) {
      if (fmt.empty(v)) return '';
      if ([true, 'true', 't', 1, '1', 'si', 'sì'].includes(v)) return 'Sì';
      if ([false, 'false', 'f', 0, '0', 'no'].includes(v)) return 'No';
      return String(v);
    },
    /** "Presa in carico" -> "presa-in-carico" (per data-status) */
    slug: v => fmt.val(v).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''),
    /** jsonb arrivato come oggetto o come stringa */
    json(v, fallback) {
      if (v === null || v === undefined || v === '') return fallback;
      if (typeof v === 'object') return v;
      try { return JSON.parse(v); } catch (e) { return fallback; }
    },
    join: (parts, sep = ' ') => parts.map(fmt.val).filter(Boolean).join(sep),
  };

  /* ---------------------------------------------------------------- URL */

  const urls = {
    base: BASE,
    lizmap: LIZMAP,
    /** Media di Lizmap (allegati salvati come percorso nel progetto) */
    lizmapMedia: (repository, project, path) =>
      `${LIZMAP}/index.php/view/media/getMedia?repository=${encodeURIComponent(repository)}&project=${encodeURIComponent(project)}&path=${encodeURIComponent(path)}`,
    /**
     * Link diretto a Rekla (post_id + file) -> proxy autenticato di Prosit.
     * Gli altri indirizzi restano invariati.
     */
    document(href) {
      if (fmt.empty(href)) return '';
      try {
        const u = new URL(href, location.origin);
        const post = u.searchParams.get('post_id');
        const file = u.searchParams.get('file');
        if (post && file && u.searchParams.get('dciaction') === 'downloadfile') {
          return `${SERVIZI}/documenti/rekla?post_id=${encodeURIComponent(post)}&file=${encodeURIComponent(file)}`;
        }
        if (!['http:', 'https:'].includes(u.protocol)) return '';
        return u.href;
      } catch (e) { return ''; }
    },
    /** Pagina della issue (todo) in Twproject, come in audit.frontend_communication */
    twprojectIssue: id => fmt.empty(id) ? '' : TWPROJECT_ISSUE + encodeURIComponent(id),
    googleMaps: (lat, lon) => `https://www.google.com/maps?q=${encodeURIComponent(lat)},${encodeURIComponent(lon)}`,
    fileName(href) {
      try {
        const f = new URL(String(href || ''), location.origin).searchParams.get('file');
        if (f) return 'Documento ' + f;
      } catch (e) { /* non e' un URL */ }
      const clean = String(href || '').split('?')[0];
      try { return decodeURIComponent(clean.split('/').pop()) || 'Allegato'; } catch (e) { return clean.split('/').pop() || 'Allegato'; }
    },
  };

  /* ----------------------------------------------------------------- UI */

  const $ = sel => document.querySelector(sel);

  const ui = {
    $,
    /** Una riga etichetta/valore. opts.html = valore gia' sicuro (es. un badge) */
    field(label, value, opts = {}) {
      const v = opts.html ? value : fmt.esc(fmt.val(value));
      if (!opts.always && (opts.html ? !value : fmt.empty(value))) return '';
      return `<div class="p-field"><div class="p-label">${fmt.esc(label)}</div><div class="p-value">${v || '<span class="p-empty">—</span>'}</div></div>`;
    },
    /** Riempie un contenitore con righe [label, valore, opts?] */
    fields(target, rows, emptyText = 'Nessun dato disponibile.') {
      const html = rows.map(r => ui.field(r[0], r[1], r[2] || {})).join('');
      ui.set(target, html || `<p class="p-empty">${fmt.esc(emptyText)}</p>`);
    },
    /** Etichetta colorata (Bootstrap 2: label-*). kind: success | danger | warning | info | neutral */
    badge(text, kind = 'neutral') {
      const cls = { success: 'label-success', danger: 'label-important', warning: 'label-warning', info: 'label-info' }[kind] || '';
      return `<span class="label ${cls}">${fmt.esc(text)}</span>`;
    },
    /** Link sicuro (solo http/https, si apre in una nuova scheda) */
    link(href, text, icon = 'icon-share') {
      if (!href) return fmt.esc(text);
      return `<a href="${fmt.esc(href)}" target="_blank" rel="noopener">${fmt.esc(text)} <i class="${fmt.esc(icon)}" aria-hidden="true"></i></a>`;
    },
    /** items: [{href, label}] */
    files(target, items, countTarget) {
      if (countTarget) ui.text(countTarget, items.length);
      ui.set(target, items.length
        ? items.map(f => `<li><i class="icon-file" aria-hidden="true"></i>${f.href ? ui.link(f.href, f.label || urls.fileName(f.href)) : fmt.esc(f.label)}</li>`).join('')
        : '<li class="p-empty">Nessun allegato</li>');
    },
    /** items: [{date, title, status, body, foot}] gia' ordinati; l'ultimo e' evidenziato */
    timeline(target, items, emptyText = 'Nessun aggiornamento') {
      if (!items.length) { ui.set(target, `<p class="p-empty">${fmt.esc(emptyText)}</p>`); return; }
      ui.set(target, items.map((it, i) => `
        <div class="p-timeline-item${i === items.length - 1 ? ' is-last' : ''}">
          <div class="p-timeline-date">${fmt.esc(it.date || '')}</div>
          <div class="p-timeline-title" data-status="${fmt.esc(fmt.slug(it.status || it.title))}">${fmt.esc(it.title || '')}</div>
          ${fmt.empty(it.body) ? '' : `<p class="p-timeline-body">${fmt.esc(it.body)}</p>`}
          ${fmt.empty(it.foot) ? '' : `<div class="p-timeline-foot">${fmt.esc(it.foot)}</div>`}
        </div>`).join(''));
    },
    set(target, html) { const el = typeof target === 'string' ? $(target) : target; if (el) el.innerHTML = html; },
    text(target, value) { const el = typeof target === 'string' ? $(target) : target; if (el) el.textContent = value == null ? '' : String(value); },
    show(target, visible = true) { const el = typeof target === 'string' ? $(target) : target; if (el) el.hidden = !visible; },
    on(target, ev, fn) { const el = typeof target === 'string' ? $(target) : target; if (el) el.addEventListener(ev, fn); },
  };

  /* ---------------------------------------------------------------- PAGE */

  const page = {
    /** Parametro della query string della scheda (?id=...) */
    param: (name, fallback = null) => new URLSearchParams(location.search).get(name) || fallback,
    ready() {
      // scheda incorporata con ?parti=...: solo quelle sezioni, senza testata ne' titolo
      if (EMBED && PARTI.length) {
        document.querySelectorAll('.p-head').forEach(h => { h.hidden = true; });
        document.querySelectorAll('.p-body > *').forEach(el => {
          const keep = PARTI.includes(el.getAttribute('data-part'));
          el.hidden = !keep;
          if (keep && el.tagName === 'DETAILS') el.open = true;
        });
      }
      ui.show('#app-loader', false); ui.show('#app-error', false); ui.show('#app-content', true);
    },
    fail(err) {
      console.error('[prosit]', err);
      ui.show('#app-loader', false);
      ui.show('#app-content', false);
      const box = $('#app-error');
      if (!box) return;
      if (err && err.status === 401) {
        box.innerHTML = 'Sessione Lizmap assente o scaduta. <a href="' + fmt.esc(LIZMAP + '/') + '" target="_top">Accedi a Lizmap</a> e riapri la scheda.';
      } else if (err && err.status === 403) {
        box.textContent = 'Non hai i permessi per vedere questi dati.';
      } else {
        box.textContent = (err && err.message) || 'Errore imprevisto.';
      }
      box.hidden = false;
    },
    /** Esegue la funzione di caricamento della scheda gestendo loader ed errori */
    run(loader) {
      document.addEventListener('DOMContentLoaded', async () => {
        ui.on('[data-action="print"]', 'click', () => window.print());
        ui.on('[data-action="reload"]', 'click', () => location.reload());
        try { await loader(); page.ready(); } catch (e) { page.fail(e); }
      });
    },
  };

  /*
   * Modalita' incorporata (?embed=1): la scheda sta in un <iframe class="p-frame">
   * dentro un popup creato in QGIS. Stessa origine di Lizmap, quindi la scheda
   * imposta da sola l'altezza dell'iframe, che si adatta al contenuto.
   */
  const EMBED = new URLSearchParams(location.search).get('embed') === '1';
  // ?parti=cronologia,allegati : nella scheda incorporata solo queste sezioni (data-part),
  // senza testata ne' titolo, perche' il resto lo mostra gia' il popup QGIS
  const PARTI = (new URLSearchParams(location.search).get('parti') || '').split(',').map(s => s.trim()).filter(Boolean);
  if (EMBED) {
    document.documentElement.classList.add('p-embed');

    const fit = () => {
      try {
        if (window.frameElement) window.frameElement.style.height = Math.ceil(document.documentElement.scrollHeight) + 'px';
      } catch (e) { /* origine diversa: nessun adattamento */ }
    };
    window.addEventListener('load', fit);
    document.addEventListener('DOMContentLoaded', () => {
      if ('ResizeObserver' in window) new ResizeObserver(fit).observe(document.body);
      document.addEventListener('toggle', fit, true);   // apertura/chiusura delle sezioni <details>
      fit();
    });
  }

  // In stampa apre tutte le sezioni, poi le richiude com'erano
  window.addEventListener('beforeprint', () => {
    document.querySelectorAll('details:not([open])').forEach(d => { d.dataset.pClosed = '1'; d.open = true; });
  });
  window.addEventListener('afterprint', () => {
    document.querySelectorAll('details[data-p-closed]').forEach(d => { d.open = false; delete d.dataset.pClosed; });
  });

  window.Prosit = Object.assign(window.Prosit || {}, { fmt, ui, page, urls, embed: EMBED, version: '2.0.0' });
})();
