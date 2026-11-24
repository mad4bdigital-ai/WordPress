'use strict';
/**
 * Hermetic test of the *actual function body* in our Elementor Sticky
 * lifecycle asset; not a copy of the conditional implementation.
 */
const fs=require('fs'),path=require('path'),assert=require('assert/strict'),crypto=require('crypto');
const expectedName='mad4b-elementor-sticky-lifecycle-bc8f3f18efcfa4a1.js';
const asset=process.env.MAD4B_STICKY_ASSET_TEST_FILE ||
  path.join(__dirname,'../../wp-content/mu-plugins/assets',expectedName);
const source=fs.readFileSync(asset,'utf8');
const digest=crypto.createHash('sha256').update(source).digest('hex');
assert.equal(digest.slice(0,16),'bc8f3f18efcfa4a1','content address matches filename');
const start=source.indexOf('var addSpacer = function() {');
const end=source.indexOf('var removeSpacer = function()',start);
assert(start>=0 && end>start);
const block=source.slice(start,end);
assert.equal(block.includes("$element.clone( false, false ).empty()"),true);
const run=new Function('$element','elements','settings','getComputedStyle',
  block+'\naddSpacer();return elements.$spacer;');
function el(children,display) {
  return {
    0:{display},children,classes:[],style:{},appended:null,
    clone(){return el(this.children,this[0].display)},
    empty(){this.children=0;return this},
    addClass(name){this.classes.push(name);return this},
    css(css){Object.assign(this.style,css);return this},
    after(element){this.appended=element;return this}
  };
}
const settings={classes:{spacer:'elementor-sticky__spacer'}};
for(const [mode,expected] of [['none',0],['flex',1457],['block',1457]]) {
 const element=el(1457,mode),state={};
 const spacer=run(element,state,settings,node=>({display:node.display}));
 assert.strictEqual(state.$spacer,spacer);
 assert.equal(spacer.children,expected,mode+' nested form DOM');
 assert.equal(element.children,1457,'original Form 6 unaffected');
 assert.strictEqual(element.appended,spacer,'keeps Elementor spacer at same position');
 assert(spacer.classes.includes('elementor-sticky__spacer'));
 assert.equal(spacer.style.visibility,'hidden');
 assert.equal(spacer.style.transition,'none');
 console.log('PASS '+mode+' children='+spacer.children);
}
console.log('ALL_STICKY_HIDDEN_SPACER_TESTS_PASS');
