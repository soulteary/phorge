<?php

/** Additional source refusals. Prepared only until supported native runtime. */
function governance_private_mail_legalpad_boundary_cases($fixture,callable $check,array $state){
  $doc=$state['document'];$signature=$state['signature'];$alias=$state['alias'];
  $auth=$state['signer_auth'];$sessions=$state['sessions'];$call=$state['call'];
  $path='/legalpad/verify/'.$signature->getSecretKey().'/';$csrf=AphrontRequest::getCSRFTokenName();
  $post=array('__form__'=>true,$csrf=>$auth['viewer']->getCSRFToken());
  $pending=static function()use($signature){return !((new LegalpadDocumentSignature())->load($signature->getID()))->isVerified();};
  $fingerprint=static function()use($signature){$row=queryfx_one($signature->establishConnection('r'),'SELECT * FROM %R WHERE id=%d',$signature,$signature->getID());return hash('sha256',phutil_json_encode($row));};
  $before=$fingerprint();$alias->setIsVerified(0)->save();
  try{$response=$call($auth,$path,'POST',$post);$check($response instanceof Aphront403Response&&$before===$fingerprint(),
    'private-mail-legalpad-current-contact-unverified-refuses-before-consuming');}
  finally{$alias->setIsVerified(1)->save();}
  $versions=$doc->getVersions();$doc->setVersions((int)$versions+1)->save();
  try{$response=$call($auth,$path,'POST',$post);$check($response instanceof Aphront403Response&&$before===$fingerprint(),
    'private-mail-legalpad-current-document-version-change-refuses-old-signature');}
  finally{$doc->setVersions($versions)->save();}
  // Historical fixture rows test compatibility, not a new anonymous signing grant.
  $legacy=clone $signature;$legacy->setID(null)->setPHID(null)->setSecretKey(Filesystem::readRandomCharacters(20));
  $data=$legacy->getSignatureData();unset($data['actorPHID']);$legacy->setSignatureData($data)->save();
  $legacy_response=$call($auth,'/legalpad/verify/'.$legacy->getSecretKey().'/','GET');
  $check($legacy_response instanceof Aphront403Response&&!((new LegalpadDocumentSignature())->load($legacy->getID()))->isVerified()&&$pending(),
    'private-mail-legalpad-legacy-unbound-corporate-row-not-adopted-by-owned-email');
  $original=$signature->getSignatureData();$changed=$original;$changed['email']='unowned-contact@example.invalid';$signature->setSignatureData($changed)->save();
  try{$response=$call($auth,$path,'POST',$post);$check($response instanceof Aphront403Response&&$pending(),
    'private-mail-legalpad-signature-contact-data-mismatch-refuses');}
  finally{$signature->setSignatureData($original)->save();}
  $before=$fingerprint();$table=$fixture->table('legalpad','legalpad_document');
  governance_native_setup_query($fixture,'ALTER TABLE '.$table.' ENGINE=MyISAM');
  try{$actual=governance_native_setup_query($fixture,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='".$fixture->getNamespace()."_legalpad' AND TABLE_NAME='legalpad_document'")->fetch_assoc();
    $response=$call($auth,$path,'POST',$post);$check($actual['ENGINE']==='MyISAM'&&$response instanceof Aphront403Response&&$before===$fingerprint(),
      'private-mail-legalpad-actual-nontransactional-document-table-refused-without-signature-write');}
  finally{governance_native_setup_query($fixture,'ALTER TABLE '.$table.' ENGINE=InnoDB');}
  // Exact owned trigger proves a failed UPDATE really reached the mutation.
  $trigger='`'.$fixture->getNamespace().'_legalpad`.`legalpad_verify_owned_fault`';$installed=false;
  try{governance_native_setup_query($fixture,'CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON '.$fixture->table('legalpad','legalpad_documentsignature').
    " FOR EACH ROW BEGIN SET @legalpad_verify_fault=1; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned-legalpad-verify-fault'; END");$installed=true;
    $found=governance_native_setup_query($fixture,"SELECT COUNT(*) n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='".$fixture->getNamespace()."_legalpad' AND TRIGGER_NAME='legalpad_verify_owned_fault'")->fetch_assoc();
    $conn=(new PhabricatorUser())->establishConnection('w');queryfx($conn,'SET @legalpad_verify_fault=NULL');$before=$fingerprint();$fault=false;
    try{$call($auth,$path,'POST',$post);}catch(AphrontQueryException$error){if($error->getCode()!==1644||!str_contains($error->getMessage(),'owned-legalpad-verify-fault')){throw$error;}$fault=true;}
    $marker=queryfx_one($conn,'SELECT @legalpad_verify_fault hit');
    $check((int)$found['n']===1&&$fault&&(string)$marker['hit']==='1'&&$before===$fingerprint()&&!$conn->isInsideTransaction(),
      'private-mail-legalpad-real-update-fault-rolls-back-signature-and-closes-owned-transaction');
  }finally{if($installed){governance_native_setup_query($fixture,'DROP TRIGGER '.$trigger);}}
  $session_phid=$auth['viewer']->getSession()->getPHID();$sessions->logoutSession($auth['viewer'],$auth['viewer']->getSession());$before=$fingerprint();
  $session_gone=!(new PhabricatorAuthSession())->loadOneWhere('phid=%s',$session_phid);
  $logout_denied=false;try{$response=$call($auth,$path,'POST',$post);$logout_denied=$response instanceof Aphront403Response||$response instanceof AphrontDialogResponse||$response instanceof AphrontRedirectResponse;}
  catch(AphrontMalformedRequestException$error){$logout_denied=true;}
  $logout_html=$state['last_html']();
  $check($session_gone&&$logout_denied&&!str_contains($logout_html,'PRIVATE LEGALPAD TITLE')&&!str_contains($logout_html,$alias->getAddress())&&$before===$fingerprint()&&$pending(),'private-mail-legalpad-native-logout-old-cookie-cannot-consume');
  return governance_native_request($state['signer'],$sessions);
}
