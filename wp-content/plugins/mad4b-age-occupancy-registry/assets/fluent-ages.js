(function(){'use strict';
 const MAX=50;
 function init(form){
  if(form.dataset.mad4bAgesBound)return;
  const widget=form.querySelector('.mad4b-age-collector');if(!widget)return;
  const children=form.elements.namedItem(widget.dataset.childCount);
  const infants=form.elements.namedItem(widget.dataset.infantCount);
  if(!children||!infants)return;
  form.dataset.mad4bAgesBound='1';
  const anchor=(infants.closest('.ff-el-group')||children.closest('.ff-el-group')||infants);
  anchor.insertAdjacentElement('afterend',widget);
  const groups=[{count:children,node:widget.querySelector('.mad4b-age-child-fields'),name:'mad4b_child_ages[]',label:'Child'},
   {count:infants,node:widget.querySelector('.mad4b-age-infant-fields'),name:'mad4b_infant_ages[]',label:'Infant'}];
  const error=widget.querySelector('.mad4b-age-error');
  function quantity(input){const value=Number(input.value||0);return Number.isInteger(value)&&value>=0&&value<=MAX?value:null;}
  function render(){
   let bad=false;
   for(const group of groups){
    const n=quantity(group.count);
    if(n===null){bad=true;group.node.replaceChildren();continue;}
    const existing=[...group.node.querySelectorAll('input')].map(x=>x.value);
    const fragment=document.createDocumentFragment();
    for(let i=0;i<n;i++){
     const label=document.createElement('label');label.className='mad4b-age-item';
     const title=document.createElement('span');title.textContent=group.label+' '+(i+1)+' age';
     const input=document.createElement('input');input.type='number';input.inputMode='numeric';input.name=group.name;
     input.min='0';input.max='120';input.step='1';input.required=true;
     input.autocomplete='off';input.value=existing[i]??'';
     label.append(title,input);fragment.append(label);
    }
    group.node.replaceChildren(fragment);
   }
   widget.hidden=groups.every(g=>quantity(g.count)===0)&&!bad;
   error.hidden=!bad;error.textContent=bad?'Please enter a valid passenger count (0–50).':'';
  }
  for(const group of groups){group.count.addEventListener('input',render);group.count.addEventListener('change',render);}
  render();
  form.addEventListener('submit',function(event){
   for(const group of groups){
    if(quantity(group.count)===null){event.preventDefault();group.count.focus();return;}
    for(const input of group.node.querySelectorAll('input'))if(!input.checkValidity()){
     event.preventDefault();input.reportValidity();return;
    }
   }
  },true);
 }
 function scan(){document.querySelectorAll('form').forEach(init);}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan,{once:true});else scan();
 document.addEventListener('fluentform_submission_success',scan);
})();
