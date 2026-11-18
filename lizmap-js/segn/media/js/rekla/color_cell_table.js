/* Colora la colonna dello stato (4a colonna) delle tabelle attributi con i colori comuni
   delle schede (stessi di p-head/p-timeline: un solo posto in cui cambiarli,
   prosit-components.css). "(Complessa)" viene ignorato. */
(window.prositReady = window.prositReady || []).push(function (P) {
  var COLONNA_STATO = 3;   // 0 = prima colonna
  P.watchTables('table.dataTable', function (table) {
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      var td = tr.cells[COLONNA_STATO];
      if (td && tr.cells.length > COLONNA_STATO) P.paintStatus(td);
    });
  });
});
