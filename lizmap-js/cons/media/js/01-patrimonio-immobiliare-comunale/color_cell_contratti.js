/* Tabella contratti (contr_godim_imm): colora la colonna COLONNA in base alla data di scadenza
   (ultima colonna): verde = in corso, arancione = scaduto da meno di 60 giorni, rosso = oltre.
   Colori comuni di Prosit (toni ok / warn / ko).
   Corretto: le date gg/mm/aaaa venivano lette come mm/gg (03/05/2026 diventava il 5 marzo). */
(window.prositReady = window.prositReady || []).push(function (P) {
  var COLONNA = 7;          // 0 = prima colonna
  var TOLLERANZA = 60;      // giorni

  function parseDate(s) {
    s = (s || '').trim();
    var m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);              // 31/12/2026
    if (m) return new Date(+m[3], +m[2] - 1, +m[1]);
    m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);                          // 2026-12-31
    if (m) return new Date(+m[1], +m[2] - 1, +m[3]);
    return null;
  }

  P.watchTables('#attribute-layer-table-contr_godim_imm', function (table) {
    var oggi = new Date(); oggi.setHours(0, 0, 0, 0);
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      var c = tr.cells;
      if (c.length <= COLONNA) return;
      var fine = parseDate(c[c.length - 1].textContent);
      if (!fine) { P.paintTone(c[COLONNA], ''); return; }
      var giorni = Math.floor((fine - oggi) / 86400000);
      P.paintTone(c[COLONNA], giorni >= 0 ? 'ok' : (giorni >= -TOLLERANZA ? 'warn' : 'ko'));
    });
  });
});
