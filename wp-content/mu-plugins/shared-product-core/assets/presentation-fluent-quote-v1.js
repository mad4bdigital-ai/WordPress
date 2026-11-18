/** Governed runtime: server-calculated nonfinancial quote beside original Fluent Form #6. */
(() => {
 'use strict';
 const cfg=window.SharedPresentationFluentConfigV1;
 const broker=window.SharedPresentationSelectionV1;
 if(!cfg?.quoteEndpoint||!broker||window.SharedPresentationFluentQuoteV1||
    cfg.scope!=='tour_single_booking'||
    !document.body.classList.contains('single-tours-and-activities')||
    !cfg.formScopeSelector)return;
 const language=String(document.documentElement.lang||'en').split(/[-_]/)[0].toLowerCase();
 const messages={"en":{"estimate":"Estimated price","adults":"Adult subtotal: ","unpriced":"Adults only · child/infant prices are not included · final total on request","calcAdults":"Calculating adult price…","final":"Final price to confirm","priceOnRequest":"Price on request","calculating":"Calculating…","chooseDate":"Select travel date","chooseRoom":"Select date & occupancy","room":"Room occupancy ","chooseOccupancy":"Choose occupancy","single":"Single","double":"Double","triple":"Triple","noPayment":"No payment required","extras":"Extra activities","transport":"Transport options","from":"From ","tourEstimate":"Tour estimate · subject to confirmation","estimateAvailability":"Estimate · subject to availability","included":"Included","inPackage":"In package","extraToConfirm":"Extras to confirm"},"fr":{"estimate":"Prix estimé","adults":"Sous-total adultes : ","unpriced":"Adultes uniquement · tarifs enfants/bébés non inclus · total définitif sur demande","calcAdults":"Calcul du prix adultes…","final":"Prix final à confirmer","priceOnRequest":"Prix sur demande","calculating":"Calcul en cours…","chooseDate":"Choisissez la date de voyage","chooseRoom":"Choisissez la date et l’occupation","room":"Occupation ","chooseOccupancy":"Choisir l’occupation","single":"Individuelle","double":"Double","triple":"Triple","noPayment":"Aucun paiement requis","extras":"Activités supplémentaires","transport":"Transports","from":"À partir de ","tourEstimate":"Prix indicatif du circuit · à confirmer","estimateAvailability":"Estimation · sous réserve de disponibilité","included":"Inclus","inPackage":"Dans le forfait","extraToConfirm":"Extras à confirmer"},"de":{"estimate":"Geschätzter Preis","adults":"Zwischensumme Erwachsene: ","unpriced":"Nur Erwachsene · Kinder-/Babypreise nicht enthalten · Endpreis auf Anfrage","calcAdults":"Erwachsenenpreis wird berechnet…","final":"Endpreis muss bestätigt werden","priceOnRequest":"Preis auf Anfrage","calculating":"Berechnung läuft…","chooseDate":"Reisedatum wählen","chooseRoom":"Datum und Belegung wählen","room":"Zimmerbelegung ","chooseOccupancy":"Belegung wählen","single":"Einzel","double":"Doppel","triple":"Dreibett","noPayment":"Keine Zahlung erforderlich","extras":"Zusatzaktivitäten","transport":"Transportoptionen","from":"Ab ","tourEstimate":"Unverbindlicher Reisepreis · Bestätigung erforderlich","estimateAvailability":"Schätzung · vorbehaltlich Verfügbarkeit","included":"Inklusive","inPackage":"Im Paket","extraToConfirm":"Extras noch zu bestätigen"},"es":{"estimate":"Precio estimado","adults":"Subtotal de adultos: ","unpriced":"Solo adultos · no incluye precios de niños/bebés · total final a consultar","calcAdults":"Calculando precio de adultos…","final":"Precio final por confirmar","priceOnRequest":"Precio a consultar","calculating":"Calculando…","chooseDate":"Elige la fecha del viaje","chooseRoom":"Elige fecha y ocupación","room":"Ocupación ","chooseOccupancy":"Elegir ocupación","single":"Individual","double":"Doble","triple":"Triple","noPayment":"No se requiere pago","extras":"Actividades adicionales","transport":"Opciones de transporte","from":"Desde ","tourEstimate":"Precio orientativo · sujeto a confirmación","estimateAvailability":"Estimación · sujeta a disponibilidad","included":"Incluido","inPackage":"En el paquete","extraToConfirm":"Extras por confirmar"},"it":{"estimate":"Prezzo stimato","adults":"Subtotale adulti: ","unpriced":"Solo adulti · prezzi bambini/neonati esclusi · totale finale su richiesta","calcAdults":"Calcolo del prezzo adulti…","final":"Prezzo finale da confermare","priceOnRequest":"Prezzo su richiesta","calculating":"Calcolo in corso…","chooseDate":"Seleziona la data di viaggio","chooseRoom":"Seleziona data e occupazione","room":"Occupazione ","chooseOccupancy":"Scegli occupazione","single":"Singola","double":"Doppia","triple":"Tripla","noPayment":"Nessun pagamento richiesto","extras":"Attività extra","transport":"Opzioni di trasporto","from":"Da ","tourEstimate":"Prezzo indicativo · soggetto a conferma","estimateAvailability":"Stima · soggetta a disponibilità","included":"Incluso","inPackage":"Nel pacchetto","extraToConfirm":"Extra da confermare"},"ar":{"estimate":"السعر التقديري","adults":"إجمالي البالغين المرجعي: ","unpriced":"البالغون فقط · أسعار الأطفال والرضع غير مشمولة · الإجمالي النهائي عند التأكيد","calcAdults":"جارٍ حساب سعر البالغين…","final":"السعر النهائي يحتاج تأكيدًا","priceOnRequest":"السعر عند الطلب","calculating":"جارٍ الحساب…","chooseDate":"اختر تاريخ الرحلة","chooseRoom":"اختر التاريخ والإشغال","room":"إشغال الغرفة ","chooseOccupancy":"اختر الإشغال","single":"فردية","double":"مزدوجة","triple":"ثلاثية","noPayment":"لا يلزم الدفع الآن","extras":"الأنشطة الإضافية","transport":"خيارات النقل","from":"يبدأ من ","tourEstimate":"سعر الرحلة تقديري ويحتاج تأكيدًا","estimateAvailability":"سعر تقديري حسب التوفر","included":"مشمول","inPackage":"ضمن الباقة","extraToConfirm":"إضافات تحتاج تأكيدًا"}};
 const ageEstimateLabels={
   en:'Age-adjusted estimate · subject to confirmation',
   ar:'سعر تقديري يشمل قواعد أعمار الأطفال · يحتاج تأكيدًا',
   fr:'Estimation ajustée selon l’âge · à confirmer',
   de:'Altersabhängige Preisschätzung · vorbehaltlich Bestätigung',
   it:'Stima in base all’età · da confermare',
   es:'Estimación ajustada por edad · sujeta a confirmación'
 };
 const t=key=>key==='ageEstimate'
   ?(cfg.i18n?.ageEstimate??ageEstimateLabels[language]??ageEstimateLabels.en)
   :String(cfg.i18n?.[key]??messages[language]?.[key]??messages.en[key]??key);
 const scopeLabels={
  en:{pricedStays:'Tour + priced stays · more to confirm',stayPending:'Stay price on request',extraStays:'Tour + extras · stays to confirm',estimateStays:'Tour estimate · stays to confirm'},
  ar:{pricedStays:'الرحلة والإقامة المسعّرة · تفاصيل إضافية تحتاج تأكيدًا',stayPending:'سعر الإقامة عند الطلب',extraStays:'الرحلة والإضافات · الإقامة تحتاج تأكيدًا',estimateStays:'تقدير الرحلة · الإقامة تحتاج تأكيدًا'},
  de:{pricedStays:'Reise und berechnete Unterkünfte · weitere Bestätigung erforderlich',stayPending:'Unterkunftspreis auf Anfrage',extraStays:'Reise mit Extras · Unterkunft noch zu bestätigen',estimateStays:'Reiseschätzung · Unterkunft noch zu bestätigen'},
  fr:{pricedStays:'Circuit et hébergements chiffrés · confirmation supplémentaire',stayPending:'Prix de l’hébergement sur demande',extraStays:'Circuit avec extras · hébergement à confirmer',estimateStays:'Estimation du circuit · hébergement à confirmer'},
  es:{pricedStays:'Tour y alojamientos calculados · pendiente de confirmación',stayPending:'Precio del alojamiento a consultar',extraStays:'Tour con extras · alojamiento por confirmar',estimateStays:'Estimación del tour · alojamiento por confirmar'},
  it:{pricedStays:'Tour e soggiorni calcolati · ulteriori conferme',stayPending:'Prezzo del soggiorno su richiesta',extraStays:'Tour con extra · soggiorno da confermare',estimateStays:'Stima del tour · soggiorno da confermare'}
 };
 const scope=key=>String(cfg.i18n?.[key]??scopeLabels[language]?.[key]??scopeLabels.en[key]??key);
 const transportLabels={
  en:{added:'Added',additional:'Additional',toConfirm:'To confirm'},
  ar:{added:'تمت الإضافة',additional:'إضافي',toConfirm:'يحتاج تأكيدًا'},
  fr:{added:'Ajouté',additional:'Supplémentaire',toConfirm:'À confirmer'},
  de:{added:'Hinzugefügt',additional:'Zusätzlich',toConfirm:'Zu bestätigen'},
  es:{added:'Añadido',additional:'Adicional',toConfirm:'Por confirmar'},
  it:{added:'Aggiunto',additional:'Aggiuntivo',toConfirm:'Da confermare'}
 };
 const transportText=key=>String(cfg.i18n?.[key]??transportLabels[language]?.[key]??transportLabels.en[key]??key);
 const numberLocale=document.documentElement.lang||'en';
 const allowedForm=form=>!!(form&&form.matches(cfg.formScopeSelector));
 const bound=new WeakSet(),forms=new Set(),states=new WeakMap();
 const asInt=v=>/^(0|[1-9][0-9]{0,2})$/.test(String(v??''))?Number(v):null;
 // Original Tour Rate post IDs are not traveler counts (many exceed 999).
 const asRateId=v=>/^[1-9][0-9]{0,11}$/.test(String(v??''))&&
   Number.isSafeInteger(Number(v))?Number(v):null;
 const pricingBasisCopy={
  en:{title:'Pricing occupancy · per person',choose:'Choose pricing tier',note:'Rate tier only · room assignment is not confirmed'},
  ar:{title:'فئة التسعير · للفرد',choose:'اختر فئة التسعير',note:'فئة سعر فقط · لا تعني تخصيص غرفة'},
  es:{title:'Ocupación para tarifa · por persona',choose:'Elige la tarifa de ocupación',note:'Solo base de precio · no confirma habitación'},
  fr:{title:'Base tarifaire · par personne',choose:'Choisir la catégorie tarifaire',note:'Base de prix uniquement · aucune chambre confirmée'},
  de:{title:'Preisbelegung · pro Person',choose:'Preiskategorie wählen',note:'Nur Preisbasis · kein Zimmer zugewiesen'},
  it:{title:'Categoria tariffaria · per persona',choose:'Scegli la categoria tariffaria',note:'Solo base di prezzo · nessuna camera assegnata'}
 };
 const pricingText=key=>pricingBasisCopy[language]?.[key]??pricingBasisCopy.en[key];
 const pricedTiers=rate=>{
  if(!rate)return [];
  const raw=cfg.ratePricingBasisChoices?.[String(rate)];
  return Array.isArray(raw)?['single','double','triple'].filter(x=>raw.includes(x)):[];
 };
 // The original Elementor price rows are the primary price-tier control
 // while expanded. Do not create another tier choice or calculate money here.
 const tierNames=['single','double','triple'];
 const rateCardSelector='#tour_rates_accordion, #tour_rates';
 const tierRows=()=>[...document.querySelectorAll(
   tierNames.map(tier=>rateCardSelector.split(', ').map(region=>
     region+' .tour-occupancy-'+tier).join(', ')).join(', ')
 )];
 const nativeTierInfo=node=>{
   const rate=asRateId(node?.closest('.jet-listing-grid__item[data-post-id]')?.dataset.postId);
   const tier=tierNames.find(k=>node?.classList.contains('tour-occupancy-'+k));
   return rate&&tier&&pricedTiers(rate).includes(tier)?{rate,tier}:null;
 };
 const tierRowVisible=node=>!!node&&node.getBoundingClientRect().width>0&&
   getComputedStyle(node).visibility==='visible' &&
   getComputedStyle(node).display!=='none';
 const nativeTierAvailable=rate=>!!rate&&tierRows().some(node=>{
   const identity=nativeTierInfo(node);
   return identity?.rate===rate&&tierRowVisible(node);
 });
 const nativeRateButtonAvailable=rate=>!!rate&&
  [...document.querySelectorAll(
    '#tour_rates_accordion .mad4b-rate-header-select,'+
    '#tour_rates .mad4b-rate-header-select'
  )].some(node=>asRateId(node.dataset.rateId)===rate);
 function renderOriginalPriceChoices(rate,choice){
  for(const row of tierRows()){
   const identity=nativeTierInfo(row);
   if(!identity){
    row.classList.remove('mad4b-native-tier-choice','is-price-tier-selected');
    row.removeAttribute('data-mad4b-rate-tier');row.removeAttribute('data-mad4b-rate-id');
    row.removeAttribute('role');row.removeAttribute('aria-pressed');
    row.removeAttribute('tabindex');continue;
   }
   const active=identity.rate===rate&&identity.tier===choice;
   row.classList.add('mad4b-native-tier-choice');
   row.classList.toggle('is-price-tier-selected',active);
   row.dataset.mad4bRateTier=identity.tier;
   row.dataset.mad4bRateId=String(identity.rate);
   row.setAttribute('role','button');row.setAttribute('tabindex','0');
   row.setAttribute('aria-pressed',String(active));
   row.setAttribute('aria-label',
     (language==='ar'?'اختر فئة سعر ':'Choose ')+
     (messages[language]?.[identity.tier]||messages.en[identity.tier])+
     (language==='ar'?' للفرد':' price per person'));
  }
 }
 function chooseOriginalPriceTier(node){
  const identity=nativeTierInfo(node);
  if(!identity||!tierRowVisible(node))return;
  if(!cfg.rateChoices?.some(x=>Number(x.id)===identity.rate))return;
  const form=[...forms].find(x=>x.isConnected&&states.has(x));
  const state=form&&states.get(form);
  if(!state||state.sourcePending||effectivePricingMode(state)==='unavailable')return;
  const current=asRateId(broker.getSnapshot().selectedRateId);
  if(current!==identity.rate){
   document.dispatchEvent(new CustomEvent('rate-selection-request',{
    detail:{tourId:Number(cfg.tourId),rateId:identity.rate,remove:false}
   }));
  }
  // An explicit click on the existing original price card is the only source
  // of this tier choice; never silently preselect a retail price on Rate change.
  state.lastPricingRate=identity.rate;
  state.occupancy.value=identity.tier;
  renderOriginalPriceChoices(identity.rate,identity.tier);
  state.occupancy.dispatchEvent(new Event('change',{bubbles:true}));
  schedule(form);
 }
 document.addEventListener('click',e=>{
  const row=e.target.closest?.('.mad4b-native-tier-choice');
  if(!row||e.target.closest('a,button,input,textarea,select,summary'))return;
  chooseOriginalPriceTier(row);
 });
 document.addEventListener('keydown',e=>{
  if(!['Enter',' '].includes(e.key)||e.repeat)return;
  const row=e.target.closest?.('.mad4b-native-tier-choice');
  if(!row||e.target!==row)return;
  e.preventDefault();chooseOriginalPriceTier(row);
 });
 // Accommodation comes from MU Presentation source policy, not an
 // unconditional Fluent Form field or the presence of a Tour Rate.
 function hasAccommodation(rate){
  return !!(rate&&cfg.accommodationPolicy?.rates_with_stay?.[String(rate)]===true);
 }
 function syncAccommodationContext(state,snapshot,adults){
  const rate=asRateId(snapshot.selectedRateId);
  const realStay=hasAccommodation(rate);
  const available=pricedTiers(rate);
  const hasPricingChoice=!!(rate&&available.length);
  // Price tier comes from the authenticated server's Rate presentation,
  // independently from whether any accommodation stages are configured.
  renderOriginalPriceChoices(rate,state.occupancy.value);
  const originalCardOwnsTier=hasPricingChoice&&nativeTierAvailable(rate);
  state.occupancyLabel.hidden=!hasPricingChoice||originalCardOwnsTier;
  if(state.occupancyLabel.hidden)
   state.occupancyLabel.style.setProperty('display','none','important');
  else state.occupancyLabel.style.removeProperty('display');
  state.occupancy.disabled=!hasPricingChoice;
  for(const option of state.occupancy.options) {
   if(!option.value)continue;
   option.disabled=!available.includes(option.value);
   option.hidden=!available.includes(option.value);
  }
  if(hasPricingChoice){
   if(state.lastPricingRate!==rate || !available.includes(state.occupancy.value)){
    state.occupancy.value=''; // never silently select a chargeable price tier
   }
  }else{
   // Direct per-person pricing retains its previous neutral server basis.
   // A Rate without a certified available tier does not become bookable.
   state.occupancy.value=rate?'':'single';
  }
  state.roomContext=realStay;
  state.lastPricingRate=rate;
  state.panel.dataset.accommodation=realStay?'linked':'none';
  state.panel.dataset.pricingBasis=hasPricingChoice?'selectable':'unavailable';
  state.panel.dataset.tierChoiceSurface=originalCardOwnsTier?'native_rate_card':'form_fallback';
  renderOriginalPriceChoices(rate,state.occupancy.value);
 }
 function selectedStays(snapshot,rate,state){
   const row=(snapshot.rates||[]).find(x=>x.rateId===rate);
   if(!row||!hasAccommodation(rate))return [];
   const plans=cfg.accommodationPolicy?.plans_by_rate?.[String(rate)]?.stages||[];
   const allowed=new Map(plans.map(x=>[String(x.key),
      new Set((x.options||[]).map(o=>Number(o.id)))]));
   return Object.entries(row.stages||{})
     .filter(([key,id])=>/^[a-z0-9_-]{2,45}$/.test(key)&&asRateId(id)&&
       allowed.get(key)?.has(Number(id)))
     .sort(([a],[b])=>a.localeCompare(b))
     .map(([stage_key,property_id])=>{
       const obj={stage_key,property_id:Number(property_id)};
       const choice=state?.stayChoices.get(rate+'|'+stage_key+'|'+property_id);
       if(choice?.unit_id)obj.unit_id=choice.unit_id;
       if(choice?.board_id)obj.board_id=choice.board_id;
       if(choice?.rooms)obj.rooms=choice.rooms;
       if(Array.isArray(choice?.amenities)&&choice.amenities.length)
         obj.amenities=choice.amenities;
       return obj;
     });
 }
 const iso=v=>{
  const x=String(v??'').trim();
  let m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(x);
  if(!m)m=/^(\d{1,2})-([A-Za-z]{3})-(\d{2,4})$/.exec(x);
  if(!m)return null;
  if(x.includes('-')&&/^[0-9]{4}/.test(x)){
   const d=new Date(x+'T00:00:00Z');return !Number.isNaN(d.getTime())&&d.toISOString().slice(0,10)===x?x:null;
  }
  const months={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
  const mo=months[m[2].toLowerCase()];if(!mo)return null;
  const yy=Number(m[3]);const y=yy<100?2000+yy:yy;
  const value=[y,String(mo).padStart(2,'0'),String(m[1]).padStart(2,'0')].join('-');
  const d=new Date(value+'T00:00:00Z');
  return !Number.isNaN(d.getTime())&&d.toISOString().slice(0,10)===value?value:null;
 };
 // The pricing endpoint accepts a single departure date, never an
 // unconfirmed flexible search window. An incomplete or pending date must
 // not be passed to Quote, Last Minute or advance-routing projections.
 function appliedDateReadyForQuote(form){
  const date=window.MAD4BUnifiedDateV1;
  if(!date||date.context!=='single')return true;
  if(form.dataset.mad4bDateDraftInvalid==='1'||
     form.dataset.mad4bDatePending==='1')return false;
  const intent=date.request;
  return intent?.status==='valid'&&intent.mode==='fixed'&&
    Boolean(intent.from)&&iso(form.querySelector('[name="datetime"]')?.value)===intent.from;
 }

 // Server source context is date-scoped and nonfinancial. It does not
 // replace the original price quote or authorize checkout.
 function effectivePricingMode(state){
  const source=state?.sourceContext?.source;
  if(source==='rate')return 'existing_rates';
  if(source==='direct')return 'direct_per_person';
  if(source==='unavailable')return 'unavailable';
  return cfg.pricingEntryEffectiveMode;
 }
 function syncSourceContext(form,state){
  const date=iso(form.querySelector('[name="datetime"]')?.value);
  if(!date||!cfg.sourceContextEndpoint){
   if(state.sourceDate||state.sourcePending){
    ++state.sourceRequestId;state.sourceController?.abort();
    state.sourceController=null;state.sourceDate='';
    state.sourceContext=null;state.sourcePending=false;
    state.sourceNeedsApply=false;
   }
   return;
  }
  if(!appliedDateReadyForQuote(form)){
   ++state.sourceRequestId;state.sourceController?.abort();
   state.sourceController=null;state.sourceDate='';
   state.sourceContext=null;state.sourcePending=true;
   state.sourceNeedsApply=true;
   return;
  }
  state.sourceNeedsApply=false;
  if(date===state.sourceDate)return;
  ++state.sourceRequestId;
  const id=state.sourceRequestId;
  state.sourceController?.abort();
  state.sourceDate=date;state.sourceContext=null;state.sourcePending=true;
  const controller=new AbortController();state.sourceController=controller;
  const url=new URL(cfg.sourceContextEndpoint,location.href);
  url.searchParams.set('tour_id',String(cfg.tourId));
  url.searchParams.set('travel_date',date);
  fetch(url.toString(),{credentials:'same-origin',cache:'no-store',
    signal:controller.signal})
   .then(async response=>{
     if(!response.ok)throw new Error('source_context_unavailable');
     return response.json();
   })
   .then(data=>{
     if(id!==state.sourceRequestId||!form.isConnected||state.sourceDate!==date)return;
     if(data?.contract!=='mad4b.form6.source-date-context.v1'||
        Number(data.tour_id)!==Number(cfg.tourId)||
        data.travel_date!==date||
        !['direct','rate','unavailable'].includes(data.source)||
        !Array.isArray(data.eligible_rate_ids))
        throw new Error('source_context_invalid');
     state.sourcePending=false;state.sourceController=null;
     state.sourceContext=data;
     const chosen=asRateId(broker.getSnapshot().selectedRateId);
     if(chosen&&(data.source!=='rate'||
       !data.eligible_rate_ids.some(value=>Number(value)===chosen))){
       // Do not carry an ineligible Rate into Pickup or the next quote.
       document.dispatchEvent(new CustomEvent('rate-selection-request',{
        detail:{tourId:Number(cfg.tourId),rateId:chosen,remove:true}
       }));
     }
     document.dispatchEvent(new CustomEvent('shared-presentation-source-context-changed',{
       detail:{tourId:Number(cfg.tourId),travelDate:date,source:data.source,
         eligibleRateIds:data.eligible_rate_ids,
         readOnly:true,checkoutAuthorized:false}
     }));
     schedule(form);
   })
   .catch(error=>{
     if(id!==state.sourceRequestId||!form.isConnected||
        error.name==='AbortError')return;
     state.sourcePending=false;state.sourceController=null;
     // Unavailable source means no financial preview. No silent fallback.
     state.sourceContext={source:'unavailable',eligible_rate_ids:[]};
     document.dispatchEvent(new CustomEvent('shared-presentation-source-context-changed',{
      detail:{tourId:Number(cfg.tourId),travelDate:date,source:'unavailable',
       eligibleRateIds:[],readOnly:true,checkoutAuthorized:false}
     }));
     schedule(form);
   });
 }
 window.SharedPresentationSourceContextV1=Object.freeze({
  get:form=>{
   const state=states.get(form);
   if(!state)return null;
   return state.sourcePending?{pending:true,source:'unavailable'}:
     (state.sourceContext?{pending:false,source:state.sourceContext.source,
      travelDate:state.sourceDate}:null);
  }
 });

 function intentPayload(form){
  if(!appliedDateReadyForQuote(form))return null;
  const n=name=>form.querySelector('[name="'+name+'"]')?.value;
  const adults=asInt(n('no_of_adults')),children=asInt(n('no_of_children')),infants=asInt(n('no_of_infants'));
  const snap=broker.getSnapshot(),rate=asRateId(snap.selectedRateId);
  const st=states.get(form);
  if(!st||!st.occupancy.value)return null;
  if(st.sourcePending||effectivePricingMode(st)==='unavailable')return null;
  if(st.sourceContext?.source==='rate'&&
     (!rate||!st.sourceContext.eligible_rate_ids.some(id=>Number(id)===rate)))return null;
  if(st.sourceContext?.source==='direct'&&rate)return null;
  if(adults===null||adults<1||adults>100||children===null||infants===null)return null;
  const travelDate=iso(n('datetime'));if(!travelDate)return null;
  const extras=[...snap.extras].map(id=>{
   const item=st.extras.get(String(id));
   const persons=item?asInt(item.value):adults;
   return {tour_id:Number(id),persons:persons===null?0:persons};
  });
  // Optional activity attendance may include children; their retail
  // age pricing stays review-only until the selected activity is eligible.
  if(extras.some(x=>!x.tour_id||x.persons<1||
      x.persons>Math.min(100,adults+children)))return null;
  return {tour_id:Number(cfg.tourId),source:rate?'rate':'direct',rate_id:rate||null,
    travel_date:travelDate,adults,children,infants,occupancy:st.occupancy.value,
    extras,stay_stages:rate?selectedStays(snap,rate,st):[]};
 }
 function selected(form){
  const payload=intentPayload(form);
  if(!payload||payload.children!==0||payload.infants!==0)return null;
  // Stay preferences are saved for human review, not accepted as money.
  // Existing quote endpoints retain their exact pricing contract.
  const {stay_stages,...priceOnly}=payload;
  return priceOnly;
 }
 function amount(value,currency){
  try{return new Intl.NumberFormat(numberLocale,{style:'currency',currency,maximumFractionDigits:2}).format(value);}
  catch(_){return String(value)+' '+currency;}
 }

 function childrenOrInfants(form){
   return Number(form.querySelector('[name="no_of_children"]')?.value||0)>0||
     Number(form.querySelector('[name="no_of_infants"]')?.value||0)>0;
 }
 function indicativeExtraUnit(id){
  const source=cfg.extraDetails?.[String(id)]?.preview_price;
  if(!source||!Number.isFinite(Number(source.amount))||
     Number(source.amount)<=0||!/^[A-Z]{3}$/.test(source.currency||''))return null;
  return t('from')+amount(Number(source.amount),source.currency)
    +(source.unit==='per_person'?' / person':'');
 }
 function resetExtraPrices(state){
  for(const row of state.extraWrap.querySelectorAll('.shared-extra-item')){
   const text=row.querySelector('.shared-extra-unit-price');
   if(!text)continue;
   const possible=indicativeExtraUnit(row.dataset.extraTourId);
   text.textContent=possible||t('priceOnRequest');
   text.dataset.priceStatus=possible?'indicative':'unverified';
  }
 }
 function selectedTransportOptions(snapshot){
  // Preferences are tour-scoped. A selected Rate only resolves their
  // policies; Direct/Seed/Inquiry tours must not manufacture one.
  const groups=snapshot.transportIntent||{};
  const selected=Object.entries(groups).flatMap(([group,ids])=>
    (Array.isArray(ids)?ids:[]).map(id=>({id:String(id),group})));
  const native=window.BookingComponentSelection?.getSelections?.()||[];
  return selected.filter(x=>/^transport-(vehicles|train|air)$/.test(x.group)
      &&cfg.transportDetails?.[x.id])
    .slice(0,60).map(x=>{
     const detail=cfg.transportDetails[x.id];
     const chosen=native.find(n=>n.componentType==='transport'&&
       String(n.optionId)===x.id&&n.groupKey===x.group);
     return {id:Number(x.id),group:x.group,mode:detail.mode,
       name:detail.name,policy:chosen?.included?'included':
         ['included','additional','inquiry'].includes(chosen?.policy)?chosen.policy:'inquiry'};
    });
 }
 function renderTransports(state,snapshot){
  const options=selectedTransportOptions(snapshot);
  state.transportPicker.hidden=options.length===0;
  const fingerprint=JSON.stringify(options);
  if(state.transportFingerprint!==fingerprint){
   state.transportWrap.replaceChildren();
   for(const option of options){
    const row=document.createElement('div');
    row.className='shared-transport-option';
    row.dataset.selected='true';row.dataset.pricingPolicy=option.policy;
    const name=document.createElement('span');name.className='shared-transport-name';
    name.textContent=option.name;
    const badge=document.createElement('span');badge.className='shared-transport-status';
    const added=document.createElement('span');added.className='shared-transport-added';
    added.textContent='✓ '+(option.policy==='included'?t('included'):transportText('added'));
    const policy=document.createElement('small');policy.className='shared-transport-policy';
    policy.textContent=option.policy==='included'?t('inPackage'):
      option.policy==='additional'?transportText('additional'):transportText('toConfirm');
    badge.append(added,policy);
    row.append(name,badge);state.transportWrap.append(row);
   }
   state.transportFingerprint=fingerprint;
  }
  state.transportIntent.value=JSON.stringify({
   tour_id:Number(cfg.tourId),
   rate_id:asRateId(snapshot.selectedRateId)||0,
   options:options.map(o=>({transport_id:o.id,group:o.group}))
  });
 }
 function showPrice(state,data,partial){
   state.output.replaceChildren();
   const label=document.createElement('span');
   label.className='shared-price-amount';
   label.setAttribute('dir','auto');
   const wooTax=data.tax?.applied===true ? data.tax : null;
   label.textContent=amount(wooTax?wooTax.total_with_tax:(data.display_amount??data.total),data.currency);
   state.output.append(label);
   if(wooTax){
     const rows=document.createElement('div');
     rows.className='shared-woo-tax-breakdown';
     const terms={
       en:['Subtotal before tax','Estimated total including tax'],
       ar:['السعر قبل الضريبة','الإجمالي التقديري شامل الضريبة'],
       es:['Subtotal antes de impuestos','Total estimado con impuestos'],
       fr:['Sous-total hors taxes','Total estimé TTC'],
       de:['Zwischensumme ohne Steuer','Geschätzter Gesamtbetrag inkl. Steuer'],
       it:['Subtotale imposte escluse','Totale stimato tasse incluse']
     };
     const labels=terms[language]||terms.en;
     const values=[
       [labels[0],wooTax.subtotal],
       [String(wooTax.label||'Tax'),wooTax.tax_total],
       [labels[1],wooTax.total_with_tax]
     ];
     for(const [title,value] of values){
       const line=document.createElement('div');
       line.className='shared-woo-tax-line';
       const name=document.createElement('span');
       const figure=document.createElement('strong');
       name.textContent=title;figure.textContent=amount(value,data.currency);
       line.append(name,figure);rows.append(line);
     }
     state.output.append(rows);
   }
   if(data.tax_state==='requires_review'){
     const warning=document.createElement('small');
     warning.className='shared-price-tax-pending';
     warning.textContent=language==='ar'
       ?'الضريبة تحتاج تحديدًا من إعدادات WooCommerce قبل اعتماد الدفع'
       :'Tax determination is pending WooCommerce settings before payment approval';
     state.output.append(warning);
   }
   const note=document.createElement('small');
   note.className='shared-price-scope';
   note.textContent=partial&&!state.roomContext
     ?(data.extras_pending_count>0?t('extraToConfirm'):t('tourEstimate'))
     :partial?
     (data.stay_additional_included?scope('pricedStays'):
       data.stay_pending_count>0?scope('stayPending'):
       data.extras_included?scope('extraStays'):
       data.extras_pending_count>0?t('extraToConfirm'):
       scope('estimateStays')):
     t('estimateAvailability');
   state.output.append(note);
   for(const row of state.extraWrap.querySelectorAll('.shared-extra-item')){
    const id=row.dataset.extraTourId;
    const target=row.querySelector('.shared-extra-unit-price');
    const source=data.extra_prices?.[id];
    if(!target)continue;
    if(source&&Number.isFinite(Number(source.amount))&&
       /^[A-Z]{3}$/.test(source.currency||'')){
     target.textContent=amount(source.amount,source.currency);
     if(source.unit==='per_person')target.textContent+=' / person';
     target.dataset.priceStatus='verified';
    }else{
     const value=indicativeExtraUnit(id);
     target.textContent=value||t('priceOnRequest');
     target.dataset.priceStatus=value?'indicative':'unverified';
    }
   }
 }
 async function indicativePreview(state,payload,sequence,signal){
   if(!cfg.indicativeEndpoint)return false;
   try{
     const url=new URL(cfg.indicativeEndpoint,location.href);
     for(const key of ['tour_id','source','rate_id','travel_date',
       'adults','children','infants','occupancy'])
       url.searchParams.set(key,String(payload[key]??''));
     url.searchParams.set('extras',JSON.stringify(payload.extras||[]));
     url.searchParams.set('stay_stages',JSON.stringify(payload.stay_stages||[]));
     const reply=await fetch(url.toString(),{
       method:'GET',credentials:'same-origin',signal});
     const estimate=await reply.json();
     if(sequence!==state.seq)return true;
     if(reply.ok&&estimate.status==='indicative_preview_only'&&
       Number.isFinite(Number(estimate.total))&&/^[A-Z]{3}$/.test(estimate.currency||'')){
       showPrice(state,estimate,true);
       state.lastSettledPriceSignature=state.activePriceSignature;
       return true;
     }
   }catch(error){if(error.name==='AbortError')return true;}
   return false;
 }
 function syncStayOptions(form,state,snap,adults){
  const rate=asRateId(snap.selectedRateId);
  // This is a view of the SAME Presentation Selection broker used by the
  // original Elementor Rate widgets. Never create a parallel stay store.
  const plan=rate?cfg.accommodationPolicy?.plans_by_rate?.[String(rate)]:null;
  const planStages=Array.isArray(plan?.stages)?plan.stages:[];
  const selectedRow=(snap.rates||[]).find(x=>x.rateId===rate);
  const selectedMap=selectedRow?.stages||{};
  const existing=state.stayChoiceWrap;
  // Set DOM visibility synchronously even when a combined stylesheet is stale.
  const setVisible=(node,show)=>{
    node.hidden=!show;
    if(show)node.style.removeProperty('display');
    else node.style.setProperty('display','none','important');
  };
  const updatePickerVisibility=()=>{
    setVisible(state.stayPicker,[...state.stayChoiceWrap.children,...state.stayWrap.children]
      .some(node=>!node.hidden));
  };
  const wanted=new Set();
  for(const stage of planStages){
    if(!/^[a-z0-9_-]{2,45}$/.test(String(stage.key||'')))continue;
    const values=(stage.options||[]).filter(o=>asRateId(o.id)&&typeof o.name==='string');
    if(!values.length)continue;
    const token=String(rate)+'|'+stage.key;
    wanted.add(token);
    let row=existing.querySelector('[data-stay-source-stage="'+token+'"]');
    if(!row){
      row=document.createElement('label');
      row.className='shared-stay-source-selection';
      row.dataset.staySourceStage=token;
      const title=document.createElement('span');
      const hotelLabels={
        en:'Preferred hotel',ar:'الفندق المفضل',es:'Hotel preferido',
        fr:'Hôtel préféré',de:'Bevorzugtes Hotel',it:'Hotel preferito'
      };
      title.textContent=stage.key==='simple'?
        (hotelLabels[language]||hotelLabels.en):String(stage.label||stage.key);
      title.className='shared-stay-source-title';
      const select=document.createElement('select');
      select.className='shared-stay-source-hotel';
      select.setAttribute('aria-label',title.textContent);
      const prompt=document.createElement('option');
      const defaultOptions={
        en:'Let our team arrange the hotel',ar:'اترك اختيار الفندق لفريق الحجز',
        es:'Que nuestro equipo organice el hotel',
        fr:'Laisser notre équipe organiser l’hôtel',
        de:'Unser Team wählt das Hotel',it:'Lascia scegliere l’hotel al team'
      };
      prompt.value='';prompt.textContent=defaultOptions[language]||defaultOptions.en;
      select.append(prompt);
      for(const value of values){
        const opt=document.createElement('option');
        opt.value=String(value.id);opt.textContent=value.name;
        select.append(opt);
      }
      const hint=document.createElement('small');
      hint.className='shared-stay-source-policy';
      const stayHints={
        en:{included:'Included by Rate · hotel subject to confirmation',
          additional:'Additional stay · approved quote required',
          inquiry:'Hotel preference for review · no extra charge confirmed'},
        ar:{included:'ضمن سعر الـRate · الفندق يحتاج تأكيدًا',
          additional:'إقامة إضافية · التكلفة تحتاج اعتمادًا',
          inquiry:'تفضيل فندق للمراجعة · لا توجد رسوم إضافية معتمدة'},
        es:{included:'Incluido en la tarifa · hotel por confirmar',
          additional:'Alojamiento adicional · precio por aprobar',
          inquiry:'Preferencia de hotel · precio por confirmar'},
        fr:{included:'Inclus dans le tarif · hôtel à confirmer',
          additional:'Hébergement supplémentaire · prix à valider',
          inquiry:'Préférence d’hôtel · prix à confirmer'},
        de:{included:'Im Tarif enthalten · Hotel zu bestätigen',
          additional:'Zusatzunterkunft · Preisfreigabe erforderlich',
          inquiry:'Hotelwunsch · Preis noch offen'},
        it:{included:'Incluso nella tariffa · hotel da confermare',
          additional:'Soggiorno aggiuntivo · prezzo da approvare',
          inquiry:'Preferenza hotel · prezzo da confermare'}
      };
      const labelPolicy=['included','additional'].includes(stage.price_policy)?
        stage.price_policy:'inquiry';
      hint.textContent=(stayHints[language]||stayHints.en)[labelPolicy];
      select.addEventListener('change',()=>{
        const snapshot=broker.getSnapshot();
        if(Number(snapshot.selectedRateId)!==rate)return;
        const selected=(snapshot.rates||[]).find(x=>x.rateId===rate);
        const previous=asRateId(selected?.stages?.[stage.key]);
        const next=asRateId(select.value);
        if(previous===next)return;
        if(previous)document.dispatchEvent(new CustomEvent('booking-option-selection-change',{
          detail:{tourId:Number(cfg.tourId),contextId:rate,stageKey:stage.key,
            optionId:previous,selected:false}}));
        if(next)document.dispatchEvent(new CustomEvent('booking-option-selection-change',{
          detail:{tourId:Number(cfg.tourId),contextId:rate,stageKey:stage.key,
            optionId:next,selected:true}}));
      });
      row.append(title,select,hint);
      existing.append(row);
    }
    const control=row.querySelector('select');
    const current=String(selectedMap[stage.key]||'');
    if(current&&!values.some(o=>String(o.id)===current)){
      document.dispatchEvent(new CustomEvent('booking-option-selection-change',{
        detail:{tourId:Number(cfg.tourId),contextId:rate,stageKey:stage.key,
          optionId:Number(current),selected:false}
      }));
    }
    if(control && control.value!==current)control.value=current;
    // A one-property stage is already controlled by the main Select Rate.
    // Do not ask for the same hotel again in Fluent Form #6.
    setVisible(row,values.length!==1);
    if(values.length===1&&!current&&!state.sourcePending){
      document.dispatchEvent(new CustomEvent('booking-option-selection-change',{
        detail:{tourId:Number(cfg.tourId),contextId:rate,stageKey:stage.key,
          optionId:Number(values[0].id),selected:true,
          source:'sole_property_form_projection'}
      }));
    }
  }
  for(const row of [...existing.querySelectorAll('[data-stay-source-stage]')])
    if(!wanted.has(row.dataset.staySourceStage))row.remove();
  const stages=rate?selectedStays(snap,rate,state):[];
  setVisible(state.stayPicker,!!wanted.size||!!stages.length);
  const keys=new Set(stages.map(x=>rate+'|'+x.stage_key+'|'+x.property_id));
  for(const row of [...state.stayWrap.querySelectorAll('.shared-stay-row')])
   if(!keys.has(row.dataset.stayToken))row.remove();
  const option=(id,name)=>{const x=document.createElement('option');x.value=String(id);x.textContent=name;return x;};
  const fill=(select,items,prompt)=>{select.replaceChildren(option('',prompt));
   for(const x of items)select.append(option(x.id,x.name));};
  for(const stage of stages){
   const token=rate+'|'+stage.stage_key+'|'+stage.property_id;
   if([...state.stayWrap.children].some(x=>x.dataset.stayToken===token))continue;
   const row=document.createElement('section');row.className='shared-stay-row';row.dataset.stayToken=token;
   // Do not show empty, unverified room/meal controls during AJAX loading.
   setVisible(row,false);
   const title=document.createElement('strong');title.className='shared-stay-heading';
   title.textContent=stage.stage_key.replace(/_stay$/,'').replace(/_/g,' ')+' stay';
   const field=(name,tag)=>{
    const label=document.createElement('label');label.className='shared-stay-field';
    const text=document.createElement('span');text.textContent=name;
    const input=document.createElement(tag);label.append(text,input);return [label,input];
   };
   const [unitLabel,unit]=field('Room type','select');
   const [boardLabel,board]=field('Meals','select');
   const [roomsLabel,rooms]=field('Rooms','input');
   setVisible(unitLabel,false);setVisible(boardLabel,false);setVisible(roomsLabel,false);
   const stayCopy={
     en:{room:'Room type',cabin:'Cabin type',rooms:'Number of rooms',cabins:'Number of cabins',request:'Price to confirm'},
     ar:{room:'نوع الغرفة',cabin:'نوع الكابينة',rooms:'الغرف',cabins:'الكبائن',request:'السعر يحتاج تأكيدًا'},
     fr:{room:'Type de chambre',cabin:'Type de cabine',rooms:'Chambres',cabins:'Cabines',request:'Prix à confirmer'},
     de:{room:'Zimmertyp',cabin:'Kabinentyp',rooms:'Zimmer',cabins:'Kabinen',request:'Preis zu bestätigen'},
     it:{room:'Tipo di camera',cabin:'Tipo di cabina',rooms:'Camere',cabins:'Cabine',request:'Prezzo da confermare'},
     es:{room:'Tipo de habitación',cabin:'Tipo de cabina',rooms:'Habitaciones',cabins:'Cabinas',request:'Precio por confirmar'}
   };
   const unitsText=stayCopy[language]||stayCopy.en;
   const reflectStayBasis=entry=>{
     const choice=(entry?.boards||[]).find(x=>String(x.id)===board.value)||
       (entry?.boards||[])[0];
     const cabin=choice?.price_unit==='per_cabin_night';
     unitLabel.firstElementChild.textContent=cabin?unitsText.cabin:unitsText.room;
     roomsLabel.firstElementChild.textContent=cabin?unitsText.cabins:unitsText.rooms;
   };
   unit.className='shared-stay-unit';board.className='shared-stay-board';
   rooms.type='number';rooms.className='shared-stay-rooms';rooms.min='1';rooms.max='50';rooms.step='1';
   rooms.value='1';rooms.inputMode='numeric';board.disabled=true;rooms.disabled=true;
   fill(unit,[],'Loading rooms…');fill(board,[],'Choose meals');
   const hint=document.createElement('small');hint.className='shared-stay-hint';
   hint.textContent=unitsText.request;
   const amenityWrap=document.createElement('div');
   amenityWrap.className='shared-stay-amenities';
   amenityWrap.hidden=true;
   row.append(title,unitLabel,boardLabel,roomsLabel,amenityWrap,hint);
   state.stayWrap.append(row);
   const current=()=>state.stayChoices.get(token)||{};
   const save=obj=>{state.stayChoices.set(token,{...current(),...obj});schedule(form);};
   const updateAmenities=entry=>{
     amenityWrap.replaceChildren();
     const items=Array.isArray(entry?.amenities)?entry.amenities:[];
     amenityWrap.hidden=!items.length;
     if(!items.length)return;
     const heading=document.createElement('strong');
     heading.textContent=language==='ar'?'مرافق وخدمات إضافية · للمراجعة فقط':'Optional amenities · review only';
     amenityWrap.append(heading);
     const previous=new Map((current().amenities||[]).map(a=>[a.term_id,a]));
     const collect=()=>[...amenityWrap.querySelectorAll('input[type="checkbox"]:checked')]
       .map(check=>{
         const id=Number(check.dataset.termId);
         const quantity=amenityWrap.querySelector('input[data-quantity-for="'+id+'"]');
         return {term_id:id,quantity:Math.max(1,Math.min(100,Number(quantity?.value)||1))};
       });
     for(const item of items){
       if(!Number.isInteger(item.term_id)||!item.label)continue;
       const label=document.createElement('label');
       label.className='shared-stay-amenity-choice';
       const check=document.createElement('input');
       check.type='checkbox';check.dataset.termId=String(item.term_id);
       check.checked=previous.has(item.term_id);
       const text=document.createElement('span');
       let note=item.mode==='included'?(language==='ar'?' · مشمول':' · Included'):
         item.mode==='on_request'?(language==='ar'?' · عند الطلب':' · On request'):
         ' · '+String(item.price??'')+' '+String(item.currency||'')+
         ' / '+String(item.basis||'')+' · '+(language==='ar'?'تقديري':'Estimate');
       if(item.fulfillment==='pay_at_property')
         note+=(language==='ar'?' · الدفع بالفندق':' · Pay at property');
       text.textContent=String(item.label)+note;
       label.append(check,text);
       if(item.basis==='per_use'&&item.mode==='paid'){
         const qty=document.createElement('input');qty.type='number';qty.min='1';
         qty.max='100';qty.step='1';qty.inputMode='numeric';
         qty.setAttribute('data-quantity-for',String(item.term_id));
         qty.value=String(previous.get(item.term_id)?.quantity||1);
         qty.setAttribute('aria-label',language==='ar'?'عدد مرات الاستخدام':'Number of uses');
         qty.addEventListener('change',()=>save({amenities:collect()}));
         label.append(qty);
       }
       check.addEventListener('change',()=>save({amenities:collect()}));
       amenityWrap.append(label);
     }
   };
   const updateBoard=()=>{
    const entry=state.stayCatalog.get(token)?.units?.find(x=>x.id===Number(unit.value));
    const boards=Array.isArray(entry?.boards)?entry.boards:[];
    fill(board,boards.map(x=>({id:x.id,name:x.name})),'Choose meals');
    setVisible(boardLabel,boards.length>0);
    board.disabled=!boards.length;
    setVisible(roomsLabel,!!entry);
    rooms.disabled=!entry;
    const saved=current();
    if((entry?.boards||[]).some(x=>x.id===saved.board_id))board.value=String(saved.board_id);
    reflectStayBasis(entry);
    updateAmenities(entry);
    if(entry){
     const min=Math.max(1,Math.ceil((adults||1)/Math.max(1,entry.capacity)));
     rooms.min=String(min);rooms.value=String(Math.max(min,Math.min(50,saved.rooms||min)));
    }
    const policy=state.stayCatalog.get(token)?.price_policy;
    hint.textContent=policy==='included'?'Included · selection to confirm':
     policy==='inquiry'?'Price on request':'Price subject to availability';
   };
   unit.addEventListener('change',()=>{
    save({unit_id:Number(unit.value)||null,board_id:null,rooms:null,amenities:[]});
    updateBoard();
   });
   board.addEventListener('change',()=>{
     const entry=state.stayCatalog.get(token)?.units?.find(x=>x.id===Number(unit.value));
     reflectStayBasis(entry);
     save({board_id:Number(board.value)||null,rooms:Number(rooms.value)||1});
   });
   rooms.addEventListener('change',()=>{
    const entry=state.stayCatalog.get(token)?.units?.find(x=>x.id===Number(unit.value));
    const min=Math.max(1,Math.ceil((adults||1)/Math.max(1,entry?.capacity||1)));
    const count=Math.max(min,Math.min(50,asInt(rooms.value)||min));
    rooms.value=String(count);save({rooms:count});
   });
   const load=async()=>{
    try{
     const url=new URL(cfg.stayOptionsEndpoint,location.href);
     for(const [key,value] of Object.entries({tour_id:Number(cfg.tourId),
      rate_id:rate,stage_key:stage.stage_key,property_id:stage.property_id}))
      url.searchParams.set(key,String(value));
     const response=await fetch(url.toString(),{credentials:'same-origin',cache:'no-store'});
     const data=await response.json();
     if(!response.ok||!Array.isArray(data.units)||!row.isConnected)return;
     state.stayCatalog.set(token,data);
     const units=data.units.filter(x=>Number.isSafeInteger(Number(x?.id))&&Number(x.id)>0);
     fill(unit,units.map(x=>({id:x.id,
       name:String(x.name).replace(/^QA ONLY\s*[·:-]\s*/i,'')})),'Choose room type');
     setVisible(row,units.length>0);
     setVisible(unitLabel,units.length>0);
     if(units.some(x=>x.id===current().unit_id))unit.value=String(current().unit_id);
     updateBoard();updatePickerVisibility();schedule(form);
    }catch(e){if(row.isConnected){setVisible(row,false);
      setVisible(unitLabel,false);setVisible(boardLabel,false);setVisible(roomsLabel,false);
      hint.textContent='Continue for a quote';updatePickerVisibility();}}
   };
   if(cfg.stayOptionsEndpoint)load();else{setVisible(row,false);updatePickerVisibility();}
  }
  updatePickerVisibility();
 }

 async function refresh(form){
  const state=states.get(form);if(!state)return;
  const adults=asInt(form.querySelector('[name="no_of_adults"]')?.value);
  syncSourceContext(form,state);
  const snapshot=broker.getSnapshot();
  const sourceMode=effectivePricingMode(state);
  const currentRate=asRateId(snapshot.selectedRateId);
  if(!state.sourcePending && state.sourceContext && currentRate &&
     (sourceMode!=='existing_rates'||
      !(state.sourceContext.eligible_rate_ids||[])
       .some(id=>Number(id)===currentRate))){
    document.dispatchEvent(new CustomEvent('rate-selection-request',{
      detail:{tourId:Number(cfg.tourId),rateId:currentRate,remove:true}
    }));
    return;
  }
  if(state.rateRow) {
    // The original Select Rate action owns Rate selection when available.
    // Keep the form selector as a fallback for lazy/absent native cards.
    state.rateRow.hidden=state.sourcePending||
      sourceMode!=='existing_rates'||state.rateSelect.options.length<=1||
      nativeRateButtonAvailable(currentRate);
    if(state.rateRow.hidden)state.rateRow.style.setProperty('display','none','important');
    else state.rateRow.style.removeProperty('display');
  }
  if(state.rateSelect && state.sourceContext){
   const allowed=new Set((state.sourceContext.eligible_rate_ids||[]).map(String));
   for(const option of state.rateSelect.options){
    if(!option.value)continue;
    option.disabled=sourceMode!=='existing_rates'||!allowed.has(option.value);
    option.hidden=option.disabled;
   }
  }
  if(state.rateSelect){
   const selected=asRateId(snapshot.selectedRateId);
   const displayed=selected&&[...state.rateSelect.options].some(o=>o.value===String(selected))
     ?String(selected):'';
   if(state.rateSelect.value!==displayed)state.rateSelect.value=displayed;
  }
  syncAccommodationContext(state,snapshot,adults);
  // Keep Woo advance's optional source router in sync with the SAME form UI.
  // These are untrusted selections, not amounts. Server recomputes, validates
  // date/rate/occupancy and seals any payable reference independently.
  const forPayment=asRateId(snapshot.selectedRateId);
  state.paymentRate.value=appliedDateReadyForQuote(form)&&
    forPayment&&pricedTiers(forPayment).length?String(forPayment):'';
  state.paymentOccupancy.value=state.paymentRate.value?state.occupancy.value:'';
  const selectedIds=new Set([...snapshot.extras].map(String));
  // Only optional activities picked in the native Elementor/JetEngine section.
  // The source registry validates the selection; allowed-but-unpicked items
  // are not customer order lines and must not appear in Fluent Form #6.
  const allowed=new Set((window.SharedPresentationSelectionConfigV1?.allowedExtraTourIds||[]).map(String));
  const options=[...selectedIds].filter(id=>allowed.has(id)&&
    (cfg.extraDetails?.[id]||cfg.extraNames?.[id])).slice(0,30);
  syncStayOptions(form,state,snapshot,adults);
  if(state.sourcePending){
   state.stayPicker.hidden=true;
   state.occupancyLabel.hidden=true;
   state.occupancy.disabled=true;
  }
  renderTransports(state,snapshot);
  state.picker.hidden=options.length===0;
  state.picker.dataset.selectedCount=String(options.length);
  state.picker.classList.toggle('has-many-extras',options.length>=4);
  state.picker.classList.toggle('has-one-extra',options.length===1);
  for(const [id,field] of state.extras){
   if(options.includes(id))continue;
   field.closest('.shared-extra-item')?.remove();
   state.extras.delete(id);
  }
  const children=asInt(form.querySelector('[name="no_of_children"]')?.value)??0;
  const maximum=adults!==null&&adults>0?
    Math.min(adults+children,100):1;
  options.forEach((key,index)=>{
   const detail=cfg.extraDetails?.[key]||{};
   const name=detail.name||cfg.extraNames?.[key]||'Activity '+(index+1);
   let field=state.extras.get(key);
   if(!field){
    const row=document.createElement('div');row.className='shared-extra-item';
    row.dataset.extraTourId=key;
    const media=document.createElement('span');media.className='shared-extra-media';
    if(detail.image&&/^(https?:\/\/|\/wp-content\/uploads\/)/.test(detail.image)){
     const img=document.createElement('img');img.src=detail.image;img.alt='';
     img.loading='lazy';img.decoding='async';media.append(img);
    }else{
     media.classList.add('shared-extra-media-placeholder');
     media.textContent='✦';media.setAttribute('aria-hidden','true');
    }
    const content=document.createElement('div');content.className='shared-extra-content';
    const title=document.createElement('span');title.className='shared-extra-name';
    title.textContent=name;
    const price=document.createElement('span');price.className='shared-extra-unit-price';
    // No client-rendered CPT raw price: the effective unit amount may
    // depend on dates, package, FX and Rate source arbitration.
    price.textContent=indicativeExtraUnit(key)||t('priceOnRequest');
    price.dataset.priceStatus=indicativeExtraUnit(key)?'indicative':'unverified';
    const controls=document.createElement('div');controls.className='shared-extra-counter';
    const decrease=document.createElement('button');decrease.type='button';
    decrease.className='shared-extra-minus';decrease.textContent='−';
    decrease.setAttribute('aria-label','Decrease guests for '+name);
    field=document.createElement('input');field.type='number';
    field.className='shared-extra-quantity';field.min='1';field.step='1';
    field.inputMode='numeric';field.value=String(maximum);
    field.setAttribute('aria-label','Guests for '+name);
    const increase=document.createElement('button');increase.type='button';
    increase.className='shared-extra-plus';increase.textContent='+';
    increase.setAttribute('aria-label','Increase guests for '+name);
    const change=delta=>{
     const current=asInt(field.value)??1;
     const next=Math.max(1,Math.min(Number(field.max)||1,current+delta));
     if(next===current)return;
     field.value=String(next);
     field.dispatchEvent(new Event('input',{bubbles:true}));
    };
    // Counts customize existing native selections. Deselect an activity from
    // the tour's Extra Activities section, not by silently changing a zero row.
    decrease.addEventListener('click',()=>change(-1));
    increase.addEventListener('click',()=>change(1));
    field.addEventListener('change',()=>{
     const value=asInt(field.value);
     field.value=String(Math.max(1,Math.min(Number(field.max)||1,value??1)));
     schedule(form);
    });
    field.addEventListener('input',()=>schedule(form));
    controls.append(decrease,field,increase);
    content.append(title,controls);
    row.append(media,content,price);
    state.extraWrap.append(row);state.extras.set(key,field);
   }
   const row=field.closest('.shared-extra-item');
   if(row&&state.extraWrap.lastElementChild!==row)state.extraWrap.append(row);
   field.max=String(maximum);
   const active=selectedIds.has(key);
   const current=asInt(field.value);
   const value=Math.max(1,Math.min(maximum,current&&current>0?current:maximum));
   if(field.value!==String(value))field.value=String(value);
   if(row){
    row.classList.toggle('is-selected',active);
    const buttons=row.querySelectorAll('.shared-extra-counter button');
    if(buttons[0])buttons[0].disabled=value<=1;
    if(buttons[1])buttons[1].disabled=value>=maximum;
   }
  });
  // Editor-only Draft can customize Extras in the same original compact
  // Fluent Form picker. Never send a quote/pricing request, generate payable
  // booking intent, or suggest the Draft is commercially available.
  if(cfg.previewOnly===true){
   state.seq++;state.controller?.abort();state.controller=null;
   state.lastSettledPriceSignature=null;state.inFlightPriceSignature=null;
   state.intent.value='';state.paymentRate.value='';state.paymentOccupancy.value='';
   state.panel.hidden=true;
   resetExtraPrices(state);
   return;
  }
  if(state.sourcePending||sourceMode==='unavailable'){
   state.seq++;state.controller?.abort();state.controller=null;
   state.intent.value='';state.paymentRate.value='';state.paymentOccupancy.value='';
   state.activePriceSignature=null;state.lastSettledPriceSignature=null;
   state.output.textContent=state.sourcePending?
      (state.sourceNeedsApply?'Apply your travel date to refresh the price and accommodation':
        'Checking eligible pricing for this travel date…'):
      'Price source unavailable for this travel date';
   return;
  }
  if(sourceMode==='existing_rates'&&!asRateId(snapshot.selectedRateId)){
   state.seq++;state.controller?.abort();state.controller=null;
   state.activePriceSignature=null;state.lastSettledPriceSignature=null;
   state.inFlightPriceSignature=null;state.intent.value='';
   state.panel.dataset.priceScope='rate_selection_required';
   state.output.textContent=(Array.isArray(cfg.rateChoices)&&cfg.rateChoices.length)
     ?(cfg.rateSelectionPrompt||'Select a tour rate to see an estimate'):t('priceOnRequest');
   resetExtraPrices(state);return;
  }
  const payload=selected(form);
  const intent=intentPayload(form);
  // A known Direct Tour retail price can still be shown as an adult-only
  // lower-bound when child/infant pricing requires a human review.
  // Never turn that subtotal into the form's commercial total.
  const minorAges=(key)=>[...form.querySelectorAll('input[name="'+key+'[]"]')]
      .filter(el=>!el.disabled).map(el=>String(el.value??'').trim());
  const partialAdults=(!payload&&intent&&
    (intent.children>0||intent.infants>0)&&
    (intent.source==='direct'||(intent.source==='rate'&&asRateId(intent.rate_id))))?
    {...intent,child_ages:minorAges('mad4b_child_ages'),
      infant_ages:minorAges('mad4b_infant_ages'),
      extras:[],stay_stages:[]}:null;
  state.intent.value=intent?JSON.stringify(intent):'';
  // A transport-only preference does not change the price inputs.
  // Keep the last source-validated amount instead of blanking it to
  // Calculating and re-fetching an identical quote.
  const signature=(payload||partialAdults)?JSON.stringify({
    price:payload||partialAdults,stays:intent?.stay_stages||[],
    scope:partialAdults?'adults_only_unpriced_minors':'full_estimate'
  }):null;
  if(signature&&state.lastSettledPriceSignature===signature&&
     state.output.querySelector('.shared-price-amount'))return;
  // Repeated Fluent Form, age-stepper or projection events must never abort
  // and restart an identical outstanding quote forever.
  if(signature&&state.inFlightPriceSignature===signature&&
     state.controller&&!state.controller.signal.aborted)return;
  const seq=++state.seq;
  state.activePriceSignature=signature;
  state.inFlightPriceSignature=signature;
  state.controller?.abort();state.controller=null;
  if(!payload&&!partialAdults){
   state.lastSettledPriceSignature=null;
   state.inFlightPriceSignature=null;
   delete state.panel.dataset.priceScope;
   state.output.textContent=asRateId(snapshot.selectedRateId) &&
     pricedTiers(asRateId(snapshot.selectedRateId)).length &&
     !state.occupancy.value ? pricingText('choose') :
     (state.roomContext ? t('chooseRoom') : t('chooseDate'));
   resetExtraPrices(state);
   return;
  }
  if(partialAdults){
   state.output.textContent=t('calcAdults');
   resetExtraPrices(state);
   const controller=new AbortController();state.controller=controller;
   const deadline=setTimeout(()=>controller.abort(),9000);
   try{
    const {stay_stages,...adultPrice}=partialAdults;
    const response=await fetch(cfg.quoteEndpoint,{
      method:'POST',headers:{'Content-Type':'application/json'},
      credentials:'same-origin',body:JSON.stringify(adultPrice),signal:controller.signal
    });
    const quote=await response.json();
    if(seq!==state.seq)return;
    if(response.ok&&quote.status==='age_adjusted_price_preview_only'&&
       quote.display_kind==='age_adjusted_nonbinding_estimate'&&
       quote.checkout_ready===false&&quote.order_created===false&&
       quote.children_count===intent.children&&quote.infants_count===intent.infants&&
       Number.isFinite(Number(quote.display_amount))&&quote.display_amount>0&&
       /^[A-Z]{3}$/.test(quote.currency||'')){
      const value=document.createElement('span');
      value.className='shared-price-amount';
      value.textContent=amount(quote.display_amount,quote.currency);
      const scope=document.createElement('small');
      scope.className='shared-price-scope';
      scope.textContent=t('ageEstimate');
      state.output.replaceChildren(value,scope);
      state.panel.dataset.priceScope='age_adjusted_nonbinding_estimate';
      state.lastSettledPriceSignature=signature;
    }else if(response.ok&&quote.status==='partial_price_preview_only'&&
       quote.display_kind==='adults_only_reference'&&quote.total===null&&
       quote.checkout_ready===false&&quote.order_created===false&&
       quote.children_count===intent.children&&quote.infants_count===intent.infants&&
       Number.isFinite(Number(quote.display_amount))&&quote.display_amount>0&&
       /^[A-Z]{3}$/.test(quote.currency||'')){
      const value=document.createElement('span');
      value.className='shared-price-amount';
      value.textContent=t('adults')+amount(quote.display_amount,quote.currency);
      const scope=document.createElement('small');
      scope.className='shared-price-scope';
      scope.textContent=t('unpriced');
      state.output.replaceChildren(value,scope);
      state.panel.dataset.priceScope='adults_only_unpriced_minors';
      state.lastSettledPriceSignature=signature;
    }else{
      state.output.textContent=t('final');
      state.lastSettledPriceSignature=null;
    }
   }catch(error){
    // AbortError from an actual timeout (same request sequence) is a
    // terminal estimate failure, not permission to leave a spinner visible.
    if(seq===state.seq){
      state.output.textContent=t('final');
      state.lastSettledPriceSignature=null;
    }
   }finally{
     clearTimeout(deadline);
     if(seq===state.seq){
       state.inFlightPriceSignature=null;
       if(controller.signal.aborted)state.output.textContent=t('final');
     }
   }
   return;
  }
  state.output.textContent=t('calculating');
  // Avoid keeping a stale confirmed per-person price after changing dates,
  // occupancy, activity count, or Rate selection.
  resetExtraPrices(state);
  const controller=new AbortController();state.controller=controller;
  const deadline=setTimeout(()=>controller.abort(),9000);
  // Pure read-only estimate is allowed through the governed preview gateway;
  // POST authorization for booking/Fluent Forms remains deliberately closed.
  if(await indicativePreview(state,{...payload,stay_stages:intent?.stay_stages||[]},seq,controller.signal))return;
  try{
   const response=await fetch(cfg.quoteEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},
     credentials:'same-origin',body:JSON.stringify(payload),signal:controller.signal});
   const data=await response.json();if(seq!==state.seq)return;
   if(!response.ok||data.status!=='price_preview_only'){

     if(seq===state.seq)state.output.textContent=t('priceOnRequest');
     return;
   }
   showPrice(state,data,Boolean(data.component_adjustments_certified===false));
   state.panel.dataset.priceScope='nonbinding_tour_estimate';
   state.lastSettledPriceSignature=state.activePriceSignature;
   // Read-only UI event for the existing Form #6 submission-mode controller.
   // The server still rechecks/signs any submitted financial intent.
   document.dispatchEvent(new CustomEvent('mad4b-form6-price-verified',{
     detail:{tourId:Number(cfg.tourId)}
   }));
  }catch(err){
   if(seq!==state.seq)return;
   state.output.textContent=t('priceOnRequest');
   state.lastSettledPriceSignature=null;
  }finally{
   clearTimeout(deadline);
   if(seq===state.seq){
     state.inFlightPriceSignature=null;
     if(controller.signal.aborted)state.output.textContent=t('priceOnRequest');
   }
  }
 }
 function schedule(form){
  const state=states.get(form);if(!state)return;
  clearTimeout(state.timer);state.timer=setTimeout(()=>refresh(form),260);
 }
 function attach(form){
  if(!allowedForm(form)||bound.has(form))return;
  // Elementor Popup may clone an already-enhanced form. Throw away copied
  // pricing UI/hidden fields and attach one fresh controller to the clone.
  form.querySelectorAll('.shared-fluent-server-quote,.shared-extra-picker,.shared-transport-picker').forEach(x=>x.remove());
  form.querySelectorAll('input[name="shared_tour_selection_v2"],input[name="shared_transport_preferences_v1"],input[name="mad4b_route_rate_id"],input[name="mad4b_route_occupancy"]').forEach(x=>x.remove());
  bound.add(form);forms.add(form);
  const panel=document.createElement('div');panel.className='shared-fluent-server-quote shared-price-first-preview';
  panel.style.cssText='padding:12px;margin-block:12px;border:1px solid var(--e-global-color-secondary,#ddd);border-radius:10px';
  const title=document.createElement('strong');title.className='shared-price-title';
  title.textContent=t('estimate');
  // Accessible form-local Rate control uses the same selection broker as the
  // Elementor cards. It is never a pricing authority and does not auto-select.
  const rateRow=document.createElement('label');
  rateRow.className='shared-form6-rate-chooser';
  rateRow.style.cssText='margin-block:12px';
  const rateLabel={en:'Choose tour rate',ar:'اختر سعر الرحلة',
   fr:'Choisir le tarif',de:'Tarif auswählen',es:'Elegir tarifa',it:'Scegli tariffa'};
  const rateCaption=document.createElement('span');
  rateCaption.textContent=rateLabel[language]||rateLabel.en;
  rateCaption.style.cssText='display:block;font-weight:600;margin-bottom:5px';
  const rateSelect=document.createElement('select');
  rateSelect.className='shared-form6-rate-selector';
  rateSelect.style.cssText='display:block;width:100%;max-width:100%;min-height:44px';
  const choose=document.createElement('option');
  choose.value='';choose.textContent=cfg.rateSelectionPrompt||'Choose a rate';
  rateSelect.append(choose);
  for(const rate of (Array.isArray(cfg.rateChoices)?cfg.rateChoices:[])){
   if(!asRateId(rate?.id)||typeof rate?.label!=='string')continue;
   const option=document.createElement('option');
   option.value=String(rate.id);option.textContent=rate.label.slice(0,160);
   rateSelect.append(option);
  }
  rateRow.hidden=rateSelect.options.length<=1||
    (cfg.pricingEntryEffectiveMode!=='existing_rates'&&cfg.previewOnly!==true);
  rateRow.append(rateCaption,rateSelect);
  rateSelect.addEventListener('change',()=>{
   const current=asRateId(broker.getSnapshot().selectedRateId);
   const requested=asRateId(rateSelect.value);
   if(requested===current)return;
   if(requested){
    document.dispatchEvent(new CustomEvent('rate-selection-request',{
     detail:{tourId:Number(cfg.tourId),rateId:requested,remove:false}
    }));
   }else if(current){
    document.dispatchEvent(new CustomEvent('rate-selection-request',{
     detail:{tourId:Number(cfg.tourId),rateId:current,remove:true}
    }));
   }
  });
  const label=document.createElement('label');
  label.className='shared-pricing-basis-field';label.hidden=true;
  const labelText=document.createElement('span');
  labelText.className='shared-pricing-basis-title';
  labelText.textContent=pricingText('title');
  const occupancy=document.createElement('select');
  occupancy.className='shared-pricing-basis-select';
  [['',pricingText('choose')],['single',t('single')],['double',t('double')],['triple',t('triple')]].forEach(([value,name])=>{
   const el=document.createElement('option');el.value=value;el.textContent=name;occupancy.append(el);
  });
  const helper=document.createElement('small');
  helper.className='shared-pricing-basis-note';
  helper.textContent=pricingText('note');
  occupancy.addEventListener('change',()=>schedule(form));
  label.append(labelText,occupancy,helper);
  const picker=document.createElement('section');picker.className='shared-extra-picker';
  picker.hidden=true;picker.dataset.selectedCount='0';
  picker.setAttribute('aria-label','Selected extra activities');
  const pickerTitle=document.createElement('h3');pickerTitle.className='shared-extra-picker-title';
  pickerTitle.textContent=t('extras');
  const extraWrap=document.createElement('div');extraWrap.className='shared-fluent-extras-quantities';
  picker.append(pickerTitle,extraWrap);
  const transportPicker=document.createElement('section');
  transportPicker.className='shared-transport-picker';transportPicker.hidden=true;
  transportPicker.setAttribute('aria-label','Selected transport options');
  const transportTitle=document.createElement('h3');
  transportTitle.className='shared-transport-heading';
  transportTitle.textContent=t('transport');
  const transportWrap=document.createElement('div');
  transportWrap.className='shared-transport-list';
  transportPicker.append(transportTitle,transportWrap);
  const transportIntent=document.createElement('input');
  transportIntent.type='hidden';transportIntent.name='shared_transport_preferences_v1';
  transportIntent.autocomplete='off';form.append(transportIntent);
  const stayPicker=document.createElement('section');stayPicker.className='shared-stay-picker';
  stayPicker.hidden=true;stayPicker.setAttribute('aria-label','Stay preferences');
  const stayChoiceWrap=document.createElement('div');
  stayChoiceWrap.className='shared-stay-source-options';
  const stayWrap=document.createElement('div');stayWrap.className='shared-stay-options';
  stayPicker.append(stayChoiceWrap,stayWrap);
  const output=document.createElement('output');output.setAttribute('aria-live','polite');
  output.style.cssText='display:block;margin-block:10px;font-weight:600';
  const note=document.createElement('small');note.className='shared-price-note';
  note.textContent=t('noPayment');
  const paymentRate=document.createElement('input');
  paymentRate.type='hidden';paymentRate.name='mad4b_route_rate_id';paymentRate.autocomplete='off';
  const paymentOccupancy=document.createElement('input');
  paymentOccupancy.type='hidden';paymentOccupancy.name='mad4b_route_occupancy';paymentOccupancy.autocomplete='off';
  form.append(paymentRate,paymentOccupancy);
  const intent=document.createElement('input');
  intent.type='hidden';intent.name='shared_tour_selection_v2';intent.autocomplete='off';
  form.append(intent);
  form.addEventListener('submit',()=>{
   const snap=broker.getSnapshot();
   const rate=asRateId(snap.selectedRateId);
   const available=pricedTiers(rate);
   paymentRate.value=appliedDateReadyForQuote(form)&&rate&&available.length?String(rate):'';
   paymentOccupancy.value=paymentRate.value&&available.includes(occupancy.value)?occupancy.value:'';
   const payload=intentPayload(form);
   intent.value=payload?JSON.stringify(payload):'';
  },true);
  panel.append(title,rateRow,label,output,note);
  const description=form.querySelector('textarea[name="description"]')?.closest('.ff-el-group');
  if(description){description.insertAdjacentElement('beforebegin',picker);
   picker.insertAdjacentElement('afterend',transportPicker);
   transportPicker.insertAdjacentElement('afterend',stayPicker);
  }else{form.append(picker,transportPicker,stayPicker);}
  const sibling=form.querySelector('[data-presentation-form-projection-v1]');
  if(sibling)sibling.insertAdjacentElement('afterend',panel);else form.append(panel);
  states.set(form,{occupancy,occupancyLabel:label,rateSelect,rateRow,
    sourceDate:'',sourceContext:null,sourcePending:false,
    sourceNeedsApply:false,sourceRequestId:0,sourceController:null,
    roomContext:false,lastPricingRate:null,
    panel,picker,extraWrap,extras:new Map(),
    transportPicker,transportWrap,transportIntent,transportFingerprint:'',
    stayPicker,stayChoiceWrap,stayWrap,stayChoices:new Map(),stayCatalog:new Map(),
    output,intent,paymentRate,paymentOccupancy,seq:0,controller:null,timer:null,
    inFlightPriceSignature:null,lastSettledPriceSignature:null});
  // Unified Date may consume the bubbled change while committing a draft.
  // Capture its raw date input at the form boundary to mask stale Rate/stays
  // immediately, without treating an un-applied draft as a financial date.
  form.addEventListener('change',()=>schedule(form),true);
  form.addEventListener('input',()=>schedule(form),true);
  // The native Unified Date bridge owns draft/applied separation and may
  // stop browser events. Watch its explicit pending flag, not a guessed date.
  new MutationObserver(records=>{
    if(!records.some(record=>record.attributeName==='data-mad4b-date-pending'||
      record.attributeName==='data-mad4b-date-draft-invalid'))return;
    const state=states.get(form);
    if(state&&(form.dataset.mad4bDatePending==='1'||
      form.dataset.mad4bDateDraftInvalid==='1')){
      // Synchronous presentation-only draft mask: do not leave the previous
      // Rate's accommodation and price looking current while AJAX applies.
      state.sourcePending=true;state.sourceNeedsApply=true;
      state.sourceContext=null;
      state.sourceController?.abort();state.sourceController=null;
      state.rateRow.hidden=true;state.stayPicker.hidden=true;
      state.occupancyLabel.hidden=true;state.occupancy.disabled=true;
      state.intent.value='';state.paymentRate.value='';
      state.paymentOccupancy.value='';
      state.seq++;state.controller?.abort();state.controller=null;
      state.output.textContent='Apply your travel date to refresh the price and accommodation';
    }
    schedule(form);
  }).observe(form,{attributes:true,attributeFilter:[
    'data-mad4b-date-pending','data-mad4b-date-draft-invalid']});
  document.addEventListener('mad4b-travel-date-changed',()=>{
    if(form.isConnected)schedule(form);
  });
  schedule(form);
 }
 function initialize(){
  document.querySelectorAll(cfg.formScopeSelector).forEach((form,index)=>{
   // Elementor sticky creates an invisible clone as its spacer. It must not
   // become another live form, quote controller or duplicate HTML ID.
   if(form.closest('.elementor-sticky__spacer')){
    form.inert=true;form.setAttribute('aria-hidden','true');
    form.id='fluentform_6_sticky_spacer_'+index;
    return;
   }
   attach(form);
  });
 }
 document.addEventListener('shared-presentation-form-projected',e=>{if(e.target?.matches?.('form.fluent_form_6'))schedule(e.target);});
 document.addEventListener('shared-presentation-selection-changed',()=>{for(const form of forms)if(form.isConnected)schedule(form);});
 // JetTabs visibility and JetEngine's lazy Rate grid determine which tier
 // surface is available. Only refresh existing form controllers.
 const refreshTierSurfaces=()=>{for(const form of forms)if(form.isConnected)schedule(form);};
 document.addEventListener('click',event=>{
   if(event.target.closest?.('#tour_rates_accordion .jet-toggle__control,'+
      '#tour_rates .jet-toggle__control'))
    setTimeout(refreshTierSurfaces,390);
 });
 ['jet-filter-content-rendered','jet-engine/listing-grid/after-lazy-load',
  'shared-presentation-rehydration-requested'].forEach(type=>
   document.addEventListener(type,refreshTierSurfaces));
 // Market, passport and selected-country preferences are server owned.
 // On a persisted change, invalidate the stable quote signature and re-quote.
 // Never infer market pricing or converted amounts from the browser.
 const refreshMarketQuotes=()=>{
  for(const form of forms){
   if(!form.isConnected)continue;
   const state=states.get(form);
   if(!state)continue;
   ++state.sourceRequestId;state.sourceController?.abort();
   state.sourceDate='';state.sourceContext=null;state.sourcePending=false;
   state.lastSettledPriceSignature=null;
   state.activePriceSignature=null;
   state.controller?.abort();
   schedule(form);
  }
 };
 window.addEventListener('visitor-market:changed',refreshMarketQuotes);
 document.addEventListener('visitor-preferences:saved',refreshMarketQuotes);
 initialize();
 new MutationObserver(()=>initialize()).observe(document.body,{subtree:true,childList:true});
 window.SharedPresentationFluentQuoteV1=Object.freeze({version:'1.0.0',readOnly:true,getPayload:selected});
})();
