/* Modalita' chiara/scura della mappa, pulsanti delle fasce orarie e legenda dei pali luce.
   Prima caricava Font Awesome da Internet (cdnjs): ora usa i simboli di Lizmap
   (Glyphicons, classi icon-*) e caratteri Unicode, senza risorse esterne. */
(function () {
  var LEGENDA = {
    titolo: 'LEGENDA',
    nascondi: 'Nascondi',
    gruppi: [{
      code: 'categorie', title: 'Pali luce',
      items: [
        { title: 'Palo acceso (a norma)', code: '1', color: '#ffd387' },
        { title: 'Palo ridotto', code: '1', color: '#fffde1' },
        { title: 'Palo spento', code: '1', color: '#325780' },
        { title: 'Pali da valutare1', code: '1', color: '#ff01c0' },
        { title: 'Pali da valutare2', code: '1', color: '#ff0101' }
      ]
    }]
  };
  var LAYER = {
    chiaro: '#layer-baseClear button.checkbox[value="baseClear"]',
    scuro: '#layer-baseDark button.checkbox[value="baseDark"]',
    fascia0: '#layer-fascia0 button.checkbox[value="fascia0"]',
    fascia2: '#layer-fascia2 button.checkbox[value="fascia2"]'
  };

  function attiva(sel) { var $b = $(sel); if ($b.length && !$b.hasClass('checked')) $b.trigger('click'); }
  function disattiva(sel) { var $b = $(sel); if ($b.length && $b.hasClass('checked')) $b.trigger('click'); }

  function creaLegenda() {
    if ($('#my-legend').length) return;
    var $box = $('<div id="my-legend" class="my-legend-container"></div>');
    var $items = $('<div id="my-legend-items-container"></div>');
    $box.append(
      $('<span id="my-legend-title"></span>').text(LEGENDA.titolo),
      $('<button type="button" id="my-legend-toggle" class="btn btn-mini pull-right"></button>').text(LEGENDA.nascondi),
      $items
    );
    LEGENDA.gruppi.forEach(function (g) {
      var $tb = $('<tbody></tbody>');
      g.items.forEach(function (it) {
        $tb.append($('<tr></tr>').append(
          $('<td></td>').append($('<span class="my-legend-symbol"></span>').text(it.code).css({ color: it.color, backgroundColor: it.color })),
          $('<td></td>').text(it.title)
        ));
      });
      $items.append($('<div class="my-legend-item"></div>').attr('id', 'my-legend-' + g.code)
        .append($('<h4></h4>').text(g.title), $('<table class="my-legend-table"></table>').append($tb)));
    });
    $('#map-content').append($box);
    $('#my-legend-title, #my-legend-toggle').on('click', function () {
      $items.toggle();
      $('#my-legend-toggle').toggle();
    });
  }

  function pulsante(id, html, css, onClick) {
    if ($('#' + id).length) return $('#' + id);
    return $('<button type="button" class="btn"></button>').attr('id', id).html(html)
      .css($.extend({ position: 'absolute', zIndex: 900 }, css)).on('click', onClick).appendTo('#map-content');
  }

  function modalita(scura) {
    $('#mode-dark').toggle(!scura);
    $('#mode-clear').toggle(scura);
    $('#map-content').css('background-color', scura ? '#000' : '#fff');
    if (scura) { disattiva(LAYER.chiaro); attiva(LAYER.scuro); } else { disattiva(LAYER.scuro); attiva(LAYER.chiaro); }
    if (scura) {
      $('#fascia-sera, #fascia-notte').show();
      creaLegenda();
      $('#my-legend').show();
    } else {
      disattiva(LAYER.fascia0);
      disattiva(LAYER.fascia2);
      $('#fascia-sera, #fascia-notte, #my-legend').hide();
    }
  }

  function fascia(on, off) { $(on).trigger('click'); disattiva(off); }

  lizMap.events.on({
    uicreated: function () {
      var tondo = { right: '120px', bottom: '20px', fontSize: '24px', lineHeight: '1', padding: '8px 12px', borderRadius: '12px' };
      // passa a scuro (mostrato in modalita' chiara) / passa a chiaro (mostrato in modalita' scura)
      pulsante('mode-clear', '&#9728;', $.extend({ background: '#f7f7f7', color: '#000', border: '2px solid #000' }, tondo), function () { modalita(false); })
        .attr({ title: 'Mappa chiara', 'aria-label': 'Mappa chiara' });
      pulsante('mode-dark', '&#9790;', $.extend({ background: '#000', color: '#fff', border: '2px solid #fff' }, tondo), function () { modalita(true); })
        .attr({ title: 'Mappa scura', 'aria-label': 'Mappa scura' });
      var orario = { left: '45%', fontSize: '18px', padding: '8px 12px' };
      pulsante('fascia-sera', '<i class="icon-time"></i> 17:00-23:00', $.extend({ top: '110px' }, orario), function () { fascia(LAYER.fascia0, LAYER.fascia2); }).addClass('btn-warning').hide();
      pulsante('fascia-notte', '<i class="icon-time"></i> 23:00-06:00', $.extend({ top: '150px' }, orario), function () { fascia(LAYER.fascia2, LAYER.fascia0); }).addClass('btn-warning').hide();
      $('#mode-clear').hide();
      $('#map-content').css('background-color', '#6c6c6c');
    }
  });
})();
