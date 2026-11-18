/* All'apertura della mappa mostra il riepilogo delle segnalazioni (app/report-ticket di Prosit);
   chiudendolo apre la tabella attributi dei ticket. Il pulsante "Info segnalazioni" lo riapre.
   - l'utente lo ricava il server dalla sessione (niente ?user= nell'indirizzo);
   - "PROSIT-REKLA-BIT" compare a chi puo' vederlo (config 'apps' > 'report-ticket-bit'),
     lo stesso controllo che fa il server su bit.php. */
(window.prositReady = window.prositReady || []).push(function (P) {
  var REPORTS = {
    'PROSIT-REKLA': { href: P.url('app/report-ticket/'), app: 'report-ticket' },
    'PROSIT-REKLA-BIT': { href: P.url('app/report-ticket/bit.php'), app: 'report-ticket-bit' }
  };
  // tabelle attributi da aprire alla chiusura: la prima presente nel progetto
  var TABELLE = {
    'PROSIT-REKLA': ['tickets_man_off', 'ticketsManEsVP', 'ticketsManEsIP', 'ticketsEnvOff', 'ticketsAllActive', 'list_tickets_full_v', 'tickets_ced_off', 'ticketsManEsTLC'],
    'PROSIT-REKLA-BIT': ['list_tickets_full_v', 'tickets_man_off', 'ticketsManEsVP', 'ticketsManEsIP', 'ticketsEnvOff', 'ticketsAllActive', 'tickets_ced_off']
  };
  var last = 'PROSIT-REKLA';

  function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function src(key) {
    var today = new Date();
    return REPORTS[key].href + '?dateFrom=' + iso(new Date(today.getFullYear() - 1, 0, 1)) + '&dateTo=' + iso(today);
  }

  function openAttributeTable() {
    var btn = document.getElementById('button-attributeLayers');
    if (!btn) return;
    btn.click();
    setTimeout(function () {
      var list = TABELLE[last] || [];
      for (var i = 0; i < list.length; i++) {
        var b = document.querySelector('button.btn-open-attribute-layer[value="' + list[i] + '"]');
        if (b) { b.click(); break; }
      }
    }, 1000);
  }

  function show(keys) {
    var $m = $('#lizmap-modal');
    var $sel = $('<select class="input-large" aria-label="Riepilogo"></select>');
    keys.forEach(function (k) { $sel.append($('<option>').val(k).text(k).prop('selected', k === last)); });
    var $frame = $('<iframe class="p-frame" title="Riepilogo segnalazioni"></iframe>').attr('src', src(last)).css('height', '580px');
    $sel.on('change', function () { last = this.value; $frame.attr('src', src(last)); });
    $m.empty().append(
      $('<div class="modal-header"></div>').append('<button type="button" class="close" data-dismiss="modal" aria-label="Chiudi">&times;</button>', $sel),
      $('<div class="modal-body"></div>').append($frame),
      '<div class="modal-footer"><button type="button" class="btn btn-primary" data-dismiss="modal">OK</button></div>'
    );
    $m.off('hidden.prosit').on('hidden.prosit', function (e) {
      if (e.target === this) setTimeout(openAttributeTable, 500);
    });
    $m.modal('show');
  }

  Promise.all([P.uiReady(), P.me()]).then(function (r) {
    var me = r[1];
    if (!me) return;
    var keys = Object.keys(REPORTS).filter(function (k) { return me.apps[REPORTS[k].app]; });
    if (!keys.length) return;
    show(keys);
    var $infoUser = $('#info-user');
    var $nav = $infoUser.closest('ul.nav');

    if ($nav.length && !$nav.find('#openIframePopupBtn').length) {
      $('<li class="prosit-reports-item"></li>')
        .append(
          $('<a href="#" id="openIframePopupBtn" title="Apri il riepilogo delle segnalazioni"></a>')
            .append('<span aria-hidden="true" style="font-weight:bold; margin-right:5px;">ℹ</span>')
            .append('<span class="text hidden-phone">Info segnalazioni</span>')
            .on('click', function (e) {
              e.preventDefault();
              $('#bottom-dock .btn-bottomdock-clear').trigger('click');
              setTimeout(function () {
                show(keys);
              }, 300);
            })
        )
        .insertBefore($infoUser.closest('li'));
    }

  });
});
