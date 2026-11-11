(() => {
'use strict';
const cfg=window.MAD4BPricingLab;
const root=document.getElementById('mad4b-pricing-lab-root');
if(!root||!cfg?.endpoint)return;
const E=(tag,cls,text)=>{
 const n=document.createElement(tag);
 if(cls)n.className=cls;
 if(text!==undefined)n.textContent=String(text);
 return n;
};
const state={
  loaded:null,selected:null,preview:null,active:'Presets',currentCatalog:null,
  busy:false,revision:0
};
const tabs=['Presets','Target Groups','Controllers','Impact Preview','Apply & History'];
const nav=E('nav','mad4b-pl-tabs');nav.setAttribute('aria-label','Pricing Lab steps');
const panels=new Map();
const layout=E('div','mad4b-pl-layout');
const left=E('div','mad4b-pl-main');
const right=E('aside','mad4b-pl-preview');
const feedback=E('p','mad4b-pl-feedback','');
feedback.setAttribute('role','status');feedback.setAttribute('aria-live','polite');
const heading=E('div','mad4b-pl-preview-header');
heading.append(E('strong','','Affected Tours · Impact Preview'),
  E('small','','Live source rates · read-only'));
const previewBody=E('div','mad4b-pl-preview-body',
  'Choose a group and run a dry-run to inspect eligible Tour Rates.');
right.append(heading,previewBody);
for(const tab of tabs){
 const b=E('button','mad4b-pl-tab',tab);b.type='button';
 b.addEventListener('click',()=>showTab(tab));
 nav.append(b);
 const p=E('section','mad4b-pl-panel');p.setAttribute('aria-label',tab);
 panels.set(tab,p);left.append(p);
}
layout.append(left,right);
root.replaceChildren(nav,feedback,layout);
const msg=(str,error=false)=>{
 feedback.textContent=String(str||'');
 feedback.classList.toggle('is-error',error);
};
const input=(type,label,name,initial='')=>{
 const wrap=E('label','mad4b-pl-control');
 wrap.append(E('span','',label));
 const field=E('input');field.type=type;field.name=name;
 field.value=initial;
 wrap.append(field);
 return [wrap,field];
};
const selector=(label,choices,value='')=>{
 const wrap=E('label','mad4b-pl-control');
 wrap.append(E('span','',label));
 const s=E('select');
 for(const [k,name] of choices){
  const opt=new Option(name,k);s.add(opt);
 }
 if(value!==undefined)s.value=value;
 wrap.append(s);
 return [wrap,s];
};
const cell=(label,value)=>{
 const x=E('div','mad4b-pl-stat');
 x.append(E('small','',label),E('strong','',String(value)));return x;
};
const box=(label,content)=>{
 const el=E('div','mad4b-pl-box');
 el.append(E('strong','mad4b-pl-box-title',label),content);return el;
};
const form=E('div','mad4b-pl-form');
const presetsPanel=panels.get('Presets');
presetsPanel.append(E('h2','','Preset Library'));
presetsPanel.append(E('p','',
 'Save group Presets here. Templates come from the existing Booking Experience library; changes stay in Draft until reviewed.'));
const chooser=selector('Saved Lab presets',[['','New Draft']])[1];
const chooserWrap=E('label','mad4b-pl-control');
chooserWrap.append(E('span','','Open Draft / staged assignment'),chooser);
presetsPanel.append(chooserWrap);
const configGrid=E('div','mad4b-pl-fields');
const [,titleField]=input('text','Preset name','title','Family / Private / Cairo');
titleField.maxLength=105;
const [,noteField]=input('text','Purpose / justification','note','');
noteField.maxLength=250;
const [,templateField]=selector('Native pricing template',[]);
const [,valueField]=input('number','Price policy value','value','100');
valueField.step='0.0001';
// Currency choices and their ISO codes come from the shared JetEngine
// Glossary adapter. This is not a manually duplicated local list.
const [,currencyField]=selector('Currency from JetEngine Glossary',[]);
const policyValueControl=labelled('Price multiplier (%)',valueField);
const policyValueHint=E('small','mad4b-pl-muted',
  '100% = original price · 90% = 10% discount · 110% = 10% increase');
policyValueControl.append(policyValueHint);
const policyCurrencyControl=labelled('Currency (JetEngine Glossary · fixed amounts only)',currencyField);
policyCurrencyControl.append(E('small','mad4b-pl-muted',
 'Selection list: JetEngine Currencies Glossary. Currency support and FX remain validated by WooCommerce/WCML.'));
const [,audienceField]=selector('Price audience',[['general','International / General'],['egyptians','Egyptians']],'general');
const [,occupancyField]=selector('Occupancy',[['single','Single'],['double','Double'],['triple','Triple']],'double');
const [,languageField]=selector('WPML language',[]);
const languageControl=labelled('WPML language (legacy Drafts only)',languageField);
const languageOverview=E('p','mad4b-pl-language-inheritance','');
const [,marketField]=selector('Market country',[]);
const marketControl=labelled('Market (legacy Drafts only)',marketField);
configGrid.append(
 labelled('Preset name',titleField),
 labelled('Business justification',noteField),
 labelled('Native template',templateField),
 policyValueControl,
 policyCurrencyControl,
 labelled('Audience',audienceField),
 labelled('Occupancy',occupancyField),
 languageControl,
 marketControl
);
form.prepend(languageOverview);
function labelled(label,control){
 const l=E('label','mad4b-pl-control');l.append(E('span','',label),control);return l;
}
form.append(configGrid);
const meta=E('small','mad4b-pl-meta','New Draft · changes do not affect published prices');
form.append(meta);
const saveBtn=E('button','button button-primary','Save Draft Version');
saveBtn.type='button';
const createBtn=E('button','button','New Preset');
createBtn.type='button';
const configActions=E('div','mad4b-pl-actions');configActions.append(saveBtn,createBtn);
form.append(configActions);
presetsPanel.append(form);

const targetPanel=panels.get('Target Groups');
targetPanel.append(E('h2','','Target Groups · JetEngine'));
targetPanel.append(E('p','',
 'Taxonomies, Glossaries and attached Meta Boxes are discovered from the selected site and WPML language. AND/OR applies only inside the approved content-language and market scope.'));
const targetingControls=E('div','mad4b-pl-fields');
const [,logicField]=selector('Group matching',[['and','Match all (AND)'],['or','Match any (OR)']],'and');
targetingControls.append(labelled('Combine rules',logicField));
targetPanel.append(targetingControls);

// One base pricing policy, optionally scoped to OR-combined cohorts.
// Language and market filters are explicit dimensions per group; without
// any group the canonical Tour policy inherits across WPML translations.
const groupEditor=E('section','mad4b-pl-target-group-editor');
groupEditor.append(E('h3','','Optional language / market groups'));
groupEditor.append(E('p','mad4b-pl-muted',
 'Default: inherit the canonical-language policy across all display languages. Add groups only to limit display languages and/or markets. Multiple groups are combined with OR.'));
const groupList=E('div','mad4b-pl-target-group-list');
const addGroupButton=E('button','button','Add targeting group');
addGroupButton.type='button';
const groupRows=[];
const buildGroup=(initial={})=>{
 if(groupRows.length>=6)return;
 const row=E('div','mad4b-pl-target-group');
 const title=input('text','Group name','group_title',
     initial.title||'Target group '+(groupRows.length+1));
 title[1].maxLength=90;
 const languageToggle=input('checkbox','Target display languages','use_display_languages');
 const marketToggle=input('checkbox','Target markets','use_markets');
 const languageSelect=E('select','mad4b-pl-multi');
 const marketSelect=E('select','mad4b-pl-multi');
 languageSelect.multiple=true;marketSelect.multiple=true;
 languageSelect.size=4;marketSelect.size=4;
 const languageLabel=labelled('Display languages',languageSelect);
 const marketLabel=labelled('Market countries (preview only)',marketSelect);
 const removal=E('button','button','Remove');removal.type='button';
 const instance={row,title:title[1],languageToggle:languageToggle[1],
  marketToggle:marketToggle[1],languageSelect,marketSelect};
 for(const [code,label] of Object.entries(state.loaded?.languages||{})){
  const opt=new Option(typeof label==='string'?label:code,code);
  opt.selected=(initial.display_languages||[]).includes(code);
  languageSelect.add(opt);
 }
 for(const entry of state.loaded?.catalog?.market_countries||[]){
  const opt=new Option(String(entry.name||entry.id),String(entry.id));
  opt.selected=(initial.markets||[]).includes(String(entry.id));
  marketSelect.add(opt);
 }
 instance.languageToggle.checked=!!initial.display_languages?.length;
 instance.marketToggle.checked=!!initial.markets?.length;
 const sync=()=>{
  languageLabel.hidden=!instance.languageToggle.checked;
  marketLabel.hidden=!instance.marketToggle.checked;
 };
 languageToggle[1].addEventListener('change',sync);
 marketToggle[1].addEventListener('change',sync);
 row.append(title[0],languageToggle[0],languageLabel,marketToggle[0],marketLabel,removal);
 groupRows.push(instance);groupList.append(row);
 removal.addEventListener('click',()=>{
  row.remove();groupRows.splice(groupRows.indexOf(instance),1);
  state.preview=null;renderPreview(null);
 });
 sync();
};
addGroupButton.addEventListener('click',()=>buildGroup());
const readGroups=()=>groupRows.map((g,index)=>{
 const locales=g.languageToggle.checked
   ?[...g.languageSelect.selectedOptions].map(x=>x.value):[];
 const markets=g.marketToggle.checked
   ?[...g.marketSelect.selectedOptions].map(x=>x.value):[];
 if(!g.languageToggle.checked&&!g.marketToggle.checked)
   throw Error('Group '+(index+1)+': choose display languages or markets, or remove this group to inherit.');
 if(g.languageToggle.checked&&!locales.length)
   throw Error('Group '+(index+1)+': select display languages.');
 if(g.marketToggle.checked&&!markets.length)
   throw Error('Group '+(index+1)+': select markets.');
 return {title:g.title.value.trim(),display_languages:locales,markets};
});
groupEditor.append(groupList,addGroupButton);
targetPanel.append(groupEditor);
const rules=E('div','mad4b-pl-rules');
const addRule=E('button','button','Add JetEngine condition');
addRule.type='button';
const clearRules=E('button','button','Clear conditions');
clearRules.type='button';
targetPanel.append(rules,addRule,clearRules);
const targetHelp=E('p','mad4b-pl-muted','');
targetPanel.append(targetHelp);

const controlPanel=panels.get('Controllers');
controlPanel.append(E('h2','','Group Controllers'));
controlPanel.append(E('p','',
 'The selected Preset delegates price computation to Booking Experience. Individual source overrides remain in the Tour Rate controller; group targeting is resolved by MAD4B Strategy Registry.'));
const controlInfo=E('div','mad4b-pl-info');
const ctrlLinks=E('div','mad4b-pl-actions');
for(const [url,label] of [
 [cfg.overview_url,'Open '+(cfg.overview_label||'Pricing Overview')],
 [cfg.pricing_url,'Open native Pricing Controls'],
 [cfg.native_presets_url,'Open '+(cfg.native_presets_label||'Pricing Templates')]
]){
 if(!url)continue;
 const a=E('a','button',label);a.href=url;ctrlLinks.append(a);
}
controlPanel.append(controlInfo,ctrlLinks);
const impactPanel=panels.get('Impact Preview');
impactPanel.append(E('h2','','Affected Tours · Dry Run'));
impactPanel.append(E('p','',
 'Read published source prices and simulate a Native Preset. No live pricing, coupon or checkout state changes. Currency groups are never summed together.'));
const runBtn=E('button','button button-primary','Run live-source simulation');
runBtn.type='button';
const impactSummary=E('div','mad4b-pl-summary');
const impactRows=E('div','mad4b-pl-impact-rows');
impactPanel.append(runBtn,impactSummary,impactRows);

const applyPanel=panels.get('Apply & History');
applyPanel.append(E('h2','','Apply & History'));
applyPanel.append(E('p','',
 'Stage creates disabled, unapproved assignments in the EXISTING Booking Experience Preset provider. It does NOT activate an offer or change checkout pricing. Activation must be reviewed separately in its native controller.'));
const stageWarning=E('p','mad4b-pl-warning',
 'A fresh dry-run with zero conflicts is required. The number of rates must fit the bounded batch; otherwise narrow the targeting.');
const stageInfo=E('div','mad4b-pl-stage-info');
const approval=E('label','mad4b-pl-confirm');
const approveCheck=E('input');approveCheck.type='checkbox';
approval.append(approveCheck,E('span','',
 'I authorize staging DISABLED native assignments for this exact reviewed group. No active pricing policy is being approved.'));
const stageBtn=E('button','button button-primary','Stage disabled native assignments');
stageBtn.type='button';
const rollbackBtn=E('button','button','Rollback staged assignments');
rollbackBtn.type='button';
const history=E('div','mad4b-pl-history');
applyPanel.append(stageWarning,stageInfo,approval,stageBtn,rollbackBtn,E('h3','','Receipt History'),history);

function showTab(tab){
 state.active=tab;
 for(const [name,panel] of panels)panel.hidden=name!==tab;
 nav.querySelectorAll('button').forEach(btn=>{
  btn.classList.toggle('is-active',btn.textContent===tab);
  btn.setAttribute('aria-selected',btn.textContent===tab?'true':'false');
 });
 if(tab==='Controllers')updateControllers();
 if(tab==='Apply & History')updateApply();
}
function catalog(){return state.currentCatalog||state.loaded?.catalog||{dimensions:[]};}
function dimensionSelect(){
 const s=E('select');
 s.setAttribute('aria-label','JetEngine field');
 const categories=new Map();
 for(const dimension of catalog().dimensions||[]){
  const group=dimension.group||'other';
  if(!categories.has(group))categories.set(group,[]);
  categories.get(group).push(dimension);
 }
 for(const [group,ds] of categories){
  const g=document.createElement('optgroup');g.label=group.replaceAll('_',' ');
  for(const d of ds){
   const addition=d.glossary_id?' · Glossary':d.source==='meta_box'?' · Meta Box':'';
   g.append(new Option(d.label+addition,d.id));
  }
  s.append(g);
 }
 return s;
}
function ruleRow(data=null){
 if(rules.children.length>=12)return;
 const dimensions=catalog().dimensions||[];
 if(!dimensions.length)return;
 const container=E('div','mad4b-pl-rule');
 const header=E('div','mad4b-pl-rule-head');
 const field=dimensionSelect();
 if(data&&dimensions.some(d=>d.id===data.id))field.value=data.id;
 const del=E('button','button','Remove');del.type='button';
 del.addEventListener('click',()=>{container.remove();invalidatePreview();});
 header.append(field,del);
 const body=E('div','mad4b-pl-rule-body');
 const draw=()=>{
  body.replaceChildren();
  const d=dimensions.find(x=>x.id===field.value);
  if(!d)return;
  if(d.kind==='range'){
   const min=E('input');min.type='number';min.step='any';min.setAttribute('aria-label','Minimum');
   const max=E('input');max.type='number';max.step='any';max.setAttribute('aria-label','Maximum');
   min.value=data?.id===d.id&&data.min!==undefined?String(data.min):String(d.min);
   max.value=data?.id===d.id&&data.max!==undefined?String(data.max):String(d.max);
   body.append(labelled('Minimum',min),labelled('Maximum',max));
  }else{
   const options=E('select');options.multiple=true;
   options.size=Math.min(7,Math.max(2,d.options?.length||2));
   options.setAttribute('aria-label','Rule values');
   const selected=new Set(data?.id===d.id?data.values||[]:[]);
   for(const option of d.options||[]){
    const opt=new Option(option.label,String(option.id));
    opt.selected=selected.has(String(option.id));options.add(opt);
   }
   const matching=E('select');
   for(const [id,title] of [['any','Match any value'],['all','Match every value']])
     matching.add(new Option(title,id));
   matching.value=data?.id===d.id&&data.match==='all'?'all':'any';
   body.append(labelled('Existing field values · Ctrl/Cmd to multi-select',options),
     labelled('Within this condition',matching));
  }
 };
 field.addEventListener('change',()=>{data=null;draw();});
 container.append(header,body);rules.append(container);draw();
}
addRule.addEventListener('click',()=>{ruleRow();invalidatePreview();});
clearRules.addEventListener('click',()=>{
 rules.replaceChildren();invalidatePreview();
});
function getRules(){
 const dimensions=new Map((catalog().dimensions||[]).map(d=>[d.id,d]));
 const out=[];
 for(const row of rules.children){
  const d=dimensions.get(row.querySelector('.mad4b-pl-rule-head select')?.value);
  if(!d)throw Error('Choose a valid JetEngine source');
  if(d.kind==='range'){
   const fields=row.querySelectorAll('input');
   if(!fields[0].value||!fields[1].value)throw Error('Set both limits');
   out.push({id:d.id,min:Number(fields[0].value),max:Number(fields[1].value)});
  }else{
   const options=row.querySelector('select[multiple]');
   const values=Array.from(options.selectedOptions).map(o=>o.value);
   if(!values.length)throw Error('Select at least one value in '+d.label);
   out.push({id:d.id,values,match:row.querySelector('.mad4b-pl-rule-body select:not([multiple])').value});
  }
 }
 if(!out.length)throw Error('Add at least one targeting condition');
 return out;
}
function chosenConfig(){
 const legacy=!languageControl.hidden;
 const groups=legacy?[]:readGroups();
 return {
  title:titleField.value.trim(),note:noteField.value.trim(),
  template_id:templateField.value,value:valueField.value,
  currency:currencyField.value.toUpperCase().trim(),
  audience:audienceField.value,occupancy:occupancyField.value,
  language_scope:legacy?'legacy':groups.length?'target_groups':'inherit_default',
  authoring_language:state.loaded?.pricing_language?.default_language||'en',
  target_groups:groups,
  languages:legacy?[languageField.value]:
    [state.loaded?.pricing_language?.default_language||'en'],
  markets:legacy&&marketField.value?[marketField.value]:[],
  logic:logicField.value,rules:getRules()
 };
}
function assignOptions(node,data,selected=''){
 node.replaceChildren();
 for(const [id,label] of data)node.add(new Option(label,id));
 node.value=selected;
}
function defaultDraft(){
 const template=state.loaded?.native_templates.find(t=>t.id==='base-percent')
    ||state.loaded?.native_templates[0];
 return {title:'',note:'',template_id:template?.id||'',
    value:template?.value??100,currency:template?.currency||'',
    audience:'general',occupancy:'double',
    language_scope:'inherit_default',
    authoring_language:state.loaded?.pricing_language?.default_language||'en',
    languages:[state.loaded?.pricing_language?.default_language||'en'],
    markets:[],target_groups:[],logic:'and',rules:[]};
}
function syncPolicyValue(){
 const template=state.loaded?.native_templates.find(t=>t.id===templateField.value);
 const strategy=template?.strategy||'unchanged';
 const isPercent=strategy==='percent';
 const unchanged=strategy==='unchanged';
 policyValueControl.querySelector('span').textContent=isPercent
   ?'Price multiplier (%)'
   :unchanged?'Value not required':'Amount ('+strategy+')';
 policyValueHint.textContent=isPercent
   ?'100% = original price · 90% = 10% discount · 110% = 10% increase'
   :unchanged?'This Preset keeps the published price unchanged.'
     :'Uses the entered amount in the selected currency; preview before staging.';
 valueField.disabled=unchanged;
 currencyField.disabled=isPercent||unchanged;
}
function restore(formData){
 titleField.value=formData.title||'';
 noteField.value=formData.note||'';
 templateField.value=formData.template_id||'base-unchanged';
 valueField.value=formData.value===''?'':String(formData.value??100);
 const savedCurrency=String(formData.currency||'').toUpperCase();
 if(savedCurrency && !Object.hasOwn(state.loaded?.currency_choices||{},savedCurrency)){
  // Keep legacy Draft visible but make the unsupported ISO code explicit.
  const option=new Option(savedCurrency+' — legacy / unavailable in Glossary',
      savedCurrency);option.dataset.legacy='true';
  currencyField.add(option);
 }
 currencyField.value=savedCurrency;
 audienceField.value=formData.audience||'general';
 occupancyField.value=formData.occupancy||'double';
 const langPolicy=state.loaded?.pricing_language||{};
 const legacy=formData.language_scope==='legacy'
   ||(formData.id&&!formData.language_scope);
 languageControl.hidden=!legacy;
 marketControl.hidden=!legacy;
 languageField.value=legacy?(formData.languages?.[0]||'en'):
   (langPolicy.default_language||'en');
 languageOverview.textContent=legacy
   ?'Legacy Draft: retains its original explicit locale scope. Recreate it as a new policy to use canonical inheritance.'
   :'Default: inherit '+String(langPolicy.default_language||'en').toUpperCase()+
       ' source policy across '+Object.keys(langPolicy.available_languages||{en:1}).length+
       ' WPML display languages. Optional groups below narrow reach only when explicitly configured.';
 marketField.value=formData.markets?.[0]||'';
 groupRows.splice(0).forEach(x=>x.row.remove());
 for(const group of formData.target_groups||[])buildGroup(group);
 groupEditor.hidden=legacy;
 logicField.value=formData.logic||'and';
 rules.replaceChildren();
 for(const rule of formData.rules||[])ruleRow(rule);
 syncPolicyValue();
 state.preview=null;renderPreview(null);
 state.revision=formData.revision||0;
 meta.textContent=formData.id
  ?'Preset '+formData.id.slice(0,15)+'… · revision '+formData.revision+' · '+formData.status
  :'New unsaved Draft';
}
function populate(){
 const data=state.loaded;
 const currencyChoices=data.currency_choices||{};
 assignOptions(currencyField,[['','Select currency from JetEngine Glossary'],
     ...Object.entries(currencyChoices)],'');
 assignOptions(chooser,[['','New Draft'],...data.presets.map(p=>[p.id,p.title+' · v'+p.revision+' · '+p.status])],state.selected?.id||'');
 assignOptions(templateField,data.native_templates.map(t=>[t.id,t.label+' · '+t.strategy]),'base-percent');
 assignOptions(languageField,Object.entries(data.languages).map(([id,label])=>
   [id,typeof label==='string'?label:(label?.name||id)]),'en');
 assignOptions(marketField,[['','All approved/default market visibility'],
   ...(data.catalog.market_countries||[]).map(x=>{
    if(typeof x==='string')return [x,x];
    return [String(x.id||x.country||''),String(x.name||x.label||x.country||'')];
   }).filter(x=>x[0])],'');
 state.currentCatalog=data.catalog;
 targetHelp.textContent=data.catalog.dimensions.length+' filterable JetEngine dimensions · '+
   data.catalog.glossaries.length+' Glossaries · '+
   data.catalog.meta_box_fields.length+' discovered Meta Box fields';
 const template=data.native_templates.find(x=>x.id==='base-percent')||data.native_templates[0];
 restore(state.selected||{
  title:'',note:'',template_id:template?.id||'',
  value:template?.value??100,
  currency:'',audience:'general',occupancy:'double',
  language_scope:'inherit_default',
  authoring_language:data.pricing_language?.default_language||'en',
  languages:[data.pricing_language?.default_language||'en'],
  markets:[],target_groups:[],logic:'and',rules:[]
 });
 const native=data.native_preset_state;
 // Refresh is called after Save, Stage and Rollback. Replace the existing
 // status strip rather than accumulating duplicate card blocks each time.
 presetsPanel.querySelectorAll('.mad4b-pl-state').forEach(x=>x.remove());
 const status=E('div','mad4b-pl-state');
 status.append(cell('Native Templates',data.native_templates.length),
  cell('Native Assignments',data.native_assignment_count),
  cell('Live Preset Layer',native.enabled?'On · staging stays disabled':'Off · draft only'),
  cell('Lab Drafts',data.presets.length));
 presetsPanel.insertBefore(status,form);
 updateControllers();updateApply();
}
function updateControllers(){
 controlInfo.replaceChildren();
 const template=state.loaded?.native_templates.find(t=>t.id===templateField.value);
 const stateInfo=state.loaded?.native_preset_state||{};
 for(const [label,value] of [
  ['Native Template',template?.label||'Not selected'],
  ['Strategy',template?.strategy||'none'],
  ['Template revision',template?.revision||0],
  ['Audience / Occupancy',audienceField.value+' / '+occupancyField.value],
  ['Composition',stateInfo.composition||'single'],
  ['Commercial Preset Layer',stateInfo.enabled?'Enabled (existing setting)':'Disabled'],
  ['Source of truth','Booking Experience + Shared Product Core'],
  ['Cohort assignments','Staged disabled; never applied to checkout automatically'],
 ])controlInfo.append(cell(label,value));
}
function renderHistory(){
 history.replaceChildren();
 const receipts=state.loaded?.history||[];
 if(!receipts.length){history.textContent='No group Preset receipts yet.';return;}
 for(const r of receipts.slice(0,35)){
  const entry=E('div','mad4b-pl-history-row');
  entry.append(E('strong','',r.event.replaceAll('_',' ')),
   E('small','',r.at+' · v'+r.revision+' · '+r.id.slice(0,15)),
   E('span','',r.status));
  history.append(entry);
 }
}
function updateApply(){
 const p=state.selected;
 const eligible=!!(state.preview?.can_stage && p && p.status==='draft'
    && state.previewPresetId===p.id);
 stageInfo.replaceChildren();
 stageInfo.append(
  cell('Selected draft',p?.title||'None saved'),
  cell('Revision',p?.revision||'—'),
  cell('Matched tours',state.preview?.scope_count??'Run Dry Run'),
  cell('Staging-ready Rates',state.preview?.eligible_rates?.length??'—'),
  cell('Conflicts',state.preview?.conflicts?.length??'—')
 );
 stageBtn.disabled=!eligible||!approveCheck.checked||state.busy;
 rollbackBtn.disabled=!(p&&p.status==='staged_for_native_review')||state.busy;
 renderHistory();
}
function invalidatePreview(){
 if(!state.preview)return;
 state.preview=null;
 state.previewPresetId=null;
 renderPreview(null);
 msg('Policy or targeting changed. Run a fresh dry-run before staging.');
}
form.addEventListener('input',invalidatePreview);
form.addEventListener('change',invalidatePreview);
targetPanel.addEventListener('input',invalidatePreview);
targetPanel.addEventListener('change',invalidatePreview);
function renderPreview(data){
 previewBody.replaceChildren();impactSummary.replaceChildren();impactRows.replaceChildren();
 if(!data){
  const empty=E('div','mad4b-pl-empty-preview');
  empty.append(E('strong','','Preview an affected group'),
   E('p','mad4b-pl-muted',
    'Choose a pricing template, add JetEngine conditions, then run a dry-run. This never changes published prices.'));
  const start=E('button','button','Choose Target Groups');
  start.type='button';
  start.addEventListener('click',()=>showTab('Target Groups'));
  empty.append(start);
  previewBody.append(empty);
  updateApply();return;
 }
 const top=E('div','mad4b-pl-stats');
 top.append(cell('Matched Tours',data.scope_count),
  cell('Published Rates',data.total_linked_rates),
  cell('Eligible to stage',data.eligible_rates.length),
  cell('Conflicts',data.conflicts.length));
 previewBody.append(top);
 if(data.group_matches?.length)
  previewBody.append(E('p','mad4b-pl-warning',
    'Explicit targeting groups · '+data.group_matches.map(x=>x.title+': '+x.matched+' Tours').join(' · ')+
    ' · native commercial staging remains unavailable until scope-aware approval.'));
 const guide=E('p','mad4b-pl-warning',data.truncated
  ?'Scope exceeds bounded staging batch. Narrow the targeting before staging.'
  :data.conflicts.length
    ?'Ineligible or customized Rates are excluded. Only eligible source Rates may be staged as disabled drafts.'
    :'Pricing results are illustrative only; no checkout or offers were changed.');
 previewBody.append(guide);
 if(data.total_mixed_currencies)
  previewBody.append(E('p','mad4b-pl-warning','Mixed currencies: aggregate totals are intentionally omitted.'));
 else if(data.eligible_rates.length)
  previewBody.append(cell('Sample price delta (one occupancy per Rate)',
    (data.sum_after-data.sum_before).toFixed(2)));
 const list=E('div','mad4b-pl-affected-list');
 for(const row of data.rows.slice(0,15)){
  const item=E('div','mad4b-pl-affected-item');
  item.append(E('strong','',row.tour),
    E('small','',row.rate),
    E('span','',row.before===null?'Unavailable':row.currency+' '+
      row.before.toLocaleString()+' → '+row.illustrative_after?.toLocaleString()),
    E('small',row.reason?'mad4b-pl-error':'',
      row.reason?'Review: '+row.reason:'Eligible for disabled draft staging'));
  list.append(item);
 }
 previewBody.append(list);
 impactSummary.append(top.cloneNode(true),E('p','mad4b-pl-warning',
  'This is a preview of published source Rates and a native Preset formula, not an approved quote or market-specific selling price.'));
 impactRows.append(list.cloneNode(true));
 updateApply();
}
function busy(on){
 state.busy=on;
 for(const btn of [saveBtn,createBtn,runBtn,stageBtn,rollbackBtn])btn.disabled=on;
 updateApply();
}
async function send(operation,data={}){
 const params=new URLSearchParams({
  action:cfg.action,nonce:cfg.nonce,operation,data:JSON.stringify(data)
 });
 const response=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',
  headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
  body:params.toString()});
 const result=await response.json();
 if(!response.ok||result?.success!==true)
  throw Error(result?.data?.message||'Pricing Lab request failed ('+response.status+')');
 return result.data;
}
async function refresh(keepId=null){
 state.loaded=await send('load');
 state.selected=state.loaded.presets.find(p=>p.id===keepId)||null;
 populate();
}
chooser.addEventListener('change',()=>{
 const preset=state.loaded.presets.find(x=>x.id===chooser.value)||null;
 state.selected=preset;restore(preset||defaultDraft());
 updateApply();
});
createBtn.addEventListener('click',()=>{
 state.selected=null;chooser.value='';restore(defaultDraft());
 msg('New unsaved Draft.');
 showTab('Presets');
});
templateField.addEventListener('change',()=>{
 const template=state.loaded.native_templates.find(t=>t.id===templateField.value);
 if(template){valueField.value=template.value;currencyField.value=template.currency||'';}
 syncPolicyValue();
 updateControllers();
});
audienceField.addEventListener('change',updateControllers);
occupancyField.addEventListener('change',updateControllers);
languageField.addEventListener('change',async()=>{
 rules.replaceChildren();
 state.preview=null;renderPreview(null);
 state.currentCatalog=null;
 targetHelp.textContent='Loading WPML-compatible JetEngine options…';
 try{
  const response=await send('catalog',{languages:[languageField.value]});
  if(response.languages?.includes(languageField.value)){
   state.currentCatalog=response;
   targetHelp.textContent=response.dimensions.length+' native dimensions for '+languageField.value+
      ' · reselect conditions in this language';
  }
 }catch(error){targetHelp.textContent=error.message;msg(error.message,true);}
});
async function perform(operation,payload,success){
 try{
  busy(true);msg('Processing '+operation+'…');
  const result=await send(operation,payload);
  await success(result);
  msg('Pricing Lab: '+operation+' completed.');
 }catch(error){msg(error.message,true);}
 finally{busy(false);}
}
saveBtn.addEventListener('click',async()=>{
 let config;
 try{config=chosenConfig();}catch(e){msg(e.message,true);showTab('Target Groups');return;}
 await perform('save',{
  id:state.selected?.id||'',revision:state.selected?.revision||0,config
 },async result=>{
  state.preview=null;
  await refresh(result.id);
  showTab('Presets');
 });
});
runBtn.addEventListener('click',async()=>{
 let config;
 try{config=chosenConfig();}catch(e){msg(e.message,true);showTab('Target Groups');return;}
 const selected=state.selected;
 const normalizedRules=xs=>xs.map(x=>x.kind==='range'||x.min!==undefined?
    {id:x.id,min:Number(x.min),max:Number(x.max)}:
    {id:x.id,values:(x.values||[]).map(String),match:x.match||'any'});
 const fields=['title','template_id','strategy','value','currency','audience',
   'occupancy','logic','note'];
 const matches=!!selected && selected.status==='draft' &&
   fields.every(k=>k==='strategy' ||
     String(selected[k]??'')===String(config[k]??'')) &&
   JSON.stringify(selected.languages)===JSON.stringify(config.languages) &&
   (selected.language_scope||'legacy')===config.language_scope &&
   JSON.stringify(selected.target_groups||[])===JSON.stringify(config.target_groups||[]) &&
   JSON.stringify(selected.markets)===JSON.stringify(config.markets) &&
   JSON.stringify(normalizedRules(selected.rules))===
     JSON.stringify(normalizedRules(config.rules));
 const request=matches?selected:config;
 await perform('preview',request,async result=>{
  state.preview=result;
  state.previewPresetId=matches?selected.id:null;
  renderPreview(result);showTab('Impact Preview');
 });
});
approveCheck.addEventListener('change',updateApply);
stageBtn.addEventListener('click',async()=>{
 const selected=state.selected;
 if(!selected||!state.preview||!state.preview.can_stage||!approveCheck.checked)return;
 await perform('stage',{
   id:selected.id,revision:selected.revision,digest:state.preview.digest,
   confirm:'stage_disabled_native_drafts'
  },async result=>{
   approveCheck.checked=false;state.preview=null;
   await refresh(result.preset.id);
   msg(result.staged+' disabled native assignments staged for review; no live prices changed.');
   showTab('Apply & History');
  });
});
rollbackBtn.addEventListener('click',async()=>{
 const selected=state.selected;
 if(!selected||selected.status!=='staged_for_native_review')return;
 await perform('rollback',{id:selected.id,revision:selected.revision},
  async result=>{
   approveCheck.checked=false;state.preview=null;
   await refresh(result.preset.id);
   msg('Rollback removed '+result.removed+' staged native drafts; prices remain unchanged.');
   showTab('Apply & History');
  });
});
showTab('Presets');
send('load').then(data=>{
 state.loaded=data;populate();msg('Pricing Lab connected to native pricing and JetEngine catalogs.');
}).catch(error=>msg('Pricing Lab did not load: '+error.message,true));
})();
