/* ==========================================================================
   PROSIT — client della API v2 (PostgREST dietro il modulo Lizmap "prositapi")
   --------------------------------------------------------------------------
   Si carica DOPO assets/prosit.js e sostituisce Prosit.api con la v2,
   mantenendo gli stessi metodi, cosi' le schede cambiano pochissimo:

     <script src="../assets/prosit.js"></script>
     <script src="../assets/prosit-api-v2.js"></script>

   Indirizzo della API: /lizmap/api.php/prosit/v2 (stesso sito, cookie di Lizmap).
   Se Lizmap e' pubblicato su un altro percorso:
     <script src="../assets/prosit-api-v2.js" data-api="/gis/api.php/prosit/v2"></script>

   Filtri come nella v1 (tradotti nella sintassi PostgREST):
     { stato_segn: 'Assegnata' }            -> stato_segn=eq.Assegnata
     { stato_segn__not: 'Chiusa' }          -> stato_segn=isdistinct.Chiusa
     { seg_id__in: '1,3' }                  -> seg_id=in.("1","3")
     { seg_o_desc__like: 'ramo' }           -> seg_o_desc=ilike.*ramo*
     { latitude__null: true }               -> latitude=is.null
     { _select: 'a,b', _order: '-data,id', _limit: 50, _offset: 0, _count: 1 }
   Oppure sintassi PostgREST diretta passando il valore con l'operatore:
     { data_int: 'gte.2026-01-01' } NON e' interpretato: usare { data_int__gte: '2026-01-01' }
   Errori: Prosit.ApiError con .status e .message (dal campo "detail" di problem+json).
   ========================================================================== */
(function () {
  'use strict';

  const script = document.currentScript;
  const API = ((script && script.dataset.api) || '/lizmap/api.php/prosit/v2').replace(/\/$/, '');

  class ApiError extends Error {
    constructor(status, message, body) {
      super(message);
      this.name = 'ApiError';
      this.status = status;
      this.body = body || null;
    }
  }

  const OPS = { not: 'isdistinct', ne: 'isdistinct', gt: 'gt', gte: 'gte', lt: 'lt', lte: 'lte' };

  /** Valore per in.(...): sempre tra virgolette, con escape */
  const quota = v => '"' + String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';

  /** Oggetto filtri stile v1 -> URLSearchParams PostgREST */
  function parametri(query) {
    const p = new URLSearchParams();
    let conta = false;
    Object.entries(query || {}).forEach(([k, v]) => {
      if (v === undefined || v === null || v === '') return;
      if (k === '_select') { p.set('select', v); return; }
      if (k === '_limit') { p.set('limit', String(v)); return; }
      if (k === '_offset') { p.set('offset', String(v)); return; }
      if (k === '_count') { conta = !!v && v !== '0'; return; }
      if (k === '_order') {
        p.set('order', String(v).split(',').map(s => s.trim()).filter(Boolean)
          .map(s => s.startsWith('-') ? s.slice(1) + '.desc.nullslast' : s.replace(/^\+/, '') + '.asc').join(','));
        return;
      }
      const m = k.match(/^(.+)__(not|ne|gt|gte|lt|lte|like|in|notin|null)$/);
      if (!m) { p.append(k, 'eq.' + v); return; }
      const [, col, op] = m;
      if (op === 'like') {
        const s = String(v);
        p.append(col, 'ilike.' + (s.includes('*') ? s : '*' + s + '*'));
      } else if (op === 'in' || op === 'notin') {
        const lista = String(v).split(',').map(x => x.trim()).filter(Boolean).map(quota).join(',');
        p.append(col, (op === 'in' ? 'in.(' : 'not.in.(') + lista + ')');
      } else if (op === 'null') {
        p.append(col, ['1', 'true', 'si', 'yes', true].includes(v) ? 'is.null' : 'not.is.null');
      } else {
        p.append(col, OPS[op] + '.' + v);
      }
    });
    return { params: p, conta };
  }

  async function richiesta(metodo, percorso, { params, corpo, prefer, accept } = {}) {
    const url = new URL(API + '/' + String(percorso).replace(/^\/+/, ''), location.origin);
    if (params) params.forEach((v, k) => url.searchParams.append(k, v));
    const h = { Accept: accept || 'application/json' };
    if (prefer && prefer.length) h.Prefer = prefer.join(', ');
    const opt = { method: metodo, credentials: 'same-origin', headers: h };
    if (corpo !== undefined) {
      h['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(corpo);
    }
    let res;
    try {
      res = await fetch(url, opt);
    } catch (e) {
      throw new ApiError(0, 'Server non raggiungibile. Controlla la connessione e riprova.');
    }
    const testo = await res.text();
    let json = null;
    try { json = testo ? JSON.parse(testo) : null; } catch (e) { /* non JSON */ }
    if (!res.ok) {
      const msg = (json && (json.detail || json.message || json.title)) || ('Errore HTTP ' + res.status);
      throw new ApiError(res.status, msg, json);
    }
    return { json, res };
  }

  let risorseCache = null;
  /** Chiave (colonna identificativa) di una risorsa, da GET /risorse */
  async function chiave(res) {
    risorseCache = risorseCache || richiesta('GET', 'risorse').then(r => {
      const m = {};
      (r.json || []).forEach(x => { m[x.nome] = x.chiave; });
      return m;
    });
    const k = (await risorseCache)[res];
    if (!k) throw new ApiError(400, `La risorsa ${res} non ha una chiave: usare list() con un filtro.`);
    return k;
  }

  const api = {
    base: API,
    versione: 2,
    request: richiesta,

    /** Elenco -> array */
    list: async (res, query) => (await api.listMeta(res, query)).data,

    /** Elenco con meta {count, total?} */
    async listMeta(res, query) {
      const { params, conta } = parametri(query);
      const { json, res: r } = await richiesta('GET', res, { params, prefer: conta ? ['count=exact'] : [] });
      const data = Array.isArray(json) ? json : [];
      const meta = { count: data.length };
      const cr = r.headers.get('Content-Range');      // "0-49/1234"
      if (cr && cr.includes('/')) {
        const tot = cr.split('/')[1];
        if (tot !== '*') meta.total = parseInt(tot, 10);
      }
      return { data, meta };
    },

    /** Un record per chiave, null se non esiste */
    async one(res, id) {
      const k = await chiave(res);
      const righe = await api.list(res, { [k]: id, _limit: 1 });
      return righe[0] || null;
    },

    /** Primo record che soddisfa i filtri, o null */
    first: async (res, query) => (await api.list(res, Object.assign({ _limit: 1 }, query)))[0] || null,

    /** GET generico (es. 'auth/me', 'rpc/io') */
    get: async (percorso, query) => (await richiesta('GET', percorso, { params: query ? new URLSearchParams(query) : null })).json,

    /** Inserimento (oggetto o lista). ritorna:true restituisce le righe (serve il permesso di lettura) */
    async create(res, corpo, { ritorna = false } = {}) {
      const { json } = await richiesta('POST', res, { corpo, prefer: [ritorna ? 'return=representation' : 'return=minimal'] });
      return ritorna ? (Array.isArray(corpo) ? json : (json || [])[0] || null) : null;
    },

    async update(res, id, corpo, { ritorna = false } = {}) {
      const k = await chiave(res);
      const { json } = await richiesta('PATCH', res, {
        params: new URLSearchParams({ [k]: 'eq.' + id }), corpo,
        prefer: [ritorna ? 'return=representation' : 'return=minimal'],
      });
      return ritorna ? (json || [])[0] || null : null;
    },

    async remove(res, id) {
      const k = await chiave(res);
      await richiesta('DELETE', res, { params: new URLSearchParams({ [k]: 'eq.' + id }) });
      return { deleted: id };
    },

    /**
     * Funzione: Prosit.api.rpc('segnalazione-mail', { seg_id: 12 })
     * Le funzioni di sola lettura si chiamano in GET: rpc('calendario-interventi', {dal, al}, 'GET')
     */
    async rpc(nome, args, metodo = 'POST') {
      if (metodo === 'GET') {
        return (await richiesta('GET', 'rpc/' + nome, { params: new URLSearchParams(args || {}) })).json;
      }
      return (await richiesta('POST', 'rpc/' + nome, { corpo: args || {} })).json;
    },

    /** POST generico: compatibilita' con la v1 */
    post: async (percorso, corpo) => (await richiesta('POST', percorso, { corpo: corpo || {} })).json,

    /** Utente corrente (memorizzato per la pagina), con anche la forma della v1 */
    me: (() => {
      let p = null;
      return () => (p = p || api.get('auth/me').then(u => Object.assign({}, u, {
        authenticated: true,
        auth_type: u.tipo_accesso,
        user: { login: u.login, email: u.email, name: u.nome, organization: u.organizzazione },
        groups: { ids: u.gruppi.id, names: u.gruppi.nome },
      })));
    })(),

    /** Come la promessa data, ma in caso di errore restituisce fallback */
    safe: (promise, fallback) => promise.catch(e => { console.warn('[prosit]', e.message); return fallback; }),

    /** Esposto per i test */
    _parametri: parametri,
  };

  window.Prosit = Object.assign(window.Prosit || {}, { api, ApiError });
})();
