import fs from 'node:fs';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
const {chromium} = await import(process.env.WORKSPACE_PLAYWRIGHT_MODULE || 'playwright');

const [origin, fixturePath, output] = process.argv.slice(2);
const url = new URL(origin);
if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1' || Number(url.port) < 1024) {
  throw new Error('Only an isolated loopback HTTP test server is allowed.');
}
if (process.env.WORKSPACE_ACCEPTANCE !== '1') {
  throw new Error('Use the disposable project-workspace acceptance runner.');
}
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
if (!/^phorge-workspace-[a-f0-9]{12}-web$/.test(fixture.applicationContainer) ||
    !/^\/[a-zA-Z0-9_/-]+$/.test(fixture.applicationRoot)) {
  throw new Error('Unexpected disposable fixture application.');
}
const projects = fixture.projects;
const progressURI = `/project/progress/${projects.main.id}/`;
const report = {scope: 'Isolated HTTP progress forms, synthetic sessions and objects.', checks: []};
const browser = await chromium.launch({headless: true});
const contexts = new Map();
const check = (name, passed) => {
  report.checks.push({name, passed: Boolean(passed)});
  if (!passed) throw new Error(name);
};
async function context(role) {
  const user = fixture[role];
  const ctx = await browser.newContext({viewport: {width: 1440, height: 1000}});
  contexts.set(role, ctx);
  await ctx.route('**/*', async route => {
    if (new URL(route.request().url()).origin === origin) await route.continue();
    else await route.abort();
  });
  await ctx.addCookies([
    {name: 'phusr', value: user.username, url: origin},
    {name: 'phsid', value: user.session, url: origin, httpOnly: true},
  ]);
  const page = await ctx.newPage();
  page.setDefaultTimeout(20000);
  return page;
}
async function visit(page, route) {
  const response = await page.goto(origin + route, {waitUntil: 'networkidle'});
  check('HTTP renders ' + route, response?.status() === 200);
  check('no runtime exception on ' + route,
    !/Unhandled Exception|Call to undefined method|Fatal error|Stack Trace/i.test(await page.locator('body').innerText()));
}
function summary(page) {
  return page.locator('.team-project-progress-summary');
}
function form(page) {
  return page.locator('form').filter({has: page.locator('input[name="title"]')});
}
function workspace(page) {
  return page.locator('.team-project-workspace');
}
async function openBoardMenu(page, kind) {
  const trigger = workspace(page).locator('.team-project-board-' + kind);
  await trigger.click();
  const menu = page.locator('.phuix-dropdown-menu:visible');
  await menu.waitFor({state: 'visible'});
  return menu;
}
function controlledDraftDenial(response, body) {
  return [200, 403, 404].includes(response.status()) &&
    /class=["'][^"']*\baphront-access-dialog\b[^"']*["']/.test(body) &&
    body.includes('无法从项目记录创建卡片') &&
    body.includes('来源记录无效、不可查看，或您没有该项目的编辑权限。') &&
    !/<input\b[^>]*\bname=["']title["']/i.test(body) &&
    !body.includes('完成登录接口与错误提示');
}
async function csrfToken(page) {
  return page.evaluate(() => new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('CSRF refresh timed out.')), 15000);
    new JX.Request('/login/refresh/', data => {
      clearTimeout(timeout);
      resolve(data.token);
    }).send();
  }));
}
async function rejectPublish(page, token, kind = 'node') {
  const fields = {kind, title: 'UI unauthorized write',
    body: 'This record must never persist.', status: 'done', percent: '100'};
  if (token !== undefined) fields.__csrf__ = token;
  return page.request.post(origin + progressURI, {form: fields, maxRedirects: 0});
}
function drainNativeFeed() {
  if (!/^phorge-workspace-[a-f0-9]{12}-web$/.test(fixture.applicationContainer)) {
    throw new Error('Only the isolated fixture application can publish test feed jobs.');
  }
  return JSON.parse(execFileSync('docker', ['exec', '-i', '-e', 'WORKSPACE_ACCEPTANCE=1',
    '-e', `WORKSPACE_ACCEPTANCE_RUN=${fixture.applicationContainer.replace(/-web$/, '')}`,
    '-e', `WORKSPACE_PHORGE_ROOT=${fixture.applicationRoot}`,
    fixture.applicationContainer, 'php', fixture.applicationRoot + '/tests/project-workspace/fixture.php', '--drain-feed'],
  {input: JSON.stringify(fixture), encoding: 'utf8'}));
}
async function checkTaskReferences(page, label, canSeePrivate) {
  const body = await page.locator('body').innerText();
  const html = await page.content();
  const paths = await page.locator('a[href]').evaluateAll(nodes =>
    nodes.map(node => new URL(node.href, document.baseURI).pathname));
  const hasTaskLink = id => paths.some(pathname => pathname.replace(/\/$/, '') === `/T${id}`);
  check(label + ': visible task references are actionable links', hasTaskLink(fixture.tasks.public.id));
  if (canSeePrivate) {
    check(label + ': authorized viewer sees the private task reference',
      hasTaskLink(fixture.tasks.private.id) && body.includes(fixture.tasks.private.title));
  } else {
    check(label + ': private task reference exposes no title, description, or automatic link',
      !hasTaskLink(fixture.tasks.private.id) && !body.includes(fixture.tasks.private.title) &&
      !body.includes(fixture.tasks.private.description) && !html.includes(fixture.tasks.private.title) &&
      !html.includes(fixture.tasks.private.description) && body.includes(`{T${fixture.tasks.private.id}}`));
  }
}
async function compose(page, kind, {status, percent, participant, titleKey = kind} = {}) {
  await visit(page, progressURI + `?compose=${kind}#publish`);
  const entryForm = form(page);
  check(kind + ' exposes a record form', await entryForm.count() === 1);
  if (kind === 'node') {
    check('project history no longer offers a duplicate task execution status',
      await entryForm.locator('[name="status"]').count() === 0);
  } else {
    check('work comments do not expose node status or project completion fields',
      await entryForm.locator('[name="status"], [name="percent"]').count() === 0);
  }
  await entryForm.locator('input[name="title"]').fill(fixture.titles[titleKey]);
  await entryForm.locator('textarea[name="body"]').fill({
    node: `完成登录接口与错误提示。\n\n下一步：开展跨模块联调，请 @${fixture.member.username} 与 @${fixture.owner.username} 复核。\n\n关联任务：T${fixture.tasks.public.id} {T${fixture.tasks.public.id}}；T${fixture.tasks.private.id} {T${fixture.tasks.private.id}}。`,
    comment: `联调时发现权限提示需要补充，已与负责同事 @${fixture.owner.username} 确认。`,
    completedNode: '首轮验收节点已经完成；项目还需开展下一阶段开发。',
  }[titleKey]);
  if (status !== undefined) {
    // Simulate a stale old form: even an injected status must not recreate
    // a second execution-state model in a current project history record.
    await entryForm.evaluate((element, value) => {
      const input = document.createElement('input');
      input.type = 'hidden'; input.name = 'status'; input.value = value;
      element.appendChild(input);
    }, status);
  }
  if (percent !== undefined) await entryForm.locator('input[name="percent"]').fill(String(percent));
  if (participant) {
    const tokenizer = entryForm.locator('input.jx-tokenizer-input');
    await tokenizer.pressSequentially(participant.username, {delay: 25});
    await page.locator(`a.jx-result[rel="${participant.phid}"]`).click();
    check('participant is selected through the actual typeahead',
      await entryForm.locator(`input[name^="participants["][value="${participant.phid}"]`).count() === 1);
  }
  await Promise.all([
    page.waitForNavigation({waitUntil: 'networkidle'}),
    entryForm.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
  check(kind + ' form submission renders the new title',
    (await page.locator('body').innerText()).includes(fixture.titles[titleKey]));
  const link = summary(page).getByRole('link', {name: fixture.titles[titleKey], exact: true});
  const href = await link.getAttribute('href');
  const entry = new URL(href, origin).searchParams.get('entry');
  check(kind + ' record has a stable entry link', /^\d+$/.test(entry || ''));
  return entry;
}

try {
  const owner = await context('owner');
  await visit(owner, `/project/profile/${projects.main.id}/`);
  check('the old independent node menu is no longer exposed',
    await owner.getByRole('link', {name: '节点记录', exact: true}).count() === 0);
  check('empty overview explains how to begin without a fabricated zero percent',
    (await summary(owner).innerText()).includes('还没有') &&
    (await summary(owner).innerText()).includes('尚未填写') &&
    await summary(owner).locator('progress').count() === 0);
  check('overview visibly offers publish and history',
    await summary(owner).getByRole('button', {name: '发布项目记录', exact: true}).isVisible() &&
    await summary(owner).getByRole('button', {name: '开发历史', exact: true}).isVisible());
  check('overview offers each publishing/history destination once without a duplicate breadcrumb action',
    await owner.getByRole('button', {name: '发布项目记录', exact: true}).count() === 1 &&
    await owner.getByRole('button', {name: '开发历史', exact: true}).count() === 1 &&
    await owner.locator('.team-project-publish-navigation').count() === 0);
  await summary(owner).getByRole('button', {name: '发布项目记录', exact: true}).click();
  await owner.waitForLoadState('networkidle');
  check('overview publish action reaches the form', await form(owner).isVisible());
  check('history keeps one workspace publish action and one history tab without summary duplicates',
    await owner.getByRole('button', {name: '发布项目记录', exact: true}).count() === 1 &&
    await owner.getByRole('button', {name: '开发历史', exact: true}).count() === 1 &&
    await summary(owner).getByRole('button').count() === 0 &&
    await owner.locator('.team-project-publish-navigation').count() === 0);
  check('history filtering and writing have distinct action labels and destinations',
    await owner.getByRole('button', {name: '项目记录', exact: true}).count() === 0 &&
    await owner.getByRole('button', {name: '工作评论', exact: true}).count() === 0 &&
    (await owner.getByRole('button', {name: '只看项目记录', exact: true}).getAttribute('href')) === progressURI + '?kind=node' &&
    (await owner.getByRole('button', {name: '写项目记录', exact: true}).getAttribute('href')) === progressURI + '?compose=node#publish' &&
    (await owner.getByRole('button', {name: '只看工作评论', exact: true}).getAttribute('href')) === progressURI + '?kind=comment' &&
    (await owner.getByRole('button', {name: '写工作评论', exact: true}).getAttribute('href')) === progressURI + '?compose=comment#publish');

  for (const legacyKind of ['update', 'milestone']) {
    await visit(owner, progressURI + `?compose=${legacyKind}#publish`);
    check('legacy compose alias ' + legacyKind + ' opens the canonical node form',
      await form(owner).locator('input[name="kind"]').inputValue() === 'node' &&
      await form(owner).locator('[name="status"]').count() === 0 &&
      await owner.locator('.team-project-progress-publish .team-project-progress-actions')
        .getByRole('button').count() === 2);
  }
  const invalidNode = await owner.request.post(origin + progressURI, {
    form: {__csrf__: await csrfToken(owner), kind: 'node', title: '',
      body: 'This record must never persist.', percent: ''}, maxRedirects: 0});
  check('a record without title is rejected by the server with a clear validation message',
    invalidNode.status() === 200 && (await invalidNode.text()).includes('请填写记录标题。'));

  const nodeID = await compose(owner, 'node', {percent: 40, participant: fixture.member});
  check('published history updates manual completion without a second execution status',
    !(await summary(owner).innerText()).includes('最新节点状态') &&
    await summary(owner).locator('progress').getAttribute('value') === '40');
  check('published progress shows the selected participant',
    (await summary(owner).innerText()).includes(fixture.member.username));
  await checkTaskReferences(owner, 'project record rendered by owner first', true);
  await owner.screenshot({path: path.join(output, 'published-progress.png'), fullPage: true});

  const member = await context('member');
  await visit(member, `/project/profile/${projects.main.id}/`);
  check('read-only members can open history but have no overview publish action',
    await summary(member).getByRole('button', {name: '开发历史', exact: true}).isVisible() &&
    await member.locator('a.team-project-publish, .team-project-publish a').count() === 0);
  await visit(member, progressURI);
  check('read-only members can read records but have no publish form or action',
    (await member.locator('body').innerText()).includes(fixture.titles.node) &&
    await form(member).count() === 0 &&
    await member.locator('a.team-project-publish, .team-project-publish a').count() === 0);
  await checkTaskReferences(member, 'same project record rendered by read-only member', false);
  const memberToken = await csrfToken(member);
  check('read-only member has a valid session CSRF token for direct requests',
    typeof memberToken === 'string' && memberToken.length > 10);
  for (const kind of ['node', 'comment', 'update', 'milestone']) {
    const rejected = await rejectPublish(member, memberToken, kind);
    (report.readOnlyMemberRejections ||= []).push({kind, status: rejected.status()});
    check('read-only member direct ' + kind + ' POST is denied with valid CSRF',
      rejected.status() === 403);
  }
  await member.screenshot({path: path.join(output, 'readonly-member-history.png'), fullPage: true});
  const commentID = await compose(owner, 'comment');
  check('editors can publish work comments with their actual identity',
    (await summary(owner).innerText()).includes(fixture.owner.username));
  check('work comments preserve manual completion without restoring task execution state',
    !(await summary(owner).innerText()).includes('最新节点状态') &&
    await summary(owner).locator('progress').getAttribute('value') === '40');
  check('a new work comment does not replace the latest progress summary',
    (await summary(owner).locator('.team-project-latest-node').innerText()) === fixture.titles.node &&
    (await summary(owner).locator('.team-project-latest-discussion').innerText()).includes(fixture.titles.comment));

  const completedNodeID = await compose(owner, 'node', {titleKey: 'completedNode', status: 'done'});
  await visit(owner, `/project/profile/${projects.main.id}/`);
  check('overview shows project history and manual completion without duplicate node status',
    !(await summary(owner).innerText()).includes('最新节点状态') &&
    (await summary(owner).innerText()).includes('项目完成度') &&
    (await summary(owner).innerText()).includes(fixture.titles.completedNode) &&
    (await summary(owner).innerText()).includes(fixture.owner.username) &&
    await summary(owner).locator('progress').getAttribute('value') === '40');
  check('the latest node appears once without a second milestone summary',
    await summary(owner).getByRole('link', {name: fixture.titles.completedNode, exact: true}).count() === 1 &&
    !(await summary(owner).innerText()).includes('最近关键节点') &&
    !(await summary(owner).innerText()).includes('当前状态'));
  check('the latest key node and latest discussion remain individually discoverable',
    (await summary(owner).locator('.team-project-latest-node').innerText()) === fixture.titles.completedNode &&
    (await summary(owner).locator('.team-project-latest-discussion').innerText()).includes(fixture.titles.comment));
  await owner.screenshot({path: path.join(output, 'overview-progress.png'), fullPage: true});
  await visit(owner, progressURI);
  const ids = await owner.locator('[id^="entry-"]').evaluateAll(nodes =>
    nodes.map(node => node.id.replace('entry-', '')).filter(value => /^\d+$/.test(value)));
  check('history shows newest first with stable entry identifiers',
    JSON.stringify(ids) === JSON.stringify([completedNodeID, commentID, nodeID]));
  const timelineText = await owner.locator('body').innerText();
  check('history preserves every published kind and title',
    Object.values(fixture.titles).every(title => timelineText.includes(title)) &&
    ['工作评论', '项目记录'].every(kind => timelineText.includes(kind)));
  check('history and compose offer only node and discussion record types',
    await owner.locator('.team-project-progress-history .team-project-progress-actions').first().getByRole('button').count() === 3 &&
    await owner.locator('.team-project-progress-publish .team-project-progress-actions').getByRole('button').count() === 2);
  await owner.screenshot({path: path.join(output, 'project-history.png'), fullPage: true});

  const boardURI = `/project/board/${projects.main.id}/`;
  await visit(owner, boardURI + '?filter=all');
  check('workboard is the shared project workspace with history and native cards',
    await workspace(owner).count() === 1 &&
    await workspace(owner).getByRole('button', {name: '任务看板', exact: true}).isVisible() &&
    await workspace(owner).getByRole('button', {name: '开发历史', exact: true}).getAttribute('href') === progressURI &&
    await owner.locator('[data-sigil~="jx-workboard"]').count() === 1);
  check('workboard has a single native ordering control and a single native filtering control in the workspace',
    await owner.locator('.team-project-board-sort').count() === 1 &&
    await owner.locator('.team-project-board-filter').count() === 1 &&
    await workspace(owner).getByRole('button', {name: /^排序：/}).count() === 1 &&
    await workspace(owner).getByRole('button', {name: '筛选：全部卡片', exact: true}).count() === 1 &&
    await owner.locator('.phui-crumbs-actions [data-sigil~="boards-dropdown-menu"]')
      .filter({hasText: /Natural|Status|All Tasks|Open Tasks|排序：|筛选：/}).count() === 0);
  check('workboard has no parallel shortcut buttons for existing native menus or duplicate publishing actions',
    await workspace(owner).getByRole('button', {name: /^(全部卡片|按状态|按发布时间|按发布成员)$/}).count() === 0 &&
    await owner.getByRole('button', {name: '发布项目记录', exact: true}).count() === 1 &&
    await owner.locator('.team-project-publish-navigation').count() === 0);
  const initialOrderMenu = await openBoardMenu(owner, 'sort');
  for (const label of ['看板顺序', '按优先级', '按负责人', '按状态', '按标题', '按发布时间', '按发布成员']) {
    check('single native ordering menu retains visible option: ' + label,
      await initialOrderMenu.getByRole('link', {name: label, exact: true}).isVisible());
  }
  const defaultOrderAction = initialOrderMenu.getByRole('link', {name: 'Save as Default', exact: true});
  check('the native default-order action retains its own workflow route',
    (await defaultOrderAction.getAttribute('href')).includes('/default/sort/'));
  await defaultOrderAction.click();
  await owner.getByText('Set Board Default Order', {exact: true}).first().waitFor({state: 'visible'});
  check('default-order workflow still opens the original native confirmation dialog',
    await owner.getByText('Set Board Default Order', {exact: true}).count() > 0);
  await owner.getByRole('link', {name: 'Cancel', exact: true}).click();
  const initialFilterMenu = await openBoardMenu(owner, 'filter');
  for (const label of ['未完成卡片', '全部卡片', '分配给我']) {
    check('single native filtering menu retains visible option: ' + label,
      await initialFilterMenu.getByRole('link', {name: label, exact: true}).isVisible());
  }
  const defaultFilterAction = initialFilterMenu.getByRole('link', {name: 'Save as Default', exact: true});
  check('the native default-filter action retains its own workflow route',
    (await defaultFilterAction.getAttribute('href')).includes('/default/filter/'));
  await defaultFilterAction.click();
  await owner.getByText('Set Board Default Filter', {exact: true}).first().waitFor({state: 'visible'});
  check('default-filter workflow still opens the original native confirmation dialog',
    await owner.getByText('Set Board Default Filter', {exact: true}).count() > 0);
  await owner.getByRole('link', {name: 'Cancel', exact: true}).click();
  const advancedFilterMenu = await openBoardMenu(owner, 'filter');
  await advancedFilterMenu.getByRole('link', {name: 'Advanced Filter...', exact: true}).click();
  await owner.getByRole('button', {name: 'Apply Filter', exact: true}).waitFor({state: 'visible'});
  check('advanced filtering remains available through the single native menu',
    await owner.getByRole('button', {name: 'Apply Filter', exact: true}).isVisible() &&
    await owner.getByText('Advanced Filter', {exact: true}).count() > 0);
  await owner.getByRole('link', {name: 'Cancel', exact: true}).click();
  const openFilterMenu = await openBoardMenu(owner, 'filter');
  await openFilterMenu.getByRole('link', {name: '未完成卡片', exact: true}).click();
  await owner.waitForURL(url => url.pathname.includes('/query/open/'));
  check('the consolidated filter menu really excludes completed cards',
    await workspace(owner).getByRole('button', {name: '筛选：未完成卡片', exact: true}).isVisible() &&
    await owner.locator('[data-sigil~="project-card"]').filter({hasText: fixture.tasks.public.title}).count() === 0);
  const allFilterMenu = await openBoardMenu(owner, 'filter');
  await allFilterMenu.getByRole('link', {name: '全部卡片', exact: true}).click();
  await owner.waitForURL(url => url.pathname.includes('/query/all/'));
  check('the consolidated filter menu really restores completed cards',
    await workspace(owner).getByRole('button', {name: '筛选：全部卡片', exact: true}).isVisible() &&
    await owner.locator('[data-sigil~="project-card"]').filter({hasText: fixture.tasks.public.title}).count() === 1);
  await owner.screenshot({path: path.join(output, 'consolidated-workboard-toolbar.png'), fullPage: true});
  const workspaceSummary = await workspace(owner).innerText();
  check('workboard summary reflects the same latest project record, author and comment as development history',
    await workspace(owner).getByRole('link', {name: fixture.titles.completedNode, exact: true}).getAttribute('href') ===
      progressURI + `?entry=${completedNodeID}#entry-${completedNodeID}` &&
    await workspace(owner).getByRole('link', {name: fixture.titles.comment, exact: true}).getAttribute('href') ===
      progressURI + `?entry=${commentID}#entry-${commentID}` && workspaceSummary.includes(fixture.owner.username) &&
    /\b\d{1,2}:\d{2}\b/.test(workspaceSummary));
  check('workboard keeps manual project assessment distinct from task counts and execution states',
    workspaceSummary.includes('项目整体评估（人工）') && workspaceSummary.includes('40%') &&
    !workspaceSummary.includes('100%') && !workspaceSummary.includes('最新节点状态'));
  const workspaceBox = await workspace(owner).boundingBox();
  const boardBox = await owner.locator('.project-board-wrapper .phui-workboard-view-shadow').boundingBox();
  check('the task board starts below the shared workspace without covering its actions',
    workspaceBox && boardBox && boardBox.y >= workspaceBox.y + workspaceBox.height - 1);
  const fullscreen = owner.locator('.phui-workboard-expand-icon');
  await fullscreen.click();
  const expandedWorkspace = await workspace(owner).boundingBox();
  const expandedBoard = await owner.locator('.project-board-wrapper .phui-workboard-view-shadow').boundingBox();
  check('native fullscreen removes the sidebar gap and keeps workspace actions above the board',
    await owner.locator('.phui-workboard-fullscreen').count() === 1 &&
    expandedWorkspace && expandedBoard && expandedWorkspace.x < 70 && expandedBoard.x < 70 &&
    expandedBoard.y >= expandedWorkspace.y + expandedWorkspace.height - 1);
  await owner.screenshot({path: path.join(output, 'merged-fullscreen-workboard.png'), fullPage: true});
  await fullscreen.click();
  check('exiting native fullscreen restores normal project navigation',
    await owner.locator('.phui-workboard-fullscreen').count() === 0 &&
    (await workspace(owner).boundingBox()).x > 100);
  const contextualColumn = owner.locator('.phui-workpanel-view').filter({hasText: fixture.boardColumns[1].name}).first();
  await contextualColumn.locator('[data-sigil~="boards-dropdown-menu"]').first().click();
  const contextualCreate = owner.locator('.phuix-dropdown-menu:visible')
    .getByRole('link', {name: '在此列新建卡片', exact: true});
  check('native column creation has an explicit context rather than a second global create label',
    await contextualCreate.isVisible() &&
    await contextualCreate.getAttribute('data-sigil').then(sigil => sigil.includes('column-add-task')));
  await contextualCreate.click();
  const contextualForm = owner.locator('form.aphront-dialog-view').filter({has: owner.locator('input[name="title"]')});
  await contextualForm.locator('input[name="title"]').waitFor({state: 'visible'});
  check('renamed column creation retains its original target column and project prefill without saving',
    await contextualForm.locator('input[name="columnPHID"]').inputValue() === fixture.boardColumns[1].phid &&
    await contextualForm.locator(`input[value="${projects.main.phid}"]`).count() > 0 &&
    await contextualForm.locator('input[name="title"]').inputValue() === '');
  await owner.getByRole('link', {name: 'Cancel', exact: true}).click();
  const newCard = workspace(owner).getByRole('button', {name: '新建卡片', exact: true});
  const cardCreateURI = await newCard.getAttribute('href');
  check('new card action opens the real native task form for this project',
    new URL(cardCreateURI, origin).pathname.startsWith('/maniphest/task/edit/') &&
    new URL(cardCreateURI, origin).searchParams.get('projects') === projects.main.phid);
  await newCard.click();
  await owner.waitForLoadState('networkidle');
  check('a new card starts as an unsaved native task with private defaults',
    await owner.locator('input[name="title"]').inputValue() === '' &&
    await owner.locator(`input[value="${projects.main.phid}"]`).count() > 0 &&
    await owner.locator('input[name="policy.view"]').inputValue() === fixture.owner.phid &&
    await owner.locator('input[name="policy.edit"]').inputValue() === fixture.owner.phid);
  const cardStatuses = new Map(await owner.locator('select[name="status"] option')
    .evaluateAll(options => options.map(option => [option.value, option.textContent.trim()])));
  check('native work cards offer the four current execution states without a parallel node state',
    [['open', '待完成'], ['active', '进行中'], ['blocked', '有阻碍'], ['resolved', '已完成']]
      .every(([value, label]) => cardStatuses.get(value) === label));

  const oldSource = fixture.archiveNodes[0];
  await visit(owner, `/maniphest/task/edit/?projectNode=${oldSource.id}`);
  check('a saved old execution state is only history and does not become the new task state',
    (await owner.locator('input[name="title"]').inputValue()).includes(oldSource.title) &&
    await owner.locator('select[name="status"]').inputValue() === 'open' &&
    (await owner.locator('textarea[name="description"]').inputValue()).includes(`entry=${oldSource.id}`) &&
    (await owner.locator('textarea[name="description"]').inputValue()).includes('已完成'));

  await visit(owner, progressURI + `?entry=${nodeID}`);
  const fromRecord = owner.locator('.team-project-progress-record').filter({hasText: fixture.titles.node})
    .getByRole('button', {name: '从此记录新建卡片', exact: true});
  check('history exposes an explicit draft action instead of duplicating a work card',
    new URL(await fromRecord.getAttribute('href'), origin).searchParams.get('projectNode') === nodeID);
  await fromRecord.click();
  await owner.waitForLoadState('networkidle');
  const draftBody = await owner.locator('textarea[name="description"]').inputValue();
  check('the draft preserves source text, fixed record link and intended project',
    (await owner.locator('input[name="title"]').inputValue()).includes(fixture.titles.node) &&
    draftBody.includes('完成登录接口与错误提示') && draftBody.includes(`entry=${nodeID}`) &&
    await owner.locator(`input[value="${projects.main.phid}"]`).count() > 0);
  check('a source record does not widen native task view or edit defaults',
    await owner.locator('input[name="policy.view"]').inputValue() === fixture.owner.phid &&
    await owner.locator('input[name="policy.edit"]').inputValue() === fixture.owner.phid);
  const beforeSave = JSON.parse(execFileSync('docker', ['exec', '-i',
    '-e', 'WORKSPACE_ACCEPTANCE=1',
    '-e', `WORKSPACE_ACCEPTANCE_RUN=${fixture.applicationContainer.replace(/-web$/, '')}`,
    '-e', `WORKSPACE_PHORGE_ROOT=${fixture.applicationRoot}`,
    fixture.applicationContainer, 'php',
    fixture.applicationRoot + '/tests/project-workspace/fixture.php', '--verify-drafts'],
  {input: JSON.stringify(fixture), encoding: 'utf8'}));
  check('opening the blank, old-record and new-record drafts wrote no tasks',
    beforeSave.passed && beforeSave.taskCount === fixture.initialTaskCount);
  await owner.locator('input[name="title"]').fill(fixture.cardTitle);
  await Promise.all([owner.waitForURL(url => /^\/T\d+$/.test(url.pathname)),
    owner.getByRole('button', {name: 'Create New Task', exact: true}).click()]);
  check('an explicit native submit saves the new card',
    (await owner.locator('body').innerText()).includes(fixture.cardTitle));
  report.createdCard = new URL(owner.url()).pathname;

  for (const [label, order] of [['按状态', 'status'], ['按发布时间', 'created'], ['按发布成员', 'author']]) {
    await visit(owner, boardURI + '?filter=all');
    const orderMenu = await openBoardMenu(owner, 'sort');
    await orderMenu.getByRole('link', {name: label, exact: true}).click();
    await owner.waitForURL(url => url.searchParams.get('order') === order);
    await owner.waitForLoadState('networkidle');
    const board = owner.locator('[data-sigil~="jx-workboard"]');
    check('workspace index opens native task order: ' + order,
      new URL(owner.url()).searchParams.get('order') === order &&
      (await board.innerText()).includes(fixture.cardTitle));
    check('work cards expose their native execution status in index: ' + order,
      (await board.locator('[data-sigil~="project-card"]').filter({hasText: fixture.cardTitle})
        .locator('.team-project-card-status').innerText()).includes('待完成'));
    if (order === 'author') {
      check('author indexing groups tasks from two real publishing members',
        (await board.innerText()).includes(fixture.owner.username) &&
        (await board.innerText()).includes(fixture.member.username));
    }
    if (order === 'created') {
      const cards = await board.locator('[data-sigil~="project-card"]').allInnerTexts();
      check('created-date indexing places the newest card before older cards',
        cards.findIndex(t => t.includes(fixture.cardTitle)) === 0);
    }
    if (order === 'status' || order === 'author') {
      await owner.screenshot({path: path.join(output,
        order === 'status' ? 'merged-state-index.png' : 'merged-author-index.png'), fullPage: true});
    }
  }
  await owner.setViewportSize({width: 1100, height: 900});
  await visit(owner, boardURI + '?order=natural&filter=all');
  const columns = owner.locator('[data-sigil~="project-column"]');
  const boardScroll = owner.locator('.project-board-wrapper .phui-workboard-view-shadow');
  const longColumnText = await columns.nth(0).innerText();
  check('long workboards preserve all four native columns and public long-column tasks',
    await columns.count() === 4 && fixture.longColumnTasks.length === 12 &&
    fixture.longColumnTasks.every(task => longColumnText.includes(task.title)));
  const horizontal = await boardScroll.evaluate(element => {
    const overflow = element.scrollWidth > element.clientWidth;
    element.scrollLeft = element.scrollWidth;
    const scrolled = element.scrollLeft;
    element.scrollLeft = 0;
    return {overflow, scrolled};
  });
  check('the native board retains usable horizontal scrolling when columns overflow',
    horizontal.overflow && horizontal.scrolled > 0);
  const draggedTitle = fixture.tasks.public.title;
  const card = owner.locator('[data-sigil~="project-card"]').filter({hasText: draggedTitle});
  const fromColumn = (await columns.allInnerTexts()).findIndex(t => t.includes(draggedTitle));
  const toColumn = 3;
  check('the earliest editable public task is really the final card in the long natural-order column',
    (await columns.nth(fromColumn).locator('[data-sigil~="project-card"]').last().innerText()).includes(draggedTitle));
  await card.scrollIntoViewIfNeeded();
  const vertical = await card.evaluate(element => {
    const scroller = element.closest('.phui-workpanel-body-content');
    return {overflow: scroller.scrollHeight > scroller.clientHeight, scrolled: scroller.scrollTop};
  });
  const fromBox = await card.boundingBox();
  if (!fromBox) throw new Error('The long-column card is not reachable.');
  check('the card at the end of a long column is reached through actual vertical scrolling',
    vertical.overflow && vertical.scrolled > 0 && fromBox.y >= 0 && fromBox.y + fromBox.height <= 901);
  report.longColumnDrag = {source: draggedTitle, fromColumn, toColumn,
    vertical, horizontal, fromBox};
  await owner.mouse.move(fromBox.x + 4, fromBox.y + fromBox.height - 5);
  await owner.mouse.down();
  await owner.mouse.move(fromBox.x + 15, fromBox.y + fromBox.height - 5, {steps: 4});
  await owner.waitForTimeout(250);
  // Only scrolling is assisted here. Starting, moving and dropping the card
  // remain real mouse events handled by the unmodified native board code.
  await boardScroll.evaluate(element => { element.scrollLeft = element.scrollWidth; });
  await columns.nth(toColumn).scrollIntoViewIfNeeded();
  // Keep the empty destination in the middle of the document viewport. Native
  // drag auto-scrolling can otherwise move it after scrollIntoView and make a
  // previously measured drop point land on a toolbar or beyond the column.
  await owner.evaluate(() => window.scrollTo(0, 0));
  let toBox = await columns.nth(toColumn).boundingBox();
  if (!toBox) throw new Error('The horizontally scrolled destination column is missing.');
  report.longColumnDrag.toBox = toBox;
  const dropPoint = box => ({
    x: (Math.max(0, box.x) + Math.min(1100, box.x + box.width)) / 2,
    y: (Math.max(0, box.y) + Math.min(900, box.y + box.height)) / 2,
  });
  let point = dropPoint(toBox);
  await owner.mouse.move(point.x, point.y, {steps: 25});
  await owner.waitForTimeout(250);
  toBox = await columns.nth(toColumn).boundingBox();
  if (!toBox) throw new Error('The native destination moved out of the document.');
  point = dropPoint(toBox);
  await owner.mouse.move(point.x, point.y, {steps: 5});
  await owner.waitForTimeout(50);
  report.longColumnDrag.finalToBox = await columns.nth(toColumn).boundingBox();
  const moveResponsePromise = owner.waitForResponse(response =>
    response.request().method() === 'POST' &&
    new URL(response.url()).pathname === `/project/move/${projects.main.id}/`);
  await owner.mouse.up();
  const moveResponse = await moveResponsePromise;
  report.longColumnDrag.httpStatus = moveResponse.status();
  await columns.nth(toColumn).locator('[data-sigil~="project-card"]').filter({hasText: draggedTitle}).waitFor();
  check('the native drag AJAX response moves the card without changing its completed state',
    moveResponse.status() === 200 &&
    (await columns.nth(toColumn).locator('[data-sigil~="project-card"]').filter({hasText: draggedTitle})
      .locator('.team-project-card-status').innerText()).includes('已完成'));
  await owner.reload({waitUntil: 'networkidle'});
  check('the long-column work card drag to the fourth column persists after reload',
    fromColumn !== toColumn && (await columns.nth(toColumn).innerText()).includes(draggedTitle) &&
    (await columns.nth(toColumn).locator('[data-sigil~="project-card"]').filter({hasText: draggedTitle})
      .locator('.team-project-card-status').innerText()).includes('已完成'));
  check('the separate newly created private card retains its native pending status after reload',
    (await owner.locator('[data-sigil~="project-card"]').filter({hasText: fixture.cardTitle})
      .locator('.team-project-card-status').innerText()).includes('待完成'));
  await owner.locator('[data-sigil~="project-card"]').filter({hasText: draggedTitle}).scrollIntoViewIfNeeded();
  await owner.screenshot({path: path.join(output, 'merged-native-workboard.png'), fullPage: true});
  await owner.setViewportSize({width: 1440, height: 1000});
  await visit(member, boardURI + '?filter=all');
  const memberBoard = await member.locator('[data-sigil~="jx-workboard"]').innerText();
  const memberBoardHTML = await member.content();
  check('the shared workspace filters private tasks and the new private card',
    memberBoard.includes(fixture.tasks.public.title) && memberBoard.includes(fixture.tasks.colleague.title) &&
    !memberBoardHTML.includes(fixture.tasks.private.title) && !memberBoardHTML.includes(fixture.cardTitle));
  check('read-only workspace summary retains authorized project history without disclosing task details',
    (await workspace(member).innerText()).includes(fixture.titles.completedNode) &&
    (await workspace(member).innerText()).includes(fixture.titles.comment) &&
    (await workspace(member).innerText()).includes('40%') &&
    !(await workspace(member).innerText()).includes(fixture.tasks.private.title) &&
    !(await workspace(member).innerText()).includes(fixture.tasks.private.description));
  check('read-only project viewers have no project-history draft conversion action',
    await workspace(member).getByRole('button', {name: '发布项目记录', exact: true}).count() === 0);
  const forbiddenDraft = await member.request.get(origin + `/maniphest/task/edit/?projectNode=${nodeID}`);
  const forbiddenDraftBody = await forbiddenDraft.text();
  report.readOnlyDraftRejection = {status: forbiddenDraft.status(),
    controlledPolicyDenial: controlledDraftDenial(forbiddenDraft, forbiddenDraftBody)};
  check('read-only record conversion is denied without disclosing source body',
    report.readOnlyDraftRejection.controlledPolicyDenial);
  const malformedDraft = await owner.request.get(origin + '/maniphest/task/edit/?projectNode=bad');
  const malformedDraftBody = await malformedDraft.text();
  report.malformedDraftRejection = {status: malformedDraft.status(),
    controlledPolicyDenial: controlledDraftDenial(malformedDraft, malformedDraftBody)};
  check('a malformed project record draft parameter is rejected without a server error',
    report.malformedDraftRejection.controlledPolicyDenial &&
    !/Unhandled Exception|Stack Trace|InvalidArgumentException/.test(malformedDraftBody));
  for (const key of ['discussion', 'archive']) {
    await visit(owner, `/project/board/${projects[key].id}/`);
    const historyTab = workspace(owner).getByRole('button', {name: '开发历史', exact: true});
    check('history remains accessible on an uninitialized or disabled board: ' + key,
      await historyTab.isVisible() && await historyTab.getAttribute('href') === `/project/progress/${projects[key].id}/`);
  }

  await visit(owner, progressURI + '?kind=node');
  check('node filter retains both nodes without work comments',
    (await owner.locator('.team-project-progress-history').innerText()).includes(fixture.titles.node) &&
    (await owner.locator('.team-project-progress-history').innerText()).includes(fixture.titles.completedNode) &&
    !(await owner.locator('.team-project-progress-history').innerText()).includes(fixture.titles.comment));
  await visit(owner, `/project/manage/${projects.main.id}/`);
  const nativeHistory = await owner.locator('body').innerText();
  check('native project history still renders all progress titles',
    Object.values(fixture.titles).every(title => nativeHistory.includes(title)));
  const nativeDetailLink = owner.locator('.phui-timeline-title')
    .filter({hasText: fixture.titles.node}).locator('a[href*="/transactions/detail/"]').first();
  const nativeDetailURI = await nativeDetailLink.getAttribute('href');
  await visit(owner, nativeDetailURI);
  check('native transaction details render the progress body',
    (await owner.locator('body').innerText()).includes('完成登录接口与错误提示'));
  check('native details render another mentioned user without missing project membership data',
    (await owner.locator('.team-project-progress-details').innerText()).includes('@' + fixture.member.username));
  await checkTaskReferences(owner, 'native progress detail rendered by owner first', true);
  await visit(member, nativeDetailURI);
  check('read-only native details render the owner mention with the reader policy context',
    (await member.locator('.team-project-progress-details').innerText()).includes('@' + fixture.owner.username));
  await checkTaskReferences(member, 'same native progress detail rendered by read-only member', false);

  report.feedPublication = drainNativeFeed();
  check('selected native publication workers populate actual project activity',
    report.feedPublication.passed && report.feedPublication.publishedTasks >= 8);
  await visit(owner, progressURI);
  const activityAction = owner.getByRole('button', {name: '项目全部活动', exact: true});
  const tasksAction = owner.getByRole('button', {name: '任务高级查询', exact: true});
  const activityURI = `/feed/?projectPHIDs=${projects.main.phid}`;
  const tasksURI = `/maniphest/?projectPHIDs=${projects.main.phid}`;
  check('activity and task entry points carry this project filter',
    await activityAction.getAttribute('href') === activityURI &&
    await tasksAction.getAttribute('href') === tasksURI);
  check('project record guidance distinguishes native task discussion and manual reporting',
    (await owner.locator('body').innerText()).includes('任务内评论按任务自身权限控制') &&
    (await owner.locator('body').innerText()).includes('手工填报'));
  await activityAction.click();
  await owner.waitForLoadState('networkidle');
  check('activity action opens the actual project-filtered native feed',
    new URL(owner.url()).pathname === '/feed/' &&
    new URL(owner.url()).searchParams.get('projectPHIDs') === projects.main.phid);
  const ownerFeed = owner.locator('.phui-feed-story');
  const ownerFeedText = (await ownerFeed.allInnerTexts()).join('\n');
  check('one native activity page contains manual records and native task discussion',
    Object.values(fixture.titles).every(title => ownerFeedText.includes(title)) &&
    ownerFeedText.includes(fixture.tasks.public.title) && ownerFeedText.includes(fixture.taskComment) &&
    ownerFeedText.includes(fixture.member.username));
  check('native task status changes remain visible in project activity',
    /closed[\s\S]*(?:resolved|已完成)/i.test((await ownerFeed.filter({hasText: fixture.tasks.public.title})
      .allInnerTexts()).join('\n')));
  check('project activity shows authorized private tasks and excludes another project',
    ownerFeedText.includes(fixture.tasks.private.title) && !ownerFeedText.includes(fixture.tasks.outside.title));
  for (const [kind, entry] of Object.entries({node: nodeID, comment: commentID, completedNode: completedNodeID})) {
    check('native feed ' + kind + ' title opens its canonical project record',
      await ownerFeed.getByRole('link', {name: fixture.titles[kind], exact: true}).getAttribute('href') ===
        progressURI + `?entry=${entry}#entry-${entry}`);
  }
  await owner.screenshot({path: path.join(output, 'project-activity.png'), fullPage: true});
  await ownerFeed.getByRole('link', {name: fixture.titles.node, exact: true}).click();
  await owner.waitForLoadState('networkidle');
  check('clicking the feed record title opens exactly the selected history entry',
    new URL(owner.url()).searchParams.get('entry') === nodeID &&
    await owner.locator('.team-project-progress-history [id^="entry-"]').count() === 1 &&
    await owner.locator(`#entry-${nodeID}`).count() === 1);
  await visit(owner, activityURI);
  const feedDetailURI = await ownerFeed.filter({hasText: fixture.titles.node})
    .locator('a[href*="/transactions/detail/"]').first().getAttribute('href');
  await visit(owner, feedDetailURI);
  const details = owner.locator('.team-project-progress-details');
  check('feed details expose completion, participants and fixed link without a task state',
    !(await details.innerText()).includes('节点状态') && (await details.innerText()).includes('40%') &&
    (await details.innerText()).includes(fixture.member.username) &&
    await details.locator(`a[href="${progressURI}?entry=${nodeID}#entry-${nodeID}"]`).count() === 1);
  await checkTaskReferences(owner, 'feed detail rendered by owner first', true);
  await visit(member, feedDetailURI);
  await checkTaskReferences(member, 'same feed detail rendered by read-only member', false);
  await member.screenshot({path: path.join(output, 'task-reference-policy.png'), fullPage: true});
  await visit(member, activityURI);
  const memberFeedText = (await member.locator('.phui-feed-story').allInnerTexts()).join('\n');
  check('read-only members discover task discussion alongside project records in native activity',
    memberFeedText.includes(fixture.taskComment) &&
    Object.values(fixture.titles).every(title => memberFeedText.includes(title)));
  check('native activity respects private task visibility and the project filter',
    !memberFeedText.includes(fixture.tasks.private.title) &&
    !memberFeedText.includes(fixture.tasks.private.description) &&
    !memberFeedText.includes(fixture.tasks.outside.title));
  await member.locator(`a[href="/T${fixture.tasks.public.id}"]`).first().click();
  await member.waitForLoadState('networkidle');
  check('native task activity links open the discussion with its real member author',
    new URL(member.url()).pathname === `/T${fixture.tasks.public.id}` &&
    (await member.locator('body').innerText()).includes(fixture.taskComment) &&
    (await member.locator('body').innerText()).includes(fixture.member.username));
  check('the read-only project member retains the native task comment entry',
    await member.locator('textarea[name="comment"]').isVisible());
  await visit(member, progressURI);
  await member.getByRole('button', {name: '任务高级查询', exact: true}).click();
  await member.waitForLoadState('networkidle');
  const taskList = await member.locator('body').innerText();
  check('project task entry actually filters native results without exposing private tasks',
    new URL(member.url()).pathname.startsWith('/maniphest/') &&
    taskList.includes(fixture.tasks.public.title) &&
    !taskList.includes(fixture.tasks.private.title) && !taskList.includes(fixture.tasks.outside.title));
  await visit(owner, `/project/profile/${projects.main.id}/`);
  const overviewActivity = await owner.locator('.project-view-feed').innerText();
  check('project overview combines the current summary and task-plus-project activity',
    await summary(owner).isVisible() && overviewActivity.includes(fixture.taskComment) &&
    overviewActivity.includes(fixture.titles.completedNode));
  check('native task activity has not changed the manually reported project completion',
    await summary(owner).locator('progress').getAttribute('value') === '40');
  await owner.screenshot({path: path.join(output, 'overview-with-activity.png'), fullPage: true});

  await visit(owner, `/project/profile/${projects.discussion.id}/`);
  const discussionSummary = await summary(owner).innerText();
  check('a discussion-only project displays its comment and keeps progress unknown',
    discussionSummary.includes(fixture.discussionTitle) &&
    discussionSummary.includes('暂无项目记录') && discussionSummary.includes('尚未填写') &&
    !discussionSummary.includes('还没有项目记录或工作评论记录') && await summary(owner).locator('progress').count() === 0);

  const observer = await context('observer');
  await visit(observer, progressURI);
  check('nonmember viewers can read history but have no publish form or action',
    (await observer.locator('body').innerText()).includes(fixture.titles.node) &&
    await form(observer).count() === 0 &&
    await observer.locator('a.team-project-publish, .team-project-publish a').count() === 0);
  const observerToken = await csrfToken(observer);
  check('nonmember has a valid session CSRF token for a direct request',
    typeof observerToken === 'string' && observerToken.length > 10);
  const forbiddenWrite = await rejectPublish(observer, observerToken);
  check('nonmember direct POST is denied even with a valid CSRF token', forbiddenWrite.status() === 403);
  for (const [label, token] of [['missing', undefined], ['incorrect', 'invalid-csrf-token']]) {
    const rejected = await rejectPublish(owner, token);
    const errorBody = await rejected.text();
    const csrfError = /incorrect token/i.test(errorBody) &&
      /AphrontMalformedRequestException|Invalid Request \(CSRF\)/.test(errorBody);
    // This pinned Phorge fork maps native malformed-request exceptions to 500.
    // Require the specific CSRF rejection, then verify no rows were appended.
    (report.csrfRejections ||= []).push({case: label, status: rejected.status(), csrfError});
    check('owner POST with ' + label + ' CSRF is rejected',
      [400, 403, 500].includes(rejected.status()) && csrfError);
  }
  const crossProject = await owner.request.get(origin + progressURI + `?entry=${fixture.oldKeynode.id}`);
  check('a record ID from another project cannot be read through this project URL',
    crossProject.status() === 404 && !(await crossProject.text()).includes(fixture.oldKeynode.title));
  const denied = await observer.goto(origin + `/project/progress/${projects.private.id}/`, {waitUntil: 'networkidle'});
  const deniedBody = await observer.locator('body').innerText();
  check('private project history is not disclosed by a direct URL',
    ([403, 404].includes(denied.status()) || /Access Denied|You Shall Not Pass|权限|拒绝/.test(deniedBody)) &&
    !deniedBody.includes(projects.private.name));

  await visit(owner, `/project/progress/${projects.archive.id}/`);
  const firstPageIDs = await owner.locator('.team-project-progress-history [id^="entry-"]')
    .evaluateAll(nodes => nodes.map(node => node.id));
  check('the first history page contains exactly 50 newest records',
    firstPageIDs.length === 50 && !firstPageIDs.includes(`entry-${fixture.oldKeynode.id}`));
  await owner.locator('.phui-pager-view a[href*="after="]').click();
  await owner.waitForLoadState('networkidle');
  check('next page exposes the eight older records including both legacy node types',
    await owner.locator('.team-project-progress-history [id^="entry-"]').count() === 8 &&
    await owner.locator(`#entry-${fixture.oldKeynode.id}`).count() === 1);
  await owner.locator('.phui-pager-view a[href*="before="]').click();
  await owner.waitForLoadState('networkidle');
  const returnedIDs = await owner.locator('.team-project-progress-history [id^="entry-"]')
    .evaluateAll(nodes => nodes.map(node => node.id));
  check('previous page returns to the same newest 50 records',
    JSON.stringify(returnedIDs) === JSON.stringify(firstPageIDs));
  await visit(owner, `/project/profile/${projects.archive.id}/`);
  const oldLink = summary(owner).getByRole('link', {name: fixture.oldKeynode.title, exact: true}).first();
  check('overview key-node link resolves a historical entry beyond the first page',
    await oldLink.getAttribute('href') ===
      `/project/progress/${projects.archive.id}/?entry=${fixture.oldKeynode.id}#entry-${fixture.oldKeynode.id}`);
  await oldLink.click();
  await owner.waitForLoadState('networkidle');
  check('old key node opens its exact history entry',
    await owner.locator(`#entry-${fixture.oldKeynode.id}`).count() === 1 &&
    (await owner.locator('.team-project-progress-history').innerText()).includes(fixture.oldKeynode.title));
  await owner.screenshot({path: path.join(output, 'historical-key-node.png'), fullPage: true});
  check('the latest old record retains its link and completion without restoring an execution summary',
    !(await summary(owner).innerText()).includes('最新节点状态') &&
    await summary(owner).locator('progress').getAttribute('value') === '10' &&
    await summary(owner).getByRole('link', {name: fixture.oldKeynode.title, exact: true}).count() === 1);
  const expectedLegacyIDs = fixture.archiveNodes.map(node => String(node.id)).reverse();
  for (const kind of ['node', 'update', 'milestone']) {
    await visit(owner, `/project/progress/${projects.archive.id}/?kind=${kind}`);
    const history = owner.locator('.team-project-progress-history');
    const legacyIDs = await history.locator('[id^="entry-"]').evaluateAll(nodes =>
      nodes.map(node => node.id.replace('entry-', '')));
    check('filter ' + kind + ' includes new nodes and both original legacy transaction types',
      JSON.stringify(legacyIDs) === JSON.stringify(expectedLegacyIDs));
    const actions = await history.locator('.phui-timeline-title').allInnerTexts();
    check('legacy events retain original node labels and read-only states under the unified record filter ' + kind,
      actions.length === 3 && actions.every(text => text.includes('发布了节点')) &&
      actions.every(text => !text.includes('发布了项目进展') && !text.includes('发布了关键节点')) &&
      (await history.innerText()).includes('历史节点状态（发布时）') &&
      await history.locator('[name="status"]').count() === 0);
  }
  await visit(owner, progressURI + '?kind=comment');
  check('discussion filter contains only comments and excludes both nodes',
    await owner.locator('.team-project-progress-history [id^="entry-"]').count() === 1 &&
    await owner.locator(`#entry-${commentID}`).count() === 1);

  await owner.setViewportSize({width: 390, height: 844});
  await visit(owner, `/project/profile/${projects.main.id}/`);
  check('mobile overview keeps the progress action accessible',
    await summary(owner).getByRole('button', {name: '发布项目记录', exact: true}).isVisible());
  await owner.screenshot({path: path.join(output, 'mobile-progress.png'), fullPage: true});
  await visit(owner, progressURI);
  for (const name of ['项目全部活动', '任务高级查询', '项目变更历史']) {
    check('mobile native entry keeps its visible label: ' + name,
      await owner.getByRole('button', {name, exact: true}).isVisible());
  }
  for (const name of ['全部记录', '只看项目记录', '只看工作评论']) {
    check('mobile history filter keeps its visible label: ' + name,
      await owner.locator('.team-project-progress-history')
        .getByRole('button', {name, exact: true}).isVisible());
  }
  for (const name of ['写项目记录', '写工作评论']) {
    check('mobile compose type keeps its visible label: ' + name,
      await owner.locator('.team-project-progress-publish')
        .getByRole('button', {name, exact: true}).isVisible());
  }
  check('mobile progress page exposes the publish form', await form(owner).isVisible());
  check('mobile progress page does not overflow the 390 pixel viewport',
    await owner.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await owner.screenshot({path: path.join(output, 'mobile-history-and-form.png'), fullPage: true});
  await visit(owner, boardURI + '?order=status&filter=all');
  check('mobile workboard keeps the unified history and card actions visible',
    await workspace(owner).getByRole('button', {name: '开发历史', exact: true}).isVisible() &&
    await workspace(owner).getByRole('button', {name: '新建卡片', exact: true}).isVisible());
  check('mobile workboard shows one fully labelled ordering and filtering control',
    await workspace(owner).getByRole('button', {name: '排序：按状态', exact: true}).isVisible() &&
    await workspace(owner).getByRole('button', {name: '筛选：全部卡片', exact: true}).isVisible() &&
    await owner.getByRole('button', {name: '发布项目记录', exact: true}).count() === 1);
  const mobileOrderMenu = await openBoardMenu(owner, 'sort');
  await mobileOrderMenu.getByRole('link', {name: '按发布成员', exact: true}).click();
  await owner.waitForURL(url => url.searchParams.get('order') === 'author');
  check('the consolidated mobile order menu performs a real native author-index change',
    await workspace(owner).getByRole('button', {name: '排序：按发布成员', exact: true}).isVisible() &&
    (await owner.locator('[data-sigil~="jx-workboard"]').innerText()).includes(fixture.member.username));
  await owner.screenshot({path: path.join(output, 'mobile-merged-workboard.png'), fullPage: true});
  await workspace(owner).getByRole('button', {name: '开发历史', exact: true}).click();
  await owner.waitForLoadState('networkidle');
  check('mobile workboard history tab opens the same project history',
    new URL(owner.url()).pathname === progressURI && await form(owner).isVisible());

  report.passed = true;
} catch (error) {
  report.passed = false;
  report.error = error.message;
  for (const [role, ctx] of contexts) {
    const page = ctx.pages().at(-1);
    if (page) await page.screenshot({path: path.join(output, `failure-${role}.png`), fullPage: true}).catch(() => {});
  }
} finally {
  for (const ctx of contexts.values()) await ctx.close();
  await browser.close();
  fs.writeFileSync(path.join(output, 'browser-report.json'), JSON.stringify(report, null, 2) + '\n', {mode: 0o600});
}
console.log(JSON.stringify({passed: report.passed, checks: report.checks.length, error: report.error}));
if (!report.passed) process.exitCode = 1;
