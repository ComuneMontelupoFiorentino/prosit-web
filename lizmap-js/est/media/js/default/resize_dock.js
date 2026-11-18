/* Pannello laterale (#dock) allargabile trascinando il bordo destro.
   Prima scaricava interact.js da Internet (cdn.jsdelivr.net, senza versione fissa):
   ora poche righe senza librerie esterne. Larghezza ricordata nel browser. */
lizMap.events.on({
  uicreated: function () {
    var dock = document.getElementById('dock');
    if (!dock || document.getElementById('dock-resize-handle')) return;
    var KEY = 'prosit-dock-width', MIN = 200;
    try { var w = parseInt(localStorage.getItem(KEY), 10); if (w >= MIN) dock.style.width = w + 'px'; } catch (e) { /* niente */ }

    var handle = document.createElement('div');
    handle.id = 'dock-resize-handle';
    handle.title = 'Trascina per allargare il pannello';
    handle.style.cssText = 'position:absolute;top:0;right:-4px;width:8px;height:100%;cursor:ew-resize;z-index:10;';
    dock.appendChild(handle);

    handle.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      handle.setPointerCapture(e.pointerId);
      var left = dock.getBoundingClientRect().left;
      function move(ev) { dock.style.width = Math.max(MIN, Math.min(window.innerWidth - 100, ev.clientX - left)) + 'px'; }
      function stop() {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', stop);
        try { localStorage.setItem(KEY, parseInt(dock.style.width, 10)); } catch (e2) { /* niente */ }
        if (window.lizMap && lizMap.updateContentSize) lizMap.updateContentSize();
      }
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', stop);
    });
  }
});
