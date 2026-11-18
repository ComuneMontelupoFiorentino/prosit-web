/* Nasconde alcune voci del menu di Lizmap in questo progetto. */
lizMap.events.on({
  uicreated: function () {
    $('#mapmenu .nav-list > li.permaLink, #mapmenu .nav-list > li.metadata, #mapmenu .nav-list > li.popupcontent').hide();
  }
});
