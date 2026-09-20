const fs=require('fs'), vm=require('vm');
const code=fs.readFileSync(require('path').join(__dirname,'..','assets','js','browser-acceptance-observer.js'),'utf8');
function run(authoritative){
  const itemNodes=[1,2,3].map(id=>({getAttribute:(k)=>k==='data-post-id'?String(id):null,textContent:''}));
  const countNode=authoritative?{getAttribute:(k)=>k==='data-etg-dfsb-result-count'?null:null,textContent:'3 results'}:null;
  const listeners={};
  const document={
    title:'Test',
    querySelectorAll:(sel)=> sel.includes('data-post-id') ? itemNodes : [],
    querySelector:(sel)=> {
      if (authoritative && sel.indexOf('jet-smart-filters-results-count__value')>=0) return countNode;
      return null;
    },
    addEventListener:(name,fn)=>{listeners[name]=fn;}
  };
  const window={
    location:{href:'https://example.test/archive/'},
    history:{pushState(){},replaceState(){}},
    setTimeout:(fn)=>fn(),
    JetSmartFilters:{filterGroups:{},events:{subscribe(){}}}
  };
  const context={window,document,URL,console}; vm.createContext(context); vm.runInContext(code,context);
  const o=window.ETGDFSBBrowserAcceptanceObserver;
  const arm=o.arm({case_id:'c',provider:'jet-engine',query_id:'q',taxonomy:'tax',term_slug:'x'},{nonce:'a'.repeat(32)});
  if(!arm.ok) throw new Error('arm failed');
  const snap=o.snapshot();
  return snap.rendered;
}
const fallback=run(false);
if(fallback.result_count_authoritative!==false||fallback.result_count_source!=='dom_item_count_fallback'||fallback.ids_complete!==false) throw new Error('fallback authority contract failed');
const authoritative=run(true);
if(authoritative.result_count_authoritative!==true||authoritative.result_count_source!=='jet_smart_filters_results_count'||authoritative.result_count!==3||authoritative.ids_complete!==true) throw new Error('authoritative count contract failed');
console.log('BROWSER_OBSERVER_MATRIX=PASS');
