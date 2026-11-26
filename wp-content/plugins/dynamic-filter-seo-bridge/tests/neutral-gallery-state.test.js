const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname,'../assets/js/ajax-filter-state.js'),'utf8');
const start = source.indexOf('    function readGalleryAttribute(');
const end = source.indexOf('    function elementGroup(',start);
assert.ok(start>=0&&end>start);
const ctx={initialValues:new WeakMap(),boundGroups:new WeakMap()};
vm.createContext(ctx);vm.runInContext(source.slice(start,end),ctx);
function el(attrs){return {attrs:{...attrs},innerHTML:'initial',textContent:'initial',style:{backgroundImage:''},hasAttribute(k){return Object.hasOwn(this.attrs,k);},getAttribute(k){return this.attrs[k]??null;},setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];}};}
const old='data-etg-dfsb-gallery',modern='data-dfsb-gallery';
for(const [attrs,expected] of [[{[old]:'[1]'},'[1]'],[{[modern]:'[2]'},'[2]'],[{[old]:'[3]',[modern]:'[4]'},'[3]'],[{},null]]){
 const node=el(attrs);assert.equal(ctx.readGalleryAttribute(node),expected);ctx.remember(node);
 ctx.writeGalleryAttribute(node,'[5]');assert.equal(node.getAttribute(old),'[5]');assert.equal(node.getAttribute(modern),'[5]');
 ctx.writeGalleryAttribute(node,'[]');assert.equal(node.getAttribute(old),'[]');assert.equal(node.getAttribute(modern),'[]');
 ctx.restoreElement(node);assert.equal(node.getAttribute(old),expected);assert.equal(node.getAttribute(modern),expected);
}
const bg=fs.readFileSync(path.join(__dirname,'../assets/js/container-dynamic-background.js'),'utf8');const a=bg.indexOf('    function readGallery(el)'),b=bg.indexOf('    function safeBackgroundUrl',a);const bctx={uniqueGallery:x=>x};vm.createContext(bctx);vm.runInContext(bg.slice(a,b),bctx);assert.equal(JSON.stringify(bctx.readGallery(el({[modern]:'[2]'}))),'[2]');assert.equal(JSON.stringify(bctx.readGallery(el({[old]:'[]',[modern]:'[2]'}))),'[]');
assert.ok(!source.includes("'fallback','gallery','attachment-id'"),'mutable state must not be resurrected by normalization');
const id='data-dfsb-attachment-id',oldId='data-etg-dfsb-attachment-id';
const node=el({[id]:'12','data-dfsb-live-html':'1'});ctx.remember(node);
ctx.writePresentationAttribute(node,'attachment-id','34');assert.equal(node.getAttribute(id),'34');assert.equal(node.getAttribute(oldId),'34');
ctx.writePresentationAttribute(node,'attachment-id',null);ctx.restoreElement(node);assert.equal(node.getAttribute(id),'12');assert.equal(node.getAttribute(oldId),'12');assert.equal(node.getAttribute('data-dfsb-live-html'),null);
const valueStart=source.indexOf('    function applyValue('),valueEnd=source.indexOf('    function blockFailClosed(',valueStart);ctx.emitDFSBEvent=()=>{};vm.runInContext(source.slice(valueStart,valueEnd),ctx);
ctx.applyValue(node,{type:'html',value:'<b>example</b>'});assert.equal(node.getAttribute('data-dfsb-live-html'),'1');ctx.applyValue(node,{type:'text',value:'example'});assert.equal(node.getAttribute('data-dfsb-live-html'),null);assert.equal(node.getAttribute('data-etg-dfsb-live-html'),null);assert.equal(node.getAttribute('data-dfsb-live-content'),'1');
node.setAttribute('data-etg-dfsb-media-target','src');ctx.applyMedia(node,{image:{url:'/example.jpg'}},'hero','group');assert.equal(node.getAttribute(id),null);assert.equal(node.getAttribute(oldId),null);ctx.applyMedia(node,{image:{id:56,url:'/new.jpg'}},'hero','group');assert.equal(node.getAttribute(id),'56');assert.equal(node.getAttribute(oldId),'56');ctx.applyMedia(node,{},'hero','group');assert.equal(node.getAttribute(id),'12');assert.equal(node.getAttribute(oldId),'12');
assert.ok(!source.includes("'fallback','attachment-id','live-content','live-html'"));
const events=[];ctx.emitDFSBEvent=(name,detail)=>events.push({name,detail});
for(const name of ['data-etg-dfsb-background-source','data-dfsb-background-source']){
 const container=el({[name]:'meta_field',[modern]:'[1]','data-dfsb-media-last-applied':'old'});
 let child={identity:'listing'};Object.defineProperty(container,'innerHTML',{get(){return '<div>listing</div>';},set(){throw Error('must not replace native child DOM');}});
 container.child=child;ctx.remember(container);ctx.writeGalleryAttribute(container,'[2]');ctx.restoreElement(container);
 assert.equal(container.child,child);assert.equal(container.getAttribute(modern),'[1]');assert.equal(container.getAttribute(old),'[1]');assert.equal(container.getAttribute('data-dfsb-media-last-applied'),null);
 assert.equal(events.at(-1).name,'dfsb/media-updated');assert.equal(JSON.stringify(events.at(-1).detail.gallery),'[1]');
}
const clearStart=source.indexOf('    function clearElement('),clearEnd=source.indexOf('    function clearTransient(',clearStart);
vm.runInContext(source.slice(clearStart,clearEnd),ctx);
const registered=el({'data-dfsb-background-source':'registered_slot','data-dfsb-background-fallback':'[{"url":"/fallback.jpg"}]',[modern]:'[{"url":"/previous.jpg"}]'});
ctx.clearElement(registered,'group');assert.equal(registered.getAttribute(modern),'[{"url":"/fallback.jpg"}]');assert.equal(registered.getAttribute(old),registered.getAttribute(modern));assert.equal(events.at(-1).detail.gallery[0].url,'/fallback.jpg');
const empty=el({'data-dfsb-background-source':'registered_slot',[modern]:'[1]'});ctx.clearElement(empty,'group');assert.equal(empty.getAttribute(modern),'[]');
const templateOwned=el({'data-dfsb-background-source':'registered_slot','data-dfsb-background-template-gallery':'[{"url":"/template.jpg"}]','data-dfsb-background-fallback':'[{"url":"/static.jpg"}]','data-etg-dfsb-media-target':'gallery',[modern]:'[{"url":"/initial-term.jpg"}]'});
ctx.remember(templateOwned);ctx.applyMedia(templateOwned,{gallery:[{url:'/selected.jpg'}]},'gallery','group');assert.equal(JSON.parse(templateOwned.getAttribute(modern))[0].url,'/selected.jpg');
ctx.applyMedia(templateOwned,{gallery:[]},'gallery','group');assert.equal(JSON.parse(templateOwned.getAttribute(modern))[0].url,'/template.jpg');assert.equal(events.at(-1).detail.gallery[0].url,'/template.jpg');
ctx.clearElement(templateOwned,'group');assert.equal(JSON.parse(templateOwned.getAttribute(modern))[0].url,'/template.jpg');ctx.restoreElement(templateOwned);assert.equal(JSON.parse(templateOwned.getAttribute(modern))[0].url,'/template.jpg');assert.equal(templateOwned.getAttribute(old),templateOwned.getAttribute(modern));
const noTemplate=el({'data-dfsb-background-source':'registered_slot','data-dfsb-background-template-gallery':'[]','data-etg-dfsb-media-target':'gallery',[modern]:'[1]'});ctx.remember(noTemplate);ctx.applyMedia(noTemplate,{gallery:[]},'gallery','group');assert.equal(noTemplate.getAttribute(modern),'[]');ctx.restoreElement(noTemplate);assert.equal(noTemplate.getAttribute(modern),'[]');
console.log('DFSB_NEUTRAL_LIVE_ATTRIBUTES: PASS');
console.log('DFSB_NEUTRAL_GALLERY_STATE: PASS (legacy, neutral, conflict, absent, update, clear, reset)');
