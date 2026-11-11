/** Isolated Node/DOM-stub contract: no browser, network or WordPress mutation. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname,
  '../wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-source-rate-actions-20261106.js'),'utf8');
const alternate = fs.readFileSync(path.join(__dirname,
  '../wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-rate-widget-lifecycle-2.1.0-20261111.js'),'utf8');
assert.equal(source,alternate,'Both registered asset paths must carry identical reviewed code');
assert(source.includes("version:'2.1.0'"));
assert(!/\bnew\s+MutationObserver\s*\(/.test(source));
assert(!source.includes('setInterval('));

function scenario({jquery=true, preexistingHandler=false}={}) {
  const nativeEvents={click: preexistingHandler?[{namespace:'jetAccordion'}]:[]};
  const calls=[];
  const timeouts=[];
  const eventListeners=new Map();
  const control={};
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
      nativeEvents.click.push({namespace:'jetAccordion'});
    }}},
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
  return {window,calls,timeouts,events:nativeEvents,ajax};
}

let test=scenario();
assert.deepEqual(test.calls,['frontend/element_ready/jet-accordion.default']);
assert.equal(test.window.MAD4BRateActionLifecycleV2.inspect().accordionRepairs,1);
test.window.MAD4BRateActionLifecycleV2.refresh();
assert.equal(test.calls.length,1,'Native handler must not be registered twice');
assert.equal(typeof test.ajax,'function');
test.ajax();
assert.deepEqual(test.timeouts.map(t=>t.ms),[80,400,1100]);
for(const t of test.timeouts)t.cb();
assert.equal(test.calls.length,1,'Delayed refresh may not duplicate existing native handler');

test=scenario({preexistingHandler:true});
assert.equal(test.calls.length,0,'Never replace already-registered JetAccordion handler');
assert.equal(test.window.MAD4BRateActionLifecycleV2.inspect().accordionRepairs,0);

test=scenario({jquery:false});
assert.equal(test.calls.length,0,'Missing jQuery must not fake the vendor handler');
assert.equal(test.window.MAD4BRateActionLifecycleV2.inspect().accordionRepairs,0);

console.log(JSON.stringify({
  contract:'mad4b.rate-accordion-native-handler-lifecycle.offline-v1',
  scenarios:3,
  duplicate_native_handlers:0,
  bounded_retry_delays_ms:[80,400,1100],
  no_site_runtime_loaded:true,
  browser_acceptance_completed:false,
  state:'PASS'
}));
