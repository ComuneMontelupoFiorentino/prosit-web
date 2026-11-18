/*
 * prosit-lizmap: file ponte generato da install.sh (non modificare: viene riscritto).
 * Lizmap lo carica in tutte le mappe perche' sta in <progetti>/media/js/default/.
 * Carica da Prosit il CSS comune delle schede e lo script di integrazione:
 * cosi' gli aggiornamenti di Prosit valgono subito, senza toccare i progetti.
 */
(function () {
  var base = (location.pathname.split('/index.php')[0] || '/lizmap') + '/prosit/assets/';
  if (!document.querySelector('link[data-prosit]')) {
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = base + 'prosit-components.css';
    css.setAttribute('data-prosit', '1');
    document.head.appendChild(css);
  }
  var js = document.createElement('script');
  js.src = base + 'prosit-lizmap.js';
  js.defer = true;
  document.head.appendChild(js);
})();
