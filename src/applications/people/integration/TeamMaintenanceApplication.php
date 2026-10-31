<?php

/**
 * Native administrative UI for the private account maintenance service.
 * The browser never receives the RPC signing key or credential hashes.
 */
final class TeamMaintenanceApplication extends PhabricatorApplication {

  public function getName() { return pht('账户维护'); }
  public function getShortDescription() { return pht('注册审批、登录准入与密码管理'); }
  public function getBaseURI() { return '/maintenance/'; }
  public function getIcon() { return 'fa-user-secret'; }
  public function getApplicationGroup() { return self::GROUP_ADMIN; }
  public function canUninstall() { return true; }
  public function isUnlisted() { return !self::isEnabled(); }
  public function isLaunchable() { return self::isEnabled(); }
  public static function isEnabled() {
    return PhorgeOptionalIntegrations::isMaintenanceEnabled();
  }
  public function getQuicksandURIPatternBlacklist() {
    // Maintenance uses a stricter form Origin policy than ordinary pages.
    // Full navigation installs its document referrer policy before any POST.
    return array('/maintenance/.*');
  }
  public function isPinnedByDefault(PhabricatorUser $viewer) {
    return self::isEnabled() && $viewer->getIsAdmin();
  }

  public function getPolicy($capability) {
    return PhabricatorPolicies::POLICY_ADMIN;
  }

  public function getRoutes() {
    if (!self::isEnabled()) { return array(); }
    return array(
      '/maintenance/' => array(
        '' => 'TeamMaintenanceOverviewController',
        'registrations/' => 'TeamMaintenanceRegistrationsController',
        'users/' => 'TeamMaintenanceUsersController',
        'audit/' => 'TeamMaintenanceAuditController',
        'registration/(?P<operation>approve|reject)/' =>
          'TeamMaintenanceRegistrationActionController',
        'user/(?P<operation>status|password)/' =>
          'TeamMaintenanceUserActionController',
      ),
    );
  }
}

final class TeamMaintenanceServiceException extends Exception {
  private $serviceCode;

  public function __construct($code) {
    $this->serviceCode = $code;
    // Never include request bodies, passwords or private service responses.
    parent::__construct('The account maintenance request could not be completed.');
  }

  public function getServiceCode() { return $this->serviceCode; }
}

final class TeamMaintenanceHTMLResponse extends AphrontHTMLResponse {
  private $content;

  public function setContent($content) { $this->content = $content; return $this; }
  public function buildResponseString() { return $this->content; }

  public function getHeaders() {
    $headers = array();
    foreach (parent::getHeaders() as $header) {
      if (strcasecmp($header[0], 'Referrer-Policy') !== 0) { $headers[] = $header; }
    }
    $headers[] = array('Referrer-Policy', 'same-origin');
    return $headers;
  }
}

final class TeamMaintenanceClient extends Phobject {
  public function call(PhabricatorUser $actor, $principal, $action,
    array $params, $request_id = null) {

    if (!is_string($principal) || $principal === '' || strlen($principal) > 200) {
      throw new TeamMaintenanceServiceException('forbidden');
    }
    if ($request_id === null) {
      $request_id = bin2hex(random_bytes(16));
    }
    if (!preg_match('/^[0-9a-f]{32}$/D', $request_id)) {
      throw new TeamMaintenanceServiceException('invalid_input');
    }
    try { $settings = PhorgeOptionalIntegrations::getMaintenanceConfiguration(); }
    catch (Exception $ex) { throw new TeamMaintenanceServiceException('service_unavailable'); }
    $key = @file_get_contents($settings['key-file']);
    if (!is_string($key) || strlen($key) !== 32 || !function_exists('curl_init')) {
      throw new TeamMaintenanceServiceException('service_unavailable');
    }
    $body = json_encode(array(
      'action' => $action,
      'actor_phid' => $actor->getPHID(),
      'actor_principal' => $principal,
      'request_id' => $request_id,
      'params' => (object)$params,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false || strlen($body) > 16384) {
      throw new TeamMaintenanceServiceException('invalid_input');
    }
    $timestamp = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256',
      "POST\n/rpc\n".$timestamp."\n".$nonce."\n".hash('sha256', $body), $key);
    unset($key);
    $response = '';
    $handle = curl_init($settings['endpoint']);
    curl_setopt_array($handle, array(
      CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Maintenance-Timestamp: '.$timestamp,
        'X-Maintenance-Nonce: '.$nonce,
        'X-Maintenance-Signature: '.$signature,
      ),
      CURLOPT_CONNECTTIMEOUT => 3,
      CURLOPT_TIMEOUT => 90,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_MAXREDIRS => 0,
      CURLOPT_PROXY => '',
      CURLOPT_WRITEFUNCTION => static function($curl, $chunk) use (&$response) {
        if (strlen($response) + strlen($chunk) > 1048576) {
          return 0;
        }
        $response .= $chunk;
        return strlen($chunk);
      },
    ));
    $completed = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    unset($body, $signature);
    if ($completed === false || $status < 200 || $status >= 500) {
      throw new TeamMaintenanceServiceException('service_unavailable');
    }
    $result = json_decode($response, true);
    unset($response);
    if (!is_array($result)) {
      throw new TeamMaintenanceServiceException('service_unavailable');
    }
    if (idx($result, 'ok') !== true) {
      $error = idx($result, 'error', array());
      $code = is_array($error) ? idx($error, 'code') : null;
      if (!is_string($code) || !preg_match('/^[a-z_]{1,64}$/D', $code)) {
        $code = 'service_unavailable';
      }
      throw new TeamMaintenanceServiceException($code);
    }
    if ($status !== 200 || !is_array(idx($result, 'data'))) {
      throw new TeamMaintenanceServiceException('service_unavailable');
    }
    return $result['data'];
  }
}

abstract class TeamMaintenanceController extends PhabricatorController {
  const PAGE_SIZE = 50;

  public function shouldRequireAdmin() { return true; }

  public function willBeginExecution() {
    if (!TeamMaintenanceApplication::isEnabled()) { return new Aphront404Response(); }
    $response = parent::willBeginExecution();
    if ($response !== null) {
      return $response;
    }
    $viewer = $this->getViewer();
    if (!$viewer->getIsAdmin() || !$viewer->getIsApproved() ||
        $viewer->getIsDisabled() || !$viewer->hasSession() ||
        $viewer->getSession()->getIsPartial() ||
        $viewer->getSession()->getType() !== PhabricatorAuthSession::TYPE_WEB) {
      return new Aphront403Response();
    }
    $request = $this->getRequest();
    if ($request->isHTTPPost()) {
      $origin = AphrontRequest::getHTTPHeader('Origin');
      $site = AphrontRequest::getHTTPHeader('Sec-Fetch-Site');
      $uri = new PhutilURI(PhabricatorEnv::getEnvConfig('phabricator.base-uri'));
      $expected = $uri->getProtocol().'://'.$uri->getDomain();
      $port = $uri->getPort();
      if ($port !== null && $port !== '' && (string)$port !== ($uri->getProtocol() === 'https' ? '443' : '80')) {
        $expected .= ':'.$port;
      }
      if (!is_string($origin) || !hash_equals($expected, $origin) ||
          $site === 'cross-site') {
        return new Aphront403Response();
      }
      // Native forms carry both __form__ and the session-bound __csrf__.
      try {
        if (!$request->isFormPost()) {
          return new Aphront403Response();
        }
      } catch (AphrontMalformedRequestException $ex) {
        // CSRF failures occur before the action controller runs. Reject them
        // explicitly instead of exposing the framework's exception page.
        return new Aphront400Response();
      }
    } else if (!$request->isHTTPGet()) {
      return id(new AphrontPlainTextResponse())
        ->setHTTPResponseCode(405)->setContent('Method not allowed.');
    }
  }

  public function willSendResponse(AphrontResponse $response) {
    $response = parent::willSendResponse($response);
    if (!($response instanceof TeamMaintenanceHTMLResponse)) {
      $response->addHeader('Referrer-Policy', 'same-origin');
    }
    $response->addHeader('Cache-Control', 'private, no-store, max-age=0');
    $response->addHeader('Pragma', 'no-cache');
    return $response;
  }

  public function buildApplicationMenu() {
    $menu = id(new PHUIApplicationMenuView())->setViewer($this->getViewer());
    foreach ($this->navigation() as $path => $label) {
      $menu->addLink($label, $path);
    }
    return $menu;
  }

  private function navigation() {
    $links = array('/maintenance/' => '维护概览',
      '/maintenance/registrations/' => '注册审批',
      '/maintenance/users/' => '用户与白名单', '/maintenance/audit/' => '操作记录');
    if (class_exists('TeamOrganizationApplication') &&
        PhabricatorApplication::isClassInstalledForViewer(
          'TeamOrganizationApplication', $this->getViewer())) {
      $links['/organization/'] = '团队架构';
    }
    return $links;
  }

  protected function service($action, array $params = array(), $request_id = null) {
    return id(new TeamMaintenanceClient())->call($this->getViewer(),
      AphrontRequest::getHTTPHeader(
        PhorgeOptionalIntegrations::getEntryConfiguration()['principal-header']), $action, $params, $request_id);
  }

  protected function page($title, $content, $status = 200) {
    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb($title)->setBorder(true);
    $links = array();
    foreach ($this->navigation() as $path => $label) {
      $links[] = id(new PHUIButtonView())->setTag('a')
        ->setHref($path)->setText($label)
        ->setColor(PHUIButtonView::GREY);
    }
    $notice = null;
    $done = $this->getRequest()->getStr('done', '');
    $messages = array(
      'approved' => '注册申请已批准，申请人现在可以使用注册时设置的密码登录。',
      'rejected' => '注册申请已拒绝，原因已记录。',
      'status' => '登录准入与业务账号状态已更新。',
      'entry_only' => '已撤销此条目的登录准入；业务账号状态未修改。',
      'password' => '密码已设置，原密码登录会话已撤销。请通过合适的方式告知账户本人。',
    );
    if (isset($messages[$done])) {
      $notice = $this->notice($messages[$done], PHUIInfoView::SEVERITY_SUCCESS);
    }
    $view = $this->newPage()->setTitle($title)->appendChild(array(
      $crumbs,
      id(new PHUIHeaderView())->setHeader($title)->setHeaderIcon('fa-users'),
      id(new PHUIBoxView())->addPadding(PHUI::PADDING_LARGE)
        ->appendChild(phutil_tag('div', array(
          'style' => 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;',
          'aria-label' => '账户维护导航',
        ), $links))->appendChild(array($notice, $content)),
    ));
    // Phorge's base page injects a no-referrer meta tag. A later explicit
    // policy is required as well as the response header, or browser form
    // POSTs acquire Origin:null and correctly fail our same-origin check.
    $view->addHeadItem(phutil_tag('meta', array(
      'name' => 'referrer', 'content' => 'same-origin')));
    $response = $view->produceAphrontResponse();
    if ($response instanceof AphrontWebpageResponse) {
      $response = id(new TeamMaintenanceHTMLResponse())
        ->setContent($response->buildResponseString());
    }
    return $response->setHTTPResponseCode($status);
  }

  protected function notice($message, $severity = PHUIInfoView::SEVERITY_NOTICE) {
    return id(new PHUIInfoView())->setSeverity($severity)
      ->setErrors(array($message));
  }

  protected function serviceError(TeamMaintenanceServiceException $error) {
    $messages = array(
      'forbidden' => '当前账户没有维护权限，请重新登录后重试。',
      'unauthorized' => '当前身份校验未通过，请重新登录后重试。',
      'invalid_input' => '提交内容不符合要求，请检查必填项和确认信息。',
      'invalid_request' => '提交内容不符合要求，请检查必填项和确认信息。',
      'not_found' => '记录不存在或已经变化，请刷新列表。',
      'conflict' => '记录状态或资料已经变化，请刷新后重新确认。',
      'identity_conflict' => '账户身份资料存在冲突，请刷新并核对注册资料与已有账号。',
      'operation_conflict' => '此操作编号已有不同结果，请刷新页面后重新确认操作。',
      'protected_account' => '不能停用当前管理员或最后一名可用管理员。',
      'last_admin' => '至少需要保留一名能够登录的管理员。',
      'self_disable' => '不能停用当前登录的管理员。',
      'invalid_password' => '密码须为 15 至 128 个字符，并与确认密码一致。',
      'password_invalid' => '密码须为 15 至 128 个字符，并与确认密码一致。',
      'legacy_application' => '该历史申请没有可用密码，需要重新提交带密码的注册申请。',
      'rate_limited' => '操作过于频繁，请稍后重试。',
      'service_unavailable' => '维护服务暂时不可用，请稍后重试。',
      'native_unavailable' => '业务账号服务暂时不可用，请稍后重试。',
      'storage_unavailable' => '维护数据暂时不可用，请稍后重试。',
      'operation_in_progress' => '此操作仍在处理中，请稍后刷新后确认结果。',
      'unsupported_phone' => '当前仅支持审批中国大陆 11 位手机号；该申请的号码暂不支持，重复提交不会成功。请核对申请资料或拒绝申请并说明原因。',
      'operation_failed' => '操作未完成，状态已保留。请刷新记录后重试。',
    );
    return idx($messages, $error->getServiceCode(),
      '操作未完成，请刷新记录并核对账户资料后重试。');
  }

  protected function errorPage($title, TeamMaintenanceServiceException $error) {
    $code = $error->getServiceCode();
    $status = in_array($code, array('forbidden', 'unauthorized'), true) ? 403 :
      (in_array($code, array('service_unavailable', 'native_unavailable', 'storage_unavailable'), true) ? 503 : 409);
    return $this->page($title,
      $this->notice($this->serviceError($error), PHUIInfoView::SEVERITY_ERROR),
      $status);
  }

  protected function table($title, array $headers, array $rows) {
    $table = id(new AphrontTableView($rows))->setHeaders($headers)
      ->setNoDataString('暂无符合条件的记录。')->setDeviceReadyTable(true);
    return id(new PHUIObjectBoxView())->setHeaderText($title)
      ->appendChild(phutil_tag('div', array(
        'style' => 'overflow-x:auto;', 'tabindex' => '0',
        'aria-label' => $title,
      ), $table));
  }

  protected function searchForm($path, $q, $status = null) {
    $form = id(new AphrontFormView())->setViewer($this->getViewer())
      ->setMethod('GET')->setAction($path)
      ->appendControl(id(new AphrontFormTextControl())->setName('q')
        ->setLabel('搜索')->setValue($q)->setPlaceholder('姓名、手机号、邮箱或账号'));
    if ($status !== null) {
      $form->appendControl(id(new AphrontFormSelectControl())->setName('status')
        ->setLabel('审批状态')->setValue($status)->setOptions(array(
          'pending' => '待审批', 'approved' => '已批准',
          'rejected' => '已拒绝', 'all' => '全部状态',
        )));
    }
    return $form->appendControl(id(new AphrontFormSubmitControl())->setValue('查询'));
  }

  protected function listParams($request) {
    $q = $request->getStr('q', '');
    if (strlen($q) > 200) { $q = ''; }
    return array('q' => $q,
      'offset' => max(0, min(1000000, $request->getInt('offset'))),
      'limit' => self::PAGE_SIZE);
  }

  protected function pager($path, array $params, $total) {
    $offset = $params['offset'];
    $total = max(0, (int)$total);
    $links = array(phutil_tag('span', array(), '共 '.$total.' 条记录'));
    foreach (array(-self::PAGE_SIZE => '上一页', self::PAGE_SIZE => '下一页') as $delta => $label) {
      $next = $offset + $delta;
      if ($next < 0 || $next >= $total) { continue; }
      $query = $params;
      $query['offset'] = $next;
      unset($query['limit']);
      $links[] = phutil_tag('a', array('href' => (string)new PhutilURI($path, $query)), $label);
    }
    return phutil_tag('div', array('style' => 'display:flex;gap:16px;margin:16px 0;'), $links);
  }

  protected function date($timestamp) {
    return $timestamp ? phabricator_datetime((int)$timestamp, $this->getViewer()) : '—';
  }

  protected function statusText($status) {
    return idx(array('pending' => '待审批', 'approved' => '已批准',
      'rejected' => '已拒绝', 'active' => '允许登录', 'inactive' => '已停用',
      'success' => '成功', 'failed' => '未完成', 'failure' => '失败',
      'succeeded' => '成功', 'intent' => '处理中', 'partial' => '待继续处理',
      'started' => '处理中'), $status, '待核查');
  }

  protected function actionLink($path, $id, $label) {
    return phutil_tag('a', array('href' => (string)new PhutilURI(
      '/maintenance/'.$path, array('id' => $id))), $label);
  }

  protected function requestID(AphrontRequest $request) {
    $value = $request->isHTTPPost() ? $request->getStr('request_id', '') : '';
    if ($request->isHTTPPost() && !preg_match('/^[0-9a-f]{32}$/D', $value)) {
      throw new TeamMaintenanceServiceException('invalid_input');
    }
    return $value !== '' ? $value : bin2hex(random_bytes(16));
  }

  protected function actionForm($path, $id, $request_id) {
    return id(new AphrontFormView())->setViewer($this->getViewer())
      ->setID('maintenance-action-form')
      ->setAction((string)new PhutilURI('/maintenance/'.$path, array('id' => $id)))
      ->addHiddenInput('request_id', $request_id);
  }

  protected function addConfirmation($form, $label) {
    return $form->appendControl(id(new AphrontFormCheckboxControl())
      ->setLabel('操作确认')->addCheckbox('confirm', '1', $label, false, 'maintenance-confirm'));
  }

  protected function exactItem($action, $key, $id) {
    if (!is_string($id) || $id === '' || strlen($id) > 200) {
      throw new TeamMaintenanceServiceException('not_found');
    }
    $params = array('q' => $id, 'offset' => 0, 'limit' => 100);
    if ($action === 'list_registrations') { $params['status'] = 'all'; }
    $list = $this->service($action, $params);
    foreach (idx($list, 'items', array()) as $item) {
      if (idx($item, $key) === $id) { return $item; }
    }
    throw new TeamMaintenanceServiceException('not_found');
  }

  protected function addReason($form, $value, $label = '操作原因') {
    return $form->appendControl(id(new AphrontFormTextAreaControl())
      ->setName('reason')->setID('maintenance-reason')->setLabel($label)
      ->setValue($value)->setHeight(AphrontFormTextAreaControl::HEIGHT_SHORT)
      ->setCaption('必填，最多 500 个字符；请勿填写密码或验证码。'));
  }
}

final class TeamMaintenanceOverviewController extends TeamMaintenanceController {
  public function handleRequest(AphrontRequest $request) {
    if ($request->isHTTPPost()) { return new Aphront403Response(); }
    try { $data = $this->service('overview'); }
    catch (TeamMaintenanceServiceException $ex) { return $this->errorPage('账户维护', $ex); }
    $counts = idx($data, 'counts', array());
    $rows = array(
      array($this->actionLink('registrations/', '', '待审批注册'), (int)idx($counts, 'pending', 0)),
      array('已批准注册', (int)idx($counts, 'approved', 0)),
      array('已拒绝注册', (int)idx($counts, 'rejected', 0)),
      array('准入已启用', (int)idx($counts, 'active', 0)),
      array('准入已停用', (int)idx($counts, 'inactive', 0)),
      array('已设置个人密码', (int)idx($counts, 'passwords', 0)),
    );
    return $this->page('账户维护', array(
      $this->notice('在这里审批注册申请、维护登录准入、设置密码并查看操作记录。新用户先提交注册申请，批准后获得普通账户；项目和团队权限另行分配。'),
      $this->table('当前账户状态', array('项目', '数量'), $rows),
      $this->notice('项目、团队及对象权限在各自的权限管理入口另行设置。密码和验证码不会出现在操作记录中。'),
    ));
  }
}

final class TeamMaintenanceRegistrationsController extends TeamMaintenanceController {
  public function handleRequest(AphrontRequest $request) {
    if ($request->isHTTPPost()) { return new Aphront403Response(); }
    $params = $this->listParams($request);
    $status = $request->getStr('status', 'pending');
    if (!in_array($status, array('pending', 'approved', 'rejected', 'all'), true)) { $status = 'pending'; }
    $params['status'] = $status;
    try { $data = $this->service('list_registrations', $params); }
    catch (TeamMaintenanceServiceException $ex) { return $this->errorPage('注册审批', $ex); }
    $rows = array();
    foreach (idx($data, 'items', array()) as $item) {
      $actions = array();
      $id = idx($item, 'registration_id');
      if (idx($item, 'can_approve')) { $actions[] = $this->actionLink('registration/approve/', $id, '审核批准'); }
      if (idx($item, 'can_reject')) { $actions[] = $this->actionLink('registration/reject/', $id, '拒绝申请'); }
      $marker = phutil_tag('span', array(
        'data-maintenance-registration-id' => $id,
        'data-maintenance-status' => idx($item, 'status'),
      ), idx($item, 'name'));
      $rows[] = array($marker, idx($item, 'phone'), idx($item, 'email'),
        $this->statusText(idx($item, 'status')), $this->date(idx($item, 'created_at')),
        idx($item, 'password_available') ? '已设置' : '未设置',
        idx($item, 'approval_note', ''),
        phutil_tag('div', array('style' => 'display:flex;gap:12px;white-space:nowrap;'), $actions));
    }
    return $this->page('注册审批', array(
      $this->notice('请核实姓名、手机号和邮箱对应同一申请人。批准不会自动验证邮箱，也不会自动加入团队或项目。'),
      $this->searchForm('/maintenance/registrations/', $params['q'], $status),
      $this->table('注册申请', array('姓名', '手机号', '邮箱', '状态', '提交时间', '密码', '审核备注', '操作'), $rows),
      $this->pager('/maintenance/registrations/', $params, idx($data, 'total', 0)),
    ));
  }
}

final class TeamMaintenanceUsersController extends TeamMaintenanceController {
  public function handleRequest(AphrontRequest $request) {
    if ($request->isHTTPPost()) { return new Aphront403Response(); }
    $params = $this->listParams($request);
    try { $data = $this->service('list_users', $params); }
    catch (TeamMaintenanceServiceException $ex) { return $this->errorPage('用户与白名单', $ex); }
    $rows = array();
    foreach (idx($data, 'items', array()) as $item) {
      $actions = array(); $id = idx($item, 'user_id');
      if (idx($item, 'can_disable') || idx($item, 'can_enable')) {
        $actions[] = $this->actionLink('user/status/', $id, '调整准入');
      }
      if (idx($item, 'can_set_password')) {
        $actions[] = $this->actionLink('user/password/', $id,
          idx($item, 'has_password') ? '重置密码' : '设置密码');
      }
      $native_phid = idx($item, 'phorge_phid');
      if (class_exists('TeamOrganizationApplication') &&
          PhabricatorApplication::isClassInstalledForViewer(
            'TeamOrganizationApplication', $this->getViewer()) &&
          is_string($native_phid) && preg_match('/\APHID-USER-[a-z0-9]+\z/', $native_phid)) {
        $actions[] = phutil_tag('a', array(
          'href' => '/organization/user/'.$native_phid.'/'), '分配团队');
      }
      $native = !idx($item, 'native_state_known', true) ? '无法确认' :
        (idx($item, 'is_disabled') ? '已停用' :
          (idx($item, 'is_approved') ? '已批准' : '待核查'));
      $marker = phutil_tag('span', array(
        'data-maintenance-user-id' => $id,
        'data-maintenance-entry-status' => idx($item, 'entry_status'),
        'data-maintenance-native-disabled' => idx($item, 'is_disabled') ? '1' : '0',
        'data-maintenance-has-password' => idx($item, 'has_password') ? '1' : '0',
        'data-maintenance-entry-only' => idx($item, 'entry_only') ? '1' : '0',
      ), idx($item, 'name'));
      $rows[] = array($marker, idx($item, 'username'), idx($item, 'mail'),
        idx($item, 'phone', '—'), idx($item, 'is_admin') ? '管理员' : '普通用户',
        $this->statusText(idx($item, 'entry_status')), $native,
        idx($item, 'has_password') ? '已设置' : '未设置',
        array(phutil_tag('div', array('style' => 'display:flex;gap:12px;white-space:nowrap;'), $actions),
          phutil_tag('small', array(), idx($item, 'manage_reason', ''))));
    }
    return $this->page('用户与白名单', array(
      $this->notice('身份绑定完整的账户会同步登录准入与业务账号状态；无法确认业务身份的条目只允许撤销入口准入，不能开通或改密。重新启用不增加项目权限。新增用户请先申请注册，再到“注册审批”批准。'),
      $this->searchForm('/maintenance/users/', $params['q']),
      $this->table('用户准入', array('姓名', '账号', '邮箱', '手机号', '角色', '登录准入', '业务账号', '密码', '操作'), $rows),
      $this->pager('/maintenance/users/', $params, idx($data, 'total', 0)),
    ));
  }
}

final class TeamMaintenanceAuditController extends TeamMaintenanceController {
  public function handleRequest(AphrontRequest $request) {
    if ($request->isHTTPPost()) { return new Aphront403Response(); }
    $params = $this->listParams($request);
    try { $data = $this->service('list_audit', $params); }
    catch (TeamMaintenanceServiceException $ex) { return $this->errorPage('操作记录', $ex); }
    $actions = array('approve' => '批准注册', 'reject' => '拒绝注册',
      'set_user_status' => '调整准入', 'set_password' => '设置密码');
    $rows = array();
    foreach (idx($data, 'items', array()) as $item) {
      $rows[] = array(phutil_tag('span', array('data-maintenance-audit-id' => idx($item, 'id')),
          $this->date(idx($item, 'created_at'))),
        idx($item, 'actor_username'), idx($actions, idx($item, 'action'), '账户操作'),
        idx($item, 'target'), $this->statusText(idx($item, 'status')),
        idx($item, 'reason', ''), idx($item, 'request_id'));
    }
    return $this->page('操作记录', array(
      $this->searchForm('/maintenance/audit/', $params['q']),
      $this->table('维护操作记录', array('时间', '操作人', '动作', '对象', '结果', '原因', '操作编号'), $rows),
      $this->pager('/maintenance/audit/', $params, idx($data, 'total', 0)),
    ));
  }
}

final class TeamMaintenanceRegistrationActionController extends TeamMaintenanceController {
  public function handleRequest(AphrontRequest $request) {
    $operation = $request->getURIData('operation');
    $title = $operation === 'approve' ? '批准注册申请' : '拒绝注册申请';
    $id = $request->getStr('id', '');
    $errors = array(); $note = ''; $username = '';
    try {
      $item = $this->exactItem('list_registrations', 'registration_id', $id);
      $request_id = $this->requestID($request);
      if (!$request->isHTTPPost() && !idx($item, 'can_'.$operation)) {
        throw new TeamMaintenanceServiceException('conflict');
      }
      if ($request->isHTTPPost()) {
        $note = $request->getStr($operation === 'approve' ? 'note' : 'reason', '');
        $username = $request->getStr('username', '');
        if ($request->getStr('confirm', '') !== '1') {
          $errors[] = '请核对申请人资料，并勾选操作确认。';
        }
        if (phutil_utf8_strlen($note) > 500 || ($operation === 'reject' && trim($note) === '')) {
          $errors[] = '拒绝原因不能为空，备注或原因不能超过 500 个字符。';
        }
        if (!$errors) {
          $params = array('registration_id' => $id);
          if ($operation === 'approve') {
            $params['confirmed_phone'] = $item['phone'];
            $params['note'] = $note;
            if ($username !== '') { $params['username'] = $username; }
          } else { $params['reason'] = $note; }
          $this->service($operation, $params, $request_id);
          return id(new AphrontRedirectResponse())->setHTTPResponseCode(303)
            ->setURI('/maintenance/registrations/?status=all&done='.
              ($operation === 'approve' ? 'approved' : 'rejected'));
        }
      }
    } catch (TeamMaintenanceServiceException $ex) {
      if (!isset($item, $request_id) || !$request->isHTTPPost()) {
        return $this->errorPage($title, $ex);
      }
      $errors[] = $this->serviceError($ex);
    }
    $form = $this->actionForm('registration/'.$operation.'/', $id, $request_id);
    foreach (array('name' => '姓名', 'phone' => '手机号', 'email' => '邮箱') as $key => $label) {
      $form->appendControl(id(new AphrontFormStaticControl())->setLabel($label)->setValue($item[$key]));
    }
    if ($operation === 'approve') {
      $form->appendInstructions('批准后创建普通账户并允许密码登录，不会授予管理员或项目成员权限。请先核实以上资料。');
      $form->appendControl(id(new AphrontFormTextControl())->setName('username')
        ->setID('maintenance-username')->setLabel('账号名（可选）')->setValue($username)
        ->setCaption('3 至 64 位小写字母、数字、点、下划线或连字符；留空自动生成，登录仍使用手机号或邮箱。'));
      $form->appendControl(id(new AphrontFormTextAreaControl())->setName('note')
        ->setID('maintenance-note')->setLabel('审核备注')->setValue($note)
        ->setHeight(AphrontFormTextAreaControl::HEIGHT_SHORT)->setCaption('最多 500 个字符。'));
    } else {
      $form->appendInstructions('拒绝后不会开通账户，申请人可以重新提交注册申请。');
      $this->addReason($form, $note, '拒绝原因');
    }
    $this->addConfirmation($form, $operation === 'approve' ? '已核实申请人资料，同意开通普通账户。' : '确认拒绝该注册申请。');
    $form->appendControl(id(new AphrontFormSubmitControl())->setValue($title)
      ->addCancelButton('/maintenance/registrations/', '返回申请列表'));
    return $this->page($title, id(new PHUIObjectBoxView())->setHeaderText($title)
      ->setFormErrors($errors)->setForm($form), $errors ? 400 : 200);
  }
}

final class TeamMaintenancePasswordControl extends AphrontFormControl {
  protected function getCustomControlClass() { return 'aphront-form-control-password'; }

  protected function renderInput() {
    return phutil_tag('input', array('type' => 'password',
      'name' => $this->getName(), 'id' => $this->getID(),
      'autocomplete' => 'new-password', 'maxlength' => '256', 'required' => 'required'));
  }
}

final class TeamMaintenanceUserActionController extends TeamMaintenanceController {
  public function handleRequest(AphrontRequest $request) {
    $operation = $request->getURIData('operation');
    $title = $operation === 'password' ? '设置账户密码' : '调整登录准入';
    $id = $request->getStr('id', '');
    $errors = array(); $reason = ''; $new_status = '';
    try {
      $item = $this->exactItem('list_users', 'user_id', $id);
      $request_id = $this->requestID($request);
      if (!$request->isHTTPPost() && $operation === 'password' && !idx($item, 'can_set_password')) {
        throw new TeamMaintenanceServiceException('conflict');
      }
      if (!$request->isHTTPPost() && $operation === 'status' && !idx($item, 'can_enable') && !idx($item, 'can_disable')) {
        throw new TeamMaintenanceServiceException('protected_account');
      }
      $new_status = idx($item, 'can_disable') ? 'inactive' : 'active';
      if ($request->isHTTPPost()) {
        $reason = $request->getStr('reason', '');
        if ($request->getStr('confirm', '') !== '1') { $errors[] = '请勾选操作确认。'; }
        if (trim($reason) === '' || phutil_utf8_strlen($reason) > 500) {
          $errors[] = '请填写操作原因，最多 500 个字符。';
        }
        $params = array('user_id' => $id, 'reason' => $reason);
        if ($operation === 'password') {
          // getStr() normalizes CR/CRLF. Passwords must preserve exact input.
          // Also reject query-string credentials, even on a POST request.
          $raw = $request->getPassthroughRequestData();
          $password = idx($raw, 'password');
          $confirmation = idx($raw, 'password_confirm');
          if (!is_string($password) || !is_string($confirmation) ||
              !array_key_exists('password', $_POST) ||
              !array_key_exists('password_confirm', $_POST) ||
              array_key_exists('password', $_GET) ||
              array_key_exists('password_confirm', $_GET)) {
            throw new TeamMaintenanceServiceException('invalid_input');
          }
          if (!preg_match('//u', $password) || strpos($password, "\0") !== false ||
              phutil_utf8_strlen($password) < 15 || phutil_utf8_strlen($password) > 128 ||
              !hash_equals($password, $confirmation)) {
            $errors[] = '密码须为 15 至 128 个字符，且两次输入一致。首尾空格会保留。';
          }
          if (($password !== '' && strpos($reason, $password) !== false) ||
              ($confirmation !== '' && strpos($reason, $confirmation) !== false)) {
            $errors[] = '操作原因中不能包含新密码。';
            $reason = '';
          }
          $params['password'] = $password;
          $params['password_confirm'] = $confirmation;
        } else {
          $new_status = $request->getStr('new_status', '');
          // The service rechecks target safeguards and handles retries by
          // request_id. A completed retry must not be rejected just because
          // its first request already changed the displayed state.
          if (!in_array($new_status, array('active', 'inactive'), true)) {
            $errors[] = '此账号目前不能执行所选准入操作，请刷新页面。';
          }
          $params['status'] = $new_status;
        }
        if (!$errors) {
          $result = $this->service($operation === 'password' ? 'set_password' : 'set_user_status',
            $params, $request_id);
          unset($password, $confirmation, $params);
          if (idx($result, 'reauth_required')) { return $this->reauthenticate($request); }
          $done = $operation === 'password' ? 'password' :
            (idx($result, 'entry_only') ? 'entry_only' : 'status');
          return id(new AphrontRedirectResponse())->setHTTPResponseCode(303)
            ->setURI('/maintenance/users/?done='.$done);
        }
        unset($password, $confirmation, $params);
      }
    } catch (TeamMaintenanceServiceException $ex) {
      unset($password, $confirmation, $params);
      if (!isset($item, $request_id) || !$request->isHTTPPost()) {
        return $this->errorPage($title, $ex);
      }
      $errors[] = $this->serviceError($ex);
    }
    $form = $this->actionForm('user/'.$operation.'/', $id, $request_id);
    foreach (array('name' => '姓名', 'username' => '账号', 'mail' => '邮箱', 'phone' => '手机号') as $key => $label) {
      $form->appendControl(id(new AphrontFormStaticControl())->setLabel($label)->setValue(idx($item, $key, '—')));
    }
    if ($operation === 'password') {
      $own = idx($item, 'phorge_phid') === $this->getViewer()->getPHID();
      $form->appendInstructions($own ?
        '正在修改自己的密码。保存后将退出当前登录，请使用新密码重新登录。' :
        '设置新密码后，旧密码和原密码登录会话立即失效；独立验证码会话不因改密自动失效。');
      $form->appendControl(id(new TeamMaintenancePasswordControl())->setName('password')
        ->setID('maintenance-password')->setLabel('新密码')
        ->setCaption('15 至 128 个字符，可以包含中文和空格；不会回显或保存明文。'));
      $form->appendControl(id(new TeamMaintenancePasswordControl())->setName('password_confirm')
        ->setID('maintenance-password-confirm')->setLabel('确认新密码'));
    } else {
      $options = array();
      $entry_only = idx($item, 'entry_only');
      if (idx($item, 'can_disable')) {
        $options['inactive'] = $entry_only ? '仅撤销登录准入' : '停用登录准入与业务账号';
      }
      if (idx($item, 'can_enable')) { $options['active'] = '重新允许登录并启用业务账号'; }
      if ($entry_only) {
        $form->appendControl(id(new AphrontFormStaticControl())->setLabel('入口标识')
          ->setValue($id));
      }
      $form->appendControl(id(new AphrontFormSelectControl())->setName('new_status')
        ->setID('maintenance-status')->setLabel('调整为')->setOptions($options)->setValue($new_status));
      $form->appendInstructions($entry_only ?
        '此条目无法确认唯一的业务账号绑定。本次仅撤销登录准入，不修改业务账号状态，也不能通过此页面重新开通。' :
        '只改变该账户的可登录状态，不调整团队、项目或对象可见性。');
    }
    $this->addReason($form, $reason);
    $this->addConfirmation($form, $operation === 'password' ? '确认替换此账户的个人密码。' : '确认调整此账户的登录准入。');
    $form->appendControl(id(new AphrontFormSubmitControl())->setValue($operation === 'password' ? '保存新密码' : '保存准入状态')
      ->addCancelButton('/maintenance/users/', '返回用户列表'));
    return $this->page($title, id(new PHUIObjectBoxView())->setHeaderText($title)
      ->setFormErrors($errors)->setForm($form), $errors ? 400 : 200);
  }

  private function reauthenticate(AphrontRequest $request) {
    $viewer = $this->getViewer();
    if ($viewer->hasSession()) {
      id(new PhabricatorAuthSessionEngine())->logoutSession($viewer, $viewer->getSession());
    }
    $request->clearCookie(PhabricatorCookies::COOKIE_SESSION);
    $request->clearCookie(PhabricatorCookies::COOKIE_USERNAME);
    // The configured gateway must use a host-only cookie. Clear that cookie
    // as well when the operator entered via an independent OTP session.
    $settings = PhorgeOptionalIntegrations::getEntryConfiguration();
    return id(new AphrontRedirectResponse())->setHTTPResponseCode(303)
      ->setURI($settings['entry-login-uri'])
      ->addHeader('Set-Cookie', $settings['cookie'].
        '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax');
  }
}
