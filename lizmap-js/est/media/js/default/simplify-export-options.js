/* Riduce i formati di esportazione: strumento di selezione e tabella attributi.
   In Lizmap 3.9 i menu vengono ridisegnati: si sorvegliano invece di pulirli una volta sola.
   Le voci aggiunte da Prosit (es. "csv - ainop") restano. */
(function () {
  var SELEZIONE = ['XLSX', 'ODS', 'CSV'];
  var TABELLA = ['XLSX', 'ODS', 'CSV', 'SHP', 'GPKG', 'KML'];

  function pulisci(root) {
    if (!root.querySelectorAll) return;
    root.querySelectorAll('ul.selectiontool-export-formats > li').forEach(function (li) {
      if (SELEZIONE.indexOf(li.textContent.trim().toUpperCase()) === -1) li.remove();
    });
    root.querySelectorAll('div.export-formats ul.dropdown-menu > li').forEach(function (li) {
      if (li.className.indexOf('p-exp-') !== -1) return;
      if (TABELLA.indexOf(li.textContent.trim().toUpperCase()) === -1) li.remove();
    });
  }

  lizMap.events.on({
    uicreated: function () {
      pulisci(document);
      new MutationObserver(function (ms) {
        ms.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) pulisci(n.parentNode || n); }); });
      }).observe(document.body, { childList: true, subtree: true });
    }
  });
})();
