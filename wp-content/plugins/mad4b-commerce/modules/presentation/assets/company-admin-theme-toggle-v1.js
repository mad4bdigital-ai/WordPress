/* MAD4B admin appearance: user-local dark switch, no server mutations. */
(()=>{
 'use strict';
 const STORAGE='mad4b.admin.theme.v1';
 const ROOT=document.documentElement;
 const labels=Object.assign({dark:'Enable dark mode',light:'Enable light mode'},
   window.MAD4BCompanyAdminThemeLabels||{});
 const dark=()=>ROOT.getAttribute('data-mad4b-admin-theme')==='dark';
 function reflect(button){
   const next=dark()?'light':'dark';
   const text=labels[next];
   button.setAttribute('aria-label',text);
   button.setAttribute('aria-pressed',dark()?'true':'false');
   button.dataset.mad4bRailLabel=text;
   button.title=text;
   const reader=button.querySelector('.screen-reader-text');
   if(reader)reader.textContent=text;
 }
 function apply(mode,persist){
   if(mode==='dark')ROOT.setAttribute('data-mad4b-admin-theme','dark');
   else ROOT.removeAttribute('data-mad4b-admin-theme');
   if(persist){try{window.localStorage.setItem(STORAGE,mode)}catch(_){}}
   for(const button of document.querySelectorAll('#mad4b-admin-theme-toggle'))reflect(button);
   window.dispatchEvent(new CustomEvent('mad4b:admin-theme-change',{detail:{mode}}));
 }
 function install(){
   if(!document.body||!document.body.classList.contains('wp-admin'))return;
   if(document.getElementById('mad4b-admin-theme-toggle'))return;
   let rail=document.getElementById('screen-meta-links');
   let detached=false;
   if(!rail){
     const root=document.getElementById('wpbody-content');
     if(!root)return;
     rail=document.createElement('div');
     rail.id='mad4b-admin-theme-rail';
     root.insertBefore(rail,root.firstChild);
     detached=true;
   }
   const wrapper=document.createElement('div');
   wrapper.className='mad4b-admin-theme-wrap'; // Must not join WP screenMeta's hidden toggle group
   const button=document.createElement('button');
   button.type='button';
   button.id='mad4b-admin-theme-toggle';
   button.className='mad4b-admin-theme-button';
   button.setAttribute('aria-pressed','false');
   button.innerHTML='<span class="screen-reader-text"></span>';
   reflect(button);
   button.addEventListener('click',event=>{
     event.preventDefault();event.stopPropagation();
     apply(dark()?'light':'dark',true);
   });
   wrapper.appendChild(button);
   rail.appendChild(wrapper);
   if(detached)rail.dataset.mad4bIndependentThemeRail='1';
 }
 if(document.readyState==='loading')
   document.addEventListener('DOMContentLoaded',install,{once:true});
 else install();
 window.addEventListener('storage',event=>{
   if(event.key===STORAGE)apply(event.newValue==='dark'?'dark':'light',false);
 });
})();
