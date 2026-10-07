<?php

final class PhabricatorConfigClusterNotificationsController
  extends PhabricatorConfigServicesController {

  public function handleRequest(AphrontRequest $request) {
    $title = pht('Notification Servers');
    $doc_href = PhabricatorEnv::getDoclink('Cluster: Notifications');
    $button = id(new PHUIButtonView())
      ->setIcon('fa-book')
      ->setHref($doc_href)
      ->setTag('a')
      ->setText(pht('Documentation'));

    $header = $this->buildHeaderView($title, $button);

    $notification_status = $this->buildClusterNotificationStatus();
    $status = $this->buildConfigBoxView(
      pht('Notifications Status'),
      $notification_status);
    $browser_status = $this->buildCurrentBrowserNotificationConnection($request);

    $crumbs = $this->newCrumbs()
      ->addTextCrumb($title);

    $content = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setFooter(array($status, $browser_status));

    $nav = $this->newNavigation('notification-servers');

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->setNavigation($nav)
      ->appendChild($content);
  }

  private function buildCurrentBrowserNotificationConnection(
    AphrontRequest $request) {
    $status = id(new PhabricatorNotificationStatusView())
      ->setViewer($request->getUser())
      ->setRequest($request);
    $content = id(new PHUIBoxView())
      ->addPadding(PHUI::PADDING_MEDIUM)
      ->appendChild($status);

    return $this->buildConfigBoxView(
      pht('Current Browser Notification Connection'),
      $content);
  }

  private function buildClusterNotificationStatus() {
    $servers = PhabricatorNotificationServerRef::newRefs();
    Javelin::initBehavior('phabricator-tooltips');

    $rows = array();
    foreach ($servers as $server) {
      if ($server->isAdminServer()) {
        $type_icon = 'fa-database sky';
        $type_tip = pht('Management Interface');
      } else {
        $type_icon = 'fa-bell sky';
        $type_tip = pht('Browser Connection Address');
      }

      $type_icon = id(new PHUIIconView())
        ->setIcon($type_icon)
        ->addSigil('has-tooltip')
        ->setMetadata(
          array(
            'tip' => $type_tip,
          ));

      $messages = array();
      if (!$server->isAdminServer()) {
        $messages[] = phutil_tag(
          'code',
          array(),
          (string)$server->getWebsocketURI());
      }

      $details = array();
      if ($server->getIsDisabled()) {
        $status_icon = 'fa-ban grey';
        $status_label = pht('Disabled');
      } else if ($server->isAdminServer()) {
        try {
          $status_data = $server->loadServerStatus();
          if (!$this->isValidNotificationServerStatus($status_data)) {
            throw new Exception(
              pht('Notification server returned an invalid status response.'));
          }
          $details = $status_data;
          $status_icon = 'fa-exchange green';
          $status_label = pht('Protocol Version %s', idx($details, 'version'));
        } catch (Exception $ex) {
          $status_icon = 'fa-times red';
          $status_label = pht('Connection Error');
          $messages[] = $ex->getMessage();
        }
      } else {
        // This address is used by the browser, whose network may differ from
        // PHP's. The separate browser status view reports the active connection.
        $status_icon = 'fa-globe grey';
        $status_label = pht('Browser Connection Address');
      }

      if ($details) {
        $uptime = idx($details, 'uptime');
        $uptime = $uptime / 1000;
        $uptime = phutil_format_relative_time_detailed($uptime);

        $clients = pht(
          '%s Active / %s Total',
          new PhutilNumber(idx($details, 'clients.active')),
          new PhutilNumber(idx($details, 'clients.total')));

        $stats = pht(
          '%s In / %s Out',
          new PhutilNumber(idx($details, 'messages.in')),
          new PhutilNumber(idx($details, 'messages.out')));

        if (idx($details, 'history.size')) {
          $history = pht(
            '%s Held / %sms',
            new PhutilNumber(idx($details, 'history.size')),
            new PhutilNumber(idx($details, 'history.age')));
        } else {
          $history = pht('No Messages');
        }

      } else {
        $uptime = null;
        $clients = null;
        $stats = null;
        $history = null;
      }

      $status_view = array(
        id(new PHUIIconView())->setIcon($status_icon),
        ' ',
        $status_label,
      );

      $messages = phutil_implode_html(phutil_tag('br'), $messages);

      $rows[] = array(
        $type_icon,
        $server->getProtocol(),
        $server->getHost(),
        $server->getPort(),
        $status_view,
        $uptime,
        $clients,
        $stats,
        $history,
        $messages,
      );
    }

    $service = PhabricatorGorgeServiceRegistry::getService('notification');
    if ($service->isDisabled()) {
      $no_data = pht('Notification service is disabled.');
    } else {
      $no_data = pht('No notification servers are configured.');
    }

    $table = id(new AphrontTableView($rows))
      ->setNoDataString($no_data)
      ->setHeaders(
        array(
          null,
          pht('Protocol'),
          pht('Host'),
          pht('Port'),
          pht('Status'),
          pht('Uptime'),
          pht('Clients'),
          pht('Messages'),
          pht('History'),
          pht('Details'),
        ))
      ->setColumnClasses(
        array(
          null,
          null,
          null,
          null,
          null,
          null,
          null,
          null,
          null,
          'wide',
        ));

    return $table;
  }

  private function isValidNotificationServerStatus($details) {
    if (!is_array($details)) {
      return false;
    }

    foreach (array(
      'version', 'uptime', 'clients.active', 'clients.total',
      'messages.in', 'messages.out', 'history.size') as $key) {
      $value = idx($details, $key);
      if (!$this->isValidNotificationStatusNumber($value)) {
        return false;
      }
    }

    if ($details['history.size']) {
      $age = idx($details, 'history.age');
      if (!$this->isValidNotificationStatusNumber($age)) {
        return false;
      }
    }

    return true;
  }

  private function isValidNotificationStatusNumber($value) {
    return (is_int($value) || is_float($value)) &&
      is_finite((float)$value) && ($value >= 0);
  }

}
