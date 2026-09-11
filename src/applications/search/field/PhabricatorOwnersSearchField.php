<?php

/**
 * Tokenizer for a list of users who own something, like the assignee of a
 * Maniphest task.
 *
 * Despite the name this has nothing to do with the removed Owners
 * application: it selects people through
 * @{class:PhabricatorPeopleOwnerDatasource}, never packages. It was filed
 * under that application by accident and is retained here.
 */
final class PhabricatorOwnersSearchField
  extends PhabricatorSearchTokenizerField {

  protected function getDefaultValue() {
    return array();
  }

  protected function getValueFromRequest(AphrontRequest $request, $key) {
    return $this->getUsersFromRequest($request, $key);
  }

  protected function newDatasource() {
    return new PhabricatorPeopleOwnerDatasource();
  }

  protected function newConduitParameterType() {
    return new ConduitUserListParameterType();
  }

}
