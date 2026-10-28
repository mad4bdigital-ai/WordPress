<?php
/** Lab only: Elementor global typography and rem-based actions for card and accordion layouts. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
add_action('wp_head',static function(){
 $kit=\Elementor\Plugin::instance()->kits_manager->get_active_kit();
 $settings=$kit?$kit->get_settings():[];
 $family=(string)($settings['button_typography_font_family']??'');
 $weight=(string)($settings['button_typography_font_weight']??'');
 $size=$settings['button_typography_font_size']['size']??null;
 $family=str_replace(['\\','"','\n','\r'],['','', '', ''],$family);
 $weight=preg_match('/^(?:[1-9]00|normal|bold)$/',$weight)?$weight:'inherit';
 $size=is_string($size)&&preg_match('/^clamp\([a-z0-9., +%\-]+\)$/i',$size)?$size:'1rem';
 ?>
<style id="rate-lab-elementor-button-font-bridge">:root{--rate-lab-button-font-family:"<?php echo esc_html($family); ?>";--rate-lab-button-font-weight:<?php echo esc_html($weight); ?>;--rate-lab-button-font-size:<?php echo esc_html($size); ?>}</style>
<style id="rate-lab-rate-actions-style">
.elementor-46193 .rate-select-actions,.elementor-57381 .rate-select-actions{display:flex;align-items:center;justify-content:flex-end;gap:.5rem;min-height:2.75rem;margin-block:.625rem .25rem;position:relative;font-family:var(--rate-lab-button-font-family,inherit);font-size:clamp(.875rem,calc(var(--rate-lab-button-font-size,1rem) * .55),1.125rem);font-weight:var(--rate-lab-button-font-weight,inherit);line-height:var(--e-global-typography-text-line-height,1.4);letter-spacing:var(--e-global-typography-text-letter-spacing,normal)}
.elementor-46193 .rate-select-actions [hidden],.elementor-57381 .rate-select-actions [hidden]{display:none!important}
.elementor-46193 .rate-select-actions button,.elementor-57381 .rate-select-actions button{box-sizing:border-box;min-height:2.75rem;border-radius:.625rem;padding:.625rem 1rem;font:inherit;letter-spacing:inherit;line-height:1.25;cursor:pointer;transition:opacity .18s ease,transform .18s ease,background-color .18s ease,color .18s ease,visibility .18s ease}
.elementor-46193 .rate-choose-rate-for-preview,.elementor-57381 .rate-choose-rate-for-preview{color:var(--e-global-color-primary,#172a40);background:var(--e-global-color-accent,#91cb18);border:.0625rem solid transparent;font-weight:var(--rate-lab-button-font-weight,inherit)}
.elementor-46193 .rate-remove-rate-preview,.elementor-57381 .rate-remove-rate-preview{display:inline-flex;align-items:center;justify-content:center;width:2.75rem;height:2.75rem;padding:0;font-size:1.5rem;line-height:1;color:var(--e-global-color-primary,#172a40);background:transparent;border:.0625rem solid currentColor}
.elementor-46193 .rate-select-actions button:focus-visible,.elementor-57381 .rate-select-actions button:focus-visible{outline:.15rem solid var(--e-global-color-accent,currentColor);outline-offset:.15rem}
.elementor-46193 .rate-choose-rate-for-preview:hover,.elementor-57381 .rate-choose-rate-for-preview:hover{filter:brightness(.94)}
.elementor-46193 .rate-remove-rate-preview:hover,.elementor-57381 .rate-remove-rate-preview:hover{background:var(--e-global-color-secondary,#eef1f2)}
.elementor-46193 .rate-select-actions{padding-inline:.375rem}
.elementor-57381 .rate-select-actions{padding-inline:0;margin-block:.75rem .375rem;justify-content:flex-start}
@media (hover:hover) and (pointer:fine){
.elementor-46193 .rate-select-actions:not([data-selected="true"]) .rate-choose-rate-for-preview,.elementor-57381 .rate-select-actions:not([data-selected="true"]) .rate-choose-rate-for-preview{opacity:0;visibility:hidden;transform:translateY(.25rem);pointer-events:none}
.jet-listing-grid__item:hover .elementor-46193 .rate-select-actions:not([data-selected="true"]) .rate-choose-rate-for-preview,
.jet-listing-grid__item:focus-within .elementor-46193 .rate-select-actions:not([data-selected="true"]) .rate-choose-rate-for-preview,
.jet-toggle:hover .elementor-57381 .rate-select-actions:not([data-selected="true"]) .rate-choose-rate-for-preview,
.jet-toggle:focus-within .elementor-57381 .rate-select-actions:not([data-selected="true"]) .rate-choose-rate-for-preview,
.jet-listing-grid__item:hover .elementor-57381 .rate-select-actions:not([data-selected="true"]) .rate-choose-rate-for-preview,
.jet-listing-grid__item:focus-within .elementor-57381 .rate-select-actions:not([data-selected="true"]) .rate-choose-rate-for-preview,
.rate-select-actions:hover .rate-choose-rate-for-preview,.rate-select-actions:focus-within .rate-choose-rate-for-preview{opacity:1!important;visibility:visible!important;transform:none!important;pointer-events:auto!important}
}
@media (hover:none),(pointer:coarse){.elementor-46193 .rate-choose-rate-for-preview,.elementor-57381 .rate-choose-rate-for-preview{opacity:1;visibility:visible;transform:none}}
@media (max-width:47.99rem){.elementor-46193 .rate-select-actions,.elementor-57381 .rate-select-actions{gap:.375rem;margin-block:.5rem;min-height:2.5rem}.elementor-46193 .rate-select-actions button,.elementor-57381 .rate-select-actions button{min-height:2.5rem;padding:.5rem .75rem}.elementor-46193 .rate-remove-rate-preview,.elementor-57381 .rate-remove-rate-preview{width:2.5rem;height:2.5rem;padding:0}}
@media (prefers-reduced-motion:reduce){.elementor-46193 .rate-select-actions button,.elementor-57381 .rate-select-actions button{transition:none}}
/* REV84: move the original Select widget below the native Rate date row. */
.elementor-46193 .tour-native-package-tier .tour-rate-native-actions.rate-positioned-by-date{
 width:100%;align-self:stretch;min-width:0;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions{
 min-height:30px;margin:2px 0 7px;padding:0;justify-content:flex-end;gap:6px;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button{
 min-height:29px;padding:5px 11px;border-radius:7px;font-size:11.5px;line-height:1.2;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-choose-rate-for-preview{
 opacity:1!important;visibility:visible!important;transform:none!important;pointer-events:auto!important;
}
.elementor-46193 .tour-native-package-tier .elementor-element-f4afb2e .jet-listing-dynamic-field__content{
 font-size:14px!important;line-height:1.35!important;
}
@media(max-width:600px){
 .elementor-46193 .tour-native-package-tier .elementor-element-f4afb2e .jet-listing-dynamic-field__content{
  font-size:12.5px!important;
 }
 .elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button{
  min-height:28px;padding:5px 9px;font-size:11px;
 }
}
/* REV87: full-width Package Select visual based on original Silver/Gold cards.
   Keep the existing rate selection/remove actions and source of truth. */
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date{
 width:100%!important;min-width:0;max-width:100%;margin:0!important;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions{
 display:flex;align-items:stretch;justify-content:stretch;
 width:100%;max-width:100%;min-width:0;
 padding:0!important;margin:8px 0 10px!important;
 min-height:48px;gap:0;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button{
 display:flex;align-items:center;justify-content:center;gap:12px;
 box-sizing:border-box;width:100%;max-width:100%;min-width:0;min-height:48px;
 padding:9px 14px!important;border-radius:10px!important;
 font-family:var(--rate-lab-button-font-family,inherit);
 font-size:clamp(14px,1.15vw,16px)!important;font-weight:650!important;
 line-height:1.2!important;letter-spacing:normal!important;text-align:center;
 opacity:1!important;visibility:visible!important;transform:none!important;
 pointer-events:auto!important;box-shadow:none!important;cursor:pointer;
 transition:background-color .15s ease,border-color .15s ease,box-shadow .15s ease;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button[hidden]{
 display:none!important;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions .rate-choose-rate-for-preview,
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions .rate-remove-rate-preview{
 background:#fffdf8!important;color:#326993!important;
 border:2px solid #5686b0!important;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button::before{
 content:"";display:block;flex:0 0 22px;width:22px;height:22px;
 box-sizing:border-box;border:2px solid currentColor;border-radius:50%;
 background:transparent;opacity:.9;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions .rate-native-selected-label{
 display:inline;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions .rate-accordion-remove-icon{
 display:none;
}
/* Gold tier uses the palette of its existing badge and card border. */
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions[data-rate-tier="gold"] .rate-choose-rate-for-preview,
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions[data-rate-tier="gold"] .rate-remove-rate-preview{
 color:#fff!important;background:linear-gradient(95deg,#af7b08,#c59818)!important;
 border:2px solid #b5850c!important;
}
/* Keep the complete button when chosen, with radio confirmation. */
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions[data-selected="true"]:not([data-rate-tier="gold"]) .rate-remove-rate-preview{
 background:#326993!important;color:#fff!important;border-color:#326993!important;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions[data-selected="true"] .rate-remove-rate-preview::before{
 background:radial-gradient(circle at center,currentColor 0 5px,transparent 5.5px);
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button:hover:not(:disabled){
 filter:none!important;box-shadow:0 2px 6px rgba(20,48,70,.12)!important;
}
/* REV88: a tinted overlay on hover, without changing the button's layout.
   Inset shadow provides the overlay over the original Silver/Gold fill and
   never covers the label or the circular selector. */
@media(hover:hover) and (pointer:fine){
 .elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions:not([data-rate-tier="gold"]) button:hover:not(:disabled){
  box-shadow:inset 0 0 0 80px rgba(44,105,154,.13),0 3px 9px rgba(44,105,154,.14)!important;
 }
 .elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions[data-rate-tier="gold"] button:hover:not(:disabled){
  box-shadow:inset 0 0 0 80px rgba(75,43,2,.24),0 3px 9px rgba(136,96,10,.19)!important;
 }
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions:not([data-rate-tier="gold"]) button:focus-visible{
 box-shadow:inset 0 0 0 80px rgba(44,105,154,.13)!important;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions[data-rate-tier="gold"] button:focus-visible{
 box-shadow:inset 0 0 0 80px rgba(75,43,2,.24)!important;
}
.elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button:focus-visible{
 outline:3px solid #1a659b!important;outline-offset:2px!important;
}
.elementor-57381 .rate-select-actions .rate-native-selected-label{display:none}
@media(max-width:600px){
 .elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions{
  min-height:42px;margin:6px 0 8px!important;
 }
 .elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button{
  min-height:42px;padding:8px 10px!important;
  border-radius:8px!important;font-size:13px!important;gap:9px;
 }
 .elementor-46193 .tour-native-package-tier .rate-positioned-by-date .rate-select-actions button::before{
  width:18px;height:18px;flex-basis:18px;
 }
}
</style>
<?php });


// Preserve Elementor's authored card, moving only the existing select widget
// after its dates at runtime (also after JetSmartFilters re-renders).
add_action('wp_footer',static function(){
 if(!is_singular('tours-and-activities'))return;
 ?>
<script id="rate-actions-position-by-dates-rev84">
(()=>{'use strict';
 // Visual selection state follows the shared Tour selection broker even on
 // pages without the old Booking Preview module. No Rate is generated here.
 const syncState=()=>{
  const selected=String(window.SharedPresentationSelectionV1?.getSnapshot?.().selectedRateId||'');
  document.querySelectorAll('.elementor-46193 .tour-native-package-tier .rate-select-actions').forEach(group=>{
   const isSelected=selected!==''&&selected===group.dataset.rateActions;
   group.dataset.selected=String(isSelected);
   const choose=group.querySelector('[data-rate-booking-rate-id]');
   const remove=group.querySelector('[data-rate-remove-rate-id]');
   if(choose){choose.hidden=isSelected;choose.setAttribute('aria-pressed',String(isSelected));}
   if(remove)remove.hidden=!isSelected;
  });
 };
 const arrange=()=>{
  document.querySelectorAll('.elementor-46193 .tour-native-package-tier').forEach(card=>{
   const dateRow=card.querySelector('.elementor-element-f4afb2e');
   const widget=card.querySelector('.tour-rate-native-actions');
   if(!dateRow||!widget||!dateRow.parentElement)return;
   if(widget.previousElementSibling!==dateRow){
    dateRow.insertAdjacentElement('afterend',widget);
   }
   widget.classList.add('rate-positioned-by-date');
  });
  syncState();
 };
 let queued=false;
 const schedule=()=>{
  if(queued)return;
  queued=true;
  requestAnimationFrame(()=>{queued=false;arrange();});
 };
 arrange();
 new MutationObserver(records=>{
  if(records.some(r=>r.addedNodes.length))schedule();
 }).observe(document.body,{childList:true,subtree:true});
 document.addEventListener('jet-filter-content-rendered',schedule);
 document.addEventListener('shared-presentation-selection-changed',syncState);
 document.addEventListener('shared-presentation-rehydration-requested',schedule);
 window.addEventListener('load',schedule,{once:true});
})();
</script>
<?php },60);
