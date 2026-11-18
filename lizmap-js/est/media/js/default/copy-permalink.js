/* Clic sull'icona del permalink: copia il link invece di aprirlo.
   Usa l'API moderna degli appunti; su http (senza https) il browser la blocca e si
   ripiega sul vecchio metodo. */
lizMap.events.on({
  uicreated: function () {
    var a = document.querySelector('#tab-share-permalink #permalink');
    var input = document.getElementById('input-share-permalink');
    if (!a || !input) return;
    var $a = $(a).attr('data-original-title', 'Link copiato').tooltip({ trigger: 'manual' });
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var done = function () { $a.tooltip('show'); setTimeout(function () { $a.tooltip('hide'); }, 2000); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(done, function () { input.select(); document.execCommand('copy'); done(); });
      } else {
        input.select(); document.execCommand('copy'); done();
      }
    });
  }
});
