(()=>{
'use strict';
const root=document.querySelector('.mad4b-currency-config');
if(!root)return;
const input=root.querySelector('#mad4b-currency-search');
const choices=[...root.querySelectorAll('.mad4b-currency-option')];
const filter=()=>{
 const query=(input.value||'').trim().toLocaleLowerCase();
 for(const label of choices)
   label.hidden=query!==''&&!label.textContent.toLocaleLowerCase().includes(query);
};
input?.addEventListener('input',filter);
})();
