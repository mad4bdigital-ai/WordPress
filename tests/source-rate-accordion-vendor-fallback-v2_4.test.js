/** Isolated Node/DOM-stub contract: no browser, network or WordPress mutation. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname,
  '../wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-rate-widget-lifecycle-2.4.0-20261111.js'),'utf8');
assert(source.includes("version:'2.4.0'"));
assert(!/\bnew\s+MutationObserver\s*\(/.test(source));
assert(!source.includes('setInterval('));

function scenario({jquery=true, preexistingHandler=false, hookRegisters=true, vendorExports=true}={}) {
  const nativeEvents={click: preexistingHandler?[{namespace:'jetAccordion'}]:[]};
  const calls=[];
  const vendorCalls=[];
  const timeouts=[];
  const eventListeners=new Map();
  const control={
    closest(selector){
      return selector==='#tour_rates_accordion, #tour_rates'?{}:null;
    }
  };
  const widget={querySelector(selector){
    if(selector==='.jet-accordion__inner > .jet-toggle > .jet-toggle__control')return control;
    return null;
  }};
  const root={
    querySelector(selector){
      if(selector==='#tour_rates_accordion, #tour_rates')return {};
      return null;
    },
    querySelectorAll(selector){
      return selector==='.elementor-widget-jet-accordion'?[widget]:[];
    },
    contains(){return true;}
  };
  const document={
    documentElement:{lang:'en'},
    getElementById(id){return id==='prices'?{closest:()=>root}:null;},
    querySelector(){return null;},
    addEventListener(id,cb){eventListeners.set(id,cb);}
  };
  let ajax=null;
  function jq(target){return {on(event,fn){ajax=fn;return this;}};}
  jq._data=function(target,key){return target===control&&key==='events'?nativeEvents:null;};
  const window={
    SharedPresentationFluentConfigV1:{rateChoices:[]},
    elementorFrontend:{hooks:{doAction(name){
      calls.push(name);
      if (hookRegisters) nativeEvents.click.push({namespace:'jetAccordion'});
    }}},
    JetTabs: vendorExports?{accordionInit(widget){
      vendorCalls.push(widget);
      nativeEvents.click.push({namespace:'jetAccordion'});
    }}:null,
    addEventListener(){}
  };
  if(jquery)window.jQuery=jq;
  const context={
    window,document,
    requestAnimationFrame(cb){cb();},
    setTimeout(cb,ms){timeouts.push({cb,ms});return timeouts.length;},
    clearTimeout(){}
  };
  vm.runInNewContext(source,context,{timeout:2000});
  const interact=(name)=>{
    const target={closest(selector){return selector==='.jet-toggle__control'?control:null;}};
    const cb=eventListeners.get(name);
    if(!cb)throw new Error('NO_CAPTURED_HANDLER '+name);
    cb({target,key:'Enter'});
  };
  return {window,calls,vendorCalls,timeouts,events:nativeEvents,ajax,interact};
}

let test=scenario();
assert.deepEqual(test.calls,[],'Initial load leaves native Elementor/JetTabs handler alone');
assert.equal(test.window.MAD4BRateActionLifecycleV2.inspect().accordionRepairs,0);
test.window.MAD4BRateActionLifecycleV2.refresh();
assert.equal(test.calls.length,0,'Refresh may not manufacture an initial native handler');
test.interact('pointerdown');
assert.equal(test.calls.length,1,'First pointer interaction must restore native handler');
assert.equal(test.vendorCalls.length,0,'Do not call vendor fallback when Elementor hook succeeds');
test.interact('pointerdown');
assert.equal(test.calls.length,1,'Second pointer interaction must not duplicate handler');
assert.equal(typeof test.ajax,'function');
test.ajax();
assert.deepEqual(test.timeouts.map(t=>t.ms),[80,400,1100]);
for(const t of test.timeouts)t.cb();
assert.equal(test.calls.length,1,'Ajax recovery only restores one missing vendor handler');

test=scenario({hookRegisters:false,vendorExports:true});
test.interact('pointerdown');
assert.equal(test.calls.length,1);
assert.equal(test.vendorCalls.length,1,'Call exported JetTabs initializer only when hook fails');
test.interact('click');
assert.equal(test.vendorCalls.length,1,'Do not call JetTabs twice after native handler appears');
test=scenario({hookRegisters:false,vendorExports:false});
test.interact('pointerdown');
assert.equal(test.calls.length,1);
assert.equal(test.vendorCalls.length,0,'Do not synthesize replacement accordion if vendor API is absent');
test=scenario({preexistingHandler:true});
test.interact('pointerdown');
assert.equal(test.calls.length,0,'Never replace already-registered JetAccordion handler');
assert.equal(test.window.MAD4BRateActionLifecycleV2.inspect().accordionRepairs,0);

test=scenario();
test.interact('keydown');
assert.equal(test.calls.length,1,'First keyboard interaction restores native handler');
test=scenario({jquery:false});
test.interact('pointerdown');
assert.equal(test.calls.length,0,'Missing jQuery must not fake the vendor handler');
assert.equal(test.window.MAD4BRateActionLifecycleV2.inspect().accordionRepairs,0);

console.log(JSON.stringify({
  contract:'mad4b.rate-accordion-vendor-fallback-v2.4.offline-v1',
  scenarios:6,
  duplicate_native_handlers:0,
  bounded_retry_delays_ms:[80,400,1100],
  no_site_runtime_loaded:true,
  browser_acceptance_completed:false,
  state:'PASS'
}));
