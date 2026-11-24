const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');

const modulePath = process.env.MAD4B_OVERNIGHT_SCRIPT
  || path.resolve(__dirname, '../wp-content/mu-plugins/assets/mad4b-smart-itinerary-overnight-v1.js');
const { lookup, choices } = require(modulePath);

const base = {
  hotels:{
    aswan:{hotel:{count:1},resort:{count:0}},
    cairo:{hotel:{count:3},resort:{count:0}},
    luxor:{hotel:{count:0},resort:{count:0}},
  },
  day_specific: {
    '1':{cruise:{editorial_eligible:true},sleeper_train:{editorial_eligible:false}},
    '2':{cruise:{editorial_eligible:false},sleeper_train:{editorial_eligible:false}},
  }
};

test('approved Hotel in exact overnight city qualifies', () =>
  assert.equal(lookup(base, 0, 'aswan', 'hotel', 4), true));
test('Hotel from an absent city cannot be selected', () =>
  assert.equal(lookup(base, 0, 'hurghada', 'hotel', 4), false));
test('zero eligible Resorts means Resort is hidden from choices', () =>
  assert.equal(choices(base, 0, 4).types.has('resort'), false));
test('verified native cruise program is available only on its own day', () => {
  assert.equal(lookup(base, 1, 'nile_cruise', 'cruise', 4), true);
  assert.equal(lookup(base, 2, 'nile_cruise', 'cruise', 4), false);
});
test('a train line without supplier eligibility does not qualify an overnight', () =>
  assert.equal(lookup(base, 1, 'train', 'sleeper_train', 4), false));
test('None is eligible only on final departure day', () => {
  assert.equal(lookup(base, 3, 'none', 'none', 4), true);
  assert.equal(lookup(base, 0, 'none', 'none', 4), false);
  assert.equal(lookup(base, 3, 'aswan', 'hotel', 4), false);
});
test('an existing cruise does not qualify with an incompatible destination', () =>
  assert.equal(lookup(base, 1, 'cairo', 'cruise', 4), false));
test('valid options disappear when eligibility backend is absent', () =>
  assert.equal(lookup(null, 0, 'aswan', 'hotel', 4), false));
test('choices are scoped by day and never suggest nondeparture None', () => {
  assert.equal(choices(base, 1, 4).types.has('none'), false);
  assert.deepEqual([...choices(base, 3, 4).types], ['none']);
});
test('the backend contract remains read-only with no checkout authority', () => {
  const file=process.env.MAD4B_MATRIX_JSON;
  if (!file) return;
  const result=JSON.parse(fs.readFileSync(file,'utf8')).data;
  assert.equal(result.contract,'mad4b.editorial-overnight-smart-options.v1');
  assert.equal(result.checkout_authorized,false);
  assert.equal(result.inventory_verified,false);
  assert.ok(result.hotels.cairo.hotel.count>0);
  assert.ok(result.hotels.aswan.hotel.count>0);
  assert.equal(result.hotels.aswan.resort.count,0);
  assert.equal(result.day_specific['3'].cruise.editorial_eligible,true);
  assert.equal(result.day_specific['3'].sleeper_train.editorial_eligible,false);
});

