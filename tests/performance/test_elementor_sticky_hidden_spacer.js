'use strict';
/**
 * Hermetic test of the *actual function body* in our Elementor Sticky
 * lifecycle asset; not a copy of the conditional implementation.
 */
const fs=require('fs'),path=require('path'),assert=require('assert/strict'),crypto=require('crypto');
const expectedName='mad4b-elementor-sticky-lifecycle-9ebb20e0bb022722.js';
const asset=process.env.MAD4B_STICKY_ASSET_TEST_FILE ||
  path.join(__dirname,'../../wp-content/mu-plugins/assets',expectedName);
const source=fs.readFileSync(asset,'utf8');
const digest=crypto.createHash('sha256').update(source).digest('hex');
assert.equal(digest.slice(0,16),'9ebb20e0bb022722','content address matches filename');
const start=source.indexOf('var addSpacer = function() {');
const end=source.indexOf('var removeSpacer = function()',start);
assert(start>=0 && end>start);
const block=source.slice(start,end);
assert.equal(block.includes("$element.clone( false, false ).empty()"),true);
const run=new Function('$element','elements','settings','getComputedStyle',
  block+'\naddSpacer();return elements.$spacer;');
function el(children,display,hasForm=false) {
  const node={
    display,
    querySelector(selector){return hasForm && selector==='form.fluent_form_6' ? {}:null},
    getBoundingClientRect(){return {height:852,width:411}}
  };
  return {
    0:node,children,classes:[],style:{},appended:null,
    clone(){return el(this.children,this[0].display,hasForm)},
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
for (const display of ['flex','none']) {
 const withForm=el(1457,display,true),state={};
 const spacer=run(withForm,state,settings,node=>({display:node.display}));
 assert.equal(spacer.children,0,'must not duplicate form input subtree');
 assert.equal(withForm.children,1457,'real Form 6 stays intact');
 if (display==='flex') {
   assert.equal(spacer.style.height,'852px','sticky layout exact border box height');
   assert.equal(spacer.style.minHeight,'852px','prevent sidebar collapse');
   assert.equal(spacer.style.boxSizing,'border-box');
 } else {
   assert.equal(spacer.style.height,undefined,'hidden responsive branch stays zero height');
 }
 console.log('PASS '+display+' with Form6: spacer is empty with correct height');
}
console.log('ALL_STICKY_HIDDEN_SPACER_TESTS_PASS');
