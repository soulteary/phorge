// Unit state-machine tests only. No browser, network, cookie or authentication proof.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../../webroot/rsrc/js/core/behavior-private-media-session.js'), 'utf8');
function fixture(ok = true, delayStage = null) {
  let behavior, listener, load, posts = [], requests = [], frames = [], alerts = [], retries = 0;
  let timeoutCallback, release, mutation, resourceError;
  const delayed = new Promise(resolve => { release = resolve; });
  const image = {tagName:'IMG', isConnected:true, style:{}, getAttribute: key => key === 'src' ? 'https://files.example.invalid/file/data/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/x.png' : null,
    setAttribute: () => retries++};
  const window = {location:{href:'https://app.example.invalid/T1'}, setTimeout:fn => {timeoutCallback=fn;return 1;}, clearTimeout(){},
    addEventListener:(name, fn) => { if(name === 'message') listener = fn; }, removeEventListener(){}};
  let nodes = [image];
  const document = {documentElement:{}, addEventListener:(name,fn) => {if(name==='error')resourceError=fn;},querySelectorAll:() => nodes, createElement:(tag) => {
    if(tag !== 'iframe') return {setAttribute(){},set textContent(value){alerts.push(value);}};
    const frame = {setAttribute(){}, remove(){}, addEventListener:(_, fn) => {load = fn;},
      contentWindow:{postMessage:(data, origin) => posts.push({data,origin})}}; frames.push(frame); return frame;
  }, body:{appendChild(){}}};
  vm.runInNewContext(source, {JX:{behavior:(_, fn) => behavior = fn},window,document,URL,URLSearchParams,Uint8Array,
    MutationObserver:class {constructor(fn){mutation=fn;}observe(){}disconnect(){}},
    crypto:{getRandomValues:a => {a.fill(1);return a;}},fetch:async(uri, init) => {
      requests.push({uri,init});if(delayStage === 'fetch'){await delayed;}
      return {ok,json:async() => {if(delayStage === 'json'){await delayed;}return {code:'b'.repeat(64)};}};}});
  const config = {app:'https://app.example.invalid',files:'https://files.example.invalid',max_path_length:4096,csrf_name:'__csrf__',csrf:'synthetic-app-only',failure:'Sign in again'};
  return {start:() => behavior(config),load:() => load(),message:async(data, origin=config.files, sender) => listener({data,origin,source:sender || frames[0].contentWindow}),
    frames,posts,requests,alerts,get retries(){return retries;},window,
    timeout:() => timeoutCallback(),release:() => release(),
    setNodes:value => {nodes=value;},image,mutation:() => mutation(),resourceError:() => resourceError({target:image})};
}
(async () => {
  const a = fixture(); a.start();a.start();assert.equal(a.frames.length,1);a.load();
  const nonce = a.posts[0].data.nonce;
  assert.equal(a.posts[0].origin,'https://files.example.invalid');
  assert.equal(JSON.stringify(a.posts).includes('synthetic-app-only'),false);
  const prepared = {type:'media-prepared',nonce,transaction:'a'.repeat(64)};
  await a.message(prepared,'https://evil.example.invalid');
  await a.message(prepared,undefined,{});assert.equal(a.requests.length,0);
  await a.message({...prepared,extra:'injected'});assert.equal(a.requests.length,0);
  await a.message(prepared);assert.equal(a.requests.length,1);
  assert.equal(a.requests[0].uri,'/auth/media/issue/');assert.equal(a.requests[0].init.redirect,'error');
  assert.equal(a.posts[1].data.type,'media-code');assert.equal(a.posts[1].origin,'https://files.example.invalid');
  await a.message({type:'media-ready',nonce});assert.equal(a.retries,1);
  await a.message({type:'media-ready',nonce});assert.equal(a.retries,1);
  const expired = fixture(false);expired.start();expired.load();
  await expired.message({type:'media-prepared',nonce:expired.posts[0].data.nonce,transaction:'c'.repeat(64)});
  expired.start();assert.equal(expired.requests.length,1);assert.equal(expired.frames.length,1);
  assert.equal(expired.retries,0);assert.equal(expired.alerts.length,1);
  for (const stage of ['fetch','json']) {
    const late = fixture(true,stage);late.start();late.load();
    const pending=late.message({type:'media-prepared',nonce:late.posts[0].data.nonce,transaction:'d'.repeat(64)});
    // Let the real async receive reach either the response or body await.
    await Promise.resolve();await Promise.resolve();
    late.timeout();late.release();await pending;
    assert.equal(late.window.__phorgePrivateMediaFlight.stage,'failed');
    assert.equal(late.posts.length,1);assert.equal(late.retries,0);assert.equal(late.alerts.length,1);
  }
  const dynamic = fixture();dynamic.setNodes([]);dynamic.start();
  assert.equal(dynamic.frames.length,0);
  await dynamic.message({type:'media-ready',nonce:'untrusted'},undefined,{});
  assert.equal(dynamic.requests.length,0);
  dynamic.setNodes([dynamic.image]);dynamic.mutation();dynamic.mutation();
  dynamic.image.isConnected=false;dynamic.setNodes([]);dynamic.mutation();
  dynamic.image.isConnected=true;dynamic.setNodes([dynamic.image]);dynamic.mutation();
  assert.equal(dynamic.frames.length,1);dynamic.load();
  const dynamicNonce=dynamic.posts[0].data.nonce;
  await dynamic.message({type:'media-prepared',nonce:dynamicNonce,transaction:'e'.repeat(64)});
  await dynamic.message({type:'media-ready',nonce:dynamicNonce});
  dynamic.mutation();dynamic.start();assert.equal(dynamic.frames.length,1);assert.equal(dynamic.retries,1);
  dynamic.resourceError();dynamic.resourceError();dynamic.mutation();
  assert.equal(dynamic.window.__phorgePrivateMediaFlight.stage,'failed');
  assert.equal(dynamic.alerts.length,1);assert.equal(dynamic.requests.length,1);
  const moving = fixture();moving.start();moving.load();
  const boundTarget=moving.posts[0].data.path;
  moving.image.isConnected=false;
  const replacement={...moving.image,isConnected:true,getAttribute:key => key==='src' ?
    'https://files.example.invalid/file/data/other/PHID-FILE-bbbbbbbbbbbbbbbbbbbb/new.png' : null};
  moving.setNodes([replacement]);moving.mutation();
  await moving.message({type:'media-prepared',nonce:moving.posts[0].data.nonce,transaction:'f'.repeat(64)});
  assert.equal(JSON.parse(moving.requests[0].init.body.get('payload')).path,boundTarget);
  await moving.message({type:'media-ready',nonce:moving.posts[0].data.nonce});
  assert.equal(moving.retries,1);assert.equal(moving.frames.length,1);
  const unicode = fixture();
  const longPath='/file/data/key/PHID-FILE-aaaaaaaaaaaaaaaaaaaa/'+encodeURIComponent('测'.repeat(250)+'.xlsx');
  assert.ok(longPath.length>2048&&longPath.length<=4096);
  unicode.image.getAttribute=key => key==='src' ? 'https://files.example.invalid'+longPath : null;
  unicode.start();unicode.load();assert.equal(unicode.posts[0].data.path,longPath);
  console.log(JSON.stringify({passed:true,unit_cases:9,browser_proof:false,authentication_proof:false}));
})().catch(error => {console.error(error);process.exitCode = 1;});
