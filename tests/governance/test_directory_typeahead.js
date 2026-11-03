'use strict';
// Execute native Javelin source methods with a deterministic transport/DOM shim.
// Backend visibility/SQL is covered independently by the real native fixture.
const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const root=path.resolve(__dirname,'../..');let tick=0,requests=[],dataset=[];
global.__DEV__=true;
global.JX={now:()=>++tick,copy:Object.assign,bind:(self,fn,...args)=>fn.bind(self,...args),
  install(name,spec){const parent=spec.extend?this[spec.extend]:null;
    const ctor=function(...args){if(spec.construct)spec.construct.apply(this,args);};
    if(parent)ctor.prototype=Object.create(parent.prototype);
    Object.assign(ctor,spec.statics||{});Object.assign(ctor.prototype,spec.members||{});
    for(const [key,value]of Object.entries(spec.properties||{})){
      const cap=key[0].toUpperCase()+key.slice(1);ctor.prototype['get'+cap]=function(){return Object.hasOwn(this,'p_'+key)?this['p_'+key]:value;};
      ctor.prototype['set'+cap]=function(v){this['p_'+key]=v;return this;};}
    ctor.prototype.invoke=function(event,nodes){if(event==='resultsready')this.hits=nodes;};this[name]=ctor;},
  Request:function(uri,callback){this.setMethod=()=>{};this.setData=data=>{this.data=data;};
    this.send=()=>{requests.push({...this.data});callback(dataset.filter(x=>x[0].toLocaleLowerCase().includes(this.data.raw.toLocaleLowerCase())));};}};
for(const file of ['webroot/rsrc/externals/javelin/lib/control/typeahead/normalizer/TypeaheadNormalizer.js',
 'webroot/rsrc/externals/javelin/lib/control/typeahead/source/TypeaheadSource.js',
 'webroot/rsrc/externals/javelin/lib/control/typeahead/source/TypeaheadOnDemandSource.js','webroot/rsrc/js/core/Prefab.js']){
 vm.runInThisContext(fs.readFileSync(path.join(root,file),'utf8'),{filename:file});}
function source(mode){const s=new JX.TypeaheadOnDemandSource('/fixture');s.setNormalizer(JX.Prefab.getTokenizerNormalizer(mode));s.createNode=x=>x.id;return s;}
dataset=[['Ops_Alpha','/a','underscore'],['OpsBeta','/b','plain'],['Ops%Gamma','/c','percent'],['Ops-Team','/d','hyphen']];
// Reproduce old cache collision with the actual unchanged native default.
let s=source('default');requests=[];s.didChange('Ops_',true);s.didChange('Ops',true);
assert.equal(requests.length,1);assert.deepEqual(s.hits,['underscore']);
// Fixed directory mode: distinct cache keys and literal matching of accumulated rows.
s=source('literal-casefold-v1');requests=[];
for(const [q,expected]of [['Ops_',['underscore']],['Ops',['plain','percent','hyphen','underscore']],
 ['Ops_',['underscore']],['Ops%',['percent']],['Ops-',['hyphen']],['Ops',['plain','percent','hyphen','underscore']]]){
 s.didChange(q,true);assert.deepEqual([...s.hits].sort(),[...expected].sort());}
assert(requests.some(x=>x.q==='ops'&&x.raw==='Ops'));
assert(requests.some(x=>x.q==='ops_'&&x.raw==='Ops_'));
assert.equal(s.normalize(' A - _ %  B '),' a - _ %  b ');
assert.equal(s.normalize('I'),'i');
assert.equal(JX.Prefab.getTokenizerNormalizer(undefined),JX.TypeaheadNormalizer.normalize);
assert.equal(JX.Prefab.getTokenizerNormalizer('default'),JX.TypeaheadNormalizer.normalize);
assert.throws(()=>JX.Prefab.getTokenizerNormalizer('arbitrary-function'));
console.log(JSON.stringify({passed:true,native_cache_regression_reproduced:true,directory_literal_mode_verified:true,browser_proved:false}));
