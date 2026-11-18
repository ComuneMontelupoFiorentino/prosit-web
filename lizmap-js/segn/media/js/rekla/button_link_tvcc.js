/* Voce di menu "Registro controlli TVCC": apre l'applicazione di Prosit in una nuova scheda.
   Chi la vede lo decide Prosit (config 'apps' > 'registro-tvcc'), lo stesso controllo del server. */
(window.prositReady = window.prositReady || []).push(function (P) {
  P.menuLink({
    id: 'tvcc', label: 'Registro controlli TVCC', icon: 'icon-facetime-video', dock: 'bottomdock',
    href: P.url('app/registro-tvcc/'), app: 'registro-tvcc'
  });
});
