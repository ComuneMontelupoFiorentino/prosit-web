/* Scheda manomissione suolo pubblico — uso: manomissioni.html?id=19 */
(function () {
  'use strict';
  const { api, fmt, ui, page, urls } = Prosit;
  const id = page.param('id');

  // Allegati: in DB c'e' il percorso nel progetto Lizmap, servito dal proxy media di Lizmap
  const LIZMAP_REPOSITORY = 'gest';
  const LIZMAP_PROJECT_FALLBACK = '14-attivita-su-territorio';
  const TWPROJECT_ISSUE = 'https://mfnotwpro.ddns.net:8443/tw/applications/teamwork/issue/issueList.jsp?CM=GUESS&OBJID=';

  function mediaUrl(rawPath) {
    if (fmt.empty(rawPath)) return '';
    const m = String(rawPath).match(/media\/img\/([^/]+)\//);
    return urls.lizmapMedia(LIZMAP_REPOSITORY, m ? m[1] : LIZMAP_PROJECT_FALLBACK, rawPath);
  }

  /** Stato lavori in base alle date di inizio e fine */
  function badgeLavori(start, finish) {
    const oggi = new Date(); oggi.setHours(0, 0, 0, 0);
    const s = start ? new Date(start + 'T00:00:00') : null;
    const f = finish ? new Date(finish + 'T00:00:00') : null;
    if (s && s > oggi) return ui.badge('Programmata', 'neutral');
    if (s && f && s <= oggi && f >= oggi) return ui.badge('In corso', 'warning');
    if (s && f && f < oggi) return ui.badge('Eseguita', 'success');
    return ui.badge('Non definito', 'danger');
  }

  page.run(async () => {
    if (!id) throw new Error('Parametro id mancante nell\'indirizzo della scheda.');
    const m = await api.one('manomissioni', id);
    if (!m) throw new Error('Manomissione non trovata.');
    const allegati = await api.safe(api.list('manomissioni-allegati', { id_man: id }), []);

    // Testata
    ui.$('#ticket-header').dataset.status = fmt.slug(m.stato);
    ui.set('#header-alias', '#' + fmt.esc(m.uuid_request || `Manomissione ${id}`) + ' ' + badgeLavori(m.data_start, m.data_finish));
    ui.text('#header-status', m.stato);
    ui.text('#seg-title', m.descr_int || m.ubi || 'Nessun titolo');
    ui.text('#update-date', fmt.date(m.last_update) || '—');
    document.title = 'Manomissione ' + (m.uuid_request || id);

    ui.fields('#accettazione-fields', [
      ['Canale ricezione', m.insert_canal],
      ['Data ricezione', fmt.date(m.data_insert)],
    ], 'Dati di accettazione non disponibili.');

    ui.fields('#request-fields', [
      ['Richiedente', fmt.join([m.external_contacts_name, m.external_contacts_surname])],
      ['Domiciliato a', m.sp_address],
      ['Per conto di', m.ente],
      ['In qualità di', m.external_contacts_pos],
      ['Sede legale', fmt.join([m.legal_address_street, m.legal_address_city], ', ')],
      ['Telefono', m.external_contacts_pnumber],
      ['Mail', m.external_contacts_mail],
      ['PEC', m.external_contacts_pec],
      ['Gestore/Azienda erogatrice di pubblici servizi', fmt.bool(m.is_public_utility)],
      ['Tipologia gestore', m.tipologia_gestore],
      ['N. protocollo', m.protocollo_num],
      ['Stato', m.stato],
    ], 'Dati della richiesta non disponibili.');

    // Impresa: solo se i lavori sono eseguiti da un'impresa o ci sono dati dell'impresa
    const imp = fmt.json(m.external_executer_work, {}) || {};
    const haImpresa = Object.values(imp).some(v => !fmt.empty(v));
    if (/impresa/i.test(fmt.val(m.execute_work_by)) || haImpresa) {
      ui.fields('#impresa-fields', [
        ['Ragione sociale', imp.ragione],
        ['Sede', fmt.join([imp.via4, imp.sede1], ', ')],
        ['Telefono', imp.telazienda],
        ['E-mail', imp.emailazienda],
        ['PEC', imp.pec1],
        ["Responsabile dell'impresa", imp.resp_impresa],
      ], 'Dati impresa non disponibili.');
      ui.show('#impresa-details');
    }

    ui.fields('#intervento-fields', [
      ['Descrizione', m.descr_int],
      ['Inizio lavori', fmt.date(m.data_start)],
      ['Fine lavori', fmt.date(m.data_finish)],
      ['Lavori eseguiti da', m.execute_work_by],
      ['Garanzia prestata', m.garanzia_tipo],
    ], 'Dati intervento non disponibili.');

    const fig = fmt.json(m.figure_responsabili, {}) || {};
    ui.fields('#figure-fields', [
      ['Responsabile della commessa', fig.resp_commessa],
      ['Direttore dei lavori', fig.direttore_lavori],
      ['Responsabile di cantiere', fig.responsabile],
      ['Collaudatore / Tecnico CRE', fig.collaudatore],
      ['Coordinatore della sicurezza in esecuzione (CSE)', fig.coordinatore_cse],
      ['Responsabile sorveglianza provvisoria', fig.responsabile_sorveglianza],
    ], 'Figure responsabili non disponibili.');

    ui.files('#attach-list', allegati.map(a => ({
      href: mediaUrl(a.url_path),
      label: fmt.empty(a.title_attach) ? urls.fileName(a.url_path) : a.title_attach,
    })), '#attach-count');

    // Controlli: ultimo elemento di check_result = controllo corrente
    const eseguito = fmt.bool(m.checked) === 'Sì';
    const checks = fmt.json(m.check_result, []);
    const list = Array.isArray(checks) ? checks : [];
    const last = list.length ? list[list.length - 1] : null;
    ui.fields('#check-list', [
      ['Stato controllo', eseguito && list.length ? ui.badge('Controllato', 'success') : ui.badge('Non controllato', 'danger'), { html: true }],
      ['Sopralluogo', eseguito ? 'Eseguito' : 'Non eseguito', { always: true }],
      ['Sopralluogo eseguito da', (last && last.author) || 'Nessun valore', { always: true }],
      ['Esito sopralluogo', (last && last.comment) || 'Nessun commento inserito', { always: true }],
      ['Collegamento Twproject', m.id_issue_twprj ? ui.link(TWPROJECT_ISSUE + encodeURIComponent(m.id_issue_twprj), '#' + m.id_issue_twprj) : '', { html: true }],
    ], 'Dati controlli non disponibili.');
  });
})();
