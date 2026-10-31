#!/usr/bin/env php
<?php

/** Read-only preflight; review findings before changing membership semantics. */
require_once dirname(__FILE__).'/../__init_script__.php';
$report = PhabricatorProjectMembershipAudit::inspect();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($report['passed'] ? 0 : 2);
