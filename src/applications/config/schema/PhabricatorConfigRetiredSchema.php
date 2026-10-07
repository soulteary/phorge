<?php

/**
 * Reviewed historical tables left by physically retired applications.
 *
 * Version 1 records the retained SQL history after the 2026 application cuts.
 * This is a table-level retention policy, not a second SchemaSpec: historical
 * columns and indexes stay with their table. Never infer this list from live
 * surplus objects or a table-name prefix. Unknown tables remain schema errors,
 * and a live SchemaSpec always takes precedence over this policy.
 */
final class PhabricatorConfigRetiredSchema extends Phobject {

  const VERSION = 1;

  public static function isRetiredDatabase($database, $namespace) {
    foreach (array('audit', 'diviner', 'drydock', 'owners', 'paste',
      'repository') as $application) {
      if ($database === $namespace.'_'.$application) {
        return true;
      }
    }
    return false;
  }

  public static function isRetiredTable($database, $table, $namespace) {
    foreach (self::getRetiredTables() as $application => $tables) {
      if ($database === $namespace.'_'.$application) {
        return in_array($table, $tables, true);
      }
    }
    return false;
  }

  public static function getRetiredTables() {
    return array(
      'audit' => array(
        'audit_transaction',
        'audit_transaction_comment',
      ),
      'differential' => array(
        'differential_affectedpath',
        'differential_customfieldnumericindex',
        'differential_customfieldstorage',
        'differential_customfieldstringindex',
        'differential_difftransaction',
        'differential_hiddencomment',
        'differential_reviewer',
        'differential_revision',
        'differential_revision_fdocument',
        'differential_revision_ffield',
        'differential_revision_fngrams',
        'differential_revision_fngrams_common',
        'differential_transaction',
        'differential_transaction_comment',
      ),
      'diviner' => array(
        'diviner_liveatom',
        'diviner_livebook',
        'diviner_livebooktransaction',
        'diviner_livesymbol',
        'edge',
        'edgedata',
      ),
      'drydock' => array(
        'drydock_authorization',
        'drydock_blueprint',
        'drydock_blueprintname_ngrams',
        'drydock_blueprinttransaction',
        'drydock_command',
        'drydock_lease',
        'drydock_log',
        'drydock_repositoryoperation',
        'drydock_resource',
        'drydock_slotlock',
        'edge',
        'edgedata',
      ),
      'harbormaster' => array(
        'edge',
        'edgedata',
        'harbormaster_build',
        'harbormaster_buildable',
        'harbormaster_buildabletransaction',
        'harbormaster_buildartifact',
        'harbormaster_buildlintmessage',
        'harbormaster_buildlog',
        'harbormaster_buildlogchunk',
        'harbormaster_buildmessage',
        'harbormaster_buildplan',
        'harbormaster_buildplanname_ngrams',
        'harbormaster_buildplantransaction',
        'harbormaster_buildstep',
        'harbormaster_buildsteptransaction',
        'harbormaster_buildtarget',
        'harbormaster_buildtransaction',
        'harbormaster_buildunitmessage',
        'harbormaster_string',
        'lisk_counter',
      ),
      'owners' => array(
        'edge',
        'edgedata',
        'owners_customfieldnumericindex',
        'owners_customfieldstorage',
        'owners_customfieldstringindex',
        'owners_name_ngrams',
        'owners_owner',
        'owners_package',
        'owners_package_fdocument',
        'owners_package_ffield',
        'owners_package_fngrams',
        'owners_package_fngrams_common',
        'owners_packagetransaction',
        'owners_path',
      ),
      'paste' => array(
        'edge',
        'edgedata',
        'paste',
        'paste_paste_fdocument',
        'paste_paste_ffield',
        'paste_paste_fngrams',
        'paste_paste_fngrams_common',
        'paste_transaction',
        'paste_transaction_comment',
      ),
      'repository' => array(
        'edge',
        'edgedata',
        'repository',
        'repository_auditrequest',
        'repository_branch',
        'repository_commit',
        'repository_commit_fdocument',
        'repository_commit_ffield',
        'repository_commit_fngrams',
        'repository_commit_fngrams_common',
        'repository_commitdata',
        'repository_commithint',
        'repository_coverage',
        'repository_filesystem',
        'repository_gitlfsref',
        'repository_identity',
        'repository_identitytransaction',
        'repository_lintmessage',
        'repository_oldref',
        'repository_parents',
        'repository_path',
        'repository_pathchange',
        'repository_pullevent',
        'repository_pushevent',
        'repository_pushlog',
        'repository_refcursor',
        'repository_refposition',
        'repository_repository_fdocument',
        'repository_repository_ffield',
        'repository_repository_fngrams',
        'repository_repository_fngrams_common',
        'repository_statusmessage',
        'repository_summary',
        'repository_symbol',
        'repository_syncevent',
        'repository_transaction',
        'repository_uri',
        'repository_uriindex',
        'repository_uritransaction',
        'repository_workingcopyversion',
      ),
    );
  }

}
