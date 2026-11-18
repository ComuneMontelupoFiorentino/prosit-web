# Prosit — servizi, schede e applicazioni per Lizmap 3.9

Tutti i servizi che sulla vecchia VM stavano in `/var/www/lizmap-web-client-3.6.2/lizmap/www`
(schede dei popup QGIS, registro TVCC, calendario verde, report ticket, pannello open data,
QField, stazione meteo, sopralluoghi), riorganizzati in un unico pacchetto che si
installa **senza root**, come utente `prosit2`, **senza toccare Apache né `/etc`**:

- usa solo le due cartelle scrivibili della VM: il codice in **`/usr/local/bin/prosit/prosit-web`**
  (accanto agli altri strumenti di `/usr/local/bin/prosit`), log, cache e dati in **`/srv/data/prosit`**
  (`prosit-web/var` è un collegamento lì). Fuori dalla cartella di Lizmap;
- è pubblicato su **`/lizmap/prosit/`** con un collegamento simbolico nella www di Lizmap: solo
  sotto `/lizmap/` il browser invia il cookie di sessione di Lizmap;
- **i dati passano dalla Prosit API v2** (`/lizmap/api.php/prosit/v2`: modulo Lizmap + PostgREST,
  pacchetto `prosit-api`): schede, registro TVCC e calendario la usano con `assets/prosit-api-v2.js`.
  Qui restano solo i servizi che la v2 non copre (proxy dei documenti Rekla,
  `auth/me` per i pulsanti nelle mappe, hook per le macchine, pagine report e open data);
- **un solo modo di autenticarsi**: l'utente e i suoi gruppi li dice Lizmap (`/prosit/v2/auth/me`),
  le macchine sono riconosciute per IP o chiave;
- **un solo modo di accedere a PostgreSQL** per report e hook (`pg_service.conf`, ruoli
  dedicati, nessuna password nel codice);
- **un solo framework grafico: la Bootstrap 2 già servita da Lizmap**, con un tema in stile
  Bootstrap Italia; icone, jQuery e favicon sono anch'esse quelle di Lizmap.

Collaudato su PHP 8.3, PostgreSQL 16 + PostGIS, Apache 2.4 + PHP-FPM, su una copia della struttura
della nuova VM (Lizmap 3.9.0 con i suoi file reali di Bootstrap/jQuery, `/var/www/html/lizmap`,
utente `prosit2` senza sudo, Apache senza configurazione aggiuntiva).
La logica di sessione è verificata sul sorgente di Lizmap 3.9.0 / Jelix 1.8.
Suite automatica: `tests/run.sh` + prove nel browser dei JS di Lizmap.

---

## 1. Struttura

```
/usr/local/bin/prosit/prosit-web/
  public/                      UNICA cartella pubblicata  (<www di Lizmap>/prosit -> /usr/local/bin/prosit/prosit-web/public)
    api/index.php              servizi: /lizmap/prosit/api/index.php/v1/{health,auth/me,documenti/rekla}
    schede/                    popup QGIS: disservizio, disservizio-bit, manomissioni, pali-luce (+ _modello)
    app/                       registro-tvcc, calendario-verde, report-ticket, opendata-admin
    hooks/                     per le macchine: meteo, qfield, sopralluoghi
    schede/catalogo.html       catalogo dei componenti con il codice da copiare in QGIS
    assets/prosit.css          TEMA UNICO delle pagine (importa la Bootstrap 2 di Lizmap + i componenti)
    assets/prosit-components.css  LINGUAGGIO COMUNE delle schede (popup QGIS e schede esterne)
    assets/prosit-lizmap.js    caricato nelle mappe Lizmap: incorpora le schede esterne nei popup QGIS
    assets/prosit.js           libreria comune delle schede (formattazione, interfaccia, indirizzi)
    assets/prosit-api-v2.js    Prosit.api: client della Prosit API v2 (dopo prosit.js)
    assets/vendor/titillium/   unico file esterno: il font (Lizmap non lo ha)
  src/                         core PHP: Auth (utente da Lizmap), Db, Http, Api (servizi)
  config/defaults.php          impostazioni predefinite
  modules/                     logica non pubblica: opendata-admin, qfield, report-ticket
  local/                       password, chiavi, impostazioni della macchina (MAI pubblicata)
  lizmap-js/                   JS personalizzati dei progetti Lizmap, aggiornati (vedi 7ter)
  var -> /srv/data/prosit      log, cache, dati (meteo, open data, rilievi QField) (MAI pubblicata)
  qgis/                        modelli HTML per i popup QGIS + file ponte per media/js/default
  sql/                         ruoli e permessi di prosit_ro / prosit_rw (report, hook)
  bin/prosit                   verifica, chiavi, ricerca vecchi indirizzi nei progetti
  deploy/                      mappa dei vecchi indirizzi; installazione alternativa da root (per l'IT)
  tests/                       suite automatica su database prosit_test_*
```

`local/` e `var/` stanno fuori da `public/`: Apache non li può servire.
I file in `local/` sono leggibili solo da `prosit2` e (in sola lettura, con ACL) da `www-data`.

## 2. Installazione (senza root)

Prerequisiti, già presenti sulla nuova VM: PHP ≥ 8.1 con `pdo_pgsql`, `pdo_sqlite`, `curl`;
`setfacl`; i database ripristinati con `pgzip`; le credenziali di amministrazione PostgreSQL in
`pgzip.ini` (le stesse usate per il ripristino).

**Unico requisito di permessi**: poter scrivere nella cartella www di Lizmap, per creare il
collegamento `prosit`. Se non è così, l'installatore si ferma e stampa l'unico comando da chiedere
all'IT (una volta sola, non tocca Apache):

```bash
setfacl -m u:prosit2:rwx /var/www/lizmap-web-client-3.9.0/lizmap/www
```

Installazione:

```bash
git clone https://github.com/ComuneMontelupoFiorentino/prosit-web.git ~/prosit-web && cd ~/prosit-web
bash install.sh --archive ~/archivio.zip
```

Cosa fa `install.sh` (si può rilanciare in sicurezza; non tocca `local/` né `var/` esistenti):

1. controlla PHP, `setfacl`, la cartella di Lizmap (`/var/www/lizmap-attiva/www` se esiste, altrimenti `/var/www/html/lizmap`);
2. copia il codice in `/usr/local/bin/prosit/prosit-web` e con le ACL dà a `www-data` solo ciò che serve: attraversare la
   home, leggere il codice, leggere `local/`, scrivere in `var/`;
3. crea `local/` con **password nuove** per `prosit_ro`/`prosit_rw`, salvate subito in
   `local/credenziali-<data>.txt` (leggibile solo da `prosit2`);
4. crea i ruoli PostgreSQL con le credenziali di `pgzip.ini` (trovato da solo in
   `/usr/local/bin/prosit/TRANSFER-DB-compressed__pg2zip/`, oppure `--pg-ini FILE` / `--pg-user postgres`)
   e concede **solo** i permessi necessari (`sql/10_grant_prosit.sql`);
5. dall'archivio della vecchia VM importa `services.json` (open data), il log meteo e il **token dell'app QField**;
6. crea il collegamento `<www di Lizmap>/prosit -> /usr/local/bin/prosit/prosit-web/public` e i **file ponte** per i vecchi indirizzi;
   scrive `media/js/default/prosit-lizmap.js` accanto ai repository dei progetti (vedi sezione 7bis)
   e avvisa se un repository non ha `allowUserDefinedThemes=1`;
7. verifica tutto **come `www-data`** chiedendolo a PHP-FPM (`/v1/health?full=1`, solo da localhost):
   configurazione, gruppi Lizmap, cartelle, ogni database e ogni risorsa.

Cartelle: se `/usr/local/bin/prosit` e `/srv/data` sono scrivibili (come sulla nuova VM) il
codice va in `/usr/local/bin/prosit/prosit-web` e i dati in `/srv/data/prosit`; altrimenti tutto
in `~/prosit`. Se esiste già un'installazione in `~/prosit`, l'installatore ne riprende
`local/` (stesse password e chiavi) e i dati, e alla fine ricorda che la vecchia copia si può cancellare.

Opzioni: `--legacy completo|minimo` (vedi sezione 3), `--dest DIR`, `--var DIR`, `--lizmap-www PATH`, `--projects DIR` (cartella dei progetti, se non ricavabile da `lizmapConfig.ini.php`), `--pg-ini FILE`, `--pg-user USER`, `--skip-db`,
`--no-legacy`, `--remove-legacy` (toglie i file ponte), `--yes`.

> Se un giorno l'IT preferirà un'installazione di sistema (`/opt/prosit`, `/etc/prosit`, Alias Apache
> con redirect veri): `sudo bash deploy/install-root.sh`. Il codice è lo stesso.

### Dopo un aggiornamento di Lizmap

Il collegamento e i file ponte stanno nella cartella www **della versione** di Lizmap. Dopo il
passaggio a una nuova versione (es. 3.9.11): `bash /usr/local/bin/prosit/prosit-web/install.sh --skip-db --yes`, che li ricrea.

## 3. Vecchi e nuovi indirizzi

| Vecchio (`/lizmap/...`) | Nuovo (`/lizmap/prosit/...`) | File ponte |
|---|---|---|
| `gestione-disservizi/rekla-info-sheet/schedaDisservizio.html?seg_id=` | `schede/disservizio.html?seg_id=` | pagina che reindirizza |
| `.../schedaDisservizioBit.html?seg_id=` | `schede/disservizio-bit.html?seg_id=` | pagina che reindirizza |
| `attivita-sul-territorio/manomissioni-info-sheet/manomissioni.html?id=` | `schede/manomissioni.html?id=` | pagina che reindirizza |
| `02-patrimonio-comunale-impianti/schedaPaliLuce.html?id=` | `schede/pali-luce.html?id=` | pagina che reindirizza |
| `daily-log-tvcc/` | `app/registro-tvcc/` | redirect |
| `gestione-disservizi/calendar-green-works/` | `app/calendario-verde/` | redirect |
| `.../popup-ticket-data-report/rekla.php`, `rekla_bit.php`, `report_timing.php` | `app/report-ticket/`, `bit.php`, `timing.php` | redirect |
| `opendata/admin-service/services-admin.php` | `app/opendata-admin/` | redirect |
| `gestione-disservizi/documents/document_proxy.php` | `api/index.php/v1/documenti/rekla` | redirect |
| `API/index.php/...`, `SessionCheckAPI.php` | Prosit API v2 (`/lizmap/api.php/prosit/v2`) | **tolti** |
| `09-ambiente/get_data_station.php` | `hooks/meteo/` | stesso indirizzo |
| `ws-censimento-qfield/index.php` | `hooks/qfield/` | stesso indirizzo |
| `attivita-sul-territorio/web-service-sopralluoghi/*.php` | `hooks/sopralluoghi/*.php` | stesso indirizzo |
| `API/comunication-from-ticket/sendMailFromTicket.php` | `POST /lizmap/api.php/prosit/v2/rpc/segnalazione-mail` | sostituito |

La mappa completa è in `deploy/legacy-map.txt`. I servizi chiamati da macchine restano sullo
**stesso indirizzo** (un redirect trasformerebbe i loro POST in GET). Ogni file ponte contiene il
marcatore `prosit-legacy`: l'installatore non sovrascrive mai un file che non ha creato lui
(se nella www di Lizmap ci sono ancora le vecchie cartelle copiate dalla 3.6.2, lo segnala).

### Pulire la www di Lizmap (`--legacy minimo`)

Ogni file ponte (tranne quelli fissi) registra chi lo usa: pagina di provenienza, client, IP.
Riepilogo:

```bash
/usr/local/bin/prosit/prosit-web/bin/prosit legacy:report        # ultimi 30 giorni
```

Quando l'elenco contiene solo stazione meteo e QField (o è vuoto), la www si pulisce con

```bash
bash /usr/local/bin/prosit/prosit-web/install.sh --skip-db --yes --legacy minimo
```

Restano solo `prosit` (collegamento) e i due ponti **fissi**, usati da sistemi che non si possono
riconfigurare: `09-ambiente/get_data_station.php` (stazione meteo) e `ws-censimento-qfield/` (app QField).
La scelta è ricordata in `local/install.conf`; per tornare indietro: `--legacy completo`.

Prima di passare a `minimo`: popup QGIS aggiornati (`bin/prosit find-urls /srv/data`), JS dei
progetti sostituiti con quelli di `lizmap-js/`, e chi chiama i web service sopralluoghi o la vecchia
`API/` riconfigurato sui nuovi indirizzi (`/lizmap/prosit/hooks/sopralluoghi/…`, `/lizmap/prosit/api/index.php/…`).

## 4. Da fare dopo l'installazione

1. **Cambiare le password esposte.** Nei file della vecchia VM c'erano in chiaro la password di
   `LizmapSQL` (`calendar-green-works/api/config.php`), la chiave AES del `.env` (`decript.php`) e,
   nel log `popup-ticket-data-report/logs/log_rekla_bit.log`, la password di `send_mail`.
   Prosit non le usa più: cambiarle e aggiornare gli altri programmi che le usano.
2. **Popup QGIS.** `/usr/local/bin/prosit/prosit-web/bin/prosit find-urls` elenca i vecchi indirizzi nei progetti
   (`~/lizmap-project`) con il nuovo equivalente. Consiglio: una variabile di progetto
   `prosit_base` = `/lizmap/prosit` e nei popup
   `[% @prosit_base %]/schede/disservizio.html?seg_id=[% "seg_id" %]`.
3. **Stazione meteo.** Non può inviare una chiave: è accettata **solo dal suo IP** (10.0.33.15,
   in `local/api_keys.php`). Quando avrà la sua VM, aggiornare l'indirizzo. Il file dei dati è ora
   `/srv/data/prosit/data/meteo/dati_meteo.log`: aggiornare il percorso in `process_meteo_peaks.php`.
4. **QField.** In `local/qfield.json` verificare `media_base` (proposto
   `/home/prosit2/lizmap-project/media/img`) e `php_cli` (PHP 8.2 sulla nuova VM).
5. **Rekla.** Controllare `rekla.base_url` in `local/prosit.php` (prima `http://10.0.35.1/`).
6. **Dati.** Letture e scritture passano dalla Prosit API v2 (pacchetto `prosit-api`): risorse e
   permessi in `/opt/prosit-api/config/api.yaml`.
7. **Funzioni del database con IP vecchio** (`10.0.43.29`): `correggi.py --ip` della migrazione del database.

## 5. I servizi rimasti qui

I **dati** (letture e scritture sulle tabelle) passano solo dalla **Prosit API v2**,
`/lizmap/api.php/prosit/v2` (modulo Lizmap `prositapi` + PostgREST, pacchetto `prosit-api`):
risorse, permessi per gruppo Lizmap e funzioni (`rpc/...`) si configurano in
`/opt/prosit-api/config/api.yaml`. Nelle pagine: `assets/prosit-api-v2.js` (`Prosit.api.list`,
`one`, `create`, `update`, `remove`, `rpc`).

Qui restano solo i servizi che la v2 non copre. Base: **`/lizmap/prosit/api/index.php/v1`** (la
forma con `index.php` funziona senza regole Apache):

| Metodo e percorso | Operazione | Chi lo usa |
|---|---|---|
| `GET /health` | stato (senza login; `?full=1` da 127.0.0.1: controlli come `www-data`) | install.sh, monitoraggio |
| `GET /auth/me` | utente, gruppi Lizmap e `apps` consentite | `assets/prosit-lizmap.js` (pulsanti nelle mappe) |
| `GET /documenti/rekla?post_id=&file=` | allegato Rekla tramite il server | `Prosit.urls.document` nelle schede |

Risposte: `{"success": true, "status": 200, "data": ...}` oppure
`{"success": false, "status": 403, "error": "...", "request_id": "..."}`; il `request_id` è lo
stesso scritto in `var/log/`.

**Chi è l'utente.** `src/Auth/LizmapApi.php` chiede a Lizmap `GET /lizmap/api.php/prosit/v2/auth/me`
inoltrando solo il cookie di sessione, con 30 secondi di cache (`auth.lizmap_api` in
`local/prosit.php`). Prosit non legge né i file di sessione né le tabelle degli utenti di Lizmap,
quindi funziona uguale con gli utenti in SQLite o in PostgreSQL. Se Lizmap non risponde, l'accesso
viene negato.

### Sicurezza

- tutte le pagine e i servizi richiedono la sessione Lizmap (o la chiave/IP per gli hook);
- il proxy Rekla accetta solo `post_id` numerico e nomi di file semplici;
- intestazioni di sicurezza impostate da PHP (non serve configurare Apache); le schede hanno una
  Content-Security-Policy che blocca script e stili esterni o in linea.

## 6. Aggiungere dati a una scheda o a un'applicazione

Si aggiunge una **risorsa nella Prosit API v2** (`/opt/prosit-api/config/api.yaml`, poi
`/opt/prosit-api/bin/prosit-api applica`), non qui. Nella pagina:
`await Prosit.api.list('nome-risorsa', { campo: 'valore' })`.

## 7. Grafica: Bootstrap 2 di Lizmap + tema

Tutte le pagine usano **solo risorse di Lizmap**:

| Cosa | Da dove |
|---|---|
| CSS | `/lizmap/assets/css/bootstrap.min.css` (Bootstrap 2.3.2), importato da `assets/prosit.css` |
| icone | Glyphicons di Lizmap (`/lizmap/assets/css/images/glyphicons-halflings*.png`), classi `icon-*` |
| JS (modali, tooltip) | `/lizmap/assets/js/jquery/jquery-3.5.1.min.js`, `jquery-migrate`, `/lizmap/assets/js/bootstrap.min.js` |
| favicon | `/lizmap/assets/favicon/favicon.ico` |
| font | Titillium Web, unico file nel pacchetto (`assets/vendor/titillium`) |

`assets/prosit.css` è l'**unico foglio di stile** da modificare. Contiene, in ordine:
1. gettoni (colori, misure) in stile Bootstrap Italia;
2. il tema dei componenti Bootstrap 2 (pulsanti piatti, campi, avvisi, modali, tabelle, schede);
3. **COMPAT-BS5**: le poche classi che Bootstrap 2 non ha (griglia flex `row/col-*`, `form-control`,
   `badge bg-*`, `d-flex`, spaziature...), con i **nomi di Bootstrap 5**;
4. i componenti delle schede (testata con colore di stato, sezioni, timeline, allegati).

Modali, pannello laterale e tooltip usano i plugin jQuery di Bootstrap 2
(`$('#id').modal('show')`, markup con `class="modal hide fade"` e `data-dismiss`).

**Quando Lizmap passerà a Bootstrap 5** (3.10): cambiare l'`@import` in `prosit.css` e i tre
`<script>` nelle pagine di `app/`, cancellare la sezione 3, togliere `hide` e passare da
`data-` a `data-bs-` nelle modali, e sostituire le icone `icon-*`. Le pagine usano già i nomi
di classe di Bootstrap 5.

Regole per le schede: copiare `public/schede/_modello.html` e `_modello.js`; niente `<style>`,
`<script>` o `onclick` in linea (la CSP li blocca); i dati del database passano **solo** da
`Prosit.ui.*` / `Prosit.fmt.esc`, che fanno l'escape.

## 7bis. Linguaggio comune delle schede (popup QGIS e schede esterne)

Alcune schede sono popup scritti direttamente in QGIS, altre sono schede esterne di Prosit.
Usano **le stesse classi**, definite in un solo file: `assets/prosit-components.css`.
Catalogo con esempi dal vivo e codice da copiare: **`/lizmap/prosit/schede/catalogo.html`**.

| Classe | Uso |
|---|---|
| `p-card` | contenitore della scheda (tutto il resto sta dentro) |
| `p-head` + `data-status="presa-in-carico"` | testata colorata secondo lo stato; `p-title`, `p-status`, `p-toolbar` |
| `p-body`, `h1`, `p-meta` | corpo, titolo, riga di aggiornamento |
| `details.p-section` (+ `open`, `p-auto`) | sezione a scomparsa; con `p-auto` sparisce se non ha campi valorizzati |
| `p-field` > `p-label` + `p-value` | riga etichetta/valore; **se il valore è vuoto la riga si nasconde** |
| `p-table`, `p-files`, `p-timeline`, `p-message` | tabelle, allegati, cronologia, comunicazioni |
| `label label-success`…, `btn btn-primary btn-small`, `p-actions` | classi Bootstrap 2 di Lizmap, con l'aspetto del tema |
| `p-embed` + `data-src` | incorpora una scheda esterna nel popup |

**Come arriva il CSS nei popup QGIS.** Lizmap carica in tutte le mappe i file JavaScript di
`<cartella dei progetti>/media/js/default/`. L'installatore vi scrive `prosit-lizmap.js` (copia di
`qgis/prosit-lizmap.loader.js`, con il marcatore `prosit-legacy`), che carica da Prosit
`prosit-components.css` e `prosit-lizmap.js`: gli aggiornamenti di Prosit valgono subito, senza
toccare i progetti. Requisito: nel repository deve essere attivo **`allowUserDefinedThemes=1`**
(Amministrazione Lizmap → Repository → modifica → casella "Allow themes/JavaScript codes for this repository", o la sua traduzione); l'installatore
avvisa se manca. Tutte le regole stanno dentro `.p-card`: l'interfaccia di Lizmap non cambia.

**Nel popup QGIS** (Proprietà layer → Visualizzazione → suggerimento HTML; in Lizmap: popup "QGIS"):
partire da `qgis/popup-modello.html` (generico) o `qgis/popup-segnalazione.html`.
- niente `<script>` né `<style>`: Lizmap filtra il popup con DOMPurify e li toglie;
- nessun `CASE` per i campi vuoti: `[% "campo" %]` NULL → la riga sparisce;
- stato in testata: `data-status="[% regexp_replace(lower("stato"), '[^a-z0-9]+', '-') %]"`;
- le classi si adattano alla larghezza del pannello (etichetta sopra il valore sotto i 420 px).

**Convertire in blocco i popup esistenti.** I popup scritti come pagina HTML completa con lo schema
"record-card" (`<style>`, `.record-card`, `.section-title`, `.field-row/.field-label/.field-value`)
si convertono automaticamente; le espressioni `[% … %]` restano identiche (il comando lo verifica):

```bash
P=/usr/local/bin/prosit/prosit-web/bin/prosit
$P popup:elenco               # tutti i popup: gia' nuovi, convertibili, schede esterne, da rifare a mano
$P popup:converti --prova     # anteprima prima/dopo in ~/popup_anteprima/<repository>/<progetto>/
$P popup:converti             # copia .bak di ogni progetto, poi conversione
```

Converte anche lo schema piu' vecchio "a tabelle" (titoli `<h1>`…`<h6>` con `<font>`, tabelle
etichetta/valore, `<details>`, paragrafi "Etichetta: valore"), ma solo nei popup che Lizmap usa
(sorgente "QGIS"). Le righe nascoste con `display: [% if("x" is not null,'block','none') %]` perdono
quella espressione (ora le righe vuote si nascondono da sole); tutte le altre restano identiche.

`popup:elenco` segnala anche i layer con popup "QGIS" attivo in Lizmap ma suggerimento vuoto, e quelli
con un suggerimento che Lizmap non usa (sorgente popup "auto"/"lizmap"). Quello che resta si rifà
partendo da `qgis/popup-modello.html` (esempio completo: `qgis/popup-numeri-civici.html`).

**Relazioni 1:n** (cronologia, allegati, comunicazioni…), che in QGIS sono scomode:

```html
<div class="p-embed" data-title="Scheda segnalazione"
     data-src="/lizmap/prosit/schede/disservizio.html?seg_id=[% "seg_id" %]&amp;parti=cronologia,allegati,comunicazioni"></div>
```

`prosit-lizmap.js` sostituisce il segnaposto con la scheda esterna in modalità incorporata
(`embed=1`: senza bordi né testata, altezza automatica) e, con `parti=`, mostra solo quelle sezioni
(`data-part` nelle schede: disservizio `richiesta, localizzazione, allegati, cronologia, comunicazioni`;
disservizio-bit `richiesta, localizzazione, allegati, cronologia`; manomissioni `accettazione,
richiedente, impresa, intervento, figure, allegati, controlli`; pali-luce `sostegno, interventi`).
Il risultato è un'unica scheda continua: la parte QGIS sopra, quella dal database sotto.

Perché non un `<iframe>` scritto direttamente nel popup: Lizmap mette in `sandbox` ogni iframe il
cui indirizzo non inizia con l'origine completa del sito, e una scheda in sandbox non riceve la
sessione (non si carica). Lo script crea l'iframe dopo il filtro e **solo** per pagine
same-origin sotto `/lizmap/prosit/schede/`; altri indirizzi mostrano "Scheda non consentita".
Se l'utente non è collegato, la scheda incorporata mostra l'avviso di accesso, come quelle esterne.

## 7ter. JS personalizzati dei progetti (`media/js/...`)

La cartella `lizmap-js/` contiene i JS dei progetti corretti per Prosit e Lizmap 3.9, con **la stessa
struttura di `/srv/data`** (`<repository>/media/js/<progetto o default>/file.js`), e l'elenco
`DA_CANCELLARE.txt` dei file da togliere. Si installano con un comando, che fa prima il backup:

```bash
/usr/local/bin/prosit/prosit-web/bin/prosit lizmap-js --prova   # cosa farebbe
/usr/local/bin/prosit/prosit-web/bin/prosit lizmap-js           # backup in ~/backup_js_*.tgz, copia, pulizia
```

Usano la libreria che `prosit-lizmap.js` carica in tutte le mappe:

```js
(window.prositReady = window.prositReady || []).push(function (P) {
  P.me()               // utente, gruppi e applicazioni consentite (una sola chiamata per pagina)
  P.can('registro-tvcc')
  P.url('app/...')     // indirizzo in Prosit;  P.lizmapUrl('...') indirizzo nelle vecchie cartelle
  P.menuLink({...})    // voce di menu che apre una pagina (solo a chi può aprirla)
  P.tableButton(layer, {...}) / P.exportItem({...})   // pulsanti nelle tabelle attributi
  P.watchTables(sel, fn) / P.paintStatus(td) / P.paintTone(td, 'ok'|'warn'|'ko')
  P.operatore(['campo', ...])                          // utente collegato nei moduli di modifica
});
```

**Chi vede cosa** sta in un solo posto, `config 'apps'` (sezione `apps` di `config/defaults.php`,
sovrascrivibile in `local/prosit.php`): `GET /v1/auth/me` restituisce `apps` e i pulsanti nelle mappe
lo usano; `report-ticket-bit` è controllato anche dal server. Prima i gruppi erano scritti in ogni JS.

| File | Cosa è cambiato |
|---|---|
| `rekla/button_link_tvcc.js`, `button_link_green_works.js` | niente `SessionCheckAPI.php` né IP; voce di menu solo per chi ha l'app; non apre più il pannello vuoto |
| `rekla/popup_report_segn_start.js` | report da `app/report-ticket` (l'utente lo prende il server, niente `?user=`); opzione BIT secondo `apps`; niente stili e `<style>` in linea |
| `rekla/color_cell_table.js`, `01-…/color_cell_contratti.js` | colori di stato comuni (gli stessi delle schede); date gg/mm/aaaa lette correttamente (prima 03/05 diventava 5 marzo) |
| `rekla/add_hard_coded_legend_at_map_bottom.js` | niente Font Awesome da Internet: Glyphicons di Lizmap e simboli Unicode; niente legende duplicate |
| `rekla/calendar_popup_form_zindex.js` | una regola CSS invece di un controllo ogni 300 ms |
| `rekla/refresh_iframe_content_after_event.js` | **da cancellare**: lo fa Prosit (ricarica le schede incorporate dopo ogni modifica) |
| `*/insert_operator_automatically*.js` | una riga ciascuno (`P.operatore([...])`), nome dalla sessione; tolto codice morto |
| `01-…/button_print_table_*.js` | indirizzi relativi; Trasparenza con menu a tendina invece di `prompt()` |
| `01-…/note.js` | clic letto dalla mappa OpenLayers di Lizmap 3.9 (la vecchia `lizMap.map` non riceve più i clic), solo con il pannello aperto; messaggi di Lizmap invece di `alert()` |
| `01-…/gest_patrim.js` | indirizzi relativi, voce di menu senza trucchi |
| `09-ambiente/button_station.js` | se Lizmap è in https la pagina meteo (solo http) si apre in una scheda: in un iframe il browser la bloccherebbe |
| `*/geolocation.js` | API di Lizmap 3.9 (`mainLizmap.geolocation`) invece dei clic sui pulsanti |
| `est/…/opendata/button_link_service.js` | apre `app/opendata-admin` di Prosit; niente IP né SessionCheckAPI; visibile secondo `apps` |
| `*/default/insert_operator_automatically.js` | `P.operatore(['user'])` |
| `*/default/resize_dock.js` | niente interact.js da Internet: maniglia di trascinamento in poche righe, larghezza ricordata |
| `*/default/tour_3.7.js` | Shepherd.js 10.0.1 servito da Prosit (`assets/vendor/shepherd`) invece che da cdn.jsdelivr.net |
| `*/default/copy-permalink.js` | API degli appunti, con ripiego per http |
| `14-…/open_todos_sopralluoghi.js` | (1.9) controllo dei todo in sottofondo, senza pannello che blocca la mappa; risultato recente riusato dal server (15 min), issue lette in parallelo; indirizzi di Prosit invece di 10.0.43.29 e `SessionCheckAPI.php` |
| `*/default/simplify-export-options.js` | segue i menu ridisegnati da Lizmap 3.9; non toglie le voci di Prosit |
| `est/`, `segn/…/default/filtro_legenda.js` | stessa versione aggiornata di `cons` |

Il vecchio `/lizmap/SessionCheckAPI.php` non c'è più (nel registro dei vecchi indirizzi risultava
chiamato solo da prove locali): i JS usano `P.me()` di `prosit-lizmap.js`.

**Funzioni morte, tolte.** Diversi JS e popup chiamavano pagine che non esistono su nessun server
attuale (cercate su tutto il disco della vecchia VM; `10.0.33.29` non risponde): stampe, inventario e
note della 01-patrimonio, `iframe_content.php`, `ridSf.php` (riduzione sfalci), `openTodo`, modulo
scarichi fognari. I JS sono in `DA_CANCELLARE.txt`; dai popup le toglie

```bash
/usr/local/bin/prosit/prosit-web/bin/prosit popup:pulisci --prova    # cosa farebbe
/usr/local/bin/prosit/prosit-web/bin/prosit popup:pulisci            # copia .bak di ogni progetto, poi pulizia
```

(i progetti aperti in QGIS vanno chiusi senza salvare). Se una di queste funzioni serve, si rifà in
Prosit: per la riduzione sfalci bastano tabella, campo e gruppi autorizzati.

**AINOP**: l'esportazione CSV è stata tolta (versione 2.1.0).

## 8. Cosa è stato corretto rispetto alla vecchia VM

**1.9.2** — `open_todos_sopralluoghi.js`: "Apri Todo" e l'indicatore nella barra in alto di Lizmap, prima dell'utente (come "Info segnalazioni").

**1.9.1** — `open_todos_sopralluoghi.js`: modalità prova con `&todo_prova=1` nell'indirizzo della mappa (legge Twproject e il DB, non crea né registra nulla).

**1.9.0** — scheda ticket edifici (`disservizio-bit`): sezione *ToDo Twproject* con il link al todo, come
nella scheda Rekla. Nuova risorsa API `todo` (`audit.todo_queue`, sola lettura, senza il testo del todo):
serve `GRANT SELECT ON audit.todo_queue TO prosit_ro, prosit_rw` (in `sql/10_grant_prosit.sql`).
La scheda Rekla prende i todo da qui ed esclude quelli BIT: la vista `audit.frontend_communication` li lega
solo per numero, quindi il ticket Rekla 10 mostrava anche il todo del ticket BIT 10.

| Problema | Prima | Ora |
|---|---|---|
| Password nel codice e nei log | `config.php`, `decript.php`, log rekla | solo `local/pg_service.conf` |
| Utente DB | `LizmapSQL` per tutto | `prosit_ro` (sola lettura) / `prosit_rw` (solo dove serve) |
| Proxy documenti Rekla | nessun controllo di accesso | sessione Lizmap; documenti serviti in sandbox |
| Ricezione dati meteo | chiunque poteva scrivere nella web root | solo dall'IP della stazione, POST, limite di dimensione, fuori dalla web root |
| Lettura API | nessun controllo sui gruppi; errori SQL al browser | Prosit API v2: permessi in PostgreSQL per gruppo Lizmap; errori tradotti + `request_id` |
| Filtri API | la regex `0-0` scartava le cifre 1-9 dai nomi colonna | Prosit API v2 (PostgREST) |
| Token API di prova | `admin-token` con accesso completo | rimosso; chiavi solo come hash |
| Sessione | curl verso `10.0.43.29/SessionCheckAPI.php` | chiesta a Lizmap (`/prosit/v2/auth/me`), nessun IP scritto nel codice |
| XSS nelle schede | testo del DB inserito come HTML | escape ovunque + CSP |
| CSRF | nessuno | controllo `Origin` su ogni modifica |
| Operatore TVCC | lo inviava il browser (falsificabile) | lo imposta il server dalla sessione |
| Calendario | DEMO silenziosa (salvava nel browser) se l'API non rispondeva | messaggio d'errore |
| Report per un utente senza gruppi | mostrava tutte le segnalazioni | non mostra nulla |
| `report_timing.php` | senza login, includeva un file inesistente | login, funzionante |
| `sendMailFromTicket.php` | chiamava `get_db()` senza argomenti (errore fatale) | Prosit API v2 `rpc/segnalazione-mail` |
| Dati e log | dentro la web root (scaricabili) | `var/`, non pubblicata |
| Grafica | Bootstrap Italia + Bootstrap 5 + Google Fonts + 3 CDN | solo Bootstrap 2 di Lizmap + un tema |

## 9. Manutenzione

```bash
/usr/local/bin/prosit/prosit-web/bin/prosit check                 # verifica (come prosit2)
curl -s 'http://127.0.0.1/lizmap/prosit/api/index.php/v1/health?full=1'   # verifica come www-data
/usr/local/bin/prosit/prosit-web/bin/prosit key:new nome          # nuova chiave per una macchina
/usr/local/bin/prosit/prosit-web/bin/prosit find-urls             # vecchi indirizzi nei progetti QGIS
tail -f /srv/data/prosit/log/api-$(date +%Y-%m).log     # errori (cercare il request_id)
```

- **Aggiornare Prosit**: nuova versione del pacchetto, poi `bash install.sh --yes` (non tocca `local/` né `var/`).
- **Test** (database `prosit_test_*`, quelli veri non vengono toccati):
  `PGHOST=127.0.0.1 PGUSER=postgres PGPASSWORD=... bash tests/run.sh`.
- **Togliere Prosit**: `bash install.sh --remove-legacy` e `rm <www di Lizmap>/prosit`.

## 10. Note su Lizmap 3.9 (verificate sul sorgente)

- Jelix 1.8 imposta il cookie di sessione con path = base di Lizmap (`/lizmap/`): per questo
  Prosit è pubblicato sotto `/lizmap/prosit`.
- Utente e gruppi li fornisce Lizmap (`/prosit/v2/auth/me`, gruppi privati esclusi): Prosit non
  dipende da come Lizmap salva sessioni e utenti (SQLite o PostgreSQL).
- Il "ricordami" di Lizmap ricrea la sessione solo quando si apre una pagina di Lizmap: se la
  sessione PHP è scaduta, le schede chiedono di accedere a Lizmap.

## Parte di Prosit

Questo componente fa parte di **[Prosit](https://github.com/ComuneMontelupoFiorentino/prosit)**,
il Sistema Informativo Territoriale del Comune di Montelupo Fiorentino. Nel repository
[prosit](https://github.com/ComuneMontelupoFiorentino/prosit) ci sono l'architettura, l'elenco
di tutti i componenti (topic [`prosit`](https://github.com/search?q=org%3AComuneMontelupoFiorentino+topic%3Aprosit&type=repositories))
e le versioni che funzionano insieme.
