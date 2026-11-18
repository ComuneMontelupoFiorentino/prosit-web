/* ==========================================================================
   Todo Twproject per i sopralluoghi delle manomissioni (Prosit 1.9)
   --------------------------------------------------------------------------
   Prima: all'apertura della mappa partiva il controllo di TUTTI i todo, una issue
   alla volta, dietro un pannello "Controllo Todo in corso..." che bloccava la mappa
   anche 5-6 minuti. Ora:
     - il controllo gira in sottofondo: la mappa si usa subito;
     - il server riusa l'ultimo controllo se e' di meno di 15 minuti (web_config.ini
       [app] sync_check_min_minutes) e interroga Twproject in parallelo;
     - se un altro utente ha gia' un controllo in corso non ne parte un secondo;
     - un messaggio compare solo se qualcosa e' cambiato (todo chiusi, allegati nuovi)
       o se qualcosa non ha funzionato. Clic sull'indicatore = ricontrolla subito.
   Indirizzi e sessione da Prosit (niente IP, niente SessionCheckAPI.php).
   ========================================================================== */
(window.prositReady = window.prositReady || []).push(function (P) {
  'use strict';

  // ---- configurazione --------------------------------------------------------
  // Gruppi che vedono il bottone. Il server ha la sua lista (web_config.ini [auth]
  // allowed_groups): devono coincidere, altrimenti chi e' solo qui riceve 403.
  var GRUPPI = ['admins', 'ced', 'vigili'];
  var TASK_ID = 4140;
  var TAGS = 'Sopralluogo, Manomissioni';
  var SYNC_URL = P.url('hooks/sopralluoghi/sync_manomissioni_status.php');
  var OPEN_URL = P.url('hooks/sopralluoghi/open_todos.php');
  // MODALITA' PROVA: aggiungere  &todo_prova=1  all'indirizzo della mappa.
  // Legge Twproject e il DB come sempre, ma non crea todo, non registra chiusure,
  // non scarica allegati e non usa la cache: mostra solo cosa farebbe.
  var PROVA = /[?&]todo_prova=1(&|$)/.test(location.search);

  P.inGroup(GRUPPI).then(function (ok) {
    if (!ok) return;
    P.uiReady().then(function () {
      if (document.getElementById('btnControllaTodo')) return;
      creaBottoni();
      controlla(false);
    });
  });

  // ---- interfaccia -------------------------------------------------------------
  var $stato = null;

  function creaBottoni() {
    var etichetta = 'Apri Todo' + (PROVA ? ' (PROVA)' : '');
    $stato = $('<span id="prosit-todo-stato" class="label" title="Clic per ricontrollare subito">')
      .css({ cursor: 'pointer', verticalAlign: 'middle' });

    // Barra in alto di Lizmap (<div class="pull-right"><ul class="nav">), prima dell'utente:
    // come "Info segnalazioni"
    var $infoUser = $('#info-user');
    var $nav = $infoUser.closest('ul.nav');
    var $btn;
    if ($nav.length) {
      $btn = $('<a href="#" id="btnControllaTodo" title="Crea i Todo dei sopralluoghi su Twproject"></a>')
        .append('<span aria-hidden="true" style="font-weight:bold; margin-right:5px;">✓</span>')
        .append($('<span class="text hidden-phone">').text(etichetta));
      if (PROVA) $btn.css({ color: '#f89406' });
      var $liBtn = $('<li class="prosit-todo-item"></li>').append($btn);
      var $liStato = $('<li class="prosit-todo-stato"></li>')
        .append($('<a href="#" title="Clic per ricontrollare subito"></a>').css({ paddingLeft: '4px' }).append($stato));
      $liBtn.insertBefore($infoUser.closest('li'));
      $liStato.insertBefore($infoUser.closest('li'));
      $liStato.find('a').on('click', function (e) { e.preventDefault(); controlla(true); });
    } else {
      // ripiego: barra non trovata (es. mappa incorporata) -> bottone sopra la mappa
      $btn = $('<button type="button" id="btnControllaTodo" class="btn ' + (PROVA ? 'btn-warning' : 'btn-primary') + '">')
        .append('<i class="icon-check icon-white"></i> ').append(document.createTextNode(etichetta));
      $('<div id="prosit-todo-box">').css({ position: 'absolute', top: '10px', right: '150px', zIndex: 10000 })
        .append($btn, $stato.css({ marginLeft: '6px' })).appendTo('body');
      $stato.on('click', function () { controlla(true); });
    }

    $btn.on('click', function (e) {
      e.preventDefault();
      if (!PROVA && !confirm('Sei sicuro di voler creare i Todo per il task ' + TASK_ID + '?\n\nTag:\n' + TAGS)) return;
      apriTodo();
    });
  }

  function statoTesto(testo, tipo, titolo) {
    if (!$stato) return;
    $stato.removeClass('label-info label-success label-warning label-important')
      .addClass(tipo ? 'label-' + tipo : '').text(testo);
    if (titolo) $stato.attr('title', titolo + ' - clic per ricontrollare');
  }

  function avviso(testo, tipo) {
    // messaggio non bloccante di Lizmap; se non c'e', nella console
    if (window.lizMap && typeof lizMap.addMessage === 'function') {
      lizMap.addMessage(testo, tipo || 'info', true);
    } else {
      console.log('[todo]', testo);
    }
  }

  // ---- controllo stato dei todo (in sottofondo) ----------------------------------
  var inCorso = false;

  function controlla(forza) {
    if (inCorso) return;
    inCorso = true;
    statoTesto('Controllo Todo...', 'info');
    fetch(SYNC_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(Object.assign(forza ? { force: 1 } : {}, PROVA ? { dryRun: 1 } : {}))
    })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) { return { r: r, d: d }; });
      })
      .then(function (x) {
        var d = x.d || {};
        if (!x.r.ok) {
          // 401/403: niente finestre a ogni apertura, solo l'indicatore
          var msg = x.r.status === 401 ? 'Sessione scaduta'
            : x.r.status === 403 ? 'Non autorizzato al controllo'
              : (d.message || ('Errore ' + x.r.status));
          statoTesto('Todo: errore', 'important', msg);
          console.warn('[todo] controllo:', x.r.status, d);
          return;
        }
        if (d.running) {
          statoTesto('Controllo in corso', 'info', d.message);
          setTimeout(function () { inCorso = false; controlla(false); }, 30000);
          return;
        }
        var quando = d.checkedAt ? new Date(d.checkedAt).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }) : '';
        var dettaglio = 'Aperti: ' + (d.stillOpen ?? '-') + ', chiusi ora: ' + (d.checked ?? 0)
          + ', falliti: ' + (d.failed ?? 0) + (quando ? ' (controllo delle ' + quando + ')' : '');
        statoTesto('Todo aperti: ' + (d.stillOpen ?? '-'), d.failed ? 'warning' : 'success', dettaglio);
        console.log('[todo]', d.cached ? 'risultato recente riusato' : 'controllo completo in ' + d.seconds + ' s', d);

        if (PROVA) {
          popup('Controllo Todo (PROVA)', d.message || '', d.log || []);
          return;
        }
        // messaggio solo per un controllo nuovo che ha trovato qualcosa
        if (!d.cached) {
          var parti = [];
          if (d.checked) parti.push(d.checked + ' todo chiusi e registrati');
          if (d.attachmentsImported) parti.push(d.attachmentsImported + ' allegati nuovi importati');
          if (parti.length) avviso('Todo sopralluoghi: ' + parti.join(', ') + '.', 'info');
          if (d.failed) avviso('Todo sopralluoghi: ' + d.failed + ' issue non leggibili da Twproject (dettagli nella console).', 'error');
        }
      })
      .catch(function (e) {
        statoTesto('Todo: non raggiungibile', 'important', String(e));
        console.error('[todo] controllo:', e);
      })
      .then(function () { inCorso = false; });
  }

  // ---- creazione todo (azione dell'utente: qui il pannello di attesa ha senso) ---
  function apriTodo() {
    mostraLoader(PROVA ? 'Simulazione creazione Todo...' : 'Creazione Todo in corso...');
    fetch(OPEN_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(Object.assign({ taskId: TASK_ID, tags: TAGS }, PROVA ? { dryRun: 1 } : {}))
    })
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (d) { return { r: r, d: d }; });
      })
      .then(function (x) {
        var d = x.d;
        if (!d) return popup('Errore', 'Il server ha restituito una risposta non valida.');
        if (!x.r.ok) {
          return popup(x.r.status === 401 ? 'Sessione scaduta' : x.r.status === 403 ? 'Accesso negato' : 'Errore',
            x.r.status === 401 ? 'La sessione è scaduta. Effettua nuovamente il login.'
              : x.r.status === 403 ? 'Non hai i privilegi necessari per creare i Todo.'
                : (d.message || 'Errore durante la creazione dei Todo.'));
        }
        if (d.alert) {
          alert(d.message || 'Sono già presenti più di venti Todo aperti.');
          return;
        }
        var righe = [].concat(d.log || []);
        popup(d.dryRun ? 'Todo (PROVA, nessuna modifica)' : 'Todo creati', d.message || 'Todo creati correttamente.', righe);
        if (!d.dryRun) controlla(true); // aggiorna l'indicatore
      })
      .catch(function (e) {
        console.error('[todo] creazione:', e);
        popup('Errore', 'Impossibile contattare il servizio di creazione Todo.');
      })
      .then(nascondiLoader);
  }

  function mostraLoader(testo) {
    if (document.getElementById('lizmap-todo-loader')) return;
    var $l = $('<div id="lizmap-todo-loader">').css({
      position: 'fixed', inset: 0, background: 'rgba(255,255,255,.85)', display: 'flex',
      flexDirection: 'column', justifyContent: 'center', alignItems: 'center', zIndex: 30000
    });
    $l.append($('<div>').css({
      border: '5px solid #f3f3f3', borderTop: '5px solid #a61c20', borderRadius: '50%',
      width: '50px', height: '50px', marginBottom: '20px', animation: 'prositTodoSpin 1s linear infinite'
    }));
    $l.append($('<div>').css({ color: '#a61c20', fontWeight: 'bold', fontSize: '16px' }).text(testo));
    $l.append('<style>@keyframes prositTodoSpin{to{transform:rotate(360deg)}}</style>');
    $('body').append($l);
  }

  function nascondiLoader() {
    $('#lizmap-todo-loader').fadeOut(300, function () { $(this).remove(); });
  }

  /** Finestra di Lizmap (Bootstrap 2); i testi del server sono inseriti come testo, non come HTML. */
  function popup(titolo, messaggio, righe) {
    var $m = $('#lizmap-modal');
    if (!$m.length) { alert(titolo + '\n\n' + messaggio); return; }
    var $body = $('<div class="modal-body">').css({ lineHeight: 1.6 }).append($('<p>').text(messaggio));
    if (righe && righe.length) {
      $body.append($('<p>').append($('<strong>').text('Dettaglio:')));
      var $ul = $('<ul>');
      righe.forEach(function (r) { $ul.append($('<li>').text(r)); });
      $body.append($ul);
    }
    $m.empty().append(
      $('<div class="modal-header">').css({ background: '#a61c20', color: '#fff' }).append(
        $('<a class="close" data-dismiss="modal">').css({ color: '#fff' }).text('×'),
        $('<h4>').text(titolo)),
      $body,
      $('<div class="modal-footer">').append($('<button type="button" class="btn" data-dismiss="modal">').text('OK'))
    ).modal('show');
  }
});
