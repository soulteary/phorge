'use strict';
const fs=require('node:fs');
const {chromium}=require(process.env.PHORGE_TEST_PLAYWRIGHT||'playwright');
const need=(v,k)=>{if(v!==true)throw Error(k);};
(async()=>{
 const c=JSON.parse(fs.readFileSync(process.argv[2]));need(c.schema==='directory-browser-private-v1'&&/^http:\/\/directory\.localhost:[1-9][0-9]{0,4}$/.test(c.origin),'fixed-owned-origin');
 let browser;let page;let stage='launch';const checks=[];const result={passed:false,browser_closed:false,checks,synthetic_sessions:true,production_changed:false};
 const check=(v,k)=>{need(v,k);checks.push(k);};
 try{
  browser=await chromium.launch({headless:true,executablePath:process.env.PHORGE_TEST_CHROMIUM||undefined,args:['--no-proxy-server','--disable-background-networking','--host-resolver-rules=MAP directory.localhost 127.0.0.1']});
  result.browser_version=browser.version();
  async function context(token){const ctx=await browser.newContext({viewport:{width:1280,height:900},extraHTTPHeaders:{'X-Phorge-Isolated-Directory':c.transport}});await ctx.route('**/*',route=>{try{return new URL(route.request().url()).origin===c.origin?route.continue():route.abort();}catch{return route.abort();}});await ctx.addCookies([{name:'phsid',value:token,domain:'directory.localhost',path:'/',httpOnly:true,sameSite:'Lax'}]);return ctx;}
  const admin=await context(c.admin_session),manager=await context(c.manager_session);page=await admin.newPage();result.typeahead_responses=[];page.on('response',response=>{try{if(new URL(response.url()).pathname.startsWith('/typeahead/')&&result.typeahead_responses.length<32)result.typeahead_responses.push({status:response.status(),method:response.request().method()});}catch{}});
  stage='tree';let response=await page.goto(c.origin+'/people/organization/?view=tree',{waitUntil:'domcontentloaded'});result.initial_status=response.status();result.initial_title=await page.title();check(response.status()===200,'admin-real-tree-http-200');
  await page.locator('li').filter({hasText:'Directory dp-parent'}).getByRole('link',{name:'Open Children',exact:true}).click();
  await page.waitForURL(u=>u.searchParams.get('tree_parent')==='dp-parent',{waitUntil:'domcontentloaded'});
  await page.locator('li > a').filter({hasText:'Renamed A'}).first().waitFor();
  result.tree_parent=new URL(page.url()).searchParams.get('tree_parent');result.tree_name_count=await page.getByRole('link',{name:'Renamed A',exact:true}).count();
  check(result.tree_parent==='dp-parent'&&result.tree_name_count>=1,'tree-expand-visible-parent');
  await page.getByRole('link',{name:'Up One Level',exact:true}).click();check(!new URL(page.url()).searchParams.has('tree_parent'),'tree-collapse-to-visible-root');
  stage='import-navigation';await page.goto(c.origin+'/people/organization/?bulk=import&unit_id=dp-b',{waitUntil:'domcontentloaded'});
  stage='import-paste';await page.locator('textarea[name=usernames]').fill(c.fresh_username+'\n'+c.fresh_username);
  stage='preview-submit';await page.getByRole('button',{name:'Preview Changes',exact:true}).click();
  await page.locator('input[name=confirmed_intent]').waitFor({state:'attached'});
  check(await page.getByText('Review Membership Changes',{exact:true}).count()===1,'real-pasted-import-preview');
  const rows=await page.locator('table tbody tr').count();check(rows===1,'preview-deduplicates-real-paste');
  await page.locator('input[name=acknowledge_bulk]').check();await page.getByRole('button',{name:'Apply Reviewed Changes',exact:true}).click();
  await page.goto(c.origin+'/p/'+c.fresh_username+'/',{waitUntil:'domcontentloaded'});check(await page.getByRole('link',{name:'Directory dp-b',exact:true}).count()===1,'import-visible-in-native-profile');
  stage='replace-navigation';await page.goto(c.origin+'/people/organization/?bulk=replace',{waitUntil:'domcontentloaded'});
  const bulkForm=page.locator('form').filter({has:page.locator('input[name=form_action][value="bulk.preview"]')});const inputs=bulkForm.locator('input.jx-tokenizer-input:not([disabled])');stage='replace-typeahead';await inputs.first().pressSequentially(c.fresh_username,{delay:35});
  const candidate=page.locator('a[data-sigil~="typeahead-result"]').filter({hasText:c.fresh_username});await candidate.first().click();
  stage='replace-preview';await page.getByRole('button',{name:'Preview Changes',exact:true}).click();
  await page.getByText('End Membership',{exact:true}).waitFor();
  check(await page.getByText('End Membership',{exact:true}).count()===1,'replacement-real-tokenizer-empty-target-preview');
  await page.locator('input[name=acknowledge_bulk]').check();await page.getByRole('button',{name:'Apply Reviewed Changes',exact:true}).click();
  await page.goto(c.origin+'/p/'+c.fresh_username+'/',{waitUntil:'domcontentloaded'});check(await page.getByRole('link',{name:'Directory dp-b',exact:true}).count()===0,'replacement-removes-current-profile-relation');
  stage='manager-privacy';const limited=await manager.newPage();await limited.goto(c.origin+'/people/organization/?view=tree',{waitUntil:'domcontentloaded'});
  check(await limited.getByRole('link',{name:'Renamed A',exact:true}).count()===1&&await limited.getByText('Directory dp-parent',{exact:true}).count()===0,'manager-tree-hides-parent');
  await limited.goto(c.origin+'/p/'+c.subject_username+'/',{waitUntil:'domcontentloaded'});check(await limited.getByRole('link',{name:'Renamed A',exact:true}).count()===1&&await limited.getByRole('link',{name:'Directory dp-b',exact:true}).count()===0&&await limited.getByRole('link',{name:'Directory dp-dept',exact:true}).count()===0,'manager-profile-hides-other-team-and-department');
  await limited.goto(c.origin+'/people/organization/?bulk=replace',{waitUntil:'domcontentloaded'});check(await limited.getByRole('button',{name:'Preview Changes',exact:true}).count()===0,'manager-cannot-open-replacement-editor');
  result.passed=true;
 }catch(error){if(page){try{result.final_title=await page.title();result.review_input_count=await page.locator('input[name=confirmed_intent]').count();result.organization_error_visible=await page.getByText('Organization Request Unavailable',{exact:true}).count()>0;await page.screenshot({path:require('node:path').join(require('node:path').dirname(process.argv[3]),'failure.png')});}catch{}}result.stage=stage;result.failure=/^[a-z0-9-]+$/.test(error.message)?error.message:'browser-operation-failed';result.timeout=error.name==='TimeoutError';}
 finally{if(browser){await browser.close();result.browser_closed=true;}fs.writeFileSync(process.argv[3],JSON.stringify(result,null,2),{mode:0o600,flag:'wx'});process.stdout.write(JSON.stringify({passed:result.passed,checks:checks.length,stage:result.stage,browser_closed:result.browser_closed})+'\n');}
 process.exitCode=result.passed?0:1;
})();
