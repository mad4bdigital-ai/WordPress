/* Original operational UI JS, evaluated with a small isolated DOM fixture.
   No browser network, mutation, production admin, jQuery or WP state. */
'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const source=fs.readFileSync(path.resolve(__dirname,'../wp-content/plugins/mad4b-site-control-plane/assets/operational-remediation-ui.js'),'utf8');
function node(id, dataset={}){
 const listeners={}; const classes=new Set();
 return {
  id,dataset,listeners,classes,tabIndex:0,attributes:{},textContent:'',
  hidden:false,focused:false,
  classList:{add(v){classes.add(v)},toggle(v,flag){if(flag)classes.add(v);else classes.delete(v)}},
  setAttribute(k,v){this.attributes[k]=v},
  focus(){this.focused=true},
  addEventListener(k,cb){listeners[k]=cb},
  querySelectorAll(){return []},
  closest(){return this.dataset.tabPanel?this:(this.parent||null)}
 };
}
function scenario(tabNames,hash='',expected){
 const tabs=tabNames.map(t=>node(t,{tab:t}));const panels=tabNames.map(t=>node('mad4b-pane-'+t,{tabPanel:t}));
 const root=node('mad4b-remediation');
 root.querySelectorAll=sel=>sel==='.mad4b-remediation-tab'?tabs:sel==='.mad4b-remediation-panel'?panels:[];
 const elements={};for(const p of panels)elements[p.id]=p; elements['mad4b-remediation']=root;
 const doc={readyState:'complete',getElementById(id){return elements[id]||null},addEventListener(){}};
 const handlers={};const window={location:{hash},history:{replaceState(_,__,href){window.location.hash=href}},addEventListener(n,cb){handlers[n]=cb}};
 vm.runInNewContext(source,{document:doc,window,encodeURIComponent,decodeURIComponent,console});
 assert(root.classes.has('is-enhanced'),'Enhanced state should attach only to a usable tab');
 const active=tabs.filter(t=>t.attributes['aria-selected']==='true');
 assert.strictEqual(active.length,1,'Exactly one tab must be accessible');
 assert.strictEqual(active[0].dataset.tab,expected,'Unexpected selected tab at initial hash');
 assert.deepStrictEqual(panels.filter(p=>p.classes.has('is-active')).map(p=>p.dataset.tabPanel),[expected],
  'Exactly the intended panel must be visible');
 assert.strictEqual(tabs.filter(t=>t.tabIndex===0).length,1,'Only active tab in keyboard tab order');
 const key={key:'ArrowRight',preventDefault(){this.prevented=true}};
 tabs[tabNames.indexOf(expected)].listeners.keydown(key);
 assert(key.prevented,'Keyboard navigation must prevent document scrolling');
 assert.strictEqual(tabs.filter(t=>t.attributes['aria-selected']==='true').length,1,'Keyboard leaves exactly one tab');
}
// Default overview may be absent as Company Profile/registered diagnostics grow.
scenario(['overview','routes'],'','overview');
scenario(['diagnostics','routes'],'','diagnostics');
scenario(['diagnostics','routes'],'#mad4b-pane-nonexistent','diagnostics');
scenario(['diagnostics','routes'],'#mad4b-pane-routes','routes');
// Related CSS is page-scoped, not a global WordPress/Elementor override.
// Verify progressive-enhancement gating and responsive accessibility shape.
const css=fs.readFileSync(path.resolve(__dirname,
 '../wp-content/plugins/mad4b-site-control-plane/assets/operational-remediation-ui.css'),'utf8');
assert(css.includes('#mad4b-remediation.is-enhanced .mad4b-remediation-panel:not(.is-active){display:none}'),
 'Inactive sections must only disappear AFTER successful JS enhancement');
assert(css.includes('[aria-selected="true"]') && css.includes('focus-visible'),
 'Selected tab and keyboard focus need visible styling');
assert(css.includes('prefers-reduced-motion:reduce'),
 'Reduced-motion setting must be honored');
assert(!/@import\b|url\s*\(|expression\s*\(/i.test(css),
 'Page-scoped diagnostic CSS must not load external resources or evaluate expressions');
assert.strictEqual((css.match(/\{/g)||[]).length,(css.match(/\}/g)||[]).length,
 'CSS braces must be balanced');
for(const line of css.split(/\r?\n/).map(x=>x.trim()).filter(Boolean)){
 if(line.startsWith('/*')||line.startsWith('*/')||line.startsWith('*'))continue;
 assert(line.startsWith('#mad4b-remediation')||line.startsWith('@media('),
  'No global styles are allowed outside the operational diagnostic root: '+line.slice(0,90));
 if(line.startsWith('@media(')) {
  const cssRule=line.slice(line.indexOf('){')+2);
  assert(cssRule.includes('#mad4b-remediation'),
   'Responsive rules must also remain scoped to the original admin page');
 }
}
console.log('PASS: OPERATIONAL_REMEDIATION_TABS default/hash/keyboard tabs and scoped CSS');
