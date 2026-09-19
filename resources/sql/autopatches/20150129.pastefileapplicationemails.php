<?php

$key_files = 'metamta.files.public-create-email';
$key_paste = 'metamta.paste.public-create-email';
echo pht(
  "Migrating `%s` to new application email infrastructure...\n",
  $key_files);

$value_files = PhabricatorEnv::getEnvConfigIfExists($key_files);
$files_app = new PhabricatorFilesApplication();

if ($value_files) {
  try {
    PhabricatorMetaMTAApplicationEmail::initializeNewAppEmail(
      PhabricatorUser::getOmnipotentUser())
      ->setAddress($value_files)
      ->setApplicationPHID($files_app->getPHID())
      ->save();
  } catch (AphrontDuplicateKeyQueryException $ex) {
    // Already migrated?
  }
}

$value_paste = PhabricatorEnv::getEnvConfigIfExists($key_paste);

// The Paste half of this migration is not performed. The Paste application
// has been removed, so an address created here would carry an applicationPHID
// which resolves to nothing: PhabricatorMetaMTAApplicationEmailQuery drops
// such addresses, and an address nothing can route is worse than no address
// at all, because it still occupies the unique key on the address itself.
//
// Say so rather than dropping the setting silently -- this is an install
// which had configured a public Paste create-address.
if ($value_paste) {
  echo pht(
    "Not migrating `%s` (\"%s\"): the Paste application has been removed, ".
    "so there is no application for the address to belong to.\n",
    $key_paste,
    $value_paste);
}

echo pht('Done.')."\n";
