(function(){'use strict';
  var root=document.getElementById('dfsb-context-preview');
  if(!root||!window.dfsbContextAdmin)return;
  var $=function(id){return document.getElementById(id);};
  var status=$('dfsb-preview-status'),out=$('dfsb-preview-output');
  var base=window.dfsbContextAdmin.base,nonce=window.dfsbContextAdmin.nonce;
  function node(tag,text){var el=document.createElement(tag);el.textContent=String(text==null?'':text);return el;}
  function table(items){
    var t=node('table','');t.className='widefat striped';
    var body=document.createElement('tbody');
    Object.keys(items).forEach(function(k){
      var tr=document.createElement('tr');tr.appendChild(node('th',k));
      var val=items[k],v=(val&&typeof val==='object')?JSON.stringify(val):val;
      tr.appendChild(node('td',v));body.appendChild(tr);
    });t.appendChild(body);return t;
  }
  function query(path,params){
    var url=new URL(path,base);
    Object.keys(params||{}).forEach(function(k){url.searchParams.set(k,String(params[k]));});
    status.textContent='Reading source…';out.replaceChildren();
    return fetch(url.toString(),{method:'GET',credentials:'same-origin',cache:'no-store',
      headers:{'X-WP-Nonce':nonce,'Accept':'application/json'}})
      .then(function(resp){return resp.json().then(function(data){if(!resp.ok)throw Error(data.message||'HTTP '+resp.status);return data;});})
      .then(function(data){status.textContent='Source readback completed. No changes saved.';return data;})
      .catch(function(err){status.textContent='Unable to preview: '+err.message;return null;});
  }
  $('dfsb-preview-browse').addEventListener('click',function(){
    var scope=$('dfsb-preview-scope').value;
    if(!['post','taxonomy'].includes(scope)){
      status.textContent='Select Published Post / CPT or Taxonomy Term to browse.';return;
    }
    var type=$('dfsb-preview-type').value.trim();
    if(!type){status.textContent='Enter the CPT or taxonomy key to find sources.';return;}
    query('objects',{source:scope,type:type,limit:20}).then(function(data){
      if(!data)return;
      out.appendChild(node('h3','Available '+scope+' sources'));
      var entries=data.items||[];
      if(!entries.length){out.appendChild(node('p','No published sources matched.'));return;}
      entries.forEach(function(entry){
        var b=node('button',entry.title+' · '+entry.language+' · #'+entry.id);
        b.type='button';b.className='button button-secondary';b.style.margin='4px';
        b.addEventListener('click',function(){
          $('dfsb-preview-id').value=String(entry.id);
          $('dfsb-preview-run').click();
        });
        out.appendChild(b);
      });
    });
  });
  $('dfsb-preview-run').addEventListener('click',function(){
    var scope=$('dfsb-preview-scope').value,id=parseInt($('dfsb-preview-id').value,10)||0;
    var type=$('dfsb-preview-type').value.trim();
    query('preview',{scope:scope,id:id,type:type}).then(function(data){if(!data)return;
      out.appendChild(node('h3',data.page_kind+' · '+(data.language||'')));
      out.appendChild(node('p','Dynamic Tag values = Shortcode values (one source contract)'));
      out.appendChild(table(data.value_contract.values));
      if(data.image_value && data.image_value.url){
        try{
          var source=new URL(data.image_value.url,window.location.origin);
          if(source.protocol==='https:' || (source.protocol==='http:' && source.origin===window.location.origin)){
            out.appendChild(node('h3','Content image preview'));
            var img=document.createElement('img');img.src=source.href;
            img.alt=data.value_contract.values.title||'Source media';
            img.style.cssText='display:block;max-width:320px;max-height:210px;object-fit:contain;width:auto;height:auto;margin:8px 0;';
            out.appendChild(img);
          }
        }catch(_ignore){}
      }
      out.appendChild(node('h3','Coverage checks'));
      var rows={};(data.checks||[]).forEach(function(c){rows[c.id]=c.state+' — '+c.reason;});
      out.appendChild(table(rows));
    });
  });
  $('dfsb-preview-integrations').addEventListener('click',function(){
    query('integrations',{}).then(function(d){if(d){out.appendChild(node('h3','Installed integration status'));out.appendChild(table(d.providers));}});
  });
  $('dfsb-preview-issues').addEventListener('click',function(){
    query('issues',{}).then(function(d){if(d){out.appendChild(node('h3','MAD4B Content Readiness Alerts'));out.appendChild(table(d.summary));
      out.appendChild(node('h3','Currently detected SEO gaps (read-only)'));
      var gaps={};(d.detected_source_gaps||[]).forEach(function(g){
        gaps[g.code]=g.severity+' — '+g.label+' ['+g.stored_status+']';
      });
      out.appendChild(table(gaps));
      out.appendChild(node('p','Use the native MAD4B Content Readiness screen for an explicit scan or governed Fluent Boards sync. Preview makes no tasks or notifications.'));}});
  });
})();
