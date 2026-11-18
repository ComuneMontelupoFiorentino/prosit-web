/**
 * @license Mozilla Public License Version 2.0
 * This script has been developed by the "community"
 * There isn't any guarantee that this script will work on another version of Lizmap Web Client.
 */

// Enable you to create an HTML Popup startup
function htmlPopup(tour) {
    var html = '';
    var title = 'Benvenuto';
    var content = 'Puoi visualizzare il tutorial scegliendo in basso "Guarda il tutorial"'

    // Header
    html += '<div class="modal-header" style="background-color: white;">';
    html += '<h3 style="color: #484848;">' + title + '</h3></div>';

    // Main content : body
    html += '<div class="modal-body">';
    html += '<p>' + content + '</p>';

    // Project Metadata
    html += $('#metadata').html();

    // End of main content body
    html += '</div>';

    // Footer
    html += '<div class="modal-footer" style="background-color: white;"></div>';

    // Return shepherd
    return {
        id: 'Popup Startup',
        text: html,
        attachTo: {
            element: 'body',
        },
        buttons: [{ text: 'Guarda il tutorial', action: tour.next }],
    }
}

// Check the browser's localstorage
function dismissTour() {
    try { localStorage.setItem('shepherd-tour', 'yes'); } catch (e) { /* navigazione privata */ }
}

// Start the tutorial
function startTutorial() {
    // Create a tour
    const tour = new Shepherd.Tour({
        useModalOverlay: true,
        defaultStepOptions: {
            classes: 'shepherd-theme-custom',
            scrollTo: true,
            cancelIcon: { enabled: true },
            keyboardNavigation: true,
            exitOnEsc: true,
            when: {

                show() {

                    // Don't show the progress bar on first and final step
                    if (tour.steps.indexOf(tour.currentStep) != 0 && tour.steps.indexOf(tour.currentStep) != tour.steps.length - 1) {
                        const currentStepElement = tour.currentStep.el;
                        const header = currentStepElement.querySelector('.shepherd-footer');
                        const progress = document.createElement('div');
                        const innerBar = document.createElement('span');
                        const progressPercentage = ((tour.steps.indexOf(tour.currentStep) + 1) / tour.steps.length) * 100 + '%';

                        progress.className = 'shepherd-progress-bar';
                        innerBar.style.width = progressPercentage;

                        progress.style.minWidth = '10px';

                        progress.appendChild(innerBar);
                        header.insertBefore(progress, currentStepElement.querySelector('.shepherd-button'));
                    }
                }
            }
        },

    });

    tour.addStep(htmlPopup(tour));

    // Add automatic tour steps
    const tourSteps = $('.nav.nav-list li').filter(function () {
        return this.style.display !== 'none' && !this.classList.contains('hide');
    }).map(function (index, element) {
    
        const aElement = $(element).find('a')[0];
        const buttonName = $(aElement).attr('data-original-title') || `Passo ${index + 1}`;
        
        // Mappa con descrizioni personalizzate
        const descriptions = {
            "Home": "Bottone attraverso il quale si viene rimandati alla homepage.",
            "Informazioni": "Serve a visualizzare i metadati del progetto come epsg del datum e descrizione.",
            "Modifica": "Consente di accedere ai forms per l'inserimento dei dati.",
            "Filtro": "Consente di filtrare i dati presenti. Funziona sia per i dati in mappa che per quelli in tabella.",
            "Selezione": "Consente di selezionare record sulla base di interrogazioni spaziali.",
            "Localizzazione": "Consente di localizzare (muovere e zoommare la mappa) sulla base di valori noti, come via e numero civico.",
            "Stampa": "Consente la stampa della mappa: posizionati sull'area da stampare; clicca sul bottone 'Stampa'; seleziona il modello (A3, A4..) tra quelli disponibili e il formato (PDF, JPEG..); seleziona la scala che, attraverso il riquadro rosso rappresenta l'estensione che verrà stampata, sposta il riquadro rosso sull'area da stampare; Clicca su 'Stampa' e attendi.",
            "Misura": "Consente di misurare distanze e aree direttamente sulla mappa.",
            "Permalink": "Consente di memorizzare la visualizzazione stabilita (layer spuntati, zoom, posizione..) come segnalibro e di condividerla attraverso un link.",
            "Disegna": "Consente di rappresentare sulla mappa geometrie (linee, punti, poligoni), di esportarli (KML, GPX, GeoJSON).",
            "Dati": "Consente di visualizzare i dati della mappa in versione tabellare, di interagire comunque con gli ogetti della mappa. Dopo il click puoi scegliere quale livello visualizzare.",
            "Nota geolocalizzata": "Consente di inserire in mappa una nota.",
            "Geolocalizzazione": "Serve a localizzare in mappa la propria posizione (solo per dispositivi mobile).",
            "Layer": "Serve a visualizzare e attivare/disattivare i livelli presenti in mappa.",
            "Link esterno": "Serve a generare, partendo dalla mappa, un link per visualizzare la posizione su google maps, street view o osm.",
        };
    
        return {
            id: `Step ${index + 1}`,
            title: buttonName,
            text: descriptions[buttonName] || "Funzionalità disponibile nell'interfaccia di Lizmap.",
            attachTo: {
                element: aElement,
                on: 'right',
            },
            buttons: [{ text: 'Precedente', action: tour.back }, { text: 'Prossimo', action: tour.next }],
        };
    }).get();

    // Add 3 more personal tour steps
    const stepHappyCoding = [
        {
            id: 'move-map',
            title: 'Sposta la mappa',
            text: 'Serve a gestire gli zoom o il pad sulla mappa. Funziona anche scrollando con il cursore.',
            attachTo: {
                element: 'span#navbar',
                on: 'left',
            },
            buttons: [{ text: 'Precedente', action: tour.back }, { text: 'Prossimo', action: tour.next }],
        },

        {
            id: 'overview',
            title: 'Overview box',
            text: 'Serve a visualizzare la scala della mappa e le coordinate (geografiche o proiettate) rispetto alla posizione del cursore.',
            attachTo: {
                element: '#overview-box',
                on: 'top',
            },
            buttons: [{ text: 'Precedente', action: tour.back }, { text: 'Prossimo', action: tour.next }],
        },

        {
            id: 'happy-coding',
            title: 'Buona visualizzazione',
            text: 'è tutto, adesso puoi iniziare visualizzare i dati.',
            attachTo: {
                element: 'body',
            },
            buttons: [
                { text: 'Precedente', action: tour.back },
                {
                    text: 'Fine',
                    action() {
                        // Dismiss the tour when the finish button is clicked
                        dismissTour();
                        return this.hide();
                    }
                }],
        }
    ];

    // Spread the steps into the tour array
    tourSteps.push(...stepHappyCoding);
    tour.addSteps(tourSteps);

    // Return the
    return tour;
}

lizMap.events.on({
    uicreated: function () {
        // CSS di Shepherd
        var link = document.createElement('link');
        // Shepherd.js 10.0.1 servito da Prosit (prima: cdn.jsdelivr.net)
        var base = (location.pathname.split('/index.php')[0] || '/lizmap') + '/prosit/assets/vendor/shepherd/';
        link.href = base + 'shepherd.css';
        link.rel = 'stylesheet';
        document.head.appendChild(link);

        // JS di Shepherd
        var script = document.createElement('script');
        script.src = base + 'shepherd.min.js';
        script.type = 'text/javascript';

        script.onload = function () {
            console.log('Shepherd.js has been loaded successfully!');
            const tour = startTutorial();

            // Dismiss the tour when the cancel icon is clicked
            tour.on('cancel', dismissTour);

            // Start the tour
            var visto = false;
            try { visto = !!localStorage.getItem('shepherd-tour'); } catch (e) { /* navigazione privata */ }
            if (!visto) {
                tour.start();
            }
        };

        script.onerror = function () {
            console.error('Error when loading shepherd.js');
        };

        document.head.appendChild(script);
    }
});