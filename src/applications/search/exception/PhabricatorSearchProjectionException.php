<?php

/** A projection capture failure must retry rather than mark indexes current. */
final class PhabricatorSearchProjectionException extends Exception {}
