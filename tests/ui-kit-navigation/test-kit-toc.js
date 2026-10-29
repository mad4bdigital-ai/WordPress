'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const policy=JSON.parse(fs.readFileSync(path.join(root,'governance/ui/elementor-kit-navigation-tokens-v1.json'),'utf8'));
const css=fs.readFileSync(path.join(root,'wp-content/mu-plugins/assets/mad4b-header-tour-ux.css'),'utf8');
const tocSource=path.join(root,'wp-content/mu-plugins/assets/mad4b-mobile-tour-toc.js');
const toc=require(tocSource);
const php=fs.readFileSync(path.join(root,'wp-content/mu-plugins/199-mad4b-client-header-and-tour-toc.php'),'utf8');
assert.equal(policy.templates.tour_content_ids.length,6);
assert.deepEqual(policy.kit_integrity.required_system_tokens,['primary','secondary','text','accent']);
assert.equal(policy.kit_integrity.current_legacy_duplicate_ids.length,0);
assert.equal(policy.kit_integrity.reconciled_duplicate_ids.length,13);
assert.equal(policy.kit_integrity.before_custom_color_count,56);
assert.equal(policy.kit_integrity.after_custom_color_count,19);
assert.equal(policy.kit_integrity.removed_redundant_rows,37);
assert.equal(policy.kit_integrity.runtime_guard.admission_function,'mad4b_kit_integrity_admission()');
assert.equal(policy.templates.real_header_mega_menu.widget_id,'6072b3f');
assert.equal(policy.templates.real_header_mega_menu.template_ids.length,6);
assert.equal(policy.templates.real_header_mega_menu.require_typography,'globals/typography?id=text');
assert.deepEqual(policy.templates.real_header_mega_menu.require_global_colors,{
  menu_item_title_text_color_normal:'primary',
  menu_item_title_text_color_hover:'accent',
  menu_item_title_text_color_active:'secondary',
  menu_toggle_icon_color_normal:'primary'
});
for(const [widget,settings] of Object.entries(policy.templates.fields)){
  assert.ok(/^[a-f0-9]{7}$/.test(widget));
  for(const [setting,token] of Object.entries(settings))
    assert.ok(['text','secondary','accent','bnav001'].includes(token),setting+' must bind valid Kit token');
}
assert.match(css,/--mad4b-client-ink:\s*var\(--e-global-color-primary/);
assert.match(css,/--mad4b-client-hover:\s*var\(--e-global-color-secondary/);
assert.match(css,/--mad4b-client-accent:\s*var\(--e-global-color-accent/);
assert.match(css,/--mad4b-client-surface:\s*var\(--e-global-color-bnav001/);
assert.doesNotMatch(css,/#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})\b/i,'No standalone palette colors');
assert.match(css,/\.mad4b-tour-toc\s*\{\s*display:\s*none;/);
assert.match(css,/max-width:\s*767px/);
assert.match(css,/body\.single-tours-and-activities/);
assert.match(css,/focus-visible/);
assert.match(css,/elementor-element-6072b3f/,'Actual Elementor Mega Menu targeted');
assert.match(css,/e-n-menu-title-container::after/,'Native mega menu hover underline');
assert.doesNotMatch(css,/#ast-desktop-header/,'No obsolete Astra-only selector');
assert.match(css,/prefers-reduced-motion/);
assert.equal(toc.allowedHash('#elementor-toc__heading-anchor-2'),true);
assert.equal(toc.allowedHash('https://evil.example'),false);
assert.equal(toc.allowedHash('#javascript:alert(1)'),false);
assert.equal(toc.classify('Duration',0),0);
assert.equal(toc.classify('Itinerary - Pyramids of Giza',10),1);
assert.equal(toc.classify('Important Links - Full-Day Pyramids',7),2);
assert.equal(toc.classify('FAQ - Pyramids',2),3);
assert.equal(toc.classify('Travel Inspiration Starts Here',2),3);
assert.equal(toc.languageFor({documentElement:{getAttribute:()=> 'fr-FR'}}),'fr');
assert.equal(toc.languageFor({documentElement:{getAttribute:()=> 'ar'}}),'ar');
assert.equal(toc.languageFor({documentElement:{getAttribute:()=> 'xx'}}),'en');
assert.match(php,/\$widget->get_id\(\) !== 'c0a29f0'/,'Desktop label only; mobile Country untouched');
assert.doesNotMatch(php,/'c0a29f0', 'c0a29f5'/);
console.log('PASS: company Kit source-only colors, semantic navigation refs, scoped mobile nested TOC, language and link rules');
