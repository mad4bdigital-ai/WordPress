// Qualification of native hierarchy membership and additional-provider proxies.
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/jsf-native-rebind.js'),'utf8');
const start=source.indexOf('    function compositeKind('),end=source.indexOf('    function sameFilter(',start);
const reports=[];const ctx={lastCompositeReport:'[]',window:{jQuery:el=>({data:()=>el.providers})},document:{dispatchEvent:e=>reports.push(e.detail)},CustomEvent:class{constructor(type,opts){this.type=type;this.detail=opts.detail;}}};vm.createContext(ctx);vm.runInContext(source.slice(start,end),ctx);
function el(hierarchy,providers,connected=true){const attrs={'data-filter-id':'42','data-content-provider':'jet-engine','data-query-id':'custom'};return {nodeType:1,isConnected:connected,providers,matches:s=>s==='.jet-smart-filters-hierarchy'?hierarchy:!!providers,querySelector:s=>s==='[data-additional-providers]'?(providers?{}:null):{getAttribute:k=>attrs[k]}};}
const old=el(true,['other-provider/other'],false),fresh=el(true,['other-provider/other']);const instance={filters:[]};
for(let i=0;i<3;i++)instance.filters.push({filterId:42,isHierarchy:true,depth:i,hierarchicalInstance:instance,$container:[old],path:'native/path/'+i,queryKey:'tax-'+i});
const proxies=instance.filters.map(f=>({isAdditional:true,filterId:42,isHierarchy:true,depth:f.depth,path:f.path,queryKey:f.queryKey,provider:'other-provider',queryId:'other'}));
const j={filterGroups:{'jet-engine/custom':{filters:instance.filters},'other-provider/other':{filters:proxies}}};
const observe=()=>{ctx.observeComposites(j,[fresh]);return reports.at(-1).rows[0];};
let row=observe();assert.equal(row.reason,'native_disposal_not_qualified');assert.equal(row.hierarchy_levels,3);assert.equal(row.authorizing,false);assert.equal(row.read_only,true);assert.deepEqual(Array.from(row.groups),['jet-engine/custom','other-provider/other']);assert.equal(ctx.identity(fresh),null);assert.equal(reports.length,1);observe();assert.equal(reports.length,1,'unchanged observations deduplicated');
for(const f of instance.filters)f.$container=[fresh];row=observe();assert.equal(row.status,'observed_live');assert.equal(row.reason,'native_membership_observed');
proxies.pop();row=observe();assert.equal(row.reason,'additional_proxy_membership_unverified');assert.equal(row.status,'deferred');
j.filterGroups['other-provider/other'].isAjaxLoading=true;assert.equal(observe().reason,'native_group_loading');j.filterGroups['other-provider/other'].isAjaxLoading=false;
instance.filters[1].depth=0;assert.equal(observe().reason,'hierarchy_membership_unverified');instance.filters[1].depth=1;
fresh.providers=['unknown/other'];assert.equal(observe().reason,'additional_group_unavailable');
assert.equal(j.filterGroups['jet-engine/custom'].filters.length,3,'native registry untouched');assert.equal(proxies.length,2,'proxy registry untouched');
console.log('PASS: composite native hierarchy/proxy observation, ambiguity, loading, dedup and read-only deferral');
