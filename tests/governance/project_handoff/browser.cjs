'use strict';
const fs=require('node:fs');
const {chromium}=require(process.env.PHORGE_TEST_PLAYWRIGHT||'playwright');
const need=(v,k)=>{if(v!==true)throw Error(k);};
(async()=>{
 const c=JSON.parse(fs.readFileSync(process.argv[2]));
 need(c.schema==='project-handoff-browser-private-v1'&&/^http:\/\/directory\.localhost:[1-9][0-9]{0,4}$/.test(c.origin),'owned-origin');
 let browser;let page;let stage='launch';const checks=[];
 const result={passed:false,browser_closed:false,checks,synthetic_sessions:true,production_changed:false};
 const check=(v,k)=>{need(v,k);checks.push(k);};
 try{
  browser=await chromium.launch({headless:true,executablePath:process.env.PHORGE_TEST_CHROMIUM||undefined,args:['--no-proxy-server','--disable-background-networking','--host-resolver-rules=MAP directory.localhost 127.0.0.1']});
  result.browser_version=browser.version();
  async function context(token){const ctx=await browser.newContext({extraHTTPHeaders:{'X-Phorge-Isolated-Directory':c.transport}});
   await ctx.route('**/*',route=>{try{return new URL(route.request().url()).origin===c.origin?route.continue():route.abort();}catch{return route.abort();}});
   await ctx.addCookies([{name:'phsid',value:token,domain:'directory.localhost',path:'/',httpOnly:true,sameSite:'Lax'}]);return ctx;}
  const admin=await context(c.admin_session),ordinary=await context(c.ordinary_session);
  page=await admin.newPage();const path='/project/handoff/'+c.web_project_id+'/';
  result.responses=[];page.on('response',response=>{try{const u=new URL(response.url());if(u.origin===c.origin&&result.responses.length<64)result.responses.push({status:response.status(),method:response.request().method(),path:u.pathname});}catch{}});
  stage='web-form';let response=await page.goto(c.origin+path,{waitUntil:'domcontentloaded'});
  check(response.status()===200,'web-real-handoff-form');
  await page.locator('input.jx-tokenizer-input:not([disabled])').pressSequentially(c.recipient_username,{delay:30});
  await page.locator('a[data-sigil~="typeahead-result"]').filter({hasText:c.recipient_username}).first().click();
  await page.getByRole('button',{name:'Preview Final Permissions',exact:true}).click();
  stage='web-preview';await page.locator('input[name=intent]').waitFor({state:'attached'});
  const intent=await page.locator('input[name=intent]').inputValue();const reviewedForm=page.locator('form').filter({has:page.locator('input[name=intent]')});const csrf=await reviewedForm.locator('input[name=__csrf__]').inputValue();
  check(JSON.parse(intent).recipientPHID===c.recipient_phid,'web-reviewed-recipient-is-bound');
  response=await admin.request.post(c.origin+path,{form:{handoff_action:'commit',intent,acknowledge:'1'}});
  check(response.status()>=400,'web-missing-csrf-denied');
  await page.locator('input[name=acknowledge]').check();
  stage='web-commit';await page.getByRole('button',{name:'Confirm Handoff',exact:true}).click();
  await page.getByText('The policy handoff was recorded. Your access now follows the final native policies.',{exact:true}).waitFor();
  check(true,'web-native-confirmation-commits');
  stage='web-retry';response=await admin.request.post(c.origin+path,{form:{handoff_action:'commit',intent,acknowledge:'1',__csrf__:csrf}});
  check(response.status()===200&&(await response.text()).includes('Handoff Complete'),'web-exact-retry-after-self-lockout');
  response=await admin.request.post(c.origin+'/project/handoff/'+c.api_project_id+'/',{form:{handoff_action:'commit',intent,acknowledge:'1',__csrf__:csrf}});
  check(response.status()===400,'web-route-target-mismatch-denied');
  stage='web-final-access';response=await admin.request.get(c.origin+path);const deniedHTML=await response.text();check((response.status()===404||deniedHTML.includes('aphront-policy-rejection'))&&!deniedHTML.includes('name="recipient[]"'),'web-new-view-respects-final-native-policy');
  response=await ordinary.request.get(c.origin+'/project/handoff/'+c.api_project_id+'/');
  check(response.status()===404,'web-ordinary-user-handoff-denied');
  const apiContext=await browser.newContext({extraHTTPHeaders:{'X-Phorge-Isolated-Directory':c.transport}});
  async function api(action,payload){const response=await apiContext.request.post(c.origin+'/api/project.handoff',
   {form:{params:JSON.stringify({action,payload,__conduit__:{token:c.admin_api_token}})}});return response.json();}
  stage='conduit-preview';const planned=await api('preview',{projectPHID:c.api_project_phid,recipientPHID:c.recipient_phid,operationID:'browser_api_handoff_01'});
  result.api_preview_error=planned.error_code;check(planned.error_code===null&&planned.result.after.edit===c.recipient_phid,'conduit-real-token-preview');
  const payload={};for(const key of ['operationID','projectPHID','recipientPHID','expiresAt','expectedDigest'])payload[key]=planned.result[key];payload.acknowledge=true;
  stage='conduit-commit';const committed=await api('commit',payload);
  check(committed.error_code===null&&committed.result.committed===true&&committed.result.postCommitProcessingComplete!==false,'conduit-real-atomic-commit');
  const replay=await api('commit',payload);check(replay.error_code===null&&replay.result.replayed===true&&replay.result.transactionPHID===committed.result.transactionPHID,'conduit-retry-same-native-receipt');
  const conflict=await api('commit',{...payload,recipientPHID:c.ordinary_phid});check(conflict.error_code==='ERR-HANDOFF','conduit-operation-rebinding-denied');
  result.passed=true;
 }catch(error){result.failure={stage,type:error.constructor.name};if(page){result.last_path=new URL(page.url()).pathname;result.title=await page.title().catch(()=>null);result.input_names=await page.locator('input[name]').evaluateAll(nodes=>nodes.map(n=>n.name)).catch(()=>[]);result.body_markers={unavailable:await page.getByText('Handoff Unavailable',{exact:true}).count().catch(()=>0),result_unknown:await page.getByText('The handoff result could not be confirmed.',{exact:false}).count().catch(()=>0)};}}
 finally{if(browser){await browser.close();result.browser_closed=true;}fs.writeFileSync(process.argv[3],JSON.stringify(result,null,2)+'\n');}
 process.exitCode=result.passed&&result.browser_closed?0:1;
})();
