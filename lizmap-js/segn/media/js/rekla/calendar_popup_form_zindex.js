/* Il calendario (jQuery UI datepicker) dei moduli di modifica finiva sotto il pannello:
   regola CSS invece del controllo ogni 300 ms. */
(function () {
  var st = document.createElement('style');
  st.textContent = '#ui-datepicker-div { z-index: 20051 !important; }';
  document.head.appendChild(st);
})();
