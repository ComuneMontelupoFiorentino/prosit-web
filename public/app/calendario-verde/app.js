/* ============================================================
   VP Planner - app.js
   Integrazione API Lizmap (sessione, tipi, oggetti) + DB via PHP.
   Fallback DEMO (dati locali) se le API non sono raggiungibili.
   ============================================================ */
(function () {
  'use strict';

  // --- Endpoint (stesso host di Lizmap in produzione) ---
  // API Prosit v2 (stessa origine di Lizmap): /lizmap/api.php/prosit/v2, client assets/prosit-api-v2.js
  const api = window.Prosit && window.Prosit.api;
  // NB: in produzione l'abilitazione e il manteiner arrivano da /rpc/calendario-contesto
  // (unica fonte di verità = config.php). Le costanti qui sotto servono SOLO
  // alla modalità DEMO offline (file://), per l'anteprima senza backend.
  const EDITOR_GROUPS = ['man_verde_es', 'man_int'];
  const GROUP_MANTEINER = { 'man_verde_es': 'Cooperativa orizzonti', 'man_int': 'Manutentori interni' };
  const TYPE_FONTE = {
    'potature siepi': 'verde',
    'falciatura tappeti erbosi': 'verde',
    'Pulizia Strade, Piazze, Parcheggi...': 'strade',
  };
  const TYPE_COLOR = {
    'potature siepi': '#9c6b00',
    'falciatura tappeti erbosi': '#16794c',
    'Pulizia Strade, Piazze, Parcheggi...': '#3a5e8c',
  };

  const MESI = ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre'];
  const GIORNI = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];

  let MODE = 'api';
  const state = {
    login: '—', role: 'none', manteiner: null, isEditor: false, editorGroups: [],
    tipi: [], objectsByType: {}, objById: {},
    current: firstOfMonth(new Date()), giorni: {},
  };

  function firstOfMonth(d){ return new Date(d.getFullYear(), d.getMonth(), 1); }
  function ymd(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
  function el(id){ return document.getElementById(id); }
  function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
  function fonteDi(t){ return TYPE_FONTE[t] || 'verde'; }
  function coloreDi(t){ return TYPE_COLOR[t] || '#5d7083'; }
  function isMobile(){ return window.matchMedia('(max-width: 767.98px)').matches; }
  function fmtData(iso){ const d=new Date(iso+'T00:00:00'); return d.toLocaleDateString('it-IT',{weekday:'long',day:'numeric',month:'long'}); }

  /* ----------------------------- DATA LAYER ----------------------------- */

  async function loadContext(){
    if(location.protocol==='file:') { MODE='demo'; Demo.init(); return Demo.context(); }
    let ctx;
    try{
      // Unica fonte di verità: /rpc/calendario-contesto (sezione 'calendario' di config/api.yaml)
      const c = await api.rpc('calendario-contesto', {}, 'GET');
      ctx = { login: c.login || '—', role: c.role || 'none', manteiner: c.manteiner, isEditor: !!c.isEditor,
              seeAll: !!c.seeAll, filterGroups: c.filterGroups || [] };
      MODE='api';
    }catch(e){
      // Niente piu' DEMO silenziosa: in produzione salverebbe solo nel browser.
      console.error('Contesto non disponibile', e);
      const msg = e.status===401 ? 'Sessione Lizmap assente o scaduta: accedi a Lizmap e ricarica la pagina.'
                                 : 'Servizio non disponibile: ' + e.message;
      return { login:'—', role:'none', manteiner:null, isEditor:false, seeAll:false, editorGroups:[], tipi:[], objs:[], error: msg };
    }
    // Tipi e oggetti dalle API Lizmap, filtrati per i gruppi indicati dal server.
    const fg = ctx.filterGroups;
    let tipi=[], objs=[];
    try{ const tj=await api.list('vp-tipi-intervento');
      tipi = uniq((tj||[]).filter(r=>fg.includes(r.usergroup)).map(r=>r.int_name)).filter(n=>TYPE_FONTE[n]);
    }catch(e){ console.warn('join_int_user non disponibile', e); }
    try{ const oj=await api.list('vp-oggetti-manutentore');
      objs = (oj||[]).filter(o=>fg.includes(o.maintainer));
    }catch(e){ console.warn('obj_mantainer_int non disponibile', e); }
    return { login: ctx.login, role: ctx.role, manteiner: ctx.manteiner, isEditor: ctx.isEditor, seeAll: ctx.seeAll, editorGroups: fg, tipi, objs };
  }

  function indexObjects(objs){
    const byType = {};
    const byId = {};

    objs.forEach(o => {
        const tipo = o.int_ty;   // <-- invece di int_name

        (byType[tipo] = byType[tipo] || []).push(o);
        byId[o.id] = o.name_obj;
    });

    return { byType, byId };
}

  async function listInterventi(){
    const dal=ymd(state.current), al=ymd(new Date(state.current.getFullYear(),state.current.getMonth()+1,0));
    let giorni;
    if(MODE==='demo') giorni=Demo.list(dal,al);
    else { const j=await api.rpc('calendario-interventi', { dal, al }, 'GET'); giorni=(j&&j.giorni)||{}; }
    // risolvi il "luogo" per il verde tramite gli oggetti gia' scaricati
    Object.values(giorni).forEach(g=>g.interventi.forEach(it=>{
      it.tipo_colore=coloreDi(it.tipo);
      it.luogo = it.fonte==='strade' ? it.strada : (state.objById[it.rif] || ('Zona #'+it.rif));
    }));
    return giorni;
  }
  async function apiWrite(funzione,args){
    return Object.assign({success:true}, (await api.rpc(funzione, args)) || {});
  }
  async function createIntervento(p){ return MODE==='demo'?Demo.create(p):apiWrite('calendario-crea', p); }
  async function updateIntervento(id,fonte,p){ return MODE==='demo'?Demo.update(id,p):apiWrite('calendario-modifica', { id, fonte, dati: p }); }
  async function deleteIntervento(id,fonte){ return MODE==='demo'?Demo.remove(id):apiWrite('calendario-elimina', { id, fonte }); }

  function uniq(a){ return Array.from(new Set(a)); }

  /* ------------------------------ DEMO ------------------------------ */
  const Demo = {
    KEY:'vp_planner_v3', data:null,
    _session:{ login:'m.rossi', groups:{names:['man_verde_es'],ids:['man_verde_es']} },
    _types:[
      {int_name:'potature siepi',usergroup:'man_verde_es'},
      {int_name:'falciatura tappeti erbosi',usergroup:'man_verde_es'},
      {int_name:'Pulizia Strade, Piazze, Parcheggi...',usergroup:'man_verde_es'},
      {int_name:'alberature',usergroup:'man_int'},
    ],
    _objs:[
      {id:12,name_obj:'PARCO FLUVIALE',maintainer:'man_verde_es',int_name:'falciatura tappeti erbosi'},
      {id:13,name_obj:'AIUOLE VIALE STAZIONE',maintainer:'man_verde_es',int_name:'falciatura tappeti erbosi'},
      {id:16,name_obj:'PARCO DELLA RIMEMBRANZA',maintainer:'man_verde_es',int_name:'falciatura tappeti erbosi'},
      {id:17,name_obj:'GIARDINI DI VIA VERDI',maintainer:'man_verde_es',int_name:'falciatura tappeti erbosi'},
      {id:18,name_obj:'AREA VERDE VIA DANTE',maintainer:'man_verde_es',int_name:'falciatura tappeti erbosi'},
      {id:19,name_obj:'PRATO SCUOLA MEDIA',maintainer:'man_verde_es',int_name:'falciatura tappeti erbosi'},
      {id:20,name_obj:'ROTONDA VIA EUROPA',maintainer:'man_verde_es',int_name:'falciatura tappeti erbosi'},
      {id:14,name_obj:'SIEPI VIA MARCONI',maintainer:'man_verde_es',int_name:'potature siepi'},
      {id:15,name_obj:'SIEPI PARCO NORD',maintainer:'man_verde_es',int_name:'potature siepi'},
      {id:21,name_obj:'SIEPI VIALE STAZIONE',maintainer:'man_verde_es',int_name:'potature siepi'},
      {id:22,name_obj:'SIEPI PIAZZA DUOMO',maintainer:'man_verde_es',int_name:'potature siepi'},
      {id:9, name_obj:'PARCHEGGIO SCAMBIATORE',maintainer:'man_verde_es',int_name:'Pulizia Strade, Piazze, Parcheggi...'},
      {id:7, name_obj:"VIA DELL'ARGINE",maintainer:'man_verde_es',int_name:'Pulizia Strade, Piazze, Parcheggi...'},
      {id:23,name_obj:'VIA ROMA',maintainer:'man_verde_es',int_name:'Pulizia Strade, Piazze, Parcheggi...'},
      {id:24,name_obj:'VIA GARIBALDI',maintainer:'man_verde_es',int_name:'Pulizia Strade, Piazze, Parcheggi...'},
      {id:25,name_obj:'PIAZZA VITTORIO VENETO',maintainer:'man_verde_es',int_name:'Pulizia Strade, Piazze, Parcheggi...'},
      {id:26,name_obj:'VIALE DELLA REPUBBLICA',maintainer:'man_verde_es',int_name:'Pulizia Strade, Piazze, Parcheggi...'},
      {id:1, name_obj:'PIAZZA VIII MARZO',maintainer:'man_int',int_name:'Pulizia Strade, Piazze, Parcheggi...'},
    ],
    init(){ try{this.data=JSON.parse(localStorage.getItem(this.KEY));}catch(e){this.data=null;} if(!this.data)this.seed(); },
    save(){ try{localStorage.setItem(this.KEY,JSON.stringify(this.data));}catch(e){} },
    seed(){
      const y=new Date().getFullYear(), m=new Date().getMonth();
      const d=(day)=>`${y}-${String(m+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
      let seq=100; this.data={seq:0,items:[]};
      this.data.items.push({id:++seq,fonte:'verde',tipo:'falciatura tappeti erbosi',data:d(3),rif:12,strada:null,eseguito:false,date_int_ex:null,planned_int:'su Programmazione'});
      this.data.items.push({id:++seq,fonte:'verde',tipo:'potature siepi',data:d(6),rif:14,strada:null,eseguito:true,date_int_ex:d(6),planned_int:'su Programmazione'});
      this.data.items.push({id:++seq,fonte:'strade',tipo:'Pulizia Strade, Piazze, Parcheggi...',data:d(11),rif:null,strada:"VIA DELL'ARGINE",eseguito:false,date_int_ex:null,planned_int:'su Programmazione'});
      this.data.seq=seq; this.save();
    },
    context(){
      const groups=uniq([].concat(this._session.groups.names,this._session.groups.ids));
      const editorGroups=EDITOR_GROUPS.filter(g=>groups.includes(g));
      const manteiner=editorGroups.includes('man_verde_es')?GROUP_MANTEINER['man_verde_es']:(editorGroups.includes('man_int')?GROUP_MANTEINER['man_int']:null);
      const tipi=uniq(this._types.filter(r=>editorGroups.includes(r.usergroup)).map(r=>r.int_name)).filter(n=>TYPE_FONTE[n]);
      const objs=this._objs.filter(o=>editorGroups.includes(o.maintainer));
      return { login:this._session.login, manteiner, isEditor:editorGroups.length>0, editorGroups, tipi, objs };
    },
    list(dal,al){
      const g={};
      this.data.items.filter(it=>it.data>=dal&&it.data<=al).forEach(it=>{
        (g[it.data]=g[it.data]||{interventi:[]}).interventi.push({...it});
      });
      return g;
    },
    create(p){
      const dates=buildDates(p.data,p.ripeti), ids=[];
      dates.forEach(dd=>{ const id=++this.data.seq;
        this.data.items.push({id,fonte:fonteDi(p.tipo),tipo:p.tipo,data:dd,
          rif:fonteDi(p.tipo)==='verde'?p.id_zona:null, strada:fonteDi(p.tipo)==='strade'?p.strada:null,
          eseguito:!!p.execution,date_int_ex:p.execution?(p.date_int_ex||dd):null,planned_int:'su Programmazione'});
        ids.push(id); });
      this.save(); return {success:true,ids,creati:ids.length};
    },
    update(id,p){ const it=this.data.items.find(x=>x.id===id); if(!it)return{success:true};
      if('data'in p)it.data=p.data; if('ty_int'in p)it.tipo=p.ty_int;
      if('execution'in p){it.eseguito=!!p.execution; it.date_int_ex=p.execution?(p.date_int_ex||it.data):null;}
      if('id_zona'in p)it.rif=p.id_zona; if('strada'in p)it.strada=p.strada;
      this.save(); return {success:true,id}; },
    remove(id){ this.data.items=this.data.items.filter(x=>x.id!==id); this.save(); return {success:true,id}; },
  };
  function buildDates(start,ripeti){
    if(!ripeti||!ripeti.fino_a) return [start];
    const g=(ripeti.giorni||[]).map(Number), out=[]; let cur=new Date(start+'T00:00:00'); const end=new Date(ripeti.fino_a+'T00:00:00'); let n=0;
    while(cur<=end&&n<366){ const dow=cur.getDay()===0?7:cur.getDay(); if(!g.length||g.includes(dow))out.push(ymd(cur)); cur.setDate(cur.getDate()+1); n++; }
    return out.length?out:[start];
  }

  /* ------------------------------ RENDER ------------------------------ */
  function renderLegend(){
    const tipi = state.tipi.map(t => `
      <span class="voce">
        <span class="swatch" style="background:${esc(coloreDi(t))}"></span>
        ${esc(t)}
      </span>
    `).join('');
  
    el('legenda').innerHTML = `
      ${tipi}
      <span class="voce" style="margin-left:auto;">✓ eseguito</span>
      <span class="voce">
        <span class="swatch swatch-overdue"></span>
        intervento scaduto
      </span>
    `;
  }
  function renderMonthLabel(){ el('month-label').textContent=`${MESI[state.current.getMonth()]} ${state.current.getFullYear()}`; }

  function renderCalendar(){
    const cont=el('calendar');
    const year=state.current.getFullYear(), month=state.current.getMonth();
    const firstDow=(new Date(year,month,1).getDay()+6)%7;
    const days=new Date(year,month+1,0).getDate();
    const todayStr=ymd(new Date());
    let html='<div class="cal-grid">';
    GIORNI.forEach(g=>html+=`<div class="cal-head"><span class="d-none d-sm-inline">${g}</span><span class="d-sm-none">${g[0]}</span></div>`);
    for(let i=0;i<firstDow;i++) html+='<div class="cal-cell empty"></div>';
    for(let day=1;day<=days;day++){
      const dateStr=`${year}-${String(month+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
      const dow=(new Date(year,month,day).getDay()+6)%7;
      const weekend=dow>=5?' weekend':''; const oggi=dateStr===todayStr?' oggi':'';
      const info=state.giorni[dateStr]; let chips=''; let dots='';
      const scaduto = info && dateStr < todayStr && info.interventi.some(it => !it.eseguito);
      if(info&&info.interventi.length){
        info.interventi.slice(0,4).forEach(it=>{
          const col=it.tipo_colore||'#5d7083'; const check=it.eseguito?'✓ ':'';
          const title=`${it.tipo}${it.luogo?' · '+it.luogo:''}${it.eseguito?' — eseguito':''}`;
          chips+=`<span class="cal-chip" style="background:${esc(col)}" data-id="${it.id}" data-fonte="${it.fonte}" data-date="${dateStr}" title="${esc(title)}">
                    <span class="chip-label">${check}${esc(it.luogo||it.tipo)}</span></span>`;
          dots+=`<span class="cal-dot" style="background:${esc(col)}"></span>`;
        });
        if(info.interventi.length>4){ chips+=`<span class="cal-more">+${info.interventi.length-4}</span>`; dots+=`<span class="cal-more-dot">+${info.interventi.length-4}</span>`; }
      }
      html+=`<div class="cal-cell${weekend}${oggi}${info?' has-events':''}${scaduto?' overdue':''}" data-date="${dateStr}" tabindex="0" role="button" aria-label="${day} ${MESI[month]}">
               <span class="cal-day">${day}</span>
               <div class="cal-items">${chips}</div>
               <div class="cal-dots">${dots}</div>
             </div>`;
    }
    html+='</div>'; cont.innerHTML=html;
    cont.querySelectorAll('.cal-cell:not(.empty)').forEach(cell=>{
      cell.addEventListener('click', () => {
        openDay(cell.dataset.date);
      });
      cell.addEventListener('keydown',e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); isMobile()?openDay(cell.dataset.date):(state.isEditor?openNew(cell.dataset.date):openDay(cell.dataset.date)); } });
    });
  }

  /* --------------------------- MODALE GIORNO --------------------------- */
  let dayModal, modal;
  function openDay(dateStr){
    el('modal-day-title').textContent = fmtData(dateStr);
    const info=state.giorni[dateStr];
    const list=el('day-list');
    if(info&&info.interventi.length){
      list.innerHTML = info.interventi.map(it=>{
        const col=it.tipo_colore||'#5d7083'; const check=it.eseguito?'<span class="day-check">✓</span>':'';
        return `<button type="button" class="day-item" data-id="${it.id}" data-fonte="${it.fonte}" data-date="${dateStr}">
                  <span class="day-bar" style="background:${esc(col)}"></span>
                  <span class="day-txt"><b>${esc(it.tipo)}</b><span class="day-luogo">${esc(it.luogo||'')}</span></span>${check}
                </button>`;
      }).join('');
    } else {
      list.innerHTML = `<p class="text-muted small mb-0">Nessun intervento pianificato in questa data.</p>`;
    }
    list.querySelectorAll('.day-item').forEach(b=>b.addEventListener('click',()=>{
      dayModal.hide(); openEdit(b.dataset.id,b.dataset.fonte,b.dataset.date);
    }));
    el('btn-add-day').hidden = !state.isEditor;
    el('btn-add-day').onclick = ()=>{ dayModal.hide(); openNew(dateStr); };
    dayModal.show();
  }

  /* ------------------------- MODALE INTERVENTO ------------------------- */
  function fillTipi(){ el('f-tipo').innerHTML = state.tipi.map(t=>`<option value="${esc(t)}">${esc(t)}</option>`).join('') || '<option value="">(nessun tipo disponibile)</option>'; }

  // ---- Combobox oggetto/zona con ricerca (filtro locale) ----
  const Combo = {
    objects: [], filtered: [], selected: null, active: -1,
    inp(){ return el('f-oggetto-input'); }, ul(){ return el('f-oggetto-list'); }, hid(){ return el('f-oggetto-id'); },
    set(objs, preId, preName){
      this.objects=(objs||[]).slice(); this.filtered=[]; this.selected=null; this.active=-1;
      this.inp().value=''; this.hid().value=''; this.close();
      if(preId!=null){ const o=this.objects.find(x=>String(x.id)===String(preId)); this.choose(o||{id:preId,name_obj:'Zona #'+preId}); }
      else if(preName!=null){ const o=this.objects.find(x=>x.name_obj===preName); this.choose(o||{id:null,name_obj:preName}); }
    },
    match(q){ q=(q||'').trim().toLowerCase(); const a=q?this.objects.filter(o=>o.name_obj.toLowerCase().includes(q)):this.objects; return a.slice(0,80); },
    open(q){ this.filtered=this.match(q); this.render(); this.ul().hidden=false; this.inp().setAttribute('aria-expanded','true'); },
    close(){ this.ul().hidden=true; this.inp().setAttribute('aria-expanded','false'); this.active=-1; },
    render(){
      const ul=this.ul();
      if(!this.objects.length){ ul.innerHTML='<li class="combo-empty">Nessun oggetto assegnato</li>'; return; }
      if(!this.filtered.length){ ul.innerHTML='<li class="combo-empty">Nessun risultato</li>'; return; }
      ul.innerHTML=this.filtered.map((o,i)=>`<li id="combo-opt-${i}" role="option" data-idx="${i}" class="${i===this.active?'active':''}" aria-selected="${i===this.active}">${esc(o.name_obj)}</li>`).join('');
    },
    choose(o){ if(!o) return; this.selected=o; this.inp().value=o.name_obj; this.hid().value=o.id==null?'':o.id; this.close(); },
    chooseIdx(i){ this.choose(this.filtered[i]); },
    move(d){ if(this.ul().hidden) this.open(this.inp().value); if(!this.filtered.length) return;
      this.active=(this.active+d+this.filtered.length)%this.filtered.length; this.render();
      const a=el('combo-opt-'+this.active); if(a)a.scrollIntoView({block:'nearest'}); },
    onInput(){ this.selected=null; this.hid().value=''; this.active=-1; this.open(this.inp().value); },
    tryExact(){ if(this.selected) return; const v=this.inp().value.trim().toLowerCase();
      const o=this.objects.find(x=>x.name_obj.toLowerCase()===v); if(o) this.choose(o); },
    result(){ return this.selected?{id:this.selected.id,name:this.selected.name_obj}:{id:null,name:''}; },
  };
  function fillOggetti(tipo, preId, preName){
    Combo.set(state.objectsByType[tipo]||[], preId, preName);
    el('lbl-oggetto').textContent = fonteDi(tipo)==='strade' ? 'Strada / oggetto' : 'Zona / area verde';
  }
  function selectedOggetto(){ return Combo.result(); }

  function openNew(dateStr){
    el('modal-intervento-title').textContent='Nuovo intervento';
    el('f-id').value=''; el('f-fonte').value='';
    el('f-data').value=dateStr||ymd(new Date());
    el('f-tipo').selectedIndex=0;
    fillOggetti(el('f-tipo').value);
    el('f-eseguito').checked=false; el('grp-dataex').hidden=true; el('f-dataex').value=dateStr||'';
    el('btn-elimina').hidden=true;
    el('ripeti-box').hidden=false; el('f-ripeti').checked=false; el('ripeti-dettagli').hidden=true;
    el('f-ripeti-fino').value=''; document.querySelectorAll('.giorno-pill input').forEach(c=>c.checked=false);
    modal.show();
  }
  function openEdit(id,fonte,dateStr){
    if(!state.isEditor){
      return;
    }
    const info=state.giorni[dateStr];
    const it=info&&info.interventi.find(x=>String(x.id)===String(id)&&x.fonte===fonte);
    if(!it) return;
    const isAdmin = state.manteiner === 'admins' || state.editorGroups.includes('admins');
    if(!isAdmin && it.manteiner !== state.manteiner){
      alert(`Non puoi modificare un intervento inserito da ${it.manteiner}.`);
      return;
    }
    el('modal-intervento-title').textContent='Modifica intervento';
    el('f-id').value=it.id; el('f-fonte').value=it.fonte;
    el('f-data').value=dateStr; el('f-tipo').value=it.tipo;
    if(it.fonte==='verde') fillOggetti(it.tipo, it.rif, null);
    else fillOggetti(it.tipo, null, it.strada);
    el('f-eseguito').checked=!!it.eseguito; el('grp-dataex').hidden=!it.eseguito; el('f-dataex').value=it.date_int_ex||dateStr;
    el('btn-elimina').hidden=false; el('ripeti-box').hidden=true;
    modal.show();
  }

  async function save(){
    const id=el('f-id').value, fonteEdit=el('f-fonte').value;
    const tipo=el('f-tipo').value, fonte=fonteDi(tipo);
    if(!tipo){ alert('Nessun tipo disponibile.'); return; }
    const obj=selectedOggetto();
    const p={ tipo, data:el('f-data').value, execution:el('f-eseguito').checked,
      date_int_ex:el('f-eseguito').checked?el('f-dataex').value:null };
    if(fonte==='verde'){ if(!obj.id){alert('Seleziona una zona/oggetto.');return;} p.id_zona=obj.id; }
    else { if(!obj.name){alert('Seleziona una strada/oggetto.');return;} p.strada=obj.name; }
    if(!p.data){ alert('Indica la data.'); return; }
    if(!id && el('f-ripeti').checked && el('f-ripeti-fino').value){
      p.ripeti={ fino_a:el('f-ripeti-fino').value, giorni:Array.from(document.querySelectorAll('.giorno-pill input:checked')).map(c=>+c.value) };
    }
    el('btn-salva').disabled=true;
    try{
      if(id){ const up={ data:p.data, ty_int:tipo, execution:p.execution, date_int_ex:p.date_int_ex };
        if(fonteEdit==='verde') up.id_zona=p.id_zona; else up.strada=p.strada;
        await updateIntervento(parseInt(id,10),fonteEdit,up);
      } else await createIntervento(p);
      modal.hide(); await refresh();
    }catch(e){ alert('Salvataggio non riuscito: '+e.message); }
    finally{ el('btn-salva').disabled=false; }
  }
  async function removeCurrent(){
    const id=el('f-id').value, fonte=el('f-fonte').value; if(!id) return;
    if(!confirm('Eliminare questo intervento?')) return;
    try{ await deleteIntervento(parseInt(id,10),fonte); modal.hide(); await refresh(); }
    catch(e){ alert('Eliminazione non riuscita: '+e.message); }
  }

  /* -------------------------------- FLOW -------------------------------- */
  async function refresh(){
    el('cal-loading').hidden=false; el('calendar').hidden=true;
    try{ state.giorni=await listInterventi(); }catch(e){ state.giorni={}; console.error(e); }
    renderMonthLabel(); renderCalendar();
    el('cal-loading').hidden=true; el('calendar').hidden=false;
  }

  async function init(){
    // modali Bootstrap 2 di Lizmap (plugin jQuery)
    const bs2Modal = (node) => ({ show: () => jQuery(node).modal('show'), hide: () => jQuery(node).modal('hide') });
    modal=bs2Modal(el('modal-intervento'));
    dayModal=bs2Modal(el('modal-day'));
    const ctx=await loadContext();
    state.login=ctx.login||'—'; state.role = ctx.role || 'none'; state.manteiner=ctx.manteiner; state.isEditor=ctx.isEditor; state.editorGroups=ctx.editorGroups;
    state.tipi=ctx.tipi||[];
    const idx=indexObjects(ctx.objs||[]); state.objectsByType=idx.byType; state.objById=idx.byId;

    el('login-line').textContent=state.login;
    el('manteiner-sub').textContent = state.manteiner ? ('utente: '+state.manteiner) : '';
    if(state.role === 'viewer'){
      el('msg-noeditor').textContent =
        'Accesso in sola visualizzazione.';
      el('msg-noeditor').hidden=false;
    }
    
    if(state.role === 'none'){
        el('msg-noeditor').textContent = ctx.error ||
          'Utente non autorizzato.';
        el('msg-noeditor').hidden=false;
    }

    fillTipi(); renderLegend();

    el('btn-prev').addEventListener('click',()=>{ state.current.setMonth(state.current.getMonth()-1); refresh(); });
    el('btn-next').addEventListener('click',()=>{ state.current.setMonth(state.current.getMonth()+1); refresh(); });
    el('btn-today').addEventListener('click',()=>{ state.current=firstOfMonth(new Date()); refresh(); });
    el('btn-nuovo').addEventListener('click',()=>{ if(state.isEditor) openNew(ymd(new Date())); });
    el('btn-salva').addEventListener('click',save);
    el('btn-elimina').addEventListener('click',removeCurrent);
    el('f-tipo').addEventListener('change',()=>fillOggetti(el('f-tipo').value));

    // combobox oggetto/zona
    const ci=el('f-oggetto-input');
    ci.addEventListener('input',()=>Combo.onInput());
    ci.addEventListener('focus',()=>Combo.open(ci.value));
    ci.addEventListener('keydown',e=>{
      if(e.key==='ArrowDown'){ e.preventDefault(); Combo.move(1); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); Combo.move(-1); }
      else if(e.key==='Enter'){ if(!el('f-oggetto-list').hidden){ e.preventDefault();
        if(Combo.active>=0) Combo.chooseIdx(Combo.active); else if(Combo.filtered.length===1) Combo.chooseIdx(0); } }
      else if(e.key==='Escape'){ Combo.close(); }
    });
    ci.addEventListener('blur',()=>setTimeout(()=>Combo.tryExact(),150));
    el('f-oggetto-list').addEventListener('mousedown',e=>{ const li=e.target.closest('li[data-idx]'); if(li){ e.preventDefault(); Combo.chooseIdx(parseInt(li.dataset.idx,10)); } });
    document.addEventListener('click',e=>{ if(!e.target.closest('#combo-oggetto')) Combo.close(); });

    el('f-eseguito').addEventListener('change',e=>{ el('grp-dataex').hidden=!e.target.checked; });
    el('f-ripeti').addEventListener('change',e=>{ el('ripeti-dettagli').hidden=!e.target.checked; });

    await refresh();
  }
  document.addEventListener('DOMContentLoaded',init);
})();
