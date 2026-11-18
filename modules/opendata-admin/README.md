# OpenData & services.json — Guida di riferimento

Questa guida spiega due cose:

1. **Come compilare `services.json`**, in particolare la sezione `datasets`.
2. **Come usare `OpenData`** (l'oggetto globale esposto da `service-head.js`) per costruire pagine che leggono e visualizzano quei dati.

Per la gestione pratica del file (creare/modificare/testare i servizi senza editare l'JSON a mano) usa `services-admin.php`. Questo documento spiega invece **cosa** significano i campi e **come** consumarli in una pagina.

---

## 1. Struttura generale di `services.json`

Il file è un array di **servizi**. Ogni servizio è un oggetto con questi campi:

| Campo | Tipo | Obbligatorio | Descrizione |
|---|---|---|---|
| `id` | stringa | ✅ | Identificativo usato negli URL (`?service=id`). Solo `a-z 0-9 _ -`, minuscolo. Deve corrispondere allo slug del titolo (il pannello admin lo genera/verifica da solo). |
| `title` | stringa | ✅ | Titolo mostrato in testata. |
| `desc` | stringa | | Sottotitolo, una frase. |
| `icona` | stringa | | Nome icona nello sprite SVG del progetto (es. `it-calendar`). |
| `link` | stringa | | URL per raggiungere la pagina del servizio, es. `?service=cerca-manutenzioni`. |
| `new` | booleano | | Mostra un badge "novità" nell'elenco pubblico. |
| `allowEmbed` | booleano | | Consente di incorporare il servizio in un iframe su un dominio pubblico esterno. |
| `dev` | `"prod"` \| `"debug"` | | Usato solo dal pannello admin per l'export: `prod` = incluso nell'export di produzione (campo poi rimosso); `debug` = escluso dall'export prod, visibile solo in quello debug. Assente = trattato come `prod`. |
| `tags` | array di stringhe | | Parole chiave per ricerca/filtro nell'elenco pubblico. |
| `alert` | array di `{typeAlert, text}` | | Banner mostrati in testata. `typeAlert`: `danger` (errore), `success`, `warning`, `info`. |
| `descriptiion` | array di blocchi `{tag: testo}` | | Contenuto del modale "info" (icona accanto al titolo). Ogni blocco è un oggetto con **una chiave** che è il nome del tag HTML (`p`, `h3`, `h4`, `strong`, `em`, `b`, `i`, `span`, `li`, `ul`, `br`) e il valore è il testo. |
| `datasets` | array di dataset | | Vedi sezione 2. |

Esempio minimo:

```json
{
  "id": "cerca-manutenzioni",
  "title": "Cerca Manutenzioni",
  "desc": "Esplora la programmazione degli interventi.",
  "icona": "it-calendar",
  "link": "?service=cerca-manutenzioni",
  "dev": "prod",
  "tags": ["Manutenzioni", "Verde Pubblico"],
  "alert": [
    { "typeAlert": "warning", "text": "I dati sono aggiornati settimanalmente." }
  ],
  "descriptiion": [
    { "p": "Questo servizio mostra il programma degli interventi di manutenzione ordinaria." },
    { "h3": "Come leggere i dati" },
    { "p": "Ogni intervento è associato a una data e a uno stato di avanzamento." }
  ],
  "datasets": []
}
```

---

## 2. La sezione `datasets` (il cuore del file)

Ogni servizio ha uno o più dataset. Un dataset descrive **una fonte dati** che la pagina potrà richiedere tramite `OpenData.getData(alias)`.

### 2.1 Campi base

| Campo | Tipo | Obbligatorio | Descrizione |
|---|---|---|---|
| `alias` | stringa | ✅ | Nome breve, univoco **nel servizio**, usato dal codice: `OpenData.getData('alias')`. |
| `fetch` | stringa (o array di stringhe) | ✅ | L'URL da cui scaricare i dati. Se è un array, `OpenData` prova ogni URL in ordine (fallback), fermandosi al primo che risponde. |
| `nameDataset` | stringa | consigliato | Etichetta mostrata nella lista dataset in testata. |
| `url` | stringa | consigliato | Link alla scheda del dataset su CKAN (solo informativo — **non** è l'URL da cui si scaricano i dati, quello è `fetch`). |
| `cache` | booleano | | Se `true` (default), `OpenData` mantiene i dati in memoria nel browser per la sessione corrente. Se `false`, ogni chiamata a `getData` rifà il fetch. |

### 2.2 Il campo `fetch`: diretto o via relay

Se il server che ospita i dati (es. CKAN) **non** restituisce l'header `Access-Control-Allow-Origin`, il browser blocca la lettura della risposta per CORS — indipendentemente da quanto sia corretto il resto della configurazione. In questo caso `fetch` deve puntare al **relay locale** (`opendata_relay.php`), che scarica i dati lato server (dove CORS non si applica) e li restituisce same-origin:

```json
"fetch": "api/opendata_relay.php?u=https%3A%2F%2Fdati.toscana.it%2Fdataset%2F...%2Fdownload%2Ffile.json"
```

Il parametro `u` è l'URL reale **urlencodato**. Non scriverlo a mano: nel pannello `services-admin.php` inserisci l'URL reale nel campo apposito e spunti "Usa relay" — la stringa sopra viene generata automaticamente.

Puoi anche dichiarare un array con entrambi, così il diretto viene tentato per primo (utile se in futuro CKAN abiliterà CORS) e il relay scatta solo come fallback:

```json
"fetch": [
  "https://dati.toscana.it/dataset/.../download/file.json",
  "api/opendata_relay.php?u=https%3A%2F%2Fdati.toscana.it%2Fdataset%2F...%2Fdownload%2Ffile.json"
]
```

### 2.3 Formati dati riconosciuti automaticamente

`OpenData` riconosce da solo il formato del JSON scaricato e lo riduce sempre a un array di record prima di applicare filtri/ordinamento/limite, poi ricostruisce il formato originale in uscita. Non serve dichiarare nulla per questi due casi:

**Array semplice:**
```json
[ { "campo": "valore" }, { "campo": "valore2" } ]
```

**GeoJSON:**
```json
{ "type": "FeatureCollection", "features": [ { "type": "Feature", "properties": {...}, "geometry": {...} } ] }
```

**Struttura raggruppata** (es. dati organizzati per data): questa invece **va dichiarata esplicitamente**, perché non è un formato standard:

```json
{
  "giorni": {
    "2026-06-04": {
      "totale": 3,
      "interventi": [
        { "fk": "PIAZZA C. CASTELLANI", "status": "Eseguito" }
      ]
    }
  }
}
```

Per questo caso, aggiungi al dataset:

| Campo | Descrizione |
|---|---|
| `recordsPath` | Percorso a punti con **un** segmento jolly `*` che indica il livello di raggruppamento, es. `"giorni.*.interventi"`. |
| `groupKeyField` | (opzionale) Nome campo in cui iniettare la chiave di raggruppamento (es. la data) in ogni record, così i filtri/l'ordinamento possono usarla. Viene rimosso in uscita se non era già presente nel record originale — è un campo di lavoro, non finisce nel dato finale a meno che non fosse già lì. |
| `groupCountField` | (opzionale) Nome del campo del gruppo da ricalcolare col nuovo conteggio dopo i filtri (es. `"totale"`). |

```json
{
  "alias": "plan",
  "fetch": "...",
  "recordsPath": "giorni.*.interventi",
  "groupKeyField": "date",
  "groupCountField": "totale"
}
```

### 2.4 Filtri (`filters`)

Array di condizioni applicate ai record, in **AND** (un record deve soddisfarle tutte). Due modalità per il valore:

**Valore statico:**
```json
{ "field": "status", "operator": "=", "value": "Eseguito" }
```

**Valore dalla query string della pagina** (es. `?obj_id=15`):
```json
{ "field": "id", "operator": "=", "source": "query", "parameter": "obj_id" }
```
Se il parametro non è presente nell'URL, il filtro viene **ignorato** (non esclude tutti i record).

**Operatori supportati:** `=`, `!=`, `>`, `>=`, `<`, `<=`, `contains`, `startsWith`, `endsWith`, `in`, `notIn`, `isNull`, `isNotNull`.

- `contains`/`startsWith`/`endsWith` non distinguono maiuscole/minuscole.
- `in`/`notIn` si aspettano un array come `value` (es. `["Programmato", "Eseguito"]`).
- `isNull`/`isNotNull` non hanno `value`: valutano solo se il campo è vuoto/assente.

### 2.5 Ordinamento (`sort`) e limite (`limit`)

```json
"sort": { "field": "date", "direction": "desc" },
"limit": 100
```

`direction` è `asc` o `desc`. Se ometti `sort`, i record restano nell'ordine in cui arrivano dalla fonte. `limit` tronca l'array **dopo** filtri e ordinamento.

### 2.6 Esempio di dataset completo

```json
{
  "nameDataset": "Programma Interventi di manutenzione ordinaria",
  "alias": "plan",
  "url": "https://dati.toscana.it/dataset/pianificazione-interventi",
  "fetch": "api/opendata_relay.php?u=https%3A%2F%2Fdati.toscana.it%2Fdataset%2F4073a84d-abf1-4cbd-bac1-8077bdf9a311%2Fresource%2F213a162e-f903-478a-b311-72f411e37729%2Fdownload%2Finterventi_di_manutenzione_ordinaria.json",
  "cache": true,
  "recordsPath": "giorni.*.interventi",
  "groupKeyField": "date",
  "groupCountField": "totale",
  "filters": [
    { "field": "status", "operator": "=", "value": "Eseguito" }
  ],
  "sort": { "field": "date", "direction": "desc" },
  "limit": 200
}
```

---

## 3. Usare OpenData in una pagina

`OpenData` è disponibile come `window.OpenData` in ogni pagina che include `service-head.js` — non solo nella pagina di testata. Qualunque script della pagina può usarlo.

### 3.1 Il flusso base

```javascript
// 1) registra i dataset del servizio (legge services.json, una volta per pagina)
await OpenData.loadService('cerca-manutenzioni');

// 2) recupera i dati di un dataset per alias — filtri/ordinamento/limit
//    dichiarati in services.json sono già applicati automaticamente
const plan = await OpenData.getData('plan');

// 3) usa i dati per compilare la pagina
plan.forEach(function (intervento) {
  console.log(intervento.location, intervento.status);
});
```

Non serve mai conoscere l'URL reale del dataset, né gestire fetch/retry/timeout/CORS a mano: `OpenData` se ne occupa.

### 3.2 Costruire una pagina dinamica: esempio completo

```javascript
document.addEventListener('DOMContentLoaded', async function () {
  var listEl = document.getElementById('lista-interventi');

  try {
    await OpenData.loadService('cerca-manutenzioni');
    var interventi = await OpenData.getData('plan');

    listEl.innerHTML = interventi.map(function (i) {
      return '<li>' + i.location + ' — ' + i.status + ' (' + i.date + ')</li>';
    }).join('');
  } catch (err) {
    // OpenData mostra già un alert generico all'utente; qui puoi solo
    // aggiungere logica supplementare (es. nascondere uno spinner)
    console.error('Impossibile caricare gli interventi', err);
  }
});
```

Se il dataset ha un filtro con `"source": "query"` (es. sul parametro `obj_id`), una pagina di dettaglio può semplicemente essere aperta con `?obj_id=15` — il filtro scatta da solo, senza scrivere alcuna logica di filtraggio nella pagina.

### 3.3 Più dataset nello stesso servizio

```javascript
await OpenData.loadService('cerca-manutenzioni');

const [plan, mapElements] = await Promise.all([
  OpenData.getData('plan'),
  OpenData.getData('map-elements')
]);
```

### 3.4 Forzare un aggiornamento (ignorando la cache)

```javascript
// utile per un bottone "Aggiorna" nella pagina
await OpenData.refresh('plan');
```

### 3.5 Diagnostica: tempo di risposta, provenienza, numero record

```javascript
const info = OpenData.getInfo('plan');
// { elapsedMs, recordCount, source: 'cache'|'network', timestamp }
```

### 3.6 Gestione errori centralizzata

```javascript
OpenData.onError(function (err, context) {
  // context.phase: 'getData' | 'probe'
  // context.alias: alias del dataset che ha fallito
  console.warn('OpenData ha segnalato un errore:', context.alias, err.message);
});
```

`OpenData` mostra già un alert visivo generico all'utente in caso di errore (nel box `#alertMessageService`); `onError` serve solo per logica **aggiuntiva** (es. telemetria), non sostituisce quell'alert.

### 3.7 Più servizi nella stessa pagina

Il `Registry` interno è condiviso da tutta la pagina. Se carichi più servizi con `loadService()`, i loro dataset si accumulano per alias. **Attenzione**: se due servizi diversi usano lo stesso alias, l'ultimo caricato sovrascrive il precedente per quell'alias. Per pagine che aggregano più servizi contemporaneamente, usa alias namespaced (es. `"cerca-manutenzioni:plan"`) per evitare collisioni.

---

## 4. Riferimento rapido: API pubblica di OpenData

| Metodo | Descrizione |
|---|---|
| `OpenData.loadService(id)` | Carica `services.json`, trova il servizio e registra i suoi dataset. |
| `OpenData.getData(alias)` | Restituisce i dati del dataset (Promise), con filtri/ordinamento/limit già applicati. |
| `OpenData.refresh(alias)` | Come `getData`, ma ignora la cache. |
| `OpenData.getInfo(alias)` | Statistiche dell'ultimo fetch riuscito. |
| `OpenData.aliases()` | Elenco degli alias attualmente registrati. |
| `OpenData.get(alias)` | Restituisce la definizione grezza del dataset (utile per debug). |
| `OpenData.probe(opts)` | Verifica la raggiungibilità di uno o più dataset all'avvio. |
| `OpenData.onError(fn)` | Callback per errori di fetch, oltre all'alert visivo automatico. |
| `OpenData.configure(opts)` | Sovrascrive impostazioni globali (retries, timeout, ecc.). |
| `OpenData.showAlert(type, text)` / `OpenData.hideAlert()` | Alert generico riusabile del servizio. |

---

## 5. Errori comuni da evitare

- **Alias duplicato nello stesso servizio**: il secondo sovrascrive il primo nel Registry — `OpenData.getData()` restituirà sempre i dati del secondo. Il pannello admin lo segnala come errore bloccante.
- **`id` del servizio non conforme** (`a-z 0-9 _ -`): il router pubblico scarta silenziosamente qualunque id fuori da questo pattern — il servizio non sarà mai raggiungibile.
- **`recordsPath` senza il segmento `*`**: non verrà riconosciuto come struttura raggruppata, e i dati passeranno al resto della pipeline così come sono (probabilmente rompendo filtri/ordinamento).
- **Filtro `in`/`notIn` con un singolo valore invece di un array**: funziona comunque (`OpenData` normalizza in array), ma è più chiaro scrivere esplicitamente `["valore"]`.
- **Dimenticare `groupKeyField`** in un dataset raggruppato quando serve filtrare/ordinare per la chiave di raggruppamento (es. la data): senza quel campo, quell'informazione non è disponibile ai record durante l'elaborazione.