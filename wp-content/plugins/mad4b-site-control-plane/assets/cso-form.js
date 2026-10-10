/* CSO typed first-party UI. No plugin HTML, script evaluation, secret input or automatic save. */
(function (global) {
  'use strict';
  const messages = {
    ar: { title:'نموذج الموقع', intro:'أدخل القيم ثم راجعها. التحقق لا يحفظ تغييرات الموقع.', validate:'تحقق واعرض المراجعة', required:'هذا الحقل مطلوب.', invalid:'راجع نوع القيمة وحدود الحقل.', pending:'جارٍ التحقق…', valid:'القيم اجتازت التحقق. لم تُحفظ تغييرات الموقع.', failed:'تعذر التحقق. حدّث النموذج أو راجع اتصال الموقع.', secret:'هذا الحقل يحتاج إدخالًا آمنًا منفصلًا.', unsupported:'هذا الحقل يحتاج واجهة متخصصة.', empty:'لا توجد حقول قابلة للإدخال.', old:'النموذج قديم. افتح نموذجًا جديدًا قبل المتابعة.', revision:'إصدار العنصر', plan:'اعرض خطة التغيير', planned:'خطة جاهزة للمراجعة؛ تحتاج تفويضًا مستقلًا للتنفيذ.', suggestions:'اعرض القيم المتاحة', suggestHelp:'القيم من مصدر الحقل المعتمد.', notReady:'لم يُعتمد مصدر الاقتراحات لهذا الحقل.', review:'مراجعة القيم', label:'الحقل', value:'القيمة' },
    en: { title:'Site form', intro:'Enter values and review them. Validation does not save site changes.', validate:'Validate and review', required:'This field is required.', invalid:'Check the field type and limits.', pending:'Validating…', valid:'Values validated. No site changes were saved.', failed:'Validation failed. Refresh the form or check the site connection.', secret:'Use the separate secure handoff for this field.', unsupported:'This field needs a specialized interface.', empty:'There are no editable fields.', old:'This form is stale. Open a fresh form before continuing.', revision:'Object revision', plan:'Prepare change plan', planned:'Plan ready for review; execution requires independent authorization.', suggestions:'Show available values', suggestHelp:'Values from the approved field source.', notReady:'Suggestions are not certified for this field.', review:'Review values', label:'Field', value:'Value' }
  };
  function object(v) { return v !== null && typeof v==='object' && !Array.isArray(v); }
  function element(doc, tag, text, attrs) {
    const node=doc.createElement(tag);
    if(text!==undefined) node.textContent=String(text);
    Object.entries(attrs||{}).forEach(([key,value])=>node.setAttribute(key,String(value)));
    return node;
  }
  function normalize(view) {
    const result=view.form||{};
    const sealed=result.sealed_form||result.form||{};
    const material=sealed.material||result;
    const raw=result.fields||material.fields||[];
    const fields=Array.isArray(raw)?raw:Object.entries(raw).map(([name,f])=>Object.assign({name,field_id:name},f));
    if(fields.length>128) throw new Error('FIELD_BOUNDS');
    return {sealed,fields,ability_name:material.ability_name||result.ability_name||''};
  }
  function fieldType(field) {
    const schema=field.schema||field;
    const type=field.field_type||field.type||schema.type;
    if(field.secret===true||field.sensitive===true||['secret','secret_handoff'].includes(type)) return 'secret';
    if(field.readonly===true||field.read_only===true||field.locked===true||schema.readOnly===true) return 'readonly';
    if(field.options||schema.enum) return (['array','multiselect','multi_enum'].includes(type))?'multiselect':'select';
    if(type==='boolean'||type==='bool') return 'checkbox';
    if(['number','integer','currency'].includes(type)) return 'number';
    if(['relation','relationship','media_reference'].includes(type)&&['integer','number'].includes(field.value_type)) return 'number';
    if(['relation','relationship','media_reference'].includes(type)&&field.value_type==='string') return 'text';
    if(['object','array','repeater','nested','relation','relationship','media_reference'].includes(type)) return 'typed-json';
    if(type==='date'||schema.format==='date') return 'date';
    if(type==='datetime'||schema.format==='date-time') return 'datetime-local';
    if(['rich_text','richtext','textarea'].includes(type)) return 'textarea';
    if(['string','text','url','relationship','media','taxonomy','enum'].includes(type)) return 'text';
    return 'unsupported';
  }
  function readControl(control, kind, schema) {
    if(kind==='checkbox') return control.checked;
    if(kind==='multiselect') return Array.from(control.selectedOptions||[]).map(o=>JSON.parse(o.value));
    if(kind==='select') return control.value===''?undefined:JSON.parse(control.value);
    if(control.value==='') return undefined;
    if(kind==='typed-json') return JSON.parse(control.value);
    if(kind==='number') { const n=Number(control.value); if(!Number.isFinite(n)||schema.type==='integer'&&!Number.isInteger(n)) throw new Error('TYPE'); return n; }
    if(kind==='datetime-local') return new Date(control.value).toISOString();
    return control.value;
  }
  function condition(rule, values, depth=0) {
    if(!object(rule)||depth>4) return false;
    for(const group of ['all','any']) if(Object.hasOwn(rule,group)) {
      if(Object.keys(rule).length!==1||!Array.isArray(rule[group])||!rule[group].length||rule[group].length>16) return false;
      return group==='all'?rule[group].every(r=>condition(r,values,depth+1)):rule[group].some(r=>condition(r,values,depth+1));
    }
    if(typeof rule.field!=='string') return false;
    const present=Object.hasOwn(values,rule.field); const value=values[rule.field];
    if(rule.operator==='is_set') return present;
    if(!present) return false;
    if(rule.operator==='equals') return value===rule.value;
    if(rule.operator==='not_equals') return value!==rule.value;
    if(rule.operator==='in') return Array.isArray(rule.value)&&rule.value.includes(value);
    if(rule.operator==='not_in') return Array.isArray(rule.value)&&!rule.value.includes(value);
    return false;
  }
  function constraints(value,schema) {
    if(value===undefined) return;
    if(typeof value==='number'&&(typeof schema.minimum==='number'&&value<schema.minimum||typeof schema.maximum==='number'&&value>schema.maximum)) throw new Error('LIMIT');
    if(typeof value==='string') {
      const length=Array.from(value).length;
      if(Number.isInteger(schema.minLength)&&length<schema.minLength||Number.isInteger(schema.maxLength)&&length>schema.maxLength) throw new Error('LENGTH');
    }
    if(Array.isArray(value)&&(Number.isInteger(schema.minItems)&&value.length<schema.minItems||Number.isInteger(schema.maxItems)&&value.length>schema.maxItems)) throw new Error('ITEMS');
  }
  function applySuggestion(control,kind,schema,value) {
    const encoded=JSON.stringify(value);
    if(kind==='select'||kind==='multiselect') {
      const option=Array.from(control.children).find(o=>o.value===encoded);
      if(!option) throw new Error('OPTION_NOT_IN_FORM');
      if(kind==='select') control.value=encoded; else option.selected=true;
    } else if(kind==='typed-json') {
      if(schema.type==='array'&&!Array.isArray(value)) {
        const current=control.value===''?[]:JSON.parse(control.value);
        if(!Array.isArray(current)||current.length>=Math.min(schema.maxItems??100,100)) throw new Error('ITEMS');
        if(!current.some(v=>JSON.stringify(v)===encoded)) current.push(value);
        control.value=JSON.stringify(current);
      } else control.value=encoded;
    } else if(kind==='number') {
      if(typeof value!=='number'||!Number.isFinite(value)||schema.type==='integer'&&!Number.isInteger(value)) throw new Error('TYPE');
      control.value=String(value);
    } else {
      if(typeof value!=='string') throw new Error('TYPE');
      control.value=value;
    }
  }
  function render(root, config, transport) {
    const doc=root.ownerDocument;
    const view=config.presentation;
    const text=messages[view.direction==='rtl'?'ar':'en'];
    const form=normalize(view); const controls=[];
    root.replaceChildren(); root.setAttribute('dir',view.direction==='rtl'?'rtl':'ltr');
    root.append(element(doc,'h1',text.title),element(doc,'p',text.intro));
    const status=element(doc,'p','',{'role':'status','aria-live':'polite',id:'cso-status'});
    const errors=element(doc,'ul','',{'role':'alert',id:'cso-errors',tabindex:'-1'});
    const inputs=element(doc,'form',undefined,{'novalidate':'','aria-describedby':'cso-status'});
    const review=element(doc,'section',undefined,{'aria-label':text.review});
    root.append(status,errors,inputs,review);
    let busy=false;
    form.fields.forEach((field,index)=>{
      if(!object(field)) throw new Error('FIELD_SHAPE');
      const name=field.field_id||field.name||field.id||field.key;
      if(typeof name!=='string'||name.length>193||['__proto__','constructor','prototype'].includes(name)) throw new Error('FIELD_NAME');
      const kind=fieldType(field); const schema=Object.assign({},field.constraints||{},field.schema||field,{type:field.value_type||field.schema?.type||field.type});
      // The authoritative read foundation projects snake_case length limits.
      if(schema.minLength==null&&Number.isInteger(field.min_length)) schema.minLength=field.min_length;
      if(schema.maxLength==null&&Number.isInteger(field.max_length)) schema.maxLength=field.max_length;
      const group=element(doc,'div',undefined,{'class':'cso-field'});
      const id='cso-field-'+index; const help=id+'-help'; const error=id+'-error';
      const label=String(field.label||name);
      group.append(element(doc,'label',label+(field.required?' *':''),{for:id}));
      if(['secret','readonly','unsupported'].includes(kind)) {
        group.append(element(doc,'p',kind==='secret'?text.secret:kind==='unsupported'?text.unsupported:String(field.help||''),{id:help})); inputs.append(group); return;
      }
      const control=element(doc,['select','multiselect'].includes(kind)?'select':['textarea','typed-json'].includes(kind)?'textarea':'input',undefined,{id,name,'aria-describedby':help+' '+error});
      if(control.tagName==='INPUT') control.setAttribute('type',kind==='checkbox'?'checkbox':kind==='number'?'number':kind==='date'?'date':kind==='datetime-local'?'datetime-local':'text');
      control.setAttribute('autocomplete','off'); control.setAttribute('dir','auto');
      if(kind==='multiselect') { control.multiple=true; control.setAttribute('multiple',''); }
      if(kind==='select') control.append(element(doc,'option','',{value:''}));
      if(['select','multiselect'].includes(kind)) {
        const options=field.options||schema.enum||[];
        if(options.length>200) throw new Error('OPTIONS_BOUNDS');
        options.forEach(o=>{ const val=object(o)&&Object.hasOwn(o,'value')?o.value:o; const caption=object(o)&&Object.hasOwn(o,'label')?o.label:val; control.append(element(doc,'option',caption,{value:JSON.stringify(val)})); });
      }
      if(field.required) control.setAttribute('aria-required','true');
      ['minimum','maximum','minLength','maxLength'].forEach((key)=>{ if(schema[key]!=null) control.setAttribute({minimum:'min',maximum:'max',minLength:'minlength',maxLength:'maxlength'}[key],schema[key]); });
      if(kind==='number') control.setAttribute('step',schema.type==='integer'?'1':'any');
      const problem=element(doc,'span','',{id:error,'class':'cso-error'});
      group.append(control,element(doc,'p',field.help||field.description||'',{id:help}),problem);
      const source=field.autocomplete||field.suggestion_source||schema['x-cso-suggestions'];
      if(source) {
        let suggestionList;
        const query=element(doc,'input',undefined,{type:'search','aria-label':label+' '+text.suggestHelp,dir:'auto',autocomplete:'off',maxlength:'80'});
        const button=element(doc,'button',text.suggestions,{type:'button'});
        button.addEventListener('click',async()=>{
          button.disabled=true;
          try {
            const result=await transport('field_suggest',{form:form.sealed,field:name,query:query.value||'',offset:0});
            const options=result.items||result.options||result.suggestions||[];
            if(!Array.isArray(options)||options.length>200) throw new Error('OPTIONS_BOUNDS');
            const list=element(doc,'select',undefined,{'aria-label':label+' '+text.suggestHelp,dir:'auto'});
            list.append(element(doc,'option','',{value:''}));
            options.slice(0,50).forEach(o=>list.append(element(doc,'option',o.label??o.value??o.id??'',{value:JSON.stringify(o.value??o.id??'')})));
            list.addEventListener('change',()=>{
              if(list.value==='') return;
              try { applySuggestion(control,kind,schema,JSON.parse(list.value)); problem.textContent=''; visibility(); }
              catch(e) { problem.textContent=text.invalid; }
            });
            if(suggestionList) suggestionList.remove();
            suggestionList=list; group.append(list);
          } catch(e) { problem.textContent=text.notReady; } finally { button.disabled=false; }
        }); group.append(query,button);
      }
      controls.push({field,name,kind,schema,control,problem,group,active:true}); inputs.append(group);
    });
    function visibility() {
      const values=Object.assign(Object.create(null),form.sealed.material?.target||{});
      controls.forEach(row=>{try {const value=readControl(row.control,row.kind,row.schema);if(value!==undefined) values[row.name]=value;}catch(e){}});
      // Repeat for bounded dependency chains. Hidden values cannot activate downstream fields.
      for(let pass=0;pass<=controls.length;pass++) controls.forEach(row=>{
        row.active=!row.field.visible_when||condition(row.field.visible_when,values);
        row.group.hidden=!row.active;
        row.control.disabled=!row.active;
        if(!row.active) delete values[row.name];
        row.required=row.active&&(row.field.required||row.field.required_when&&condition(row.field.required_when,values));
        if(row.required) row.control.setAttribute('aria-required','true'); else row.control.removeAttribute('aria-required');
      });
    }
    controls.forEach(row=>{row.control.addEventListener('input',visibility);row.control.addEventListener('change',visibility);}); visibility();
    if(!controls.length) inputs.append(element(doc,'p',text.empty));
    const validate=element(doc,'button',text.validate,{type:'submit'}); inputs.append(validate);
    inputs.addEventListener('submit',async(event)=>{
      event.preventDefault(); if(busy) return;
      const values=Object.create(null); errors.replaceChildren(); review.replaceChildren();
      visibility();
      let first=null;
      controls.forEach(row=>{
        row.problem.textContent=''; row.control.removeAttribute('aria-invalid');
        if(!row.active) return;
        try {
          const value=readControl(row.control,row.kind,row.schema);
          if(value===undefined&&row.required) throw new Error('REQUIRED');
          constraints(value,row.schema);
          if(value!==undefined) values[row.name]=value;
        } catch(e) {
          row.problem.textContent=e.message==='REQUIRED'?text.required:text.invalid;
          row.control.setAttribute('aria-invalid','true');
          errors.append(element(doc,'li',(row.field.label||row.name)+': '+row.problem.textContent)); if(!first) first=row.control;
        }
      });
      if(first) { first.focus(); return; }
      busy=true; validate.disabled=true; status.textContent=text.pending;
      try {
        const result=await transport('typed_validate',{form:form.sealed,values});
        if(!['VALIDATED','VALID'].includes(result.status)) throw new Error('VALIDATION_FAILED');
        status.textContent=text.valid;
        const table=element(doc,'table'); const head=element(doc,'tr'); head.append(element(doc,'th',text.label,{scope:'col'}),element(doc,'th',text.value,{scope:'col'})); table.append(head);
        controls.forEach(row=>{if(values[row.name]===undefined) return; const tr=element(doc,'tr');tr.append(element(doc,'th',row.field.label||row.name,{scope:'row'}),element(doc,'td',typeof values[row.name]==='object'?JSON.stringify(values[row.name]):String(values[row.name]),{dir:'auto'}));table.append(tr);});
        review.append(element(doc,'h2',text.review),table);
      } catch(e) {
        status.textContent=/STALE|CHANGED|EXPIRED/i.test(e.message||'')?text.old:text.failed;
        errors.append(element(doc,'li',status.textContent)); errors.focus();
      } finally { busy=false; validate.disabled=false; }
    });
    return {controls,inputs,status,errors,review};
  }
  async function request(config,action,args) {
    const url=new URL(config.endpoint,global.location.href);
    if(url.origin!==global.location.origin||url.protocol!=='https:') throw new Error('ORIGIN_MISMATCH');
    const response=await global.fetch(url.href,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce},body:JSON.stringify({action,arguments:args})});
    const body=await response.text(); if(body.length>131072) throw new Error('RESPONSE_BOUNDS');
    const value=JSON.parse(body); if(!response.ok||value.code) throw new Error(value.data?.reason||value.code||'REQUEST_FAILED'); return value;
  }
  const api={render,normalize,fieldType,readControl,condition,constraints,applySuggestion,request};
  if(typeof module!=='undefined'&&module.exports) module.exports=api;
  if(global.document) {
    const root=global.document.getElementById('cso-app'); const node=global.document.getElementById('cso-config');
    if(root&&node) { try { const config=JSON.parse(node.textContent);render(root,config,(a,v)=>request(config,a,v)); } catch(e) {root.textContent='Form unavailable / النموذج غير متاح';} }
  }
})(typeof window==='undefined'?globalThis:window);
