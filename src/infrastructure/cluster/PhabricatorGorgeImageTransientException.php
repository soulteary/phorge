<?php

/** A transient image failure must not become a permanent transform cache hit. */
final class PhabricatorGorgeImageTransientException extends Exception {}
