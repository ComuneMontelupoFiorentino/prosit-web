/* Scheda segnalazione (Rekla / Twproject / Prosit) — uso: disservizio.html?seg_id=123 */
(function () {
  'use strict';
  const { api, fmt, ui, page, urls } = Prosit;
  const segId = page.param('seg_id');

  /** Allegati: la fonte dipende da dove nasce la segnalazione */
  async function loadDocs(t) {
    if (t.isfrom === 'rekla' && !fmt.empty(t.seg_i_rek_n)) {
      const rows = await api.safe(api.list('segnalazioni-allegati-rekla', { seg_i_rek_n: t.seg_i_rek_n }), []);
      return rows.map(d => ({ href: urls.document(d.path_doc || d.url), label: urls.fileName(d.path_doc || d.url) }));
    }
    if (t.isfrom === 'twproject') {
      const rows = await api.safe(api.list('segnalazioni-allegati-twproject', { id_ticket: segId }), []);
      return rows.map(d => ({ href: urls.document(d.path_doc || d.url), label: urls.fileName(d.path_doc || d.url) }));
    }
    if (t.isfrom === 'prosit' && !fmt.empty(t.seg_o_img)) {
      return [{ href: urls.lizmapMedia('segn', 'rekla', t.seg_o_img), label: urls.fileName(t.seg_o_img) }];
    }
    return [];
  }

  /** riga di audit.todo_queue nella stessa forma delle righe di audit.frontend_communication */
  function todoComm(x) {
    const st = { pending: 'in coda', sended: 'inviato' }[x.status] || 'errore';
    return {
      type: 'TODO', status_desc: st, subject: x.subject,
      timing: fmt.date(x.status === 'sended' ? x.sent_at : x.created_at),
      link_twprj: urls.twprojectIssue(x.id_todo_twprj),
    };
  }

  page.run(async () => {
    if (!segId) throw new Error('Parametro seg_id mancante nell\'indirizzo della scheda.');

    const [me, t] = await Promise.all([api.me(), api.one('segnalazioni', segId)]);
    if (!t) throw new Error('Segnalazione non trovata.');

    const [assegnatario, timeline, commsView, todos, docs, loc] = await Promise.all([
      t.man_es ? api.safe(api.first('assegnatari', { man_es: t.man_es }), null) : null,
      api.safe(api.list('segnalazioni-cronologia', { id_prosit: segId, _order: 'action_date' }), []),
      api.safe(api.list('segnalazioni-comunicazioni', { id_ticket: segId }), []),
      api.safe(api.list('todo', { id_segn: segId }), null),
      loadDocs(t),
      t.ident ? api.safe(api.first('segnalazioni-coordinate', { ident: t.ident }), null) : null,
    ]);

    // Testata
    ui.$('#ticket-header').dataset.status = fmt.slug(t.stato_segn);
    ui.text('#header-alias', t.seg_i_rek_alias || `Ticket #${segId}`);
    ui.text('#header-status', t.stato_segn || 'Stato non identificato');
    ui.text('#seg-title', t.seg_t_mat || 'Nessun titolo');
    ui.text('#update-date', fmt.date(t.update_date) || '—');
    document.title = `Segnalazione ${t.seg_i_rek_alias || segId}`;

    ui.fields('#richiesta-fields', [
      ['Oggetto', t.seg_o_ogg],
      ['Descrizione', t.seg_o_desc],
      ['Data creazione', fmt.date(t.seg_s_ac_d)],
      ['Segnalante', fmt.join([t.seg_i_nc, t.seg_i_cog])],
      ['Note URP', t.seg_o_n],
      ['Assegnatario', assegnatario ? assegnatario.alias_name : (t.man_es || 'Non assegnato')],
    ]);

    ui.fields('#loc-fields', [
      ['Via', fmt.join([t.seg_l_ind, t.seg_l_civ])],
      ['Categoria', loc ? fmt.join([loc.tema, loc.oggetto_spec], ' - ') : ''],
      ['ID oggetto', t.ident],
    ], 'Dati di localizzazione non disponibili.');
    if (!fmt.empty(t.latitude) && !fmt.empty(t.longitude)) {
      ui.$('#maps-link').href = urls.googleMaps(t.latitude, t.longitude);
      ui.show('#maps-link');
    }

    ui.files('#attach-list', docs, '#attach-count');

    ui.timeline('#timeline', timeline.map(s => ({
      date: fmt.date(s.action_date), title: s.status, body: s.action_description, foot: 'Operatore: ' + (s.action_user || 'N/D'),
    })));

    // La vista delle comunicazioni lega i todo solo per numero (id_segn): un ticket BIT con lo
    // stesso numero comparirebbe anche qui. Se la risorsa "todo" e' disponibile si usano i todo
    // di Rekla (insert_by_app <> 'rekla-bit'); altrimenti si resta sulla vista come prima.
    const comms = todos === null ? commsView : [
      ...commsView.filter(c => c.type !== 'TODO'),
      ...todos.filter(x => x.insert_by_app !== 'rekla-bit').map(todoComm),
    ];

    ui.text('#comm-count', comms.length);
    ui.set('#comm-list', comms.length ? comms.map(c => `
      <details class="p-section">
        <summary>${fmt.esc(c.type || 'Email')} ${ui.badge(c.status_desc || 'Inviata')}</summary>
        <div class="p-message">
          <strong>Oggetto:</strong> ${fmt.esc(c.subject || 'Nessuno')}
          ${c.type === 'TODO' && urls.document(c.link_twprj) ? '<br>' + ui.link(urls.document(c.link_twprj), 'Visualizza ToDo') : ''}
          <small>Data: ${fmt.esc(c.timing || '')}</small>
        </div>
      </details>`).join('') : '<p class="p-empty">Nessuna comunicazione</p>');

    // Invio mail: non per chi e' nel gruppo assegnatario (la regola e' verificata anche dal server)
    const myGroups = [...(me.groups.ids || []), ...(me.groups.names || [])].map(g => g.toLowerCase());
    const assignees = (t.man_es || '').split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
    if (assignees.length && !assignees.some(g => myGroups.includes(g))) {
      const btn = ui.$('#send-mail-btn');
      ui.show(btn);
      btn.addEventListener('click', async () => {
        if (!confirm('Inviare il ticket via mail? Arrivera\' all\'assegnatario e a te in copia.')) return;
        btn.disabled = true;
        try {
          const r = await api.rpc('segnalazione-mail', { seg_id: Number(segId) });
          alert(`Mail messa in coda (${r.recipients} destinatari).`);
        } catch (e) {
          alert('Mail non inviata: ' + e.message);
        } finally {
          btn.disabled = false;
        }
      });
    }
  });
})();
