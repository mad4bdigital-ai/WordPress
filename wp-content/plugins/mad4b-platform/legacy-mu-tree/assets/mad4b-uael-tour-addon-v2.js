(function(window,document){
 'use strict';
 const selector='.elementor-popup-modal [data-mad4b-uael-tour="1"]';
 const el=(tag,cls,label)=>{
  const node=document.createElement(tag);
  if(cls)node.className=cls;
  if(label!==undefined)node.textContent=String(label);
  return node;
 };
 const centerActive=(widget,tab)=>{
  const bar=widget.querySelector('.uael-masonry-filters-wrapper');
  if(!bar||!tab)return;
  const left=tab.getBoundingClientRect().left;
  const b=bar.getBoundingClientRect();
  const delta=left-b.left+(tab.getBoundingClientRect().width-b.width)/2;
  if(Math.abs(delta)>8)bar.scrollBy({left:delta,behavior:'smooth'});
 };
 const normUrl=(v)=>{
  try{return new URL(v,window.location.href).pathname.toLowerCase();}catch{return String(v).split('?')[0].toLowerCase();}
 };
 const activate=(widget)=>{
  if(!widget||widget.dataset.mad4bUaelReady==='1')return;
  const host=widget.querySelector('[data-mad4b-uael-tour="1"]');
  if(!host)return;
  const template=widget.querySelector('template[data-mad4b-uael-native-template]');
  if(template)template.replaceWith(template.content.cloneNode(true));
  const gallery=widget.querySelector('.uael-gallery-parent .uael-img-gallery-wrap');
  if(!gallery)return;
  const filterBar=widget.querySelector('.uael-masonry-filters');
  const tabs=[...(filterBar?.querySelectorAll('.uael-masonry-filter')||[])];
  for(const tab of tabs){
   tab.setAttribute('role','button');tab.tabIndex=0;
   tab.addEventListener('keydown',(e)=>{
    if(e.key==='Enter'||e.key===' '){e.preventDefault();tab.click();}
   });
  }
  widget.dataset.mad4bUaelReady='1';
  if(window.elementorFrontend?.hooks?.doAction && window.jQuery){
   try{window.elementorFrontend.hooks.doAction('frontend/element_ready/uael-image-gallery.default',window.jQuery(widget),window.jQuery);}
   catch(e){widget.dataset.mad4bUaelReady='init-error';return;}
  }
  if(!gallery.querySelector('.uael-grid-item')){widget.dataset.mad4bUaelReady='missing-gallery';return;}
  // Timeline is an enhancement on top of one native UAEL gallery; the native
  // masonry, filtering data and Fancybox links remain untouched.
  let manifest=null;
  try{manifest=JSON.parse(host.dataset.mad4bTimeline||'null');}catch{}
  if(!manifest||!Array.isArray(manifest.days)||manifest.days.length<1||!tabs.length)return;
  const nativeLinks=new Map();
  gallery.querySelectorAll('a[data-fancybox][href]').forEach((link)=>{
    const path=normUrl(link.getAttribute('href'));
    if(!nativeLinks.has(path))nativeLinks.set(path,link);
  });
  const heading=(String(manifest.timeline_title||'Itinerary Timeline')).trim();
  const days=manifest.days;
  const wrap=el('section','mad4b-journey-timeline');
  wrap.setAttribute('aria-label',heading);
  if(manifest.is_rtl)wrap.dir='rtl';
  wrap.append(el('h2','mad4b-journey-timeline-title',heading));
  const lead=el('p','mad4b-journey-timeline-lead',`${days.length} ${manifest.day_label||'Day'} · ${manifest.unique_photo_count||0} ${manifest.photos_label||'photos'}`);
  wrap.append(lead);
  let previewCount=0;
  for(const day of days){
   const item=el('article','mad4b-journey-day');
   item.dataset.day=String(day.day);
   const head=el('div','mad4b-journey-day-heading');
   const num=el('span','mad4b-journey-day-number',String(day.day));
   num.setAttribute('aria-label',`${manifest.day_label||'Day'} ${day.day}`);
   const words=el('div','mad4b-journey-day-words');
   words.append(el('span','mad4b-journey-day-kicker',`${manifest.day_label||'Day'} ${day.day}`));
   words.append(el('h3','mad4b-journey-day-title',day.title||''));
   if(day.optional)words.append(el('span','mad4b-journey-optional','Optional'));
   head.append(num,words);item.append(head);
   const pictures=el('div','mad4b-journey-day-images');
   let added=0;
   for(const photo of (day.photos||[])){
    const native=nativeLinks.get(normUrl(photo.url));
    if(!native)continue;
    const tile=el('button','mad4b-journey-photo');tile.type='button';
    const img=el('img');
    const nativeImg=native.querySelector('img');
    img.src=(nativeImg&&nativeImg.currentSrc)||photo.thumbnail||photo.url;
    img.alt=photo.alt||photo.destination||day.title||'';
    img.loading='lazy';img.decoding='async';
    const label=el('span','mad4b-journey-photo-place',photo.destination||'');
    tile.setAttribute('aria-label',`${manifest.day_label||'Day'} ${day.day} · ${img.alt}`);
    tile.append(img,label);
    // The ORIGINAL UAEL/Fancybox link owns the lightbox and its gallery set.
    tile.addEventListener('click',()=>native.click());
    pictures.append(tile);added++;previewCount++;
   }
   if(added){item.append(pictures);}
   else item.append(el('p','mad4b-journey-no-photo',manifest.fallback_text||'No verified photo for this day'));
   wrap.append(item);
  }
  const extras=(manifest.overview||[]).filter(p=>nativeLinks.has(normUrl(p.url)));
  if(extras.length){
   const overview=el('section','mad4b-journey-overview');
   overview.append(el('h3','mad4b-journey-overview-title',manifest.overview_title||'Tour Photos'));
   const grid=el('div','mad4b-journey-day-images');
   for(const photo of extras){
    const native=nativeLinks.get(normUrl(photo.url));
    if(!native)continue;
    const tile=el('button','mad4b-journey-photo');tile.type='button';
    const img=el('img');img.src=native.querySelector('img')?.currentSrc||photo.thumbnail||photo.url;
    img.alt=photo.alt||manifest.overview_title||'Tour Photos';img.loading='lazy';img.decoding='async';
    tile.setAttribute('aria-label',img.alt);
    tile.append(img);tile.addEventListener('click',()=>native.click());grid.append(tile);previewCount++;
   }
   overview.append(grid);wrap.append(overview);
  }
  // No linkable photos means leave the native widget as a safe fallback.
  if(previewCount<1)return;
  const all=tabs[0];
  all.textContent=heading;
  all.setAttribute('aria-label',heading);
  all.dataset.mad4bTimelineTab='1';
  const allPhotos=el('button','mad4b-uael-all-photos-tab',manifest.all_title||'All Photos');
  allPhotos.type='button';allPhotos.dataset.mad4bView='all';
  all.insertAdjacentElement('afterend',allPhotos);
  gallery.parentNode.insertBefore(wrap,gallery);
  host.dataset.mad4bTimelineActive='1';
  widget.classList.add('mad4b-uael-timeline-widget');
  const scrollChoice=tab=>requestAnimationFrame(()=>centerActive(widget,tab));
  const mode=(view,selected)=>{
    const showTimeline=view==='timeline';
    host.classList.toggle('mad4b-timeline-active',showTimeline);
    wrap.hidden=!showTimeline;
    gallery.setAttribute('aria-hidden',showTimeline?'true':'false');
    const allActive=view==='all';
    allPhotos.classList.toggle('mad4b-selected',allActive);
    allPhotos.setAttribute('aria-pressed',allActive?'true':'false');
    if(allActive)tabs.forEach(t=>t.classList.remove('uael-current'));
    else if(showTimeline){tabs.forEach(t=>t.classList.toggle('uael-current',t===all));}
    const active=selected|| (allActive?allPhotos:all);
    for(const tab of [...tabs,allPhotos]){
      tab.setAttribute('aria-current',tab===active?'true':'false');
    }
    scrollChoice(active);
  };
  all.addEventListener('click',()=>window.setTimeout(()=>mode('timeline',all),40));
  tabs.slice(1).forEach(tab=>tab.addEventListener('click',()=>window.setTimeout(()=>mode('native',tab),40)));
  allPhotos.addEventListener('click',e=>{
    e.preventDefault();e.stopPropagation();
    // Reset UAEL Isotope to show all; then select our independent All Photos view.
    all.click();
    window.setTimeout(()=>{
      mode('all',allPhotos);
      window.dispatchEvent(new Event('resize'));
    },65);
  });
  mode('timeline',all);
  widget.dataset.mad4bTimelineReady='1';
  widget.dataset.mad4bTimelinePhotos=String(previewCount);
 };
 const activateAll=()=>document.querySelectorAll(selector).forEach(host=>activate(host.closest('.elementor-widget-uael-image-gallery')));
 const scheduled=()=>{
  for(const delay of [0,90,260,740])window.setTimeout(activateAll,delay);
 };
 if(window.jQuery)window.jQuery(document).on('elementor/popup/show',scheduled);
 document.addEventListener('click',e=>{
  const trigger=e.target.closest('#single_tour_gellery_button,[href*="popup%3Aopen"]');
  if(trigger)scheduled();
 },true);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scheduled,{once:true});
 else scheduled();
})(window,document);
