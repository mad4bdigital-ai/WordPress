'use strict';
const assert=require('node:assert/strict');
const fs=require('fs');
const original=fs.readFileSync;
try {
 fs.readFileSync=function(path,...rest) {
   if(String(path).includes('capture-index.json'))
     throw new Error('INVALID_LEGACY_CAPTURE_INDEX_READ_DURING_REQUIRE');
   return original.call(this,path,...rest);
 };
 const {assemble,unionRanges}=require('./assemble.js');
 let count=0;
 function ok(expr){assert.ok(expr);count++}
 ok(typeof assemble==='function');
 ok(typeof unionRanges==='function');
 const input='.a{color:red}.b{color:blue}@media(max-width:767px){.c{display:block}.d{display:none}}';
 const a={start:0,end:input.indexOf('}')+1};
 const cs=input.indexOf('.c{'),cc={start:cs,end:input.indexOf('}',cs)+1};
 const out=assemble(input,[a,cc]);
 ok(out.css.includes('.a{color:red}')&&out.css.includes('.c{display:block}'));
 ok(!out.css.includes('.b{color:blue}')&&!out.css.includes('.d{display:none}'));
 ok(out.stats.failed===0);
 ok(assemble(input,[]).css==='');
 ok(unionRanges([{start:0,end:3},{start:2,end:5}]).length===1);
 console.log('PASS '+count+'/7 ordered assembler import-without-legacy-file tests');
} finally {fs.readFileSync=original;}
