/* Registro controlli videosorveglianza — API Prosit v2 (/lizmap/api.php/prosit/v2, client assets/prosit-api-v2.js) */
const PROSIT_BASE = new URL('../..', document.currentScript.src).pathname.replace(/\/$/, '');
const LIZMAP_BASE = PROSIT_BASE.replace(/\/prosit$/, '') || '/lizmap';
const api = window.Prosit.api;
/** sessione assente o senza permessi: si torna a Lizmap */
const accessoNegato = e => e && (e.status === 401 || e.status === 403);
/** data PostgreSQL/ISO -> data e ora italiane */
const dataOra = v => v ? new Date(String(v).replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1').replace(/([+-]\d{2})$/, '$1:00')).toLocaleString('it-IT') : '';
const ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = v => (v === null || v === undefined ? '' : String(v)).replace(/[&<>"']/g, c => ESC_MAP[c]);

document.addEventListener('DOMContentLoaded', () => {


    let problems = {};    
    let currentUser = null;
    let recordsMap = {};
    let pendingIssues = [];
    
    const sidePanelEl = document.getElementById('sidePanel');
    // componenti Bootstrap 2 di Lizmap (plugin jQuery)
    const bs2Modal = (node) => ({ show: () => jQuery(node).modal('show'), hide: () => jQuery(node).modal('hide') });
    const sidePanel = bs2Modal(sidePanelEl);
    const sidePanelContent = document.getElementById('sidePanelContent');
    const sidePanelLabel = document.getElementById('sidePanelLabel');
    const issueModalEl = document.getElementById('issueModal');
    const issueModal = bs2Modal(issueModalEl);

    // =========================
    // MODAL
    // =========================
    document.addEventListener('click', (e) => {
        const link = e.target.closest('.issue-link');
        if (!link) return;

        e.preventDefault();
        e.stopPropagation();

        const issueId = link.dataset.issue;
        
        // Se hai una funzione per chiudere i popover chiamala, 
        // altrimenti commentala per evitare errori JS
        if (typeof closeAllPopovers === "function") closeAllPopovers();

        const iframe = document.getElementById('issueFrame');
        iframe.src = `${PROSIT_BASE}/schede/disservizio.html?seg_id=${encodeURIComponent(issueId)}`;

        issueModal.show();
    });

    // =========================
    // LOAD DATA
    // =========================
    function redirectToLogin() {
        window.location.href = `${LIZMAP_BASE}/index.php/view/map?repository=segn&project=rekla`;
    }
    async function loadData() {
        try {
            const me = await api.me();
            currentUser = me.nome || me.login;
            document.getElementById('worker-log-field').value = currentUser;
            document.getElementById('date-log-field').value = new Date().toLocaleDateString('it-IT');

            const rows = await api.list('tvcc-apparati');

            recordsMap = {};
            rows.forEach(r => { recordsMap[r.id_app] = r; });

            renderGrid(rows);
        } catch (err) {
            if (accessoNegato(err)) { redirectToLogin(); return; }
            console.error(err);
        }
    }

    // =========================
    // RENDER GRID
    // =========================
    function parsePgArray(value) {
        if (Array.isArray(value)) return value;
        if (typeof value !== "string") return [];

        // rimuove le graffe iniziali/finali: "{Sospesa,Aperta}" -> "Sospesa,Aperta"
        const trimmed = value.replace(/^{|}$/g, "");
        if (trimmed === "") return [];

        return trimmed
            .split(",")
            .map(s => s.replace(/^"|"$/g, "").trim()); // rimuove eventuali virgolette
    }

    // Mappo i colori stato ticket
    const STATUS_COLORS = {
        "Accettata": "#c6c6c6", 
        "Sospesa": "#06c", 
        "Convalidata": "#c90002", 
        "Assegnata": "#ff3b02", 
        "Presa in carico": "#ff8102", 
        "Programmata": "#ffe32e", 
        "Eseguita": "#b6e32e", 
    };
    const DEFAULT_STATUS_COLOR = "#cc334d"; // fallback per stati non mappati
    function renderGrid(records) {
        const container = document.getElementById('tvcc-container');
        container.innerHTML = '';
        
        records.forEach((r, index) => {
            const isTarga = r.lett_targh && r.lett_targh.toLowerCase() === "si";
            const iconClass = isTarga 
                ? "icon-road"
                : "icon-facetime-video";
            const idClass = isTarga 
                ? "h5 mb-2 text-secondary" 
                : "h5 mb-2";
            const hasReportedOnDb = r.reported === true || parseInt(r.num_reported) > 0;
            const hasLocalIssues = problems[r.id_app] && problems[r.id_app].length > 0;
            const statiArray = parsePgArray(r.stati);
            const numReported = parseInt(r.num_reported) || 0;


            let badgeHtml = '';
            let statusId = 1;
            if (hasLocalIssues) {
                statusId = 3;
                badgeHtml = `<span class="badge bg-warning">Nuovi problemi (${problems[r.id_app].length})</span>`;
            } else if (hasReportedOnDb) {
                statusId = 2;
                if (numReported === 1) {
                    const statoSegnalazione = statiArray[0] || "N.D.";
                    const color = STATUS_COLORS[statoSegnalazione] || DEFAULT_STATUS_COLOR;
                    badgeHtml = `<span class="badge" style="background-color: ${color};">${esc(statoSegnalazione)}</span>`;
                } else {
                    badgeHtml = `<span class="badge" style="background-color: ${DEFAULT_STATUS_COLOR};">Varie segnalazioni (${numReported})</span>`;
                }
            } else {
                badgeHtml = `<span class="badge bg-success">Funzionante</span>`;
            }

            const col = document.createElement('div');
            col.className = "col-6 col-sm-4 col-md-3 col-lg-2 mb-3";
            col.style.setProperty('--i', index);

            col.innerHTML = `
                <div class="card shadow-sm tvcc-box text-center p-3 ${hasLocalIssues ? 'border-warning' : ''}" 
                    role="button" tabindex="0"
                    data-id="${esc(r.id_app)}"
                    data-status="${statusId}">
                    <div class="${idClass}">${esc(r.id_app)}</div>
                    ${badgeHtml}
                    <div class="mt-2">
                        <i class="${iconClass} tvcc-icon"></i>
                    </div>
                </div>
            `;
                
            container.appendChild(col);
        });

        applyFilters();
    }

    // apertura del dettaglio telecamera (click o tastiera), senza onclick in linea
    const grid = document.getElementById('tvcc-container');
    grid.addEventListener('click', (e) => {
        const box = e.target.closest('.tvcc-box');
        if (box) openCameraDetails(box.dataset.id);
    });
    grid.addEventListener('keydown', (e) => {
        const box = e.target.closest('.tvcc-box');
        if (box && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openCameraDetails(box.dataset.id); }
    });
    sidePanelContent.addEventListener('click', (e) => {
        const add = e.target.closest('[data-add-issue]');
        if (add) { addLocalIssue(add.dataset.addIssue); return; }
        const del = e.target.closest('[data-remove-issue]');
        if (del) removeLocalIssue(del.dataset.removeIssue, parseInt(del.dataset.index, 10));
    });

    // =========================
    // FILTRI
    // =========================
    function applyFilters() {
        const searchValue = (document.getElementById('filter-id').value || '').toLowerCase();
        const statusValue = document.getElementById('status-tlc').value;

        document.querySelectorAll('.tvcc-box').forEach(card => {
            const id = (card.dataset.id || '').toLowerCase();
            const status = card.dataset.status;

            const matchSearch = id.includes(searchValue);
            const matchStatus = !statusValue || status === statusValue;

            card.parentElement.style.display = (matchSearch && matchStatus) ? '' : 'none';
        });
    }

    // =========================
    // EVENTI FILTRI
    // =========================
    document.getElementById('filter-id').addEventListener('input', applyFilters);
    document.getElementById('status-tlc').addEventListener('change', applyFilters);

    document.getElementById('clear-search').onclick = () => {
        const input = document.getElementById('filter-id');
        input.value = '';
        applyFilters();
    };

    // =========================
    // RELOAD
    // =========================
    document.getElementById('reload-button').onclick = () => {
        loadData();
    };

    // =========================
    // SIDE PANEL
    // =========================
    function renderLocalIssues(id) {
        if (!problems[id] || problems[id].length === 0) return "";

        let html = '<ul class="list-group">';
        
        problems[id].forEach((p, index) => {
            html += `
                <li class="list-group-item list-group-item-warning small d-flex justify-content-between">
                    ${esc(p.description)}
                    <button type="button" class="btn btn-sm text-danger" data-remove-issue="${esc(id)}" data-index="${index}" title="Rimuovi">
                        <i class="icon-trash"></i>
                    </button>
                </li>`;
        });

        html += '</ul>';
        return html;
    }
    window.openCameraDetails = async (id) => {

        sidePanelLabel.textContent = `Telecamera: ${id}`;
        sidePanelContent.innerHTML = `
            <div class="text-center">
                <span class="p-spinner"></span>
            </div>`;
        sidePanel.show();

        try {
            const issues = await api.list('segnalazioni', {
                segn_ogg_gen: id,
                stato_segn__not: 'Verificata e chiusa',
                _select: 'seg_id,seg_i_rek_alias,insert_timestamp,seg_o_desc',
                _order: 'insert_timestamp',
            });

            let html = `<h6>Segnalazioni Attive</h6>`;

            if (issues.length > 0) {
                html += '<ul class="list-group mb-4">';
                issues.forEach(i => {
                    html += `
                        <li class="list-group-item small">
                            <a class="issue-link" data-issue="${esc(i.seg_id)}" href="#">
                                <b>${esc(i.seg_i_rek_alias || 'N.D.')}</b> ${esc(dataOra(i.insert_timestamp))}
                            </a>
                            <p>"${esc(i.seg_o_desc || 'Nessuna descrizione')}"</p>
                        </li>`;
                });
                html += '</ul>';
            } else {
                html += '<p class="text-muted small">Nessuna segnalazione aperta.</p>';
            }

            html += `
                <hr>
                <h6>Nuova Segnalazione</h6>
                <textarea id="new-desc" class="form-control mb-2" maxlength="2000" aria-label="Descrizione del problema"></textarea>
                <button type="button" class="btn btn-primary btn-sm w-100" data-add-issue="${esc(id)}">Aggiungi</button>
                <div id="local-list" class="mt-3">${renderLocalIssues(id)}</div>
            `;

            sidePanelContent.replaceChildren();

            const wrapper = document.createElement("div");
            wrapper.innerHTML = html;

            sidePanelContent.appendChild(wrapper);

        } catch (err) {
            if (accessoNegato(err)) { redirectToLogin(); return; }
            console.error("openCameraDetails ERROR:", err);
            sidePanelContent.innerHTML =
                `<div class="alert alert-danger">${esc(err.message || 'Errore')}</div>`;
        }
    };

    // =========================
    // PROBLEMI LOCALI
    // =========================
    window.addLocalIssue = (id) => {
        const descEl = document.getElementById('new-desc');
        const desc = descEl.value.trim();
        if (!desc) return alert("Inserisci descrizione");

        if (!problems[id]) problems[id] = [];
        
        const issue = { id, description: desc };

        problems[id].push({ description: desc });
        pendingIssues.push(issue);

        descEl.value = '';
        document.getElementById('local-list').innerHTML = renderLocalIssues(id);

        renderGrid(Object.values(recordsMap));
    };
    window.removeLocalIssue = (id, index) => {
        const removed = problems[id][index];

        problems[id].splice(index, 1);

        // rimuove anche dal pending array
        pendingIssues = pendingIssues.filter(p => !(p.id === id && p.description === removed.description));

        document.getElementById('local-list').innerHTML = renderLocalIssues(id);
        renderGrid(Object.values(recordsMap));
    };

    document.getElementById('submit-control').onclick = async () => {

        const status = document.getElementById('status-log-field').value;

        if (!status) {
            alert("Seleziona una dichiarazione");
            return;
        }

        // VALIDAZIONE
        const validationError = validateControl();
        if (validationError) {
            showAlert(validationError, "danger");
            return;
        }

        //if (pendingIssues.length === 0 && status !== "3") {
        //    showAlert("Nessuna segnalazione da registrare.", "error");
        //    return;
        //}

        const confirmSend = confirm(
            `Confermi la registrazione del controllo?`
        );

        if (!confirmSend) return;

        let successCount = 0;

        for (const item of pendingIssues) {

            // l'operatore (seg_s_ac_op) lo imposta il server dalla sessione
            const body = {
                seg_o_ogg: "Registro controllo TLC",
                seg_o_desc: item.description,
                segn_ogg_gen: item.id
            };

            try {
                await api.create('tvcc-ticket', body);
                successCount++;
            } catch (err) {
                if (accessoNegato(err)) {
                    // NON rimando al login: mostro il motivo reale
                    console.error("Accesso negato sulla insert:", err.status, err.message);
                    showAlert(`Insert negata (HTTP ${err.status}): ${err.message}`, "danger");
                    return;
                }
                console.error("Errore API insert:", err);
                showAlert(`Errore insert: ${err.message}`, "danger");
            }
        }

        // =========================
        // ESITO
        // =========================

        if (successCount === pendingIssues.length) {

        let logOk = true;
        const statusValue = document.getElementById('status-log-field').value;
        const bodyLog = { note: statusValue };   // insert_by lo imposta il server

        try {
            await api.create('tvcc-log', bodyLog);
        } catch (err) {
            logOk = false;
            console.error("Errore audit.daily_log_tvcc:", err);
            showAlert("Controllo salvato ma errore nel log giornaliero", "warning");
        }

        if (logOk) {
            showAlert('Registrazione avvenuta', 'success');
        } else {
            showAlert('Registrazione avvenuta ma log non completato', 'warning');
        }

        pendingIssues = [];
        problems = {};

        await loadData();

        } else {
        showAlert(`Errore nell'inserimento del registro (${successCount}/${pendingIssues.length})`, 'danger');
        }
    };

    function validateControl() {

        const status = document.getElementById('status-log-field').value;
        const hasNew = pendingIssues.length > 0;
        const hasOld = Object.values(recordsMap).some(r =>
            r.reported === true || parseInt(r.num_reported) > 0
        );

        // =========================
        // CASI
        // =========================

        // 1. Tutto funzionante + nuove segnalazioni
        if (status === "Tutto funzionante" && hasNew) {
            return "Stai dichiarando che è tutto funzionante ma hai inserito segnalazioni";
        }
        // 2. Tutto funzionante + vecchie segnalazioni
        //if (status === "Tutto funzionante" && hasOld) {
        //    return "Segnalazioni presenti ma stai dichiarando tutto funzionante";
        //}
        // 3. Nuovo problema ma nessuna nuova segnalazione
        if (status === "Nuovo problema rilevato" && !hasNew) {
            return "Inserisci il nuovo problema individuato o cambia dichiarazione";
        }
        // 4. Problema già segnalato ma nessuna segnalazione esistente
        if (status === "Problema già segnalato (ancora presente)" && !hasOld) {
            return "Non è presente alcuna segnalazione preesistente";
        }
        return null;
    }

    function showAlert(message, type = "success") {

        const container = document.querySelector('.container-fluid');

        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        alert.role = "alert";

        alert.textContent = message;

        container.prepend(alert);

        setTimeout(() => alert.remove(), 5000);
    }



    // =========================
    // INIT
    // =========================
    loadData();

});