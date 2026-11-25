// Exercise the actual send function with deterministic transport and timers.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname,'../assets/js/ajax-filter-state.js'),'utf8');
const send = source.slice(source.indexOf('    function invalidatePresentationRequest(key)'),source.indexOf('    function schedule(provider, queryId)'));
async function main() {
    const events=[], requests=[], timers=new Map(); let nextTimer=0, semantic=true;
    class Controller { constructor(){this.signal={aborted:false};} abort(){this.signal.aborted=true;} }
    const key='jet-engine/tours';
    const window={location:{pathname:'/tours/'},JetSmartFilters:{filterGroups:{[key]:{}}},setTimeout(fn){timers.set(++nextTimer,fn);return nextTimer;},clearTimeout(id){timers.delete(id);}};
    const c={window,AbortController:Controller,controllers:{},sequences:{},initialSemanticKeys:{},nativePending:{},prepared:{},timers:{},eventSeenKeys:{},userFilterGesture:true,cfg:{endpoint:'/dfsb'},requestTimeoutMs:8000,
        jsfRuntimeContract:()=>({ready:true}),groupKey:(p,q)=>p+'/'+q,effectiveCurrentQuery:()=>semantic?{s:'Cairo'}:{},queryHasSemanticFilters:q=>!!q.s,
        bindings:()=>({tokens:['title'],slots:[]}),baseArchivePath:p=>p,syncAutoBindings:()=>key,
        emitDFSBEvent:(name,detail)=>events.push({name,detail}),restoreInitial:()=>events.push({name:'reset'}),clearTransient:()=>events.push({name:'reset'}),
        blockFailClosed:reason=>events.push({name:'blocked',reason}),applyResponse:data=>events.push({name:'updated',data}),readHttpResponse:response=>response,
        fetch:(url,options)=>new Promise(resolve=>requests.push({resolve,options})),runtimeBlockedSignature:''};
    vm.createContext(c);vm.runInContext(send,c);
    const drain=async()=>{for(let i=0;i<14;i++)await Promise.resolve();};
    c.send('jet-engine','tours');await drain();
    assert.equal(events[0].name,'dfsb/ajax-presentation-start');
    const firstTimer=timers.get(1);
    c.send('jet-engine','tours');await drain();
    assert.equal(requests.length,2);assert.equal(requests[0].options.signal.aborted,true);
    firstTimer();await drain();assert.equal(requests[1].options.signal.aborted,false,'old deadline must not abort newer request');
    requests[1].resolve({ok:true,data:{value:'new'}});await drain();
    assert.equal(events.filter(e=>e.name==='updated').length,1);
    c.AbortController=undefined;c.send('jet-engine','tours');await drain();semantic=false;c.send('jet-engine','tours');
    requests[2].resolve({ok:true,data:{value:'stale'}});await drain();
    assert.equal(events.filter(e=>e.name==='updated').length,1,'cleared filters invalidate outstanding request without abort support');
    semantic=true;c.fetch=()=>{throw new Error('transport');};c.send('jet-engine','tours');await drain();
    assert.equal(events.at(-1).reason,'transport_error');
    c.fetch=(url,options)=>new Promise(resolve=>requests.push({resolve,options}));
    c.schedule=(p,q)=>c.send(p,q);c.scheduleKey=()=>{};
    let before=events.filter(e=>e.name==='updated').length;
    c.nativeStart('jet-engine','tours');await drain();let req=requests.at(-1),count=requests.length;req.resolve({ok:true,data:{value:'prepared'}});await drain();
    assert.equal(events.filter(e=>e.name==='updated').length,before,'no early DOM apply');
    c.nativeUpdated('jet-engine','tours');c.nativeEnd('jet-engine','tours');await drain();assert.equal(requests.length,count,'reuse completed request');assert.equal(events.filter(e=>e.name==='updated').length,++before);
    c.nativeStart('jet-engine','tours');await drain();req=requests.at(-1);count=requests.length;c.nativeUpdated('jet-engine','tours');c.nativeEnd('jet-engine','tours');req.resolve({ok:true,data:{value:'inflight'}});await drain();assert.equal(requests.length,count);assert.equal(events.filter(e=>e.name==='updated').length,++before,'inflight reused');
    c.nativeStart('jet-engine','tours');await drain();req=requests.at(-1);count=requests.length;window.location.pathname='/changed/';c.nativeUpdated('jet-engine','tours');c.nativeEnd('jet-engine','tours');await drain();assert.equal(requests.length,count+1,'changed path refetch');req.resolve({ok:true,data:{value:'stale'}});await drain();assert.equal(events.filter(e=>e.name==='updated').length,before);requests.at(-1).resolve({ok:true,data:{value:'fresh'}});await drain();before++;
    c.nativeStart('jet-engine','tours');await drain();req=requests.at(-1);c.nativeEnd('jet-engine','tours');req.resolve({ok:true,data:{value:'failed native'}});await drain();assert.equal(events.filter(e=>e.name==='updated').length,before,'failed native cannot apply');
    c.nativeStart('jet-engine','tours');await drain();req=requests.at(-1);count=requests.length;c.nativeStart('jet-engine','tours');c.nativeUpdated('jet-engine','tours');c.nativeEnd('jet-engine','tours');c.nativeUpdated('jet-engine','tours');c.nativeEnd('jet-engine','tours');await drain();assert.equal(requests.length,count+1,'overlap falls back after native settlement');req.resolve({ok:true,data:{value:'overlap stale'}});await drain();assert.equal(events.filter(e=>e.name==='updated').length,before);
    requests.at(-1).resolve({ok:true,data:{value:'overlap settled'}});await drain();before++;
    c.nativeStart('jet-engine','tours');await drain();req=requests.at(-1);const nativeDeadline=timers.get(c.nativePending[key].deadline);nativeDeadline();await drain();req.resolve({ok:true,data:{value:'late native'}});await drain();assert.equal(events.filter(e=>e.name==='updated').length,before);assert.equal(c.nativePending[key],undefined);assert.equal(events.at(-1).detail.reason,'native_jsf_lifecycle_timeout');
    console.log('PASS: request start, cancellation, exact prepared reuse, in-flight reuse, changed path, native failure, overlap and bounded native lifecycle');
}
main().catch(err=>{console.error(err);process.exitCode=1;});
