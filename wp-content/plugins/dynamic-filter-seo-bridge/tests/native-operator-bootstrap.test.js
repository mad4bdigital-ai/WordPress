'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/ajax-filter-state.js'), 'utf8');
const start = source.indexOf('    function configureNativeDirectLinkOperators() {');
const end = source.indexOf('\n    document.addEventListener(\'jet-smart-filters/before-init\'', start);
assert.ok(start >= 0 && end > start, 'native operator bootstrap must precede JSF before-init');

function run({query,valid = true, provider='jet-engine',queryId='tours_query_archive',options=['city-tours','cultural-tours'], initial='OR'}) {
    const attribute = {'data-query-var':'tour-styles','data-content-provider':provider,'data-query-id':queryId};
    if (initial === 'AND') attribute['data-relational-operator'] = 'AND';
    const el={
        getAttribute(name) {return attribute[name] || '';},
        setAttribute(name,value){attribute[name]=String(value);},
        removeAttribute(name){delete attribute[name];},
        querySelectorAll(){return options.map(slug=>({getAttribute(){return slug;}}));}
    };
    const group = {currentQuery: {unchanged: true}};
    const scope={
        prettyPathState: () => ({valid, group:'jet-engine/tours_query_archive',query:{'_tax_query_tour-styles':query}}),
        document:{querySelectorAll(){return [el];}},
        window:{JetSmartFilters:{filterGroups:{'jet-engine/tours_query_archive':group}}}
    };
    vm.runInNewContext(source.slice(start,end)+'\nthis.runBootstrap = configureNativeDirectLinkOperators;',scope);
    scope.runBootstrap();
    return {operator: attribute['data-relational-operator'] || null,query:group.currentQuery};
}

test('explicit AND enables the native checkbox operator without editing currentQuery',()=>{
    const result=run({query:['city-tours','cultural-tours','operator_AND']});
    assert.equal(result.operator,'AND');
    assert.deepEqual(result.query,{unchanged:true});
});
test('legacy Staging comma-string parser also enables native AND',()=>{
    assert.equal(run({query:'city-tours,cultural-tours,operator_AND'}).operator,'AND');
});
test('legacy explicit IN remains OR and malformed slugs are ignored',()=>{
    assert.equal(run({query:'city-tours,cultural-tours,operator_IN',initial:'AND'}).operator,null);
    assert.equal(run({query:'city-tours,cultural-tours,unknown,operator_XOR'}).operator,null);
    assert.equal(run({query:'city-tours,,operator_AND'}).operator,null);
});
test('explicit IN remains a native OR request',()=>{
    assert.equal(run({query:['city-tours','cultural-tours','operator_IN'],initial:'AND'}).operator,null);
});
test('single value, unknown term, invalid route cannot enable AND',()=>{
    assert.equal(run({query:['city-tours','operator_AND']}).operator,null);
    assert.equal(run({query:['city-tours','missing','operator_AND']}).operator,null);
    assert.equal(run({query:['city-tours','cultural-tours','operator_AND'],valid:false}).operator,null);
});
test('unrelated provider, queryId, and duplicate terms are ignored',()=>{
    assert.equal(run({query:['city-tours','cultural-tours','operator_AND'],provider:'epro-archive'}).operator,null);
    assert.equal(run({query:['city-tours','cultural-tours','operator_AND'],queryId:'other'}).operator,null);
    assert.equal(run({query:['city-tours','city-tours','operator_AND']}).operator,null);
});
test('malformed operator does not acquire privileged native AND treatment',()=>{
    assert.equal(run({query:['city-tours','cultural-tours','operator_XOR']}).operator,null);
});
