/* Pannello "Dati meteo in tempo reale" (pagina esterna di meteoproject).
   La pagina esterna e' solo http: se Lizmap e' in https il browser bloccherebbe l'iframe
   (contenuto misto), quindi in quel caso la voce di menu la apre in una nuova scheda. */
(function () {
  var URL_STAZIONE = 'http://stazioni.meteoproject.it/dati/montelupofiorentino/index-vuoto.php';
  lizMap.events.on({
    uicreated: function () {
      if (location.protocol === 'https:' && URL_STAZIONE.indexOf('http:') === 0) {
        lizMap.addDock('web', 'Dati meteo in tempo reale', 'bottomdock', '', 'icon-tint');
        var a = document.getElementById('button-web');
        if (a) a.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          window.open(URL_STAZIONE, '_blank', 'noopener');
        }, true);
        return;
      }
      var frame = document.createElement('iframe');
      frame.src = URL_STAZIONE;
      frame.title = 'Dati meteo in tempo reale';
      frame.style.cssText = 'width:100%;height:1600px;border:0';
      var box = document.createElement('div');
      box.appendChild(frame);
      lizMap.addDock('web', 'Dati meteo in tempo reale', 'bottomdock', box.innerHTML, 'icon-tint');
    }
  });
})();
