<?php

final class DiffusionLowLevelCommitFieldsQuery
  extends DiffusionLowLevelQuery {

  private $ref;
  private $revisionMatchData = array(
    'usedURI' => null,
    'foundURI' => null,
    'validDomain' => null,
    'matchHashType' => null,
    'matchHashValue' => null,
  );

  public function withCommitRef(DiffusionCommitRef $ref) {
    $this->ref = $ref;
    return $this;
  }

  public function getRevisionMatchData() {
    return $this->revisionMatchData;
  }

  private function setRevisionMatchData($key, $value) {
    $this->revisionMatchData[$key] = $value;
    return $this;
  }

  protected function executeQuery() {
    $ref = $this->ref;
    $message = $ref->getMessage();
    $hashes = $ref->getHashes();

    $params = array(
      'corpus' => $message,
      'partial' => true,
    );

    $result = id(new ConduitCall('differential.parsecommitmessage', $params))
      ->setUser(PhabricatorUser::getOmnipotentUser())
      ->execute();
    $fields = $result['fields'];

    $revision_id = idx($fields, 'revisionID');
    if ($revision_id) {
      $this->setRevisionMatchData('usedURI', true);
    } else {
      $this->setRevisionMatchData('usedURI', false);
    }
    $revision_id_info = $result['revisionIDFieldInfo'];
    $this->setRevisionMatchData('foundURI', $revision_id_info['value']);
    $this->setRevisionMatchData(
      'validDomain',
      $revision_id_info['validDomain']);

    return $fields;
  }


}
