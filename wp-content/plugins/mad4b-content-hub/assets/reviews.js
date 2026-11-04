/* Server-rendered reviews: accessible reason presets, no remote HTML/SVG injection. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-mad4b-review-form]').forEach(function (form) {
      var note = form.querySelector('textarea[name="review_note"]');
      var ack = form.querySelector('input[name="rv_ack_evidence"]');
      form.querySelectorAll('[data-mad4b-reason]').forEach(function (preset) {
        preset.addEventListener('click', function () {
          if (!note) return;
          note.value = preset.getAttribute('data-mad4b-reason') || '';
          note.setCustomValidity('');
          note.focus();
        });
      });
      form.addEventListener('submit', function (event) {
        var choice = event.submitter && event.submitter.value;
        if (!note || note.value.trim().length < 8) {
          event.preventDefault();
          if (note) {
            note.setCustomValidity('Provide a short reason of at least eight characters.');
            note.reportValidity();
            note.focus();
          }
          return;
        }
        note.setCustomValidity('');
        if (choice === 'approve' && ack && !ack.checked) {
          event.preventDefault();
          ack.setCustomValidity('Acknowledge the evidence and limitations first.');
          ack.reportValidity();
          ack.focus();
          return;
        }
        if (ack) ack.setCustomValidity('');
      });
      if (note) note.addEventListener('input', function () { note.setCustomValidity(''); });
      if (ack) ack.addEventListener('change', function () { ack.setCustomValidity(''); });
    });
  });
}());

/* Dynamic, keyboard-accessible Master Review gallery, local media only. */
(function(){
 'use strict';
 function initGallery(root){
  var slides=Array.prototype.slice.call(root.querySelectorAll('[data-rv-slide]'));
  var thumbs=Array.prototype.slice.call(root.querySelectorAll('[data-rv-thumb]'));
  var count=root.querySelector('[data-rv-count]');
  var prev=root.querySelector('[data-rv-prev]');
  var next=root.querySelector('[data-rv-next]');
  if(!slides.length)return;
  var current=0;
  function show(index,focusThumb){
   current=(index+slides.length)%slides.length;
   slides.forEach(function(slide,i){slide.hidden=i!==current;});
   thumbs.forEach(function(t,i){t.setAttribute('aria-current',i===current?'true':'false');});
   if(count)count.textContent=(current+1)+' / '+slides.length;
   if(focusThumb&&thumbs[current])thumbs[current].focus();
  }
  if(prev)prev.addEventListener('click',function(){show(current-1,false);});
  if(next)next.addEventListener('click',function(){show(current+1,false);});
  thumbs.forEach(function(t,i){t.addEventListener('click',function(){show(i,false);});});
  root.addEventListener('keydown',function(event){
   if(event.key==='ArrowLeft'||event.key==='ArrowRight'){
    if(!event.target.closest('.mad4b-rv-gallery-stage, .mad4b-rv-gallery-thumbs'))return;
    event.preventDefault();
    show(current+(event.key==='ArrowRight'?1:-1),false);
   }
  });
  var startX=null;
  var stage=root.querySelector('.mad4b-rv-gallery-stage');
  if(stage){
   stage.addEventListener('touchstart',function(e){
    if(e.touches.length===1)startX=e.touches[0].clientX;
   },{passive:true});
   stage.addEventListener('touchend',function(e){
    if(startX===null||!e.changedTouches.length)return;
    var distance=e.changedTouches[0].clientX-startX;startX=null;
    if(Math.abs(distance)>48)show(current+(distance<0?1:-1),false);
   },{passive:true});
   stage.addEventListener('keydown',function(e){
    if((e.key==='Enter'||e.key===' ')&&e.target.matches('.mad4b-rv-gallery-photo img')){
     e.preventDefault();e.target.click();
    }
   });
   stage.addEventListener('click',function(e){
    var photo=e.target.closest('.mad4b-rv-gallery-photo img');
    if(!photo || !window.HTMLDialogElement)return;
    var dialog=document.createElement('dialog');
    dialog.className='mad4b-rv-image-dialog';
    var close=document.createElement('button');
    close.type='button';close.className='button';close.textContent='Close image';
    close.setAttribute('aria-label','Close enlarged gallery image');
    var full=document.createElement('img');
    full.src=photo.currentSrc||photo.src;
    full.alt=photo.alt;
    dialog.appendChild(close);dialog.appendChild(full);
    document.body.appendChild(dialog);
    var trigger=photo;
    close.addEventListener('click',function(){dialog.close();});
    dialog.addEventListener('click',function(event){if(event.target===dialog)dialog.close();});
    dialog.addEventListener('close',function(){dialog.remove();trigger.focus();});
    dialog.showModal();close.focus();
   });
  }
  show(0,false);
 }
 document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('[data-mad4b-gallery]').forEach(initGallery);
 });
}());

/* Reviewer-owned metadata suggestions from previously confirmed visual subjects. */
document.addEventListener('click',function(event){
 var button=event.target.closest('[data-rv-media-suggest]');
 if(!button)return;
 var form=button.closest('.mad4b-rv-media-form');
 if(!form)return;
 var subjects=(button.getAttribute('data-rv-media-suggest')||'').trim();
 if(!subjects)return;
 var alt=form.querySelector('[name="image_alt"]');
 var caption=form.querySelector('[name="image_caption"]');
 var description=form.querySelector('[name="image_description"]');
 if(alt&&!alt.value.trim())alt.value=subjects;
 if(caption&&!caption.value.trim())caption.value=subjects;
 if(description&&!description.value.trim())description.value='Observed visual subjects: '+subjects;
 if(alt)alt.focus();
});
