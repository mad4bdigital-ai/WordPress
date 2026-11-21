"""Real vendor CSS + native shell contract: product x-alignment and repeater width."""
import asyncio,json
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[3]
FIXTURE=(Path(__file__).parent/'fixtures/composed-workspace.html').as_uri()
STYLES=[
 ROOT/'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/functional-modes.css',
 ROOT/'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/jetengine-field-recipe.css',
 Path(__file__).parent/'fixtures/pricing-composer-vendor.css',
 ROOT/'wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.css',
 ROOT/'wp-content/mu-plugins/assets/mad4b-pricing-interface-v2.css'
]
SHELL=ROOT/'wp-content/mu-plugins/assets/mad4b-jetengine-workspace-shell-v1.js'
SOURCE=(ROOT/'wp-content/plugins/mad4b-jetengine/modules/functional-modes/assets/functional-modes.js').read_text()
SNIPPET=SOURCE[SOURCE.index('  const renderDimensionOptions=(data)=>{'):
 SOURCE.index('  const setDimensionOpen=(open)=>{')]
async def main():
 async with async_playwright() as p:
  browser_bin=Path('/root/.cache/ms-playwright/chromium_headless_shell-1194/chrome-linux/headless_shell')
  b=await p.chromium.launch(headless=True,
    executable_path=str(browser_bin) if browser_bin.is_file() else None,
    args=['--no-sandbox','--disable-dev-shm-usage'])
  rows=[]
  for width in [1440,1280,1024,768,390,320]:
   page=await b.new_page(viewport={'width':width,'height':920},device_scale_factor=1)
   await page.goto(FIXTURE)
   for file in STYLES:
    if not file.exists():raise RuntimeError('CSS not available: '+str(file))
    await page.add_style_tag(path=str(file))
   await page.evaluate("""() => {
    const root=document.querySelector('.jet-engine-options-page-wrap');
    root.classList.add('mad4b-pricing-composed');
    const content=root.querySelector('#settings_top');
    const repeat=document.createElement('div');
    repeat.className='cx-control cx-control-inline cx-control-repeater mad4b-ws-control';
    repeat.dataset.controlName='shared_mode_policy_rows';
    repeat.innerHTML='<div class="cx-control__info"><div class="cx-control__title">Pricing strategy & financial policies</div><div class="cx-control__name">shared_mode_policy_rows</div></div><div class="cx-control__content"><div class="cx-ui-repeater-container"><div class="cx-ui-repeater-item-control">Native item with real unchanged input <input aria-label="Mode"/></div></div><div class="cx-control__description">Original JetEngine repeater rules</div></div>';
    content.append(repeat);
    const panel=root.querySelector('.mad4b-ws-external-section');
    if (!panel){
      const s=document.createElement('section');s.className='mad4b-ws-external-section';
      root.querySelector('.mad4b-ws-content').append(s);
    }
    const external=root.querySelector('.mad4b-ws-external-section');
    external.hidden=true;
    external.innerHTML='<section class="mad4b-stay-panel"><details class="mad4b-stay-panel__accordion" open><summary class="mad4b-stay-panel__summary"><span class="mad4b-stay-panel__heading"><strong>Accommodation & Unit Pricing</strong></span></summary><div class="mad4b-stay-panel__body"><div class="mad4b-stay-panel__layout"><div class="mad4b-stay-panel__catalog"><section class="mad4b-stay-panel__group"><div class="mad4b-stay-panel__group-head">Choose a Property</div><input aria-label="Property Search"></section></div><section class="mad4b-stay-panel__quote">Original Property/Unit Cost Preview</section></div></div></details></section>';
   }""")
   await page.evaluate("window.MAD4BWorkspaceShell={pricing:true};")
   await page.add_script_tag(path=str(SHELL))
   company=await page.evaluate("""() => {
    const root=document.querySelector('.jet-engine-options-page-wrap');
    const sidebar=root.querySelector('.mad4b-ws-sidebar');
    const content=root.querySelector('.mad4b-ws-content');
    const rep=root.querySelector('.cx-control-repeater');
    const ri=rep.querySelector('.cx-control__info');
    const rc=rep.querySelector('.cx-control__content');
    const box=e=>{const r=e.getBoundingClientRect();return {x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),right:Math.round(r.right)}};
    return {sidebar:box(sidebar),content:box(content),rep:box(rep),info:box(ri),body:box(rc),
      root:box(root),searchIsInContent:!!content.querySelector('.mad4b-ws-find-settings .mad4b-ws-search'),
      repeaterDisplay:getComputedStyle(rep).display,
      overflow:document.documentElement.scrollWidth-innerWidth};
   }""")
   assert company['searchIsInContent'],f'{width}: original field-search not in common content'
   assert company['repeaterDisplay']=='grid',f'{width}: native Repeater remains inline width split'
   assert company['body']['w']>=company['rep']['w']*.88,f'{width}: repeater body still 30/70 split {company}'
   assert company['overflow']<=8,f'{width}: company page overflows {company}'
   product=await page.evaluate("""() => {
    const root=document.querySelector('.jet-engine-options-page-wrap');
    const outside=root.querySelector('.mad4b-ws-external-section');
    root.dataset.mad4bPricingPane='accommodation';
    outside.hidden=false;
    const sidebar=root.querySelector('.mad4b-ws-sidebar');
    const content=root.querySelector('.mad4b-ws-content');
    const layout=outside.querySelector('.mad4b-stay-panel__layout');
    const quote=outside.querySelector('.mad4b-stay-panel__quote');
    const box=e=>{const r=e.getBoundingClientRect();return {x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),right:Math.round(r.right)}};
    return {sidebar:box(sidebar),content:box(content),layout:box(layout),quote:box(quote),
      outerOverflow:document.documentElement.scrollWidth-innerWidth,
      titleHidden:getComputedStyle(root.querySelector('.mad4b-ws-section-heading')).display==='none',
      cssGrid:getComputedStyle(layout).gridTemplateColumns};
   }""")
   assert abs(company['sidebar']['x']-product['sidebar']['x'])<=2,f'{width}: sidebar shifts between company/product'
   assert abs(company['content']['x']-product['content']['x'])<=2,f'{width}: main column shifts company/product {company} {product}'
   assert product['titleHidden'],f'{width}: duplicate product heading still visible'
   await page.wait_for_function('''() => {
      const p=document.querySelector('.mad4b-ws-product-context');
      return p&&!p.hidden&&p.querySelector('.mad4b-ws-product-context__title').textContent==='Company-wide dimensions apply here';
   }''', timeout=1800)
   assert product['outerOverflow']<=8,f'{width}: product page overflows {product}'
   if width>=1100:
    assert product['quote']['x']>=product['layout']['x'],f'{width}: quote rail broke grid'
   if width in (1440,390):
    await page.screenshot(path=f'/tmp/mad4b_pricing_alignment_{width}.png',full_page=True)
   rows.append({'viewport':width,'main_left_company':company['content']['x'],
    'main_left_product':product['content']['x'],'repeater_body':company['body']['w'],
    'repeater_row':company['rep']['w'],'product_quote_x':product['quote']['x'],
    'status':'PASS'})
   await page.close()
  # Test actual dimension-render function extracted from authoritative source.
  page=await b.new_page()
  await page.goto('about:blank')
  script="""(() => {
    const dimPanel=document.createElement('div');dimPanel.id='dimension-panel';
    document.body.append(dimPanel);
    const dimBadge=document.createElement('span');
    const dimInput={mode:document.createElement('input'),
      selected:document.createElement('input'),languageMode:document.createElement('input'),
      authoringLanguage:document.createElement('input')};
    const dimCfg={pricing_lab:'/admin/pricing-lab'};
    const dimLabels={};const recommendedIDs=new Set();
    const change=()=>{};
    const ensureDimInputs=()=>{};
    const policyRow=()=>document.createElement('details');
    const make=(tag,cls,content)=>{
      const element=document.createElement(tag);
      if(cls)element.className=cls;
      if(content!==undefined)element.textContent=String(content);
      return element;
    };
    """+SNIPPET+"""
    renderDimensionOptions({catalog:[],enabled_ids:[],saved:true,mode:'custom',
      pricing_language:{default_language:'en'}});
    const names=[...dimPanel.children].map(x=>x.className);
    const tooltip=dimPanel.querySelector('.mad4b-ws-dimension-choice-help');
    const disclosure=dimPanel.querySelector('.mad4b-ws-dimension-guidance');
    const choices=[...dimPanel.querySelectorAll('.mad4b-ws-dimension-action button')];
    return {names,choices:choices.length,captions:tooltip.querySelectorAll('small').length,
      text:disclosure.textContent.slice(0,240),
      described:choices.every(x=>!!document.getElementById(x.getAttribute('aria-describedby'))),
      groupIndex:names.indexOf('mad4b-ws-dimension-groups'),
      guidanceIndex:names.indexOf('mad4b-ws-dimension-guidance')};
  })()"""
  # The JS source is evaluated as a script with expression result, no server calls.
  info=await page.evaluate('source => eval(source)',script)
  assert info['choices']==4 and info['captions']==4 and info['described'],info
  assert info['groupIndex']<info['guidanceIndex'],info
  print('DIMENSION_SOURCE_ORDER_VERIFIED '+json.dumps(info))
  await b.close()
  print('PRICING_LAYOUT_REPEATERS_ALIGNMENT_PASS '+json.dumps(rows))
asyncio.run(main())
