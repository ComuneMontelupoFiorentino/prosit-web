/* Scheda ticket edifici (BIT) — uso: disservizio-bit.html?seg_id=10 */
(function () {
  'use strict';
  const { api, fmt, ui, page, urls } = Prosit;
  const id = page.param('seg_id') || page.param('id');

  page.run(async () => {
    if (!id) throw new Error('Parametro seg_id mancante nell\'indirizzo della scheda.');
    const t = await api.one('bit-ticket', id);
    if (!t) throw new Error('Segnalazione non trovata.');
    const [timeline, attachments, todos] = await Promise.all([
      api.safe(api.list('bit-cronologia', { id_ticket: id, _order: 'action_date' }), []),
      api.safe(api.list('bit-allegati', { id_ticket: id }), []),
      api.safe(api.list('todo', { id_segn: id, insert_by_app: 'rekla-bit' }), []),
    ]);

    ui.$('#ticket-header').dataset.status = fmt.slug(t.detailed_name);
    ui.text('#header-alias', t.id_alias || `Ticket #${id}`);
    ui.text('#header-status', t.detailed_name || '');
    ui.text('#main-org-name', t.organization_name || '');
    document.title = `Ticket ${t.id_alias || id}`;

    if (!fmt.empty(t.aborted)) {
      ui.text('#aborted-text', t.aborted);
      ui.show('#aborted-section');
    }
    ui.fields('#richiesta-fields', [
      ['Oggetto', t.issue_subject],
      ['Descrizione', t.issue_description],
      ['Data creazione', fmt.date(t.entry_date)],
      ['Segnalante', t.full_name],
    ]);
    ui.fields('#loc-fields', [['Immobile', t.organization_name]]);
    ui.files('#attach-list', attachments.filter(a => a.doc_url).map(a => ({ href: urls.document(a.doc_url), label: a.doc_name || urls.fileName(a.doc_url) })), '#attach-count');
    // Todo Twproject aperti per il ticket (stesso blocco della scheda Rekla)
    ui.text('#comm-count', todos.length);
    ui.set('#comm-list', todos.length ? todos.map(x => {
      const st = { pending: 'in coda', sended: 'inviato' }[x.status] || 'errore';
      const link = urls.twprojectIssue(x.id_todo_twprj);
      return `
      <details class="p-section">
        <summary>TODO ${ui.badge(st)}</summary>
        <div class="p-message">
          <strong>Oggetto:</strong> ${fmt.esc(x.subject || 'Nessuno')}
          ${link ? '<br>' + ui.link(link, 'Visualizza ToDo') : ''}
          <small>Data: ${fmt.esc(fmt.date(x.status === 'sended' ? x.sent_at : x.created_at) || '')}</small>
        </div>
      </details>`;
    }).join('') : '<p class="p-empty">Nessun ToDo</p>');

    ui.timeline('#timeline', timeline.map(r => ({
      date: fmt.date(r.action_date), title: r.action_description, status: r.status || r.action_description,
      body: r.action_note, foot: 'Operatore: ' + (r.action_user || 'N/D'),
    })), 'Nessun aggiornamento presente.');
  });
})();
