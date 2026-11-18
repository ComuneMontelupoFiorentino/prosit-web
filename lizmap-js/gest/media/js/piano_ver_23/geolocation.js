/* Apre il pannello della geolocalizzazione e avvia il rilevamento della posizione
   all'apertura della mappa. Usa l'API di Lizmap 3.9 (mainLizmap.geolocation) invece di
   cliccare sui pulsanti, che compaiono solo dopo il caricamento dei moduli. */
lizMap.events.on({
  uicreated: function () {
    var btn = document.getElementById('button-geolocation');
    if (btn && !btn.parentElement.classList.contains('active')) btn.click();
    var tries = 0;
    (function start() {
      var g = lizMap.mainLizmap && lizMap.mainLizmap.geolocation;
      if (g && typeof g.toggleTracking === 'function') {
        if (!g.isTracking) g.toggleTracking();
      } else if (++tries < 20) {
        setTimeout(start, 250);
      }
    })();
  }
});
