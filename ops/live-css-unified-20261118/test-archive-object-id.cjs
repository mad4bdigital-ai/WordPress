'use strict';
const fs=require('fs'),path=require('path'),vm=require('vm');
const root=path.resolve(__dirname,'../dynamic-critical-css-20261116/tools');
const components=[
  {file:'capture.js',fn:'boundObject',end:'if(ROUTE &&',type:'ROUTE_TYPE',id:'ROUTE_ID'},
  {file:'probe-public.js',fn:'assertBodyIdentity',end:'if(PUBLIC_ROUTE &&',type:'PUBLIC_TYPE',id:'PUBLIC_OBJECT_ID'}
];
const types=['destinations','tour-types','property-types','tour-categories','category'];
function call(comp,type,id,html) {
 const s=fs.readFileSync(path.join(root,comp.file),'utf8');
 const start=s.indexOf('function '+comp.fn+'(html){'),end=s.indexOf('\n'+comp.end,start);
 if(start<0||end<0)throw Error('not-original-source-function');
 const fn=s.slice(start,end).trimEnd();
 const context={};
 vm.createContext(context);
 const script='const TERM_TYPES='+JSON.stringify(types)+';const '+comp.type+'='+JSON.stringify(type)+';const '+comp.id+'='+JSON.stringify(id)+';'+fn+'\n'+comp.fn+'('+JSON.stringify(html)+')';
 return vm.runInContext(script,context,{timeout:1000});
}
const html=classes=>'<!doctype html><html><body class="'+classes+'">Public</body></html>';
const cases=[
 ['category',2943,html('archive category category-interests category-2943'),true],
 ['category',1,html('archive category category-uncategorized category-1'),true],
 ['category',2943,html('archive category category-interests category-1 term-2943'),false],
 ['category',2943,html('archive category category-interests category-29430'),false],
 ['category',2943,html('archive category category-interests term-2943'),false],
 ['destinations',91,html('archive tax-destinations term-luxor term-91'),true],
 ['tour-types',777,html('archive tax-tour-types term-private term-777'),true],
 ['tour-types',777,html('archive tax-tour-types category-777 term-778'),false],
 ['page',691,html('page page-id-691 elementor-default'),true],
 ['post',777,html('single single-post postid-777'),true],
 ['tours-and-activities',777,html('single single-tours-and-activities postid-777'),true],
 ['tours-and-activities',777,html('single postid-7777'),false],
 ['archive:tours-and-activities',0,html('archive post-type-archive-tours-and-activities'),true],
 ['archive:tours-and-activities',0,html('archive post-type-archive-properties'),false],
 ['archive:tours-and-activities',3,html('archive post-type-archive-tours-and-activities'),false]
];
let count=0;
for (const c of components) for(const [type,id,body,expected] of cases){
 let actual=true;
 try{call(c,type,id,body)}catch(e){actual=false}
 if(actual!==expected)throw Error('FAIL '+c.file+' '+type+' '+id+' expected '+expected+' got '+actual);
 count++;
}
console.log('PASS_ORIGINAL_CAPTURE_AND_PROBE_WP_BODY_IDENTITY',count,'cases');
