'use strict';
const assert=require('node:assert/strict');
const {test}=require('node:test');
const fs=require('node:fs');
const smart=process.env.SMART_FILE;
const pricing=process.env.LAB_FILE;
const m=require(smart);
const source=fs.readFileSync(pricing,'utf8');

test('resort with one operational tier candidate unlocks editorial overnight kind, not checkout',()=>{
 const q={hotels:{sharm:{resort:{count:1,tiers:[2354],planning_only:true},
   hotel:{count:0}}},day_specific:{}};
 assert.equal(m.lookup(q,8,'sharm','resort',14),true);
 assert.equal(m.lookup(q,13,'sharm','resort',14),false);
 assert.equal(m.lookup(q,8,'cairo','resort',14),false);
});
test('unknown resort without tier eligible candidate remains unselectable',()=>{
 const q={hotels:{sharm:{resort:{count:0,planning_only:true}}},day_specific:{}};
 assert.equal(m.lookup(q,8,'sharm','resort',14),false);
});
test('client labels mark exploratory Resort as pending, never approved price',()=>{
 const str=fs.readFileSync(smart,'utf8');
 assert.ok(str.includes('Planning option only'));
 assert.ok(str.includes('Reservations Cost Review pending'));
 assert.ok(str.includes('editorialPlanning ?'));
});
test('Pricing Lab customer package totals cannot be derived from web market offers',()=>{
 assert.ok(source.includes('NO complete Package price or Starting From'));
 assert.ok(source.includes('NOT an approved 3-night offer'));
 assert.ok(source.includes('Draft-only'));
 assert.ok(source.includes('generate.disabled=true'));
 assert.ok(source.includes('direct_or_rate_price_source_review'));
 assert.ok(source.includes('tier_source'));
});
test('Pricing Lab preserves native supplier and Booking steps',()=>{
 for(const needle of ['Native Package Rate generator:','No approved Package Cost total','Supplier cost:', 'Reservations review and supplier net cost worksheet'])
   assert.ok(source.includes(needle),needle);
});

