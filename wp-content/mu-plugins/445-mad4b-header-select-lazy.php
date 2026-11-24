<?php
/**
 * Plugin Name: MAD4B Header Switcher Select Lazy Catalog
 * Description: On-demand options for duplicated non-transactional header selectors. Original no-script dropdown fallback retained.
 * Version: 0.1.0-canary
 */
defined('ABSPATH') || exit;
function mad4b_header_lazy_is_safe(): bool {
    if (is_admin() || wp_doing_ajax() || is_preview() ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (defined('WP_CLI') && WP_CLI) ||
        strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET' ||
        !is_singular('tours-and-activities') || isset($_GET['elementor-preview'])) return false;
    if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->editor) && \Elementor\Plugin::$instance->editor->is_edit_mode()) return false;
    return (bool) apply_filters('mad4b/header_lazy/allowed', true);
}
add_filter('do_shortcode_tag', static function($output, $tag, $attrs) {
    if (!mad4b_header_lazy_is_safe() || !is_string($output)) return $output;
    $expected = ['mad4b_country_switcher' => 'market_country', 'travel_booking_nationality_switcher' => 'nationality', 'etg_nationality_switcher' => 'nationality'];
    if (!isset($expected[$tag])) return $output;
    $name = $expected[$tag];
    // Exact-matching native shortcode only, not traveller Form 6.
    $regex = '~(<select\b[^>]*\bname="' . preg_quote($name, '~') . '"[^>]*>)(.*?)(</select>)~si';
    if (!preg_match($regex, $output, $m)) return $output;
    if (!preg_match_all('~<option\b([^>]*)>(.*?)</option>~si', $m[2], $options, PREG_SET_ORDER)
        || count($options) < 150 || count($options) > 300) return $output;
    $catalog = [];
    $first = null;
    $selected = null;
    $selectedCount = 0;
    foreach ($options as $index => $option) {
        $attr = $option[1];
        if (!preg_match('~\bvalue=["\x27]([^"\x27]*)["\x27]~', $attr, $v)) return $output;
        $code = $v[1];
        $label = trim(wp_strip_all_tags(html_entity_decode($option[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($index === 0 && $code === '') { $first = $option[0]; continue; }
        if (!preg_match('/^[A-Z]{2}$/D', $code) || $label === '') return $output;
        if (isset($catalog[$code])) return $output;
        $catalog[$code] = $label;
        if (preg_match('~\bselected(?:\s*=\s*["\x27]?selected["\x27]?)?(?:\s|$)~i', trim($attr))) {
            $selected = $option[0];
            $selectedCount++;
        }
    }
    if (!$first || count($catalog) < 150 || $selectedCount > 1) return $output;
    $key = hash('sha256', $name . ':' . wp_json_encode($catalog));
    $script = '<script type="application/json" data-mad4b-header-catalog="' . esc_attr($key) . '">'
        . wp_json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>';
    static $seen = [];
    if (isset($seen[$key])) $script = '';
    else $seen[$key] = true;
    $open = substr($m[1], 0, -1) . ' disabled data-mad4b-header-lazy="' . esc_attr($key) . '">';
    $compact = $open . $first . ($selected ?: '') . $m[3];
    // When scripting is disabled, the full original native select is present
    // and is the final submitted field; only it is visible to the user.
    $fallback = '<noscript><style>select[data-mad4b-header-lazy="' . esc_attr($key) . '"]{display:none!important}</style>'
        . $m[0] . '</noscript>';
    return str_replace($m[0], $script . $compact . $fallback, $output);
}, 99, 3);
add_action('wp_head', static function () {
    if (!mad4b_header_lazy_is_safe()) return;
?>
<script id="mad4b-header-select-hydration-v1">
(function(){
'use strict';
var catalogCache=new Map(), nativeSetter=Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,'value');
function getCatalog(id){
 if(catalogCache.has(id))return catalogCache.get(id);
 var el=document.querySelector('script[data-mad4b-header-catalog="'+id+'"]'),obj=null;
 try{if(el)obj=JSON.parse(el.textContent);}catch(e){obj=null;}
 if(!obj||typeof obj!=='object'||Array.isArray(obj))return null;
 catalogCache.set(id,obj);return obj;
}
function ensureSelected(select,code){
 var id=select.getAttribute('data-mad4b-header-lazy'),catalog=id&&getCatalog(id);
 if(!catalog||typeof code!=='string'||!/^[A-Z]{2}$/.test(code)||
    !Object.prototype.hasOwnProperty.call(catalog,code))return;
 for(var i=0;i<select.options.length;i++)if(select.options[i].value===code)return;
 select.add(new Option(catalog[code],code));
}
function hydrate(select){
 var id=select.getAttribute('data-mad4b-header-lazy'),catalog=id&&getCatalog(id);
 if(!catalog||select.dataset.mad4bLazyHydrated==='1')return;
 var chosen=select.value,fragment=document.createDocumentFragment();
 // Preserve the site's original localized ISO order; only append missing entries.
 Object.keys(catalog).forEach(function(code){
   if(!Array.prototype.some.call(select.options,function(opt){return opt.value===code;}))
     fragment.appendChild(new Option(catalog[code],code));
 });
 select.appendChild(fragment);
 nativeSetter.set.call(select,chosen);
 select.dataset.mad4bLazyHydrated='1';
 select.dispatchEvent(new CustomEvent('country-search:catalogue-updated',{bubbles:true}));
}
function boot(){
 document.querySelectorAll('select[data-mad4b-header-lazy]').forEach(function(select){
   if(select.dataset.mad4bHeaderSetHook==='1')return;
   select.dataset.mad4bHeaderSetHook='1';
   select.disabled=false;
   Object.defineProperty(select,'value',{
     configurable:true,
     get:function(){return nativeSetter.get.call(this);},
     set:function(next){ensureSelected(this,next);return nativeSetter.set.call(this,next);}
   });
 });
}
document.addEventListener('focusin',function(event){
 var panel=event.target.closest('.header-context-panel');
 if(panel)panel.querySelectorAll('select[data-mad4b-header-lazy]').forEach(hydrate);
},true);
document.addEventListener('pointerdown',function(event){
 var panel=event.target.closest('.header-context-panel');
 if(panel && (event.target.closest('.mad4b-country-search,.travel-booking-passport-search') ||
             event.target.matches('select[data-mad4b-header-lazy]')))
   panel.querySelectorAll('select[data-mad4b-header-lazy]').forEach(hydrate);
},true);
document.addEventListener('header-preferences:state',boot);
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
window.MAD4BHeaderSelectLazy={hydrate:hydrate,boot:boot};
})();
</script>
<?php
}, 1);
