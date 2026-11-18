/* MODELLO DI SCHEDA — uso: nome.html?id=123
 * 1. registrare la risorsa in config/resources.php (o local/resources.local.php)
 * 2. caricare i dati con Prosit.api (one / list / first), in parallelo dove possibile
 * 3. disegnare SOLO con Prosit.ui (fa l'escape dei dati: niente innerHTML a mano)
 */
(function () {
  'use strict';
  const { api, fmt, ui, page, urls } = Prosit;
  const id = page.param('id');

  page.run(async () => {
    if (!id) throw new Error('Parametro id mancante nell\'indirizzo della scheda.');

    // record principale (404 -> null) e dati collegati in parallelo
    const rec = await api.one('NOME-RISORSA', id);
    if (!rec) throw new Error('Elemento non trovato.');
    const [storico, allegati] = await Promise.all([
      api.safe(api.list('NOME-RISORSA-STORICO', { id_padre: id, _order: 'data' }), []),
      api.safe(api.list('NOME-RISORSA-ALLEGATI', { id_padre: id }), []),
    ]);

    ui.$('#ticket-header').dataset.status = fmt.slug(rec.stato);   // colore della testata
    ui.text('#header-alias', rec.codice || `#${id}`);
    ui.text('#header-status', rec.stato);
    ui.text('#titolo', rec.titolo);

    ui.fields('#campi', [
      ['Descrizione', rec.descrizione],
      ['Data', fmt.date(rec.data)],
      ['Attivo', fmt.bool(rec.attivo)],
      ['Stato', ui.badge(rec.stato || 'n.d.', 'neutral'), { html: true }],
    ]);
    ui.timeline('#storico', storico.map(s => ({ date: fmt.date(s.data), title: s.stato, body: s.nota, foot: 'Operatore: ' + (s.utente || 'N/D') })));
    ui.files('#allegati', allegati.map(a => ({ href: urls.document(a.url), label: a.nome })), '#n-allegati');
  });
})();
