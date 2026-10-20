import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const source = readFileSync(process.argv[2], 'utf8');
const start = source.indexOf('key:"getPageUrl",value:function(e){');
assert.ok(start >= 0, 'real pagination method exists');
const first = source.indexOf('{', start);
let depth=1, end=first+1;
for (; end<source.length && depth; end++) {
  if(source[end]==='{') depth++;
  if(source[end]==='}') depth--;
}
const paginate = new Function('e', source.slice(first+1, end-1));
const cases = [
  ['', '/fr/tours/jsf/jet-engine:archive/pagenum/2/', ''],
  ['?jet_search=Cairo&jet_ajax_search_categories=0', '/fr/tours/jsf/jet-engine:archive/pagenum/2/', '?jet_search=Cairo&jet_ajax_search_categories=0'],
  ['jsf/jet-engine:archive/tax/location:egypt/?jet_search=Cairo#results', '/fr/tours/jsf/jet-engine:archive/tax/location:egypt/pagenum/2/', '?jet_search=Cairo'],
  ['?q=https%3A%2F%2Fexample.test%2Fpagenum%2F2%2F', '/fr/tours/jsf/jet-engine:archive/pagenum/2/', '?q=https%3A%2F%2Fexample.test%2Fpagenum%2F2%2F'],
];
for(const [state,path,query] of cases){
  const url=new URL(paginate.call({filterGroup:{siteUrl:'https://example.test',baseUrl:'/fr/tours/',urlType:'permalink'},provider:'jet-engine',queryId:'archive',currentUrlParams:state},2));
  assert.equal(url.pathname,path);assert.equal(url.search,query);
  assert.equal(url.hash,state.includes('#')?'#results':'');
  console.log('PASS pagination state',state||'(empty)');
}
const plain=paginate.call({filterGroup:{siteUrl:'https://example.test',baseUrl:'/tours/',urlType:'plain'},provider:'jet-engine',queryId:'default',currentUrlParams:'?jet_search=Cairo&jsf=jet-engine'},3);
assert.equal(new URL(plain).searchParams.get('jet_search'),'Cairo');
assert.equal(new URL(plain).searchParams.get('pagenum'),'3');
console.log('PASS plain URL search preservation');
