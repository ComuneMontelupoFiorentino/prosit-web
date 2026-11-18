/* Voce di menu "Calendario interventi VP": apre l'applicazione di Prosit in una nuova scheda.
   Chi la vede lo decide Prosit (config 'apps' > 'calendario-verde'). */
(window.prositReady = window.prositReady || []).push(function (P) {
  P.menuLink({
    id: 'vp', label: 'Calendario interventi VP', icon: 'icon-calendar', dock: 'bottomdock',
    href: P.url('app/calendario-verde/'), app: 'calendario-verde'
  });
});
