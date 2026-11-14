'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const source=fs.readFileSync(path.join(__dirname,'../../../wp-content/mu-plugins/assets/mad4b-elementor-device-mode-frame-cache-v1.js'),'utf8');
let checks=0;
function run(config={}) {
 const events=new Map(),docEvents=new Map();let frame=0,frames=new Map(),originalCalls=0;
 let state={width:820,height:844,pixelRatio:2.75,visible:'visible',edit:!!config.edit};
 const add=(map,k,cb)=>{const arr=map.get(k)||[];arr.push(cb);map.set(k,arr)};
 const context={window:{innerWidth:820,innerHeight:844,devicePixelRatio:2.75,addEventListener:(k,cb)=>add(events,k,cb),requestAnimationFrame(cb){frames.set(++frame,cb);return frame},cancelAnimationFrame(n){frames.delete(n)}},document:{visibilityState:'visible',addEventListener:(k,cb)=>add(docEvents,k,cb)},console};
 context.window.window=context.window;
 const api={getCurrentDeviceMode(){originalCalls++;return context.window.innerWidth<=767?'mobile':context.window.innerWidth<=991?'tablet':context.window.innerWidth<=1200?'tablet_extra':'desktop'},isEditMode(){return state.edit}};
 context.window.elementorFrontend=api;
 const runScript=()=>vm.runInNewContext(source,context);
 const trigger=(map,k)=>{for(const cb of map.get(k)||[])cb()};
 runScript();runScript();trigger(events,'elementor/frontend/init');
 const base=api.getCurrentDeviceMode();const again=api.getCurrentDeviceMode();checks++;assert.equal(base,'tablet');assert.equal(again,'tablet');
 if(config.edit){checks++;assert.equal(originalCalls,2);return}
 checks++;assert.equal(originalCalls,1,'same-frame native getter must execute once');
 checks++;assert.equal(context.window.__mad4bElementorDeviceModeFrameCacheV1.installed,true);
 for(const fn of [...frames.values()])fn();frames.clear();assert.equal(api.getCurrentDeviceMode(),'tablet');checks++;assert.equal(originalCalls,2);
 context.window.innerWidth=390;trigger(events,'resize');assert.equal(api.getCurrentDeviceMode(),'mobile');checks++;assert.equal(originalCalls,3);
 context.window.innerWidth=1024;trigger(events,'resize');assert.equal(api.getCurrentDeviceMode(),'tablet_extra');checks++;assert.equal(originalCalls,4);
 context.window.innerWidth=1440;trigger(events,'resize');assert.equal(api.getCurrentDeviceMode(),'desktop');checks++;assert.equal(originalCalls,5);
 context.window.devicePixelRatio=3.5;assert.equal(api.getCurrentDeviceMode(),'desktop');checks++;assert.equal(originalCalls,6);
 context.document.visibilityState='hidden';trigger(docEvents,'visibilitychange');api.getCurrentDeviceMode();api.getCurrentDeviceMode();checks++;assert.equal(originalCalls,8,'background tab must not cache');
 context.document.visibilityState='visible';trigger(docEvents,'visibilitychange');assert.equal(api.getCurrentDeviceMode(),'desktop');checks++;assert.equal(originalCalls,9);
 assert.equal(api.__mad4bOriginalDeviceModeMethod instanceof Function,true);checks++;
}
run();run({edit:true});console.log(`PASS ${checks}/${checks} Elementor frame cache (native truth, responsive, background, preview)`);
