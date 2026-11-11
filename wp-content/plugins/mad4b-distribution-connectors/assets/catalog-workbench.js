/* MAD4B Catalog Studio progressive enhancement; data commits only via secured native admin forms. */
(function () {
  'use strict';
  const root = document.getElementById('mad4b-studio-workspace');
  if (!root) return;
  const tbody = root.querySelector('#mad4b-studio-mapping-body');
  const add = root.querySelector('#mad4b-studio-add-row');
  function nextIndex() {
    if (!tbody) return 0;
    const indexes=Array.from(tbody.querySelectorAll('input[name^="column["]'))
      .map(n=>Number((n.name.match(/\[(\d+)\]/)||[])[1]))
      .filter(Number.isFinite);
    return indexes.length ? Math.max(...indexes)+1 : 0;
  }
  if (add && tbody) {
    add.addEventListener('click',function(){
      const i=nextIndex();
      if(tbody.rows.length>=48){add.disabled=true;return;}
      const tr=document.createElement('tr');
      const types=['object_field','meta','static','language_code','computed','featured_image','taxonomy_terms'];
      const mkCell=(inner)=>{const td=document.createElement('td');td.innerHTML=inner;tr.appendChild(td);return td;};
      mkCell('<input name="column['+i+']" placeholder="output_field" required>');
      mkCell('<select name="source_type['+i+']">'+types.map(t=>'<option value="'+t+'">'+t.replaceAll('_',' ')+'</option>').join('')+'</select>');
      mkCell('<input name="source['+i+']" placeholder="source key">');
      mkCell('<input name="extra['+i+']" placeholder="optional">');
      mkCell('<input type="checkbox" name="required['+i+']" value="1" aria-label="Required output row '+(i+1)+'">');
      mkCell('<button class="button mad4b-studio__remove-row" type="button" aria-label="Remove row '+(i+1)+'">×</button>');
      tbody.appendChild(tr);
      if(tbody.rows.length>=48)add.disabled=true;
      tr.querySelector('input[name^="column["]').focus();
    });
    tbody.addEventListener('click',function(ev){
      const b=ev.target.closest('.mad4b-studio__remove-row');
      if(!b)return;
      b.closest('tr').remove();
      if(tbody.rows.length<48)add.disabled=false;
    });
  }
  const kind=root.querySelector('#mad4b-studio-object-type');
  const source=root.querySelector('#mad4b-studio-object-key');
  if(kind && source) {
    function updateSources(){
      Array.from(source.options).forEach(option=>{
        option.hidden=option.dataset.kind!==kind.value;
        option.disabled=option.dataset.kind!==kind.value;
      });
      if(!source.selectedOptions.length || source.selectedOptions[0].disabled){
        const first=Array.from(source.options).find(o=>!o.disabled);
        if(first)source.value=first.value;
      }
    }
    kind.addEventListener('change',updateSources);
    updateSources();
  }
})();
