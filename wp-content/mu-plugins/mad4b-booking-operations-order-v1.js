(()=>{
'use strict';
const config=window.MAD4BOperationsOrder||{};
for(const root of document.querySelectorAll('.mad4b-ops-root')){
const orderId=Number(root.dataset.order||0);
let data=null, lines=[], searchPage=1, searchQ='', searchType='', familyFilter='', loading=false;
const $=(tag,cls,txt)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(txt!==undefined&&txt!==null)e.textContent=String(txt);return e;};
const allowedScope=[
 ['keep_source','حسب المصدر الأصلي'],['additional_service','إضافة خدمة مستقلة'],
 ['request_upgrade','ترقية الخدمة المشمولة (فرق تكلفة)'],
 ['replace_included','طلب استبدال خدمة مشمولة'],['not_required','غير مطلوبة لهذا التنفيذ']
];
const states=[['draft','مسودة تشغيل'],['needs_review','تحتاج مراجعة'],['supplier_confirmed','تأكيد المورد موثق'],['fleet_confirmed','المركبة مؤكدة تشغيليًا']];
const icons={read:'↻',save:'✓',add:'＋'};
const state={};
function form(title,el){const label=$('label','mad4b-ops-field');label.append($('span','',title),el);return label;}
function field(title,value,onChange,type='text'){
 const e=$('input');e.type=type;e.value=value===null||value===undefined?'':String(value);
 if(type==='number'){e.min='0';if(title==='التكلفة التقديرية'||title==='كمية الخدمة')e.step='any';else e.step='1';}
 e.addEventListener('input',()=>onChange(e.value===''?null:(type==='number'?Number(e.value):e.value)));
 return form(title,e);
}
function select(title,options,value,handler){const e=$('select');
 for(const [v,l] of options){const o=$('option');o.value=v;o.textContent=l;e.append(o);}
 e.value=value;e.addEventListener('change',()=>handler(e.value));return form(title,e);}
function button(label,callback,klass='button'){const e=$('button',klass,label);e.type='button';e.addEventListener('click',callback);return e;}
function badge(text,klass=''){return $('span','mad4b-ops-pill '+klass,text);}
const errors={
 operations_revision_conflict_reload_order:'تم تعديل هذا الأوردر من جلسة أخرى. حمّل البيانات الحالية أولًا.',
 operations_original_data_changed_reload_order:'اتغيّر المصدر الأصلي؛ راجع البيانات الجديدة قبل الحفظ.',
 operations_native_service_row_must_be_preserved:'لا يمكن حذف سطر خدمة أصلي؛ اختر «غير مطلوبة» مع سبب بدل الحذف.',
 operations_closed_order_cannot_confirm:'الأوردر مغلق؛ لا يمكن اعتماد مركبات جديدة.',
 operations_fleet_evidence_required:'تأكيد المركبة يحتاج عدد المركبات والسعة الفعلية ومرجع المركبة وإثبات التأكيد.',
 operations_confirmed_fleet_capacity_inadequate:'السعة المعتمدة أقل من عدد الركاب المحدد.',
 operations_child_seat_evidence_required:'يجب إثبات عدد مقاعد الأطفال والرضّع قبل التأكيد.',
 operations_child_restraint_reference_required:'يجب تسجيل مرجع تجهيز/تركيب مقاعد الأطفال والرضّع قبل تأكيد المركبة.',
 operations_actual_luggage_inadequate:'سعة الأمتعة الفعلية غير كافية.',
 operations_party_exceeds_request:'تقسيم المجموعة لا يجوز أن يتجاوز عدد الركاب المحفوظ في Form 6.',
 operations_partial_party_split:'عند تقسيم المجموعة، أدخل أعداد البالغين والأطفال والرضّع جميعًا.',
 operations_custom_service_title_required:'الخدمة الجديدة تحتاج اسمًا أو مرجع خدمة من الكتالوج.',
 operations_exclusion_requires_reason:'استبعاد خدمة من المصدر يحتاج توثيق السبب.',
 operations_selected_transport_not_found:'مرجع Transportation البديل غير موجود في كتالوج الموقع.',
 operations_supplier_confirmation_evidence_required:'تأكيد أي خدمة يتطلب اسم المورد ومرجع تأكيد التوافر والتنفيذ.',
 operations_transport_requires_fleet_confirmation:'النقل يحتاج اعتماد المركبة وسعتها، ولا يكفي تأكيد عام للمورد.',
 operations_catalog_type_invalid:'نوع الخدمة غير مسجل في كتالوج MAD4B.',
 operations_service_quantity_invalid:'الكمية غير صالحة. استخدم قيمة عددية غير سالبة.'
};
const status=$('p','mad4b-ops-notice','تحميل بيانات التشغيل…');
const header=$('div','mad4b-ops-header');
const body=$('div','mad4b-ops-body');
root.replaceChildren(header,status,body);
async function api(task,other={}){
 const params=new URLSearchParams({action:'mad4b_booking_ops_order_v1',nonce:config.nonce||'',
 order_id:String(orderId),task,...other});
 const r=await fetch(config.ajax,{method:'POST',credentials:'same-origin',body:params,
 headers:{'X-Requested-With':'XMLHttpRequest'}});
 const payload=await r.json();
 if(!r.ok||!payload.success)throw Error(errors[payload?.data?.code]||payload?.data?.code||'تعذر تنفيذ الطلب');
 return payload.data;
}
function message(msg,ok=false){status.textContent=msg;status.className=ok?'mad4b-ops-notice success':'mad4b-ops-notice';}
function setDirty(){message('فيه تغييرات تشغيلية غير محفوظة. لم تتغير الأسعار أو حالة الأوردر.');}
function lineTitle(line){return line.custom_title||line.native_title||'خدمة مضافة';}
function sourceRole(r){
 return ({
 rate_included:'مشمولة بالـTour Rate',
 rate_optional:'اختيارية من الـTour Rate',
 itinerary_unlinked:'احتياج Itinerary غير مربوط بالـRate',
 extra_request_only:'رحلة اختيارية حسب المصدر',
 manual_request:'خدمة تشغيل جديدة',
 editorial_stay_plan_not_rate_inclusion:'خطة إقامة أصلية — لم تدخل Rate بعد',
 rate_stay_included_configured:'إقامة مشمولة حسب إعداد Rate — يحتاج اعتماد المورد',
 rate_stay_reference_only:'إقامة مرتبطة بمسودة Rate',
 rate_property_option_only:'Property مرشحة في Rate — ليست حجزًا',
 guide_day_source_not_rate_inclusion:'يوم إرشاد حسب المصدر — يحتاج مطابقة Rate',
 guide_optional_source:'إرشاد اختياري في البرنامج',
 tour_optional_catalog_only:'رحلة اختيارية من كتالوج الرحلة',
 tour_transport_catalog_option_only:'وسيلة نقل متاحة للاختيار حسب المصدر',
 existing_commercial_amendment_reference:'بند تعديل تجاري موجود بالفعل — لا يُضاف سعره مرتين',
 tour_leader_explicit_text_review:'Tour Leader — مذكور في Itinerary ويحتاج تأكيد الدور والمورد',
 tour_leader_logistics_unconfirmed:'Tour Leader — ترشيح من الحركة أو نقطة الالتقاء، غير مشمول تلقائيًا',
 customer_requested_optional_extra:'Extra اختارها العميل في Fluent Form 6 — تحتاج اعتماد التنفيذ والتكلفة'
 })[r.source_role]||r.source_role;
}
function editLine(line,index){
 const card=$('details','mad4b-ops-line');
 if(line.operation_state!=='draft')card.open=true;
 const summary=$('summary','mad4b-ops-line-head');
 const left=$('div');
 left.append($('strong','',lineTitle(line)));
 const sub=$('div','mad4b-ops-muted','اليوم '+(line.day||'—')+' · '+sourceRole(line)+
   (line.source_family?' · '+line.source_family:''));
 left.append(sub);
 const right=$('div','mad4b-ops-line-status');
 right.append(badge(line.operation_state==='fleet_confirmed'?'مركبة مؤكدة':line.operation_state==='supplier_confirmed'?'مورد مؤكد':line.operation_state==='needs_review'?'مراجعة':'مسودة',line.operation_state));
 summary.append(left,right);
 card.append(summary);
 const content=$('div','mad4b-ops-line-detail');
 const group=$('div','mad4b-ops-fields');
 const add=(c)=>group.append(c);
 if(line.origin==='manual'){
  add(field('وصف الخدمة',line.custom_title,v=>{line.custom_title=v||'';setDirty();},'text'));
  add(field('اليوم / المرحلة (بدون سقف تجاري)',line.day,v=>{line.day=v||0;setDirty();},'number'));
 }
 add(field('كمية الخدمة',line.service_quantity,v=>{line.service_quantity=v;setDirty();},'number'));
 add(field('وحدة الكمية (فرد، ليلة، مركبة، غرفة أو أي وحدة)',line.service_quantity_unit,v=>{line.service_quantity_unit=v||'';setDirty();}));
 add(select('تعامل الخدمة',[...allowedScope],line.scope_change,v=>{line.scope_change=v;setDirty();}));
 add(select('حالة اعتماد الأوبريشن',[...states],line.operation_state,v=>{
   line.operation_state=v;setDirty();
 }));
 add(field('مرجع Transportation ID البديل (اختياري)',line.selected_transport_id,v=>{line.selected_transport_id=v;setDirty();},'number'));
 add(field('نوع المركبة (حر)',line.vehicle_category,v=>{line.vehicle_category=v||'';setDirty();}));
 add(field('مرجع المركبة / اللوحة',line.fleet_reference,v=>{line.fleet_reference=v||'';setDirty();}));
 add(field('مورد / مسؤول التنفيذ',line.supplier_reference,v=>{line.supplier_reference=v||'';setDirty();}));
 add(field('تقسيم المجموعة / المجموعة (حر)',line.group_label,v=>{line.group_label=v||'';setDirty();}));
 add(field('مرجع تركيب/تجهيز مقاعد الأطفال',line.child_restraint_reference,v=>{line.child_restraint_reference=v||'';setDirty();}));
 const numericLabels={
 vehicles:'عدد المركبات',actual_seats:'سعة ركاب كل مركبة الفعلية',
 actual_large_bags_capacity:'سعة الحقائب الكبيرة لكل مركبة',
 adults:'البالغون المخصصون',children:'الأطفال المخصصون',
 infants:'الرضّع المخصصون',large_bags:'الحقائب الكبيرة',
 child_seats_confirmed:'مقاعد الأطفال المؤكدة',cost_estimate:'التكلفة التقديرية'
 };
 for(const [key,label] of Object.entries(numericLabels)){
   add(field(label,line[key],v=>{line[key]=v;setDirty();},'number'));
 }
 add(field('العملة',line.currency,v=>{line.currency=(v||'').toUpperCase();setDirty();}));
 add(field('تاريخ التنفيذ',line.service_date,v=>{line.service_date=v||'';setDirty();},'date'));
 add(field('وقت الالتقاء',line.pickup_at,v=>{line.pickup_at=v||'';setDirty();}));
 add(field('نقطة الالتقاء',line.meeting_point,v=>{line.meeting_point=v||'';setDirty();}));
 add(field('الوصول / ملاحظات المسار',line.destination_note,v=>{line.destination_note=v||'';setDirty();}));
 add(field('مرجع التأكيد / دليل المورد',line.confirmation_reference,v=>{line.confirmation_reference=v||'';setDirty();}));
 content.append(group);
 const notes=form('ملاحظات وسبب الاستبدال / الإلغاء',$('textarea'));
 const textarea=notes.querySelector('textarea');textarea.rows=3;textarea.value=line.notes||'';
 textarea.addEventListener('input',()=>{line.notes=textarea.value;setDirty();});
 content.append(notes);
 if(line.origin==='manual'){
   content.append(button('حذف الخدمة المضافة',()=>{
      if(!window.confirm('حذف هذا السطر التشغيلي غير الأصلي؟'))return;
      lines.splice(index,1);draw();setDirty();
   },'button'));
 }else {
   const provenance=$('p','mad4b-ops-muted',
      'سجل أصلي محفوظ. '+(line.source_post_id?'CPT #'+line.source_post_id+' · ':'')+
      (line.stage_key?'Stage: '+line.stage_key:'')+
      '. تغيير الخدمة في الأوبريشن لا يغيّر Rate أو سعر العميل.');
   content.append(provenance);
   if(line.source_evidence&&Object.keys(line.source_evidence).length){
     const proof=$('details','mad4b-ops-source-evidence');
     proof.append($('summary',null,'دليل ارتباط الخدمة بالمصدر الأصلي'));
     const pre=$('pre');pre.textContent=JSON.stringify(line.source_evidence,null,2);
     proof.append(pre);content.append(proof);
   }
 }
 card.append(content);
 return card;
}
function draw(){
 if(!data)return;
 header.replaceChildren();
 const title=$('h3',null,'Booking Operations · أوردر #'+orderId);
 const meta=$('p','mad4b-ops-muted',data.tour_title+
 ' · Rate '+(data.rate_id||'غير مرتبط')+' ('+data.rate_status+')'+
 (data.program_id?' · Program '+data.program_id+' '+data.program_title:'')+
 ' · سفر '+(data.travel_date||'غير محدد')+
 ' · '+data.party.total+' راكب ('+data.party.adults+' بالغ، '+data.party.children+' طفل، '+data.party.infants+' رضيع)');
 header.append(title,meta);
 const actions=$('div','mad4b-ops-actions');
 actions.append(badge('Revision '+data.revision),
   badge(data.order_status),
   button('إعادة تحميل المصدر',load,'button'),
   button('حفظ خطة التشغيل',save,'button button-primary'));
 if(data.rate_edit_url){
   const editRate=$('a','button','تعديل Tour Rate / Program في المصدر الأصلي');
   editRate.href=data.rate_edit_url;editRate.target='_blank';editRate.rel='noopener noreferrer';
   actions.append(editRate);
 }
 header.append(actions);
 body.replaceChildren();
 if(data.order_closed)body.append($('p','mad4b-ops-warning','هذا الأوردر مغلق؛ يُسمح بحفظ الملاحظات فقط، ولا يمكن تأكيد مركبات جديدة.'));
 if(data.itinerary_source_status!=='source_verified')body.append($('p','mad4b-ops-warning',
   'الـItinerary الأصلي غير محسوم: '+data.itinerary_source_status+' — لا تعتمد مسارًا أو برنامج كروز غير مختار.'));
 const availablePrograms=data.native_service_families?.program_choices||[];
 if(availablePrograms.length){
   const assigned=availablePrograms.filter(x=>x.rate_assigned).map(x=>x.name);
   const copy='Programs المرتبطة بالمصدر: '+availablePrograms.map(x=>x.name+' #'+x.id).join(' · ');
   body.append($('p','mad4b-ops-info',copy+' — تعيين الأوردر الفعلي: '+
    (assigned.join('، ')||'غير محدد. يلزم تعيينه في Tour Rate الأصلي، وليس عبر تغيير خدمة تشغيلية.')));
 }
 if(data.source_changed_since_save)body.append($('p','mad4b-ops-warning',
   'تغير المصدر الأصلي بعد آخر حفظ. راجع السطور واعتمد النسخة الحالية قبل إعادة الحفظ.'));
 const note=$('p','mad4b-ops-info',
 'المشمول يُقرأ من Tour Rate فقط. الـItinerary مرجع احتياج، والإضافات طلبات مراجعة. تأكيد الأوبريشن لا يعتمد السعر أو يخصم أي مبالغ.');
 body.append(note);
 const summary=$('div','mad4b-ops-summary');
 summary.append(
   badge('Itinerary: '+data.itinerary.length+' مرحلة'),
   badge('Rate included: '+data.included_source_stages),
   badge('Rate optional: '+data.optional_source_stages),
   badge('الخدمات التشغيلية: '+lines.length),
   badge('Extras مختارة من Form 6: '+(data.form6_selected_extra_count||0)),
   badge('عائلات المصدر: '+(data.native_service_families?.service_row_count||0)),
   badge('Tour Leader مراجعة: '+(data.native_service_families?.tour_leader_review_candidates||0))
 );
 body.append(summary);
 const tabbar=$('div','mad4b-ops-tabs');
 const panels=$('div');
 const tabNames=[['services','خطة الخدمات'],['itinerary','برنامج الرحلة'],['search','إضافة خدمات من الكتالوج']];
 const tabs={};
 const activate=(kind)=>{
   for(const [id] of tabNames)tabs[id].classList.toggle('button-primary',id===kind);
   renderPanel(kind);
 };
 for(const [kind,name] of tabNames){
   const b=button(name,()=>activate(kind),'button');
   tabs[kind]=b;tabbar.append(b);
 }
 body.append(tabbar,panels);
 function renderPanel(kind){
   panels.replaceChildren();
   if(kind==='services'){
     if(!lines.length)panels.append($('p','mad4b-ops-muted','لا يوجد خدمات أو تعيينات في المصدر؛ أضف خدمة تشغيل أو راجع برنامج الرحلة.'));
     const families=Array.from(new Set(lines.map(x=>x.source_family||'unspecified'))).sort();
     const choice=select('عائلة الخدمة',[['','كل الخدمات'],...families.map(x=>[x,x])],familyFilter,v=>{
       familyFilter=v;renderPanel('services');});
     panels.append(choice);
     lines.forEach((line,i)=>{
       if(!familyFilter||((line.source_family||'unspecified')===familyFilter))
         panels.append(editLine(line,i));
     });
     const addRow=button('＋ خدمة تشغيل مخصصة',()=>{
       insertManual(0,'','manual');renderPanel('services');setDirty();
     });
     panels.append(addRow);
   }else if(kind==='itinerary'){
     const t=$('table','widefat striped mad4b-ops-table');
     const thead=$('tr');for(const x of ['اليوم','البرنامج الأصلي','احتياج النقل','المسار / الوسيلة','إجراء'])thead.append($('th',null,x));
     t.append(thead);
     for(const day of data.itinerary){
       const tr=$('tr');const cells=[
         String(day.day),day.title||'—',day.need,
         'From term '+(day.from_term||'—')+' / To term '+(day.to_term||'—')+' · '+day.mode
       ];
       for(const c of cells)tr.append($('td',null,c));
       const actions=$('td');
       actions.append(button('＋ مهمة نقل لهذا اليوم',()=>{
         insertManual(0,'انتقال يوم '+day.day+' — '+(day.title||'بدون عنوان'),'manual');
         const newRow=lines[lines.length-1];
         newRow.day=day.day;
         newRow.notes='مرجع اليوم الأصلي: '+day.key+'؛ يتم تحديد الخدمة الفعلية من كتالوج النقل أو وصف تشغيل حر.';
         setDirty();activate('services');
       }));
       tr.append(actions);
       t.append(tr);
     }
     panels.append(t,$('p','mad4b-ops-muted','بيانات الـItinerary قراءة فقط؛ عدل المصدر من محرر الرحلة. لا يُستنتج أن كل احتياج مشمول في السعر.'));
   }else {
     const formSearch=$('div','mad4b-ops-search');
     const query=$('input');query.type='search';query.placeholder='ابحث في كل أنواع خدمات MAD4B أو برقم CPT';
     query.value=searchQ;
     const types=[['','جميع أنواع الخدمات الأصلية'],...(data.catalog_types||[]).map(x=>[x.type,x.label+' ('+x.type+')'])];
     const typeSelect=select('نوع الخدمة',types,searchType,v=>{searchType=v;searchPage=1;});
     const results=$('div','mad4b-ops-search-results');
     const run=async(page=1)=>{
       try{
         results.replaceChildren($('p','mad4b-ops-muted','جارٍ البحث…'));
         const result=await api('search',{q:query.value,page:String(page),type:searchType});
         searchPage=result.page;searchQ=query.value;results.replaceChildren();
         if(!result.rows.length)results.append($('p','', 'لا توجد نتائج في هذه الصفحة.'));
         for(const service of result.rows){
           const item=$('div','mad4b-ops-result');
           item.append($('span','',service.title+' · '+service.type+' #'+service.id+' · '+service.status));
           item.append(button('إضافة للطلب',()=>{
             insertManual(service.id,service.title,service.type);
             setDirty();activate('services');
           }));
           results.append(item);
         }
         const pager=$('div','mad4b-ops-actions');
         if(result.page>1)pager.append(button('السابق',()=>run(page-1)));
         pager.append($('span','mad4b-ops-muted','الصفحة '+result.page+' / '+result.pages+' · نتائج '+result.total));
         if(result.page<result.pages)pager.append(button('التالي',()=>run(page+1)));
         results.append(pager);
       }catch(e){results.replaceChildren($('p','mad4b-ops-warning',e.message));}
     };
     formSearch.append(typeSelect,query,button('بحث',()=>run(1),'button button-primary'));
     const manual=$('input');manual.type='text';manual.placeholder='أو اسم خدمة غير مسجلة بعد';
     formSearch.append(manual,button('＋ إضافة بالاسم',()=>{
       if(!manual.value.trim())return;
       insertManual(0,manual.value.trim(),'manual');setDirty();activate('services');
     }));
     panels.append(formSearch,results);
     run(searchPage);
   }
 }
 activate('services');
}
function insertManual(sourceId,title,kind){
 const seed=new Uint8Array(12);window.crypto.getRandomValues(seed);
 const key=Array.from(seed).map(x=>x.toString(16).padStart(2,'0')).join('');
 lines.push({
 key,origin:'manual',native_title:title||'',custom_title:title||'',
 source_role:'manual_request',source_kind:kind,
 source_post_id:sourceId,day:0,stage_key:'',scope_change:'additional_service',
 source_family:'manual',source_evidence:{source_scope:'operator_request'},
 service_quantity:null,service_quantity_unit:'',
 group_label:'',child_restraint_reference:'',
 selected_transport_id:null,
 vehicle_category:'',fleet_reference:'',supplier_reference:'',vehicles:null,actual_seats:null,
 actual_large_bags_capacity:null,adults:null,children:null,infants:null,large_bags:null,
 child_seats_confirmed:null,pickup_at:'',service_date:'',meeting_point:'',
 destination_note:'',cost_estimate:null,currency:'',confirmation_reference:'',
 notes:'',operation_state:'draft',operator_verified:false,
 commercial_price_authorized:false,supplier_stock_verified:false
 });
}
async function load(){
 if(loading)return;
 loading=true;message('تحديث بيانات المصدر الأصلي…');
 try{data=await api('read');lines=JSON.parse(JSON.stringify(data.lines||[]));message('تم تحميل الأوردر والمصدر الأصلي.');draw();}
 catch(e){message(e.message);}
 finally{loading=false;}
}
async function save(){
 if(loading||!data)return;
 loading=true;message('جارٍ حفظ الخطة على الأوردر…');
 try{
   const payload=JSON.stringify({revision:data.revision,
      source_fingerprint:data.source_fingerprint,lines});
   const result=await api('save',{payload});
   message('تم حفظ مراجعة التشغيل رقم '+result.revision+' على الأوردر، بدون تغيير سعر أو حالة الحجز.',true);
   data=await api('read');lines=JSON.parse(JSON.stringify(data.lines||[]));draw();
 }catch(e){message(e.message);}
 finally{loading=false;}
}
load();
}
})();
