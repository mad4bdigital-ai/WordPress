'use strict';
const fs=require('fs'),path=require('path'),crypto=require('crypto');
const out=process.env.MAD4B_OUTPUT||'/out';
const sha=x=>crypto.createHash('sha256').update(x).digest('hex');

function unionRanges(ranges){
 ranges=ranges.filter(x=>Number.isInteger(x.start)&&Number.isInteger(x.end)&&x.end>x.start).sort((a,b)=>a.start-b.start);
 let r=[];for(const x of ranges){let p=r.at(-1);if(p&&x.start<=p.end){p.end=Math.max(p.end,x.end)}else r.push({start:x.start,end:x.end});}return r;
}
function assemble(source,rangeSet){
 const used=unionRanges(rangeSet);let atRules=0,selected=0,all=0,failed=0;
 function inUse(a,b){
  let lo=0,hi=used.length;
  while(lo<hi){let m=(lo+hi)>>1;if(used[m].end<=a)lo=m+1;else hi=m;}
  return lo<used.length&&used[lo].start<b;
 }
 function delimiter(start,limit,chars){
  let quote='',comment=false,parens=0, i=start;
  for(;i<limit;i++){
   let c=source[i],n=source[i+1];
   if(comment){if(c==='*'&&n==='/'){comment=false;i++;}continue;}
   if(quote){if(c==='\\'){i++;continue;}if(c===quote)quote='';continue;}
   if(c==='/'&&n==='*'){comment=true;i++;continue;}
   if(c==='"'||c==="'"){quote=c;continue;}
   if(c==='\\'){i++;continue;}
   if(c==='('||c==='['){parens++;continue;}
   if(c===')'||c===']'){parens=Math.max(0,parens-1);continue;}
   if(!parens&&chars.includes(c))return {pos:i,kind:c};
  }
  return {pos:limit,kind:''};
 }
 function close(open,limit){
  let depth=1,comment=false,quote='';
  for(let i=open+1;i<limit;i++){
   let c=source[i],n=source[i+1];
   if(comment){if(c==='*'&&n==='/'){comment=false;i++;}continue;}
   if(quote){if(c==='\\'){i++;continue;}if(c===quote)quote='';continue;}
   if(c==='/'&&n==='*'){comment=true;i++;continue;}
   if(c==='"'||c==="'"){quote=c;continue;}
   if(c==='\\'){i++;continue;}
   if(c==='{')depth++;
   if(c==='}'&&--depth===0)return i;
  }
  return limit;
 }
 function scan(start,limit){
  let result=[];
  for(let i=start;i<limit;){
   let {pos,kind}=delimiter(i,limit,';{}');
   if(!kind)break;
   if(kind==='}'){i=pos+1;continue;}
   const header=source.slice(i,pos).trim();
   if(kind===';'){
    all++;
    if(header&&inUse(i,pos+1)){result.push(source.slice(i,pos+1));selected++;}
    i=pos+1;continue;
   }
   let end=close(pos,limit);
   if(end>=limit){failed++;break;}
   all++;
   if(!header){i=end+1;continue;}
   if(/^@(?:media|supports|container|layer|scope|document)\b/i.test(header)){
    const contents=scan(pos+1,end);
    if(contents.length){result.push(source.slice(i,pos+1)+contents.join('\n')+'}');atRules++;}
   }else if(/^@font-face\b/i.test(header)||inUse(i,end+1)){
    result.push(source.slice(i,end+1));selected++;
   }
   i=end+1;
  }
  return result;
 }
 const css=scan(0,source.length).join('\n');
 return {css,stats:{sourceBytes:Buffer.byteLength(source),usedRanges:used.length,selected,atRules,all,failed}};
}
module.exports={assemble,unionRanges};
if(require.main===module){
const index=JSON.parse(fs.readFileSync(path.join(out,'capture-index.json'),'utf8'));
let candidates=[],errors=[];
const groups={};
for(const row of index.scenarios){
 if(row.error){errors.push(row);continue;}
 const key=row.lang+'|'+row.css_file;
 (groups[key]??=[]).push(row);
}
for(const [key,rows]of Object.entries(groups)){
 const [lang,cssFile]=key.split('|');
 const file=path.join(out,rows[0].css_text_file);
 const source=fs.readFileSync(file,'utf8');
 let allRanges=[];for(const row of rows) allRanges.push(...JSON.parse(fs.readFileSync(path.join(out,row.ranges_file),'utf8')));
 const {css,stats}=assemble(source,allRanges);
 if(stats.failed||css.length<16000||Buffer.byteLength(css)>1048576||css.includes('</style')){
  errors.push({lang,cssFile,reason:'invalid assembled CSS',stats});continue;
 }
 const outHash=sha(css),outputFile=outHash+'.css';
 fs.writeFileSync(path.join(out,outputFile),css);
 const pageIds=[...new Set(rows.map(r=>r.page_id))];
 const docHash=[...new Set(rows.map(r=>r.dom_sha))];
 const sourceHash=[...new Set(rows.map(r=>r.source_sha))];
 const resolvedCurrency=[...new Set(rows.map(r=>r.resolved_currency).filter(Boolean))];
 const resolvedMarket=[...new Set(rows.map(r=>r.resolved_market).filter(Boolean))];
 if(resolvedCurrency.length>1 || resolvedMarket.length>1){
    errors.push({lang,cssFile,reason:'WCML country/currency changed between viewport captures'});continue;
 }
 if(pageIds.length!==1||docHash.length!==1||sourceHash.length!==1){errors.push({lang,cssFile,reason:'inconsistent viewport sources',pageIds,docHash,sourceHash});continue;}
 let item={lang,route:rows[0].uri,device:'mobile',widths:rows.map(r=>r.width),css_file:cssFile,...(resolvedCurrency.length?{resolved_currency:resolvedCurrency[0]}:{}),
    ...(resolvedMarket.length?{resolved_market:resolvedMarket[0]}:{}),source_sha:sourceHash[0],source_bytes:rows[0].source_bytes,page_id:pageIds[0],dom_sha:docHash[0],critical_file:outputFile,critical_sha:outHash,critical_bytes:Buffer.byteLength(css),stats,gate_status:'candidate'};
 candidates.push(item);
 console.log('ASSEMBLE_OK',JSON.stringify({lang,widths:item.widths,sourceCss:item.css_file,criticalKiB:Math.round(item.critical_bytes/1024),hash:outHash.slice(0,12)}));
}
const manifest={contract:'mad4b.mobile-critical.candidates.v1',host:index.host,captured_at:index.at,candidates,errors};
fs.writeFileSync(path.join(out,'candidates.json'),JSON.stringify(manifest,null,2));
console.log('ASSEMBLE_DONE',JSON.stringify({candidates:candidates.length,errors:errors.length}));
if(errors.length)process.exitCode=2;

}
