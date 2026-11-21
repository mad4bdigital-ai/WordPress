"""CP2.8 regression: all 45 native fields have ONE visible owner tab.

No data/API access. Uses real JS/CSS with a deterministic JetEngine-like form,
including legacy switcher and repeater selectors that previously overrode
mad4b-ws-filtered and made the same field appear on every navigation page.
"""
import asyncio,json
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[3]
GROUPS={
 'overview':['pricing_engine_enabled','booking_enabled','require_availability','allow_manual_overrides','stop_sale_global','booking_fulfillment_mode'],
 'sources':['shared_price_source_direct_weight','shared_price_source_rate_weight','shared_price_source_tie_break','shared_price_source_fallback','shared_price_source_visibility','shared_price_source_mode_rows','mad4b_provider_catalog_rows','mad4b_provider_source_policy_rows'],
 'policies':['tax_percent','service_fee_percent','shared_mode_policy_rows','shared_group_tier_pricing_rows','shared_package_pricing_rows'],
 'payments':['shared_gateway_availability_rows','shared_promotion_rows','shared_payment_fee_rows'],
 'dates':['mad4b_booking_date_min_lead_days','mad4b_booking_date_min_flex_gap_days','mad4b_booking_date_max_flex_days','mad4b_booking_date_max_advance_days','mad4b_booking_lead_mode_tour','mad4b_booking_lead_mode_cruise_tour','mad4b_booking_lead_mode_activity','mad4b_booking_lead_mode_extra_trip','mad4b_booking_lead_mode_day_tour','mad4b_booking_lead_mode_half_day','mad4b_booking_lead_mode_other','quote_ttl_minutes','shared_price_card_date_mode','mad4b_booking_operating_checkout_mode_v1'],
 'advanced':['price_formula_version','tour_formula','property_formula','transport_formula','shared_mode_policies_json','shared_financial_rules_json','minimum_booking_lead_hours','default_currency','shared_visa_policy_notes']
}
REPEATERS={'shared_price_source_mode_rows','mad4b_provider_catalog_rows','mad4b_provider_source_policy_rows','shared_mode_policy_rows','shared_group_tier_pricing_rows','shared_package_pricing_rows','shared_gateway_availability_rows','shared_promotion_rows','shared_payment_fee_rows'}
CSS=['wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/functional-modes.css',
 'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/jetengine-native-field-system.css',
 'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/jetengine-field-recipe.css',
 'ops/pricing-unified-checkpoints/tests/fixtures/pricing-composer-vendor.css',
 'wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.css',
 'wp-content/mu-plugins/assets/mad4b-pricing-interface-v2.css']
JS=['wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/functional-modes.js',
 'wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.js']
async def main():
 assert sum(map(len,GROUPS.values()))==45
 assert len({f for group in GROUPS.values() for f in group})==45
 async with async_playwright() as p:
  exe=Path('/root/.cache/ms-playwright/chromium_headless_shell-1194/chrome-linux/headless_shell')
  browser=await p.chromium.launch(headless=True,
    executable_path=str(exe) if exe.exists() else None,
    args=['--no-sandbox','--disable-dev-shm-usage'])
  results=[]
  for width in (1440,1280,1024,768,390,320):
   page=await browser.new_page(viewport={'width':width,'height':920})
   errors=[]
   page.on('pageerror',lambda err:errors.append(str(err)[:300]))
   await page.set_content('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#f6f9fa;font:13px Arial}#wpcontent{padding:12px;margin-left:110px}@media(max-width:900px){#wpcontent{margin-left:0}}</style></head><body><div id="wpcontent"><div class="jet-engine-options-page-wrap"><section class="cx-section"><div class="cx-section__holder"><form id="etg-dynamic-pricing-controls_form"><div id="settings_top"></div><div class="cx-component dialog-save"><button type="submit">Save changes</button></div></form></div></section></div></div></body></html>')
   # Every real control has the same native DOM shape and identity contract.
   await page.evaluate("""({groups,repeaters}) => {
     const top=document.querySelector('#settings_top'),fields=[];
     for(const [group,names] of Object.entries(groups))for(const name of names){
       const repeater=repeaters.includes(name);
       const div=document.createElement('div');
       div.className='cx-ui-kit cx-control cx-control-inline '+(repeater?'cx-control-repeater':'cx-control-text');
       div.dataset.controlName=name;
       const info=document.createElement('div');info.className='cx-control__info';
       const title=document.createElement('span');title.className='cx-control__title';title.textContent=name;
       info.append(title);
       const body=document.createElement('div');body.className='cx-control__content';
       if(repeater){
         const listing=document.createElement('div');listing.className='cx-ui-repeater-container';
         const row=document.createElement('div');row.className='cx-ui-repeater-item';
         const wrap=document.createElement('div');wrap.className='cx-switcher-wrap';
         for(const mode of ['true','false']){
           const input=document.createElement('input');input.type='radio';
           input.className='cx-input-switcher cx-input-switcher-'+mode;
           input.name=name+'[item-0][enabled]';input.value=mode;
           wrap.append(input);
         }
         row.append(wrap);listing.append(row);body.append(listing);
       }else{
         const input=document.createElement('input');input.name=name;input.type='text';
         input.value='UNCHANGED_'+name;
         body.append(input);
       }
       div.append(info,body);top.append(div);
       fields.push({name,label:name,type:repeater?'repeater':'text',mode:'standard'});
     }
     window.MAD4BJetModePage={slug:'etg-dynamic-pricing-controls',pageLabel:'Pricing Controls',fields};
     window.MAD4BWorkspaceShell={pricing:true};
   }""",{'groups':GROUPS,'repeaters':list(REPEATERS)})
   for src in CSS:
    f=ROOT/src
    assert f.is_file(),f
    await page.add_style_tag(path=str(f))
   for src in JS:
    await page.add_script_tag(path=str(ROOT/src))
   for group,keys in GROUPS.items():
    # JS click, preserving input field identity; mobile sidebar is collapsed.
    await page.locator('.mad4b-ws-nav-btn[data-section="'+group+'"]').evaluate('(e)=>e.click()')
    actual=await page.evaluate("""() => ({
      shown:[...document.querySelectorAll('#settings_top .mad4b-ws-control')]
        .filter(el=>el.getClientRects().length>0&&el.getBoundingClientRect().height>0)
        .map(el=>el.dataset.controlName),
      nativeForms:document.querySelectorAll('#etg-dynamic-pricing-controls_form').length,
      allInputs:document.querySelectorAll('#settings_top input').length,
      overflow:Math.max(0,document.documentElement.scrollWidth-innerWidth)
    })""")
    assert set(actual['shown'])==set(keys),f'viewport={width} group={group}: {actual}'
    assert actual['nativeForms']==1 and actual['allInputs']==45+len(REPEATERS),actual
    assert actual['overflow']<=12,(width,group,actual)
   await page.locator('.mad4b-ws-nav-btn[data-section="policies"]').evaluate('(e)=>e.click()')
   await page.locator('input[name="tax_percent"]').fill('7')
   # A native repeater's existing vendor radio remains in the SAME original
   # form; changing sections must neither clone it nor erase a pending edit.
   await page.evaluate('() => {\n     const radio=document.querySelector(\n       \'input[name="shared_mode_policy_rows[item-0][enabled]"][value="true"]\');\n     radio.checked=true;\n     radio.dispatchEvent(new Event(\'change\',{bubbles:true}));\n   }')
   await page.locator('.mad4b-ws-nav-btn[data-section="sources"]').evaluate('(e)=>e.click()')
   await page.locator('.mad4b-ws-nav-btn[data-section="policies"]').evaluate('(e)=>e.click()')
   assert await page.locator('input[name="tax_percent"]').input_value()=='7'
   assert await page.evaluate('() => document.querySelector(\n      \'input[name="shared_mode_policy_rows[item-0][enabled]"][value="true"]\').checked'),(width,'repeater edit lost during navigation')
   a11y=await page.evaluate("""() => {
      const original=[...document.querySelectorAll('#settings_top input')];
      const bad=original.filter(el=>!(el.getAttribute('aria-label')||
       el.getAttribute('aria-labelledby')||el.closest('label')));
      return {bad:bad.length,named:original.length-bad.length};
   }""")
   assert a11y['bad']==0,(width,a11y)
   # JetEngine mutates repeater rows; an inserted row must be labeled
   # immediately and retain a unique row index after reorder.
   labels=await page.evaluate('() => {\n      const owner=document.querySelector(\n        \'[data-control-name="shared_mode_policy_rows"]\');\n      const list=owner.querySelector(\'.cx-ui-repeater-container\');\n      const first=list.querySelector(\'.cx-ui-repeater-item\');\n      const second=first.cloneNode(true);\n      for(const field of second.querySelectorAll(\'input\')){\n        field.name=field.name.replace(\'item-0\',\'item-1\');\n        field.removeAttribute(\'aria-label\');\n        field.removeAttribute(\'data-mad4b-generated-label\');\n      }\n      list.append(second);\n      return {first:list.firstElementChild.querySelector(\'input\').getAttribute(\'aria-label\'),\n       secondField:list.lastElementChild.querySelector(\'input\').name};\n   }')
   await page.wait_for_function('() => {\n     const rows=document.querySelectorAll(\n       \'[data-control-name="shared_mode_policy_rows"] .cx-ui-repeater-item\');\n     return rows.length===2 &&\n       (rows[1].querySelector(\'input\').getAttribute(\'aria-label\')||\'\')\n        .includes(\'Row 2\');\n   }')
   await page.evaluate('() => {\n     const list=document.querySelector(\n       \'[data-control-name="shared_mode_policy_rows"] .cx-ui-repeater-container\');\n     list.prepend(list.lastElementChild);\n   }')
   await page.wait_for_function('() => {\n     const rows=document.querySelectorAll(\n       \'[data-control-name="shared_mode_policy_rows"] .cx-ui-repeater-item\');\n     return rows.length===2 && (rows[0].querySelector(\'input\')\n       .getAttribute(\'aria-label\')||\'\').includes(\'Row 1\') &&\n       (rows[1].querySelector(\'input\')\n        .getAttribute(\'aria-label\')||\'\').includes(\'Row 2\');\n   }')
   # Keyboard activation must navigate the original controlled sections.
   if width>=1024:
    button=page.locator('.mad4b-ws-nav-btn[data-section="sources"]')
    await button.focus()
    await page.keyboard.press('Enter')
    assert await button.get_attribute('aria-current')=='true',(width,'keyboard navigation')
    visible=await page.evaluate("() => [...document.querySelectorAll('#settings_top .mad4b-ws-control')]\n      .filter(e=>e.getClientRects().length&&e.getBoundingClientRect().height)\n      .map(e=>e.dataset.controlName)")
    assert set(visible)==set(GROUPS['sources']),(width,visible)
    # Tabbing from company search must not land on a filtered field.
    await page.locator('.mad4b-ws-search').focus()
    focused=[]
    for _ in range(65):
     await page.keyboard.press('Tab')
     focused.append(await page.evaluate(
      '() => document.activeElement?.getAttribute("name")||""'))
    forbidden=set(GROUPS['overview']+GROUPS['payments']+
       GROUPS['policies']+GROUPS['advanced']+GROUPS['dates'])
    assert not (set(focused)&forbidden),(width,'hidden fields are tabbable')
   assert not errors,(width,errors)
   results.append({'width':width,'groups':len(GROUPS),
      'one_owner_controls':45,'repeater_types':len(REPEATERS),
      'native_form_count':1,'all_inputs_named':a11y['named'],
      'duplicate_controls_visible':0,'new_row_aria':True,'keyboard_checked':width>=1024,'status':'PASS'})
   await page.close()
  await browser.close()
  print('CP28_SINGLE_OWNER_45_NATIVE_FIELDS_PASS '+json.dumps(results))
asyncio.run(main())
