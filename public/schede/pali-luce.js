/* Scheda sostegno illuminazione pubblica — uso: pali-luce.html?id=1001 */
(function () {
  'use strict';
  const { api, fmt, ui, page } = Prosit;
  const id = page.param('id');

  // etichette dei campi del sostegno, nell'ordine di visualizzazione
  const LABELS = {
    num_punti_luce: 'N. punti luce', pl_is_arm: 'Tipologia armatura', pl_is_alt: 'Altezza',
    pl_is_sos: 'Tipologia installazione', pl_is_for: 'Forma', pl_is_mat: 'Materiale',
    pl_is_tilt: 'Inclinazione', pl_is_car: 'Distanza carreggiata', pl_is_bra: 'Lunghezza braccio',
    pl_is_age: 'Età sostegno', pl_is_sts: 'Stato', pl_is_ele: 'Altri elementi', pl_is_aep: 'Elementi sul palo',
  };

  page.run(async () => {
    if (!id) throw new Error('Parametro id mancante nell\'indirizzo della scheda.');
    const s = await api.one('pali-luce', id);
    if (!s) throw new Error('Sostegno non trovato.');
    const interventi = await api.safe(api.list('pali-luce-interventi', { pl_id: id, _order: 'data_int' }), []);

    ui.text('#header-alias', 'PALO ' + (s.pl_id ?? id));
    ui.show('#header-status', false);
    document.title = 'Palo ' + (s.pl_id ?? id);
    ui.fields('#sostegno-fields', Object.entries(LABELS).map(([k, label]) => [label, s[k]]));
    ui.timeline('#timeline', interventi.map(i => ({ date: fmt.date(i.data_int), title: i.ty_int })), 'Nessun intervento/ispezione');
  });
})();
