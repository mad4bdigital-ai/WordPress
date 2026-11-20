/* Fluent Form #6 — optional final drop-off inquiry, never a transport quote. */
(() => {
  'use strict';
  const cfg=window.MAD4BTransportForm6Dropoff;
  if(!cfg||!Number(cfg.tourId))return;
  const copies={
    en:{
      mode:'Final drop-off preference',place:'Drop-off place name',
      city:'Drop-off city or area',address:'Drop-off address or map details (optional)',
      choices:['Choose final drop-off','Same place as pickup (if route returns)',
        'Hotel or cruise ship','Airport','Railway station',
        'Port or marina','Meeting point / other','I will confirm later']
    },
    ar:{
      mode:'مكان التوصيل النهائي',place:'اسم مكان التوصيل',
      city:'مدينة أو منطقة التوصيل',address:'عنوان التوصيل أو رابط الخريطة (اختياري)',
      choices:['اختر مكان التوصيل','نفس مكان الاستلام (لو المسار يسمح)',
        'فندق أو مركب نيلي','مطار','محطة قطار','ميناء أو مرسى',
        'نقطة تجمع أو مكان آخر','هحدد المكان لاحقًا']
    },
    es:{
      mode:'Lugar de llegada final',place:'Nombre del lugar de llegada',
      city:'Ciudad o zona de llegada',address:'Dirección o mapa de llegada (opcional)',
      choices:['Elegir llegada','Mismo lugar de recogida (si permite la ruta)',
        'Hotel o crucero','Aeropuerto','Estación de tren','Puerto o embarcadero',
        'Punto de encuentro u otro','Lo confirmaré más tarde']
    },
    fr:{
      mode:'Lieu de dépose final',place:'Nom du lieu de dépose',
      city:'Ville ou zone de dépose',address:'Adresse ou carte (facultatif)',
      choices:['Choisir la dépose','Même lieu que la prise en charge (si possible)',
        'Hôtel ou bateau de croisière','Aéroport','Gare','Port ou marina',
        'Point de rendez-vous / autre','Je confirmerai plus tard']
    },
    de:{
      mode:'Gewünschter Zielort',place:'Name des Zielorts',
      city:'Zielstadt oder Stadtteil',address:'Zieladresse oder Karte (optional)',
      choices:['Zielort wählen','Gleicher Ort wie Abholung (falls Route geeignet)',
        'Hotel oder Kreuzfahrtschiff','Flughafen','Bahnhof','Hafen oder Marina',
        'Treffpunkt / Sonstiges','Später bestätigen']
    },
    it:{
      mode:'Luogo di arrivo finale',place:'Nome del luogo di arrivo',
      city:'Città o zona di arrivo',address:'Indirizzo o mappa (facoltativo)',
      choices:['Scegli arrivo','Stesso luogo del prelievo (se il percorso lo consente)',
        'Hotel o nave da crociera','Aeroporto','Stazione ferroviaria',
        'Porto o marina','Punto di incontro / altro','Da confermare']
    }
  };
  const c=copies[cfg.lang]||copies.en;
  const values=['','same_as_pickup','hotel','airport','rail_station','port',
    'meeting_point','to_confirm'];
  const eligibleRates=new Set((cfg.eligibleRates||[]).map(Number));
  const qs=(form,name)=>form.querySelector('[name="'+name+'"]');
  function selectedRate(form){
    const broker=window.SharedPresentationSelectionV1;
    const state=broker&&typeof broker.getSnapshot==='function'?
      broker.getSnapshot():null;
    if(state&&Number(state.tourId)===Number(cfg.tourId))
      return Number(state.selectedRateId)||0;
    const input=qs(form,'shared_tour_selection_v2');
    if(input&&input.value){
      try{
        const raw=JSON.parse(input.value);
        if(Number(raw.tour_id)===Number(cfg.tourId)&&raw.source==='rate')
          return Number(raw.rate_id)||0;
      }catch{}
    }
    return Number(qs(form,'mad4b_route_rate_id')?.value)||0;
  }
  function group(element){
    return element?.closest('.mad4b-transport-dropoff-field')||
      element?.closest('.ff-el-group')||element?.parentElement||null;
  }
  function translate(form){
    const inputs=[
      qs(form,'mad4b_dropoff_mode'),qs(form,'mad4b_dropoff_place_name'),
      qs(form,'mad4b_dropoff_city'),qs(form,'mad4b_dropoff_address')
    ];
    inputs.forEach((el,i)=>{
      if(!el||el.dataset.mad4bDropoffTranslated==='1')return;
      const label=group(el)?.querySelector('label');
      const name=['mode','place','city','address'][i];
      if(label)label.textContent=c[name];
      el.setAttribute('aria-label',c[name]);
      el.dataset.mad4bDropoffTranslated='1';
    });
    if(inputs[0]&&inputs[0].tagName==='SELECT'){
      Array.from(inputs[0].options).forEach(option=>{
        const idx=values.indexOf(option.value);
        if(idx>=0)option.textContent=c.choices[idx];
      });
    }
  }
  function update(){
    document.querySelectorAll('form.fluent_form_6').forEach(form=>{
      const mode=qs(form,'mad4b_dropoff_mode');
      if(!mode)return;
      translate(form);
      const enabled=cfg.defaultEligible||eligibleRates.has(selectedRate(form));
      const inputNames=['mad4b_dropoff_mode','mad4b_dropoff_place_name',
        'mad4b_dropoff_city','mad4b_dropoff_address'];
      const needDetail=enabled&&
        ['hotel','airport','rail_station','port','meeting_point'].includes(mode.value);
      inputNames.forEach((name,i)=>{
        const el=qs(form,name);
        if(!el)return;
        const g=group(el);
        const visible=i===0?enabled:needDetail;
        if(g)g.style.display=visible?'block':'none';
        el.disabled=!visible;
        el.required=false;
      });
      if(!mode.dataset.mad4bDropoffBound){
        mode.dataset.mad4bDropoffBound='1';
        mode.addEventListener('change',update);
      }
    });
  }
  if(document.readyState==='loading')
    document.addEventListener('DOMContentLoaded',update);
  else update();
  document.addEventListener('shared-presentation-selection-changed',update);
  document.addEventListener('mad4b:tour-rate-changed',update);
  document.addEventListener('change',event=>{
    if(event.target?.matches?.(
      '[name="shared_tour_selection_v2"],[name="mad4b_route_rate_id"]'))update();
  },true);
  document.addEventListener('input',event=>{
    if(event.target?.matches?.(
      '[name="shared_tour_selection_v2"],[name="mad4b_route_rate_id"]'))update();
  },true);
  if(document.readyState==='loading')
    document.addEventListener('DOMContentLoaded',()=>{
      if(document.body){
        const observer=new MutationObserver(update);
        observer.observe(document.body,{childList:true,subtree:true});
        setTimeout(()=>observer.disconnect(),12000);
      }
    },{once:true});
})();
