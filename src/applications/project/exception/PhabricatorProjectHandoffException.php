<?php

/** A pre-write, project-only request for interactive handoff confirmation. */
final class PhabricatorProjectHandoffException extends Exception {

  private $token;
  private $expires;
  private $losesView;

  public function __construct($token, $expires, $loses_view) {
    $this->token = $token;
    $this->expires = $expires;
    $this->losesView = $loses_view;
    parent::__construct(pht('Confirm Project Permission Transfer'));
  }

  public function newResponse(AphrontRequest $request) {
    $message = $this->losesView
      ? pht('After saving, you will no longer be able to view or edit this '.
        'project. Confirm that access has been granted to the intended user.')
      : pht('After saving, you will no longer be able to edit this project. '.
        'Confirm that edit access has been granted to the intended user.');
    $dialog = id(new AphrontDialogView())
      ->setViewer($request->getViewer())
      ->setTitle(pht('Confirm Project Permission Transfer'))
      ->appendParagraph($message)
      ->setSubmitURI($request->getPath())
      ->setDisableWorkflowOnSubmit(true);
    foreach ($request->getPassthroughRequestParameters() as $key => $value) {
      if ($key !== PhabricatorProjectHandoff::TOKEN &&
          $key !== PhabricatorProjectHandoff::EXPIRES) {
        $dialog->addHiddenInput($key, $value);
      }
    }
    $dialog
      ->addHiddenInput(PhabricatorProjectHandoff::TOKEN, $this->token)
      ->addHiddenInput(PhabricatorProjectHandoff::EXPIRES, $this->expires)
      ->addSubmitButton(pht('Confirm Save'))
      ->addCancelButton($request->getRequestURI());
    return id(new AphrontDialogResponse())->setDialog($dialog);
  }

}
