<?php

final class PeopleMainMenuBarExtension
  extends PhabricatorMainMenuBarExtension {

  const MAINMENUBARKEY = 'user';

  public function isExtensionEnabledForViewer(PhabricatorUser $viewer) {
    return $viewer->isLoggedIn();
  }

  public function shouldRequireFullSession() {
    return false;
  }

  public function getExtensionOrder() {
    return 1200;
  }

  public function buildMainMenus() {
    $viewer = $this->getViewer();
    $application = $this->getApplication();
    $dropdown_menu = $this->newDropdown($viewer, $application);

    $menu_id = celerity_generate_unique_node_id();

    Javelin::initBehavior(
      'user-menu',
      array(
        'menuID' => $menu_id,
        'menu' => $dropdown_menu->getDropdownMenuMetadata(),
      ));

    $image = $viewer->getProfileImageURI();
    $profile_image = id(new PHUIIconView())
      ->setImage($image)
      ->setHeadSize(PHUIIconView::HEAD_SMALL);

    $user_menu = id(new PHUIButtonView())
      ->setID($menu_id)
      ->setTag('a')
      ->setHref('/p/'.$viewer->getUsername().'/')
      ->setIcon($profile_image)
      ->addClass('phabricator-core-user-menu')
      ->setHasCaret(true)
      ->setNoCSS(true)
      ->setAuralLabel(pht('Account Menu'));

    $menus = array();

    // In the collaboration profile Gitea owns code. Keep its entry next to
    // the account menu so users do not have to hunt through Applications,
    // but do not expose external navigation during a partial (for example,
    // pre-MFA) session.
    $gitea_uri = PhabricatorEnv::getEnvConfig('gitea.uri');
    if ($this->getIsFullSession() && $gitea_uri) {
      $menus[] = id(new PHUIButtonView())
        ->setTag('a')
        ->setHref($gitea_uri)
        ->setIcon('fa-code-fork')
        ->addClass('phabricator-core-user-menu')
        ->setNoCSS(true)
        ->setTooltip(pht('Open Gitea'))
        ->setAuralLabel(pht('Open Gitea'));
    }

    $menus[] = $user_menu;

    return $menus;
  }

  private function newDropdown(
    PhabricatorUser $viewer,
    $application) {

    $person_to_show = id(new PHUIObjectItemView())
      ->setObjectName($viewer->getRealName())
      ->setSubHead($viewer->getUsername())
      ->setImageURI($viewer->getProfileImageURI());

    $user_view = id(new PHUIObjectItemListView())
      ->setViewer($viewer)
      ->setFlush(true)
      ->setSimple(true)
      ->addItem($person_to_show)
      ->addClass('phabricator-core-user-profile-object');

    $view = id(new PhabricatorActionListView())
      ->setViewer($viewer);

    if ($this->getIsFullSession()) {
      $view->addAction(
        id(new PhabricatorActionView())
          ->appendChild($user_view));

      $view->addAction(
        id(new PhabricatorActionView())
          ->setType(PhabricatorActionView::TYPE_DIVIDER));

      $view->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Profile'))
          ->setHref('/p/'.$viewer->getUsername().'/'));

      $view->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Settings'))
          ->setHref('/settings/user/'.$viewer->getUsername().'/'));

      $view->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Manage'))
          ->setHref('/people/manage/'.$viewer->getID().'/'));

      if ($application) {
        $help_links = $application->getHelpMenuItems($viewer);
        if ($help_links) {
          foreach ($help_links as $link) {
            $view->addAction($link);
          }
        }
      }

      $view->addAction(
        id(new PhabricatorActionView())
          ->addSigil('logout-item')
          ->setType(PhabricatorActionView::TYPE_DIVIDER));
    }

    $view->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Log Out %s', $viewer->getUsername()))
        ->addSigil('logout-item')
        ->setHref('/logout/')
        ->setWorkflow(true));

    return $view;
  }

}
