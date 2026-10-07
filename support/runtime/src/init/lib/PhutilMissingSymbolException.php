<?php

final class PhutilMissingSymbolException extends Exception {

  public function __construct($symbol, $type, $reason) {
    $message = sprintf(
      'Failed to load symbol "%s" (of type "%s").'.
      "\n\n".
      '%s'.
      "\n\n".
      'If you are not a developer, this almost always means that a library '.
      'is out of date or incomplete. Restore the project PHP runtime from '.
      'the same Phorge release and restart Apache or PHP-FPM. Make sure '.
      'the complete release is installed and services have been restarted.'.
      "\n\n".
      'If you are a developer and this symbol was recently added or '.
      'moved, your library map may need to be rebuilt. You can rebuild '.
      'the map by running "php bin/rebuild-library-map".'.
      "\n\n".
      'For more information, see: '.
      'https://we.phorge.it/book/contrib/article/adding_new_classes/',
      $symbol,
      $type,
      $reason);

    parent::__construct($message);
  }

}
