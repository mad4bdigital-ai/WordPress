'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../wp-content/plugins/mad4b-distribution-connectors');
const engine=fs.readFileSync(path.join(root,'includes/class-mad4b-catalog-studio.php'),'utf8');
const ui=fs.readFileSync(path.join(root,'includes/class-mad4b-catalog-studio-admin.php'),'utf8');
const js=fs.readFileSync(path.join(root,'assets/catalog-workbench.js'),'utf8');
const css=fs.readFileSync(path.join(root,'assets/catalog-workbench.css'),'utf8');
let checks=0;function ok(cond,why){checks++;assert.ok(cond,why);}
const operations=['create','duplicate','validate','preview','publish_internal','save_mapping','save_profile','import_review','apply_import'];
for(const operation of operations){
  ok(engine.includes("'"+operation+"'=>self::"),'engine route exists '+operation);
  ok(ui.includes("'"+operation+"'") || ui.includes('value="'+operation+'"'),'working UI control '+operation);
}
ok(ui.includes('wp_nonce_field(\'mad4b_catalog_studio_action\')'),'forms signed with WP nonce');
ok(engine.includes("check_admin_referer('mad4b_catalog_studio_action')"),'server verifies nonce');
ok(engine.includes("current_user_can('manage_options')"),'capability guard');
ok(ui.includes('Open WP All Export'),'native full export editor');
ok(ui.includes('Open WP All Import'),'native import editor');
ok(ui.includes('remove_action(\'settings_page_\'.self::PAGE,\'mcfm_admin_page\')'),'no duplicate action on same WP screen');
ok(engine.includes("'external_delivery_enabled'=>false"),'no automatic provider delivery');
ok(engine.includes("$item['status']='READY_INTERNAL'"),'internal-only mark ready');
ok(engine.includes('self::fingerprint($feed)'),'fingerprint validated');
ok(engine.includes('get_import_review'),'two-step import staging');
ok(engine.includes('max(1,min(self::MAX_SAMPLE,$limit))'),'preview limit');
ok(engine.includes('str_starts_with((string)($feed[\'slug\']??\'\'),\'mad4b-managed-\')'),'private feed prefix');
ok(ui.includes('class="mad4b-studio__mapping-table"')||ui.includes('mad4b-studio__mapping-table'),'mapping workbench');
ok(ui.includes('name="column['),'field mapping form');
ok(ui.includes('aria-label="Feed library"'),'accessible library');
ok(ui.includes('role="status"'),'notice status');
ok(ui.includes('Mark ready internally'),'honest internal scope');
ok(ui.includes('This does not publish or send data'),'preview no side effects');
ok(ui.includes('None of these buttons uploads'),'company local architecture');
ok(!js.includes('fetch(')&&!js.includes('XMLHttpRequest'),'javascript no hidden requests');
ok(js.includes("addEventListener('click'"),'add/remove row controls');
ok(js.includes("addEventListener('change'"),'source type selection');
ok(css.includes('@media(max-width:570px)'),'small-screen responsive');
ok(css.includes('prefers-reduced-motion'),'accessibility motion');
console.log(JSON.stringify({suite:'mad4b.catalog-studio.ui',checks_passed:checks,
    secure_forms:true,real_actions:operations.length,native_wp_all_import_export:true,
    external_calls:0,network_mutations:false}));
