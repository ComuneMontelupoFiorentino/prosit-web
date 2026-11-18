/**
 * Filtro dei layer nella legenda – compatibile con Lizmap Web Client 3.9
 *
 * Aggiunge sopra la legenda un campo di ricerca (stile Bootstrap Italia)
 * con etichetta e un pulsante per annullare il filtro.
 * Mostra i layer il cui titolo contiene il testo digitato e i gruppi che li contengono.
 * Se il testo corrisponde al nome di un gruppo, ne mostra tutto il contenuto.
 */
(function () {
    'use strict';

    // ---- Stili (stile form Bootstrap Italia, colori dal tema pa-it) ----
    var style = document.createElement('style');
    style.textContent = `
        #layer-filter-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 4px 12px;
            margin: 8px 0 12px;
        }
        #layer-filter-bar .layer-filter-label {
            flex: 0 0 100%;
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
            color: var(--bi-text, #1a1a1a);
        }
        #layer-filter-bar #filter-input {
            flex: 1 1 auto;
            min-width: 0;
            height: 40px;
            box-sizing: border-box;
            margin: 0;
            padding: 8px 4px;
            border: 0;
            border-bottom: 1px solid var(--bi-secondary, #5d7083);
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            font-size: 16px;
            color: var(--bi-text, #1a1a1a);
        }
        #layer-filter-bar #filter-input::placeholder {
            color: var(--bi-secondary, #5d7083);
            opacity: 1;
        }
        #layer-filter-bar #filter-input:hover {
            border-bottom-color: var(--bi-text, #1a1a1a);
        }
        #layer-filter-bar #filter-input:focus {
            border-bottom: 2px solid var(--bi-primary, #a61c20);
            outline: 2px solid #f90;
            outline-offset: 0;
            box-shadow: none;
        }
        #layer-filter-bar .clear-button {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 4px;
            background:  #06c;
            color: #fff;
            cursor: pointer;
        }
        #layer-filter-bar .clear-button svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
        }
        #layer-filter-bar .clear-button:hover {
            background: rgb(0, 86.7, 173.4);
        }
        #layer-filter-bar .clear-button:focus-visible {
            outline: 2px solid #f90;
            outline-offset: 2px;
        }
    `;
    document.head.appendChild(style);

    var currentFilter = '';
    var applying = false;

    // Testo del titolo del solo nodo (non dei figli)
    function ownTitle(li) {
        var label = li.querySelector(':scope > div .node > label');
        return label ? label.textContent.toLowerCase() : '';
    }

    // Restituisce true se il nodo (o qualcosa al suo interno) corrisponde
    function filterNode(li, text, parentMatches) {
        var selfMatches = parentMatches || ownTitle(li).includes(text);
        var childMatches = false;
        var ul = li.querySelector(':scope > ul');
        if (ul) {
            ul.querySelectorAll(':scope > li[data-testid]').forEach(function (child) {
                if (filterNode(child, text, selfMatches)) {
                    childMatches = true;
                }
            });
        }
        var visible = selfMatches || childMatches;
        li.style.display = visible ? '' : 'none';
        return visible;
    }

    function applyFilter() {
        var tree = document.querySelector('lizmap-treeview');
        if (!tree) {
            return;
        }
        applying = true;
        try {
            var roots = tree.querySelectorAll(':scope > ul > li[data-testid]');
            roots.forEach(function (li) {
                if (currentFilter === '') {
                    // nessun filtro: mostra tutto
                    li.style.display = '';
                    li.querySelectorAll('li[data-testid]').forEach(function (el) {
                        el.style.display = '';
                    });
                } else {
                    filterNode(li, currentFilter, false);
                }
            });
        } finally {
            applying = false;
        }
    }

    function init() {
        var container = document.getElementById('switcher-layers-container');
        var tree = document.querySelector('lizmap-treeview');
        if (!container || !tree || document.getElementById('layer-filter-bar')) {
            return;
        }

        var bar = document.createElement('div');
        bar.id = 'layer-filter-bar';

        var label = document.createElement('label');
        label.className = 'layer-filter-label';
        label.htmlFor = 'filter-input';
        label.textContent = 'Digita per filtrare i layer.';

        var filterInput = document.createElement('input');
        filterInput.type = 'text';
        filterInput.id = 'filter-input';
        filterInput.placeholder = 'Cerca';
        filterInput.autocomplete = 'off';
        filterInput.addEventListener('input', function () {
            currentFilter = this.value.trim().toLowerCase();
            applyFilter();
        });

        var clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'clear-button';
        clearButton.title = 'Annulla filtro';
        clearButton.setAttribute('aria-label', 'Annulla filtro');
        // Icona it-close di Bootstrap Italia, inline (nessun file esterno)
        clearButton.innerHTML =
            '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
            '<path d="m12.7 12 3.7 3.6-.8.8-3.6-3.7-3.6 3.7-.8-.8 3.7-3.6-3.7-3.6.8-.8 3.6 3.7 3.6-3.7.8.8-3.7 3.6z"/>' +
            '</svg>';
        clearButton.addEventListener('click', function () {
            filterInput.value = '';
            currentFilter = '';
            applyFilter();
            filterInput.focus();
        });

        bar.appendChild(label);
        bar.appendChild(filterInput);
        bar.appendChild(clearButton);

        // Inserisce la barra sopra la legenda
        var menuContent = container.querySelector('.menu-content');
        container.insertBefore(bar, menuContent || container.firstChild);

        // La legenda viene ridisegnata da Lizmap (espandi/chiudi gruppi, ecc.):
        // riapplica il filtro quando cambia
        new MutationObserver(function () {
            if (!applying && currentFilter !== '') {
                applyFilter();
            }
        }).observe(tree, { childList: true, subtree: true });
    }

    lizMap.events.on({
        uicreated: function () {
            try {
                init();
            } catch (e) {
                // un errore qui non deve bloccare il caricamento della mappa
                console.error('Filtro layer:', e);
            }
        }
    });
})();