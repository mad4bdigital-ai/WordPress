'use strict';
// Browser-independent probe harness: one failed translation cannot starve
// healthy translations. The production Playwright implementation is executed
// unchanged with a deterministic test browser injected at require().
const fs=require('fs'),vm=require('vm'),os=require('os'),path=require('path');
const script=fs.readFileSync(path.join(process.argv[2],'tools/probe-public.js'),'utf8');
async function check(languages,good,expectedExit){
 const out=fs.mkdtempSync(path.join(os.tmpdir(),'mad4b-css-probe-'));
 const logs=[];
 const mock={
  chromium:{
   async launch(){return{
    async close(){},
    async newContext(){return{
     async close(){},
     async newPage(){return{
      async goto(url){
       const lang=url.endsWith('/')?url.split('/').filter(Boolean).at(-1):'en';
       const intended=url.endsWith('example')?'en':(url==='https://tours.dev.mad4b.com/'?'en':lang);
       if(!good.includes(intended))throw Error('BLOCKED_LOCALE_'+intended);
       return {status:()=>200,text:async()=>'<body><link href="/wp-content/litespeed/css/0123456789abcdef0123456789abcdef.css">',
        allHeaders:async()=>({'x-mad4b-mobile-css-ready':'0'})};
      }
     }}
    }}
   }}
  }
 };
 const processContext={env:{
   MAD4B_SOURCE_SITE:'https://tours.dev.mad4b.com',MAD4B_PROBE_OUTPUT:out,
   MAD4B_PROBE_LANGS:languages.join(','),MAD4B_DEFAULT_LANG:'en'
 },exitCode:0};
 const context={require:(x)=>x==='playwright'?mock:require(x),console:{
  log:(...a)=>logs.push(['log',...a]),warn:(...a)=>logs.push(['warn',...a]),
  error:(...a)=>logs.push(['error',...a])
 },process:processContext,Buffer};
 vm.runInNewContext(script,context,{timeout:1000});
 await new Promise(r=>setTimeout(r,80));
 const report=JSON.parse(fs.readFileSync(path.join(out,'locale-holds.json')));
 if(report.successful!==good.length ||report.held.length!==languages.length-good.length ||
    processContext.exitCode!==expectedExit)throw Error('PROBE_ASSERTION:'+JSON.stringify({languages,report,exitCode:processContext.exitCode,logs}));
 for(const l of good){if(!fs.existsSync(path.join(out,l+'.html')))throw Error('SUCCESSFUL_LOCALE_EVIDENCE_MISSING_'+l)}
 fs.rmSync(out,{force:true,recursive:true});
 console.log('PASS partial Chromium discovery '+languages.join(',')+' valid='+good.join(','));
}
(async()=>{
 await check(['en','zz'],['en'],0);
 await check(['zz','es'],['es'],0);
 await check(['zz','xy'],[],4);
 console.log('PASS 3/3 independent WPML Chromium discovery isolation cases');
})().catch(e=>{console.error(e);process.exit(1)});
