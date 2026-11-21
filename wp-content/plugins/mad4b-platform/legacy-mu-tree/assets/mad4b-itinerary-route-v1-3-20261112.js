/* MAD4B Route Navigator v1.0.0 — DOM-driven native JetAccordion/JetEngine links.
 * No duplicated itinerary data, no price, booking, tour/program meta mutation.
 * Scoped per native Elementor Program template, including each JetTabs tab. */
(function(window,document){
'use strict';
let routeId=0;
const lastTargets=new WeakMap();
const nativeSliderListeners=new WeakMap();
const sels={
 tour:['.elementor-element-1c2a697','.elementor-element-615d942'],
 program:['.elementor-element-89bb670','.elementor-element-a53909d']
};
const closestScope=nav=>nav.closest(nav.dataset.routeContext==='program'?
  '.elementor-element-9840ae4':'.elementor-element-b68567e');
const visible=node=>!!node && node.getClientRects().length>0 &&
  node.getBoundingClientRect().width>0 &&
  (!node.closest('.jet-tabs__content') || node.closest('.jet-tabs__content').getAttribute('aria-hidden')!=='true');
const norm=text=>String(text||'').replace(/\s+/g,' ').trim();
function itemsFor(widget){
 if(widget.classList.contains('elementor-widget-jet-accordion')){
  return [...widget.querySelectorAll('.jet-accordion__inner > .jet-toggle')]
    .map(node=>({
      node,control:node.querySelector(':scope > .jet-toggle__control'),
      title:norm(node.querySelector(':scope > .jet-toggle__control .jet-toggle__label-text')?.textContent)
    })).filter(x=>x.control && x.title);
 }
 if(widget.classList.contains('elementor-widget-jet-listing-grid')){
  const nodes=[...widget.querySelectorAll('.jet-listing-grid__item')]
    .filter(node=>!node.closest('.swiper-slide-duplicate')&&!node.classList.contains('swiper-slide-duplicate'));
  return nodes.map(node=>{
    const label=node.querySelector('.elementor-heading-title,.jet-listing-dynamic-field__content,h3,h4,h2');
    return {node,control:null,title:norm(label?.textContent||node.textContent?.slice(0,140))};
  }).filter(x=>x.title);
 }
 return [];
}
function getItems(nav){
 const scope=closestScope(nav);
 if(!scope||!visible(scope))return [];
 const pair=sels[nav.dataset.routeContext]||sels.tour;
 const widgets=pair.map(sel=>scope.querySelector(sel)).filter(visible);
 for(const widget of widgets){
  const nodes=itemsFor(widget);
  if(nodes.length)return nodes;
 }
 return [];
}
function labels(title,index,nav){
 const day=nav.dataset.routeDayLabel||'Day';
 const stage=nav.dataset.routeStageLabel||'Stop';
 const hint=(nav.dataset.routeUnit==='stage'?stage:day)+' '+(index+1);
 let trimmed=norm(title).replace(/^(?:day|día|jour|tag|giorno|اليوم)\s*[0-9٠-٩]+\s*(?:[:—–-]\s*)?/i,'');
 if(!trimmed)trimmed=norm(title);
 if(trimmed===hint)trimmed=norm(title);
 return {name:trimmed,subtitle:hint};
}
function el(tag,cls,text){
 const node=document.createElement(tag);
 if(cls)node.className=cls;
 if(text!==undefined)node.textContent=text;
 return node;
}
function syncNativeJetSlider(nav,found){
 const first=found[0]?.node;
 const slider=first?.closest('.jet-listing-grid__slider');
 const swiper=slider?.swiper;
 const existing=nativeSliderListeners.get(nav);
 if(!swiper || swiper.destroyed || typeof swiper.on!=='function'){
   if(existing?.swiper?.off)existing.swiper.off('slideChange',existing.handler);
   nativeSliderListeners.delete(nav);
   return;
 }
 if(existing && existing.swiper!==swiper && existing.swiper?.off)
   existing.swiper.off('slideChange',existing.handler);
 if(!existing || existing.swiper!==swiper){
   const handler=()=>{
     if(!nav.isConnected||swiper.destroyed)return;
     const index=Number(swiper.realIndex);
     const links=nav.querySelectorAll('.mad4b-itinerary-route__link');
     if(!Number.isInteger(index)||index<0||index>=links.length)return;
     links.forEach((el,i)=>i===index?el.setAttribute('aria-current','step'):el.removeAttribute('aria-current'));
   };
   swiper.on('slideChange',handler);
   nativeSliderListeners.set(nav,{swiper,handler});
 }
 const state=nativeSliderListeners.get(nav);
 if(state?.handler)state.handler();
}
function showNativeJetCard(item,index){
 // JetEngine's original listing-grid slider.js sets .swiper on this
 // exact element. Move its slide rather than duplicating Swiper logic.
 const slider=item.node.closest('.jet-listing-grid__slider');
 const swiper=slider?.swiper;
 if(!swiper || swiper.destroyed ||typeof swiper.slideTo!=='function')return null;
 const looping=!!(swiper.params?.loop);
 if(looping &&typeof swiper.slideToLoop==='function')swiper.slideToLoop(index);
 else swiper.slideTo(index);
 return slider;
}
function choose(nav,anchor,index){
 const links=[...nav.querySelectorAll('.mad4b-itinerary-route__link')];
 links.forEach((link,i)=>{
  if(i===index)link.setAttribute('aria-current','step');
  else link.removeAttribute('aria-current');
 });
 anchor.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});
}
function offsetScroll(target){
 const bar=document.querySelector('#wpadminbar');
 const barH=bar&&getComputedStyle(bar).position==='fixed'?bar.getBoundingClientRect().height:0;
 const top=Math.max(0,window.scrollY+target.getBoundingClientRect().top-(barH+104));
 const motion=window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth';
 window.scrollTo({top,behavior:motion});
}
function refresh(nav){
 if(!nav.isConnected)return;
 const found=getItems(nav);
 if(!found.length){
  nav.hidden=true;
  nav.querySelector('.mad4b-itinerary-route__steps')?.replaceChildren();
  return;
 }
 const signature=found.map(x=>x.title).join('|');
 const oldTargets=lastTargets.get(nav);
 if(nav.dataset.routeSignature===signature && !nav.hidden
    &&oldTargets?.length===found.length
    &&oldTargets.every((node,index)=>node===found[index].node)){
   const active=found.findIndex(x=>x.control?.getAttribute('aria-expanded')==='true');
   if(active>=0)[...nav.querySelectorAll('.mad4b-itinerary-route__link')]
     .forEach((link,index)=>index===active?link.setAttribute('aria-current','step'):link.removeAttribute('aria-current'));
   syncNativeJetSlider(nav,found);
   return;
 }
 const steps=nav.querySelector('.mad4b-itinerary-route__steps');
 if(!steps)return;
 if(!nav.dataset.routeInstance)nav.dataset.routeInstance=String(++routeId);
 const uid=nav.dataset.routeInstance;
 steps.replaceChildren();
 found.forEach((item,index)=>{
  const anchorId='mad4b-route-'+uid+'-'+(index+1);
  // JetTabs owns the control's original ID/aria-controls. We only add an
  // independent ID on its parent item for a collision-free anchor per Program.
  if(!item.node.id)item.node.id=anchorId;
  item.node.classList.add('mad4b-itinerary-route__target');
  const original=labels(item.title,index,nav);
  const li=el('li','mad4b-itinerary-route__step');
  const link=el('a','mad4b-itinerary-route__link');
  link.href='#'+item.node.id;
  link.title=item.title;
  link.setAttribute('aria-label',original.subtitle+' — '+item.title);
  const dot=el('span','mad4b-itinerary-route__dot',String(index+1));
  dot.setAttribute('aria-hidden','true');
  link.append(dot,el('span','mad4b-itinerary-route__name',original.name));
  link.append(el('span','mad4b-itinerary-route__sub',original.subtitle));
  if(index===0)link.setAttribute('aria-current','step');
  link.addEventListener('click',event=>{
   event.preventDefault();
   if(!item.node.isConnected||!visible(item.node))return;
   if(item.control && item.control.getAttribute('aria-expanded')!=='true'){
     // Delegate to the ORIGINAL JetTabs handler, never emulate its internals.
     item.control.click();
   }
   const nativeSlider=!item.control?showNativeJetCard(item,index):null;
   choose(nav,link,index);
   window.setTimeout(()=>{
     if(!item.node.isConnected)return;
     offsetScroll(nativeSlider||item.node);
     const focus=nativeSlider?link:(item.control||item.node);
     if(!item.control&&!nativeSlider&&!focus.hasAttribute('tabindex'))
       focus.setAttribute('tabindex','-1');
     if(typeof focus.focus==='function')focus.focus({preventScroll:true});
   },150);
  });
  li.append(link);steps.append(li);
 });
 nav.dataset.routeSignature=signature;
 lastTargets.set(nav,found.map(x=>x.node));
 nav.hidden=false;
 syncNativeJetSlider(nav,found);
 // The first route marker follows the opening state of the native accordion.
 const selected=found.findIndex(x=>x.control&&x.control.getAttribute('aria-expanded')==='true');
 if(selected>=0){
  const all=[...nav.querySelectorAll('.mad4b-itinerary-route__link')];
  all.forEach((a,i)=>i===selected?a.setAttribute('aria-current','step'):a.removeAttribute('aria-current'));
 }
}
function refreshAll(){
 document.querySelectorAll('[data-mad4b-itinerary-route="1"]').forEach(refresh);
}
function schedule(){
 [0,120,390,1150].forEach(ms=>window.setTimeout(refreshAll,ms));
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',schedule,{once:true});
else schedule();
// JetTabs renders Program term templates on tab switch; a bounded,
 // delegated listener avoids polling and global MutationObservers.
document.addEventListener('click',e=>{
 if(e.target.closest('.jet-tabs__control,.jet-toggle__control'))schedule();
},true);
document.addEventListener('keydown',e=>{
 if((e.key==='Enter'||e.key===' ')&&e.target.closest('.jet-tabs__control'))schedule();
},true);
window.addEventListener('mad4b:uael-server-hydrated',schedule);
if(window.jQuery){
 window.jQuery(document).on('elementor/popup/show',schedule);
}
})(window,document);
