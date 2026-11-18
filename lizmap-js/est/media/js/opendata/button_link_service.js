/* Voce di menu che apre il pannello dei servizi open data di Prosit in una nuova scheda.
   Chi la vede lo decide Prosit (config 'apps' > 'opendata-admin'), lo stesso controllo del server:
   prima i gruppi erano scritti qui e la sessione si leggeva da SessionCheckAPI.php. */
(window.prositReady = window.prositReady || []).push(function (P) {
  P.menuLink({
    id: 'comune-in-chiaro', label: 'Pannello servizi open data', icon: 'icon-share', dock: 'bottomdock',
    href: P.url('app/opendata-admin/'), app: 'opendata-admin'
  });
});
