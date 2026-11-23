const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const path=require('node:path');
const source=fs.readFileSync(process.argv[2]||path.resolve(__dirname,'../../wp-content/mu-plugins/assets/mad4b-tour-toc-native-20261118-mobile-layout.js'),'utf8');
function check(mode) {
 let reads=0,writes=[],observed=null,events={};
 const dock={getAttribute:()=>JSON.stringify({sticky:'bottom'}),querySelector:()=>({}),getBoundingClientRect:()=>{reads++;return {height:90.73}},classList:{add:()=>{}}};
 const body={dataset:{},classList:{contains:x=>x==='single-tours-and-activities',add:()=>{}},style:{setProperty:(k,v)=>writes.push([k,v])}};
 const document={body,readyState:'complete',querySelectorAll:sel=>sel.startsWith('.elementor-location-single')?[dock]:[],addEventListener:()=>{}};
 const window={addEventListener:(k,v)=>events[k]=v,requestAnimationFrame:cb=>{cb();return 1},scrollY:0};
 const sandbox={window,document,JSON,Math,Array};
 if(mode!=='no-ro'){
  class ResizeObserver{constructor(cb){this.cb=cb;observed=this;}observe(el,opts){if(mode==='ro-legacy' && opts)throw Error('unsupported-box'); if(el!==dock)throw Error('wrong-dock'); this.cb([{borderBoxSize:mode==='ro-legacy'?undefined:[{blockSize:90.73}]}]);}}
  sandbox.ResizeObserver=ResizeObserver;window.ResizeObserver=ResizeObserver;
 }
 vm.runInNewContext(source,sandbox);
 assert.equal(body.dataset.mad4bTourDockBound,'1');
 assert.equal(writes.at(-1)?.[1],'91px');
 assert.equal(writes.length,1);
 if(mode==='modern'){
  assert.equal(reads,0);
  observed.cb([{borderBoxSize:[{blockSize:90.73}]}]);assert.equal(writes.length,1);
  observed.cb([{borderBoxSize:[{blockSize:110.1}]}]);assert.equal(writes.at(-1)[1],'111px');
  assert.equal(writes.length,2);
 }else{assert.ok(reads>=1);}
 console.log(mode,'pass',{reads,writes:writes.length});
}
['modern','ro-legacy','no-ro'].forEach(check);
