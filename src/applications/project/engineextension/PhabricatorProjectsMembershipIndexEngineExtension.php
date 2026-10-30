<?php

final class PhabricatorProjectsMembershipIndexEngineExtension
  extends PhabricatorIndexEngineExtension {

  const EXTENSIONKEY = 'project.members';

  public function getExtensionName() {
    return pht('Project Members');
  }

  public function shouldIndexObject($object) {
    if (!($object instanceof PhabricatorProject)) {
      return false;
    }

    return true;
  }

  public function indexObject(
    PhabricatorIndexEngine $engine,
    $object) {

    $this->rematerialize($object);
  }

  public function rematerialize(PhabricatorProject $project) {
    $materialize = array($project);

    // Only milestones inherit members. Ordinary descendants and ancestors
    // have independent membership indexes. Load structural dependents without
    // applying the indexing actor's visibility policy.
    if (!$project->isMilestone()) {
      $milestones = id(new PhabricatorProject())->loadAllWhere(
        'parentProjectPHID = %s AND milestoneNumber IS NOT NULL',
        $project->getPHID());
      $materialize = array_merge($materialize, $milestones);
    }

    foreach ($materialize as $project) {
      $this->materializeProject($project);
    }
  }

  private function materializeProject(PhabricatorProject $project) {
    $material_type = PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;
    $member_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

    $project_phid = $project->getPHID();
    $conn_w = $project->establishConnection('w');

    if ($project->isMilestone()) {
      $source_phids = array($project->getParentProjectPHID());
      $has_subprojects = false;
    } else {
      $source_phids = array($project_phid);
      $any_subproject = queryfx_one(
        $conn_w,
        'SELECT id FROM %T
          WHERE parentProjectPHID = %s AND milestoneNumber IS NULL
          LIMIT 1',
        $project->getTableName(),
        $project_phid);
      $has_subprojects = (bool)$any_subproject;
    }

    $any_milestone = queryfx_one(
      $conn_w,
      'SELECT id FROM %T
        WHERE parentProjectPHID = %s AND milestoneNumber IS NOT NULL
        LIMIT 1',
      $project->getTableName(),
      $project_phid);
    $has_milestones = (bool)$any_milestone;

    $project->openTransaction();

      // Copy current member edges to create new materialized edges.

      // See T13596. Avoid executing this as an "INSERT ... SELECT" to reduce
      // the required level of table locking. Since we're decomposing it into
      // "SELECT" + "INSERT" anyway, we can also compute exactly which rows
      // need to be modified.

      $have_rows = queryfx_all(
        $conn_w,
        'SELECT dst FROM %T
          WHERE src = %s AND type = %d',
        PhabricatorEdgeConfig::TABLE_NAME_EDGE,
        $project_phid,
        $material_type);

      $want_rows = queryfx_all(
        $conn_w,
        'SELECT dst, dateCreated, seq FROM %T
          WHERE src IN (%Ls) AND type = %d',
        PhabricatorEdgeConfig::TABLE_NAME_EDGE,
        $source_phids,
        $member_type);

      $have_phids = ipull($have_rows, 'dst', 'dst');
      $want_phids = ipull($want_rows, null, 'dst');

      $rem_phids = array_diff_key($have_phids, $want_phids);
      $rem_phids = array_keys($rem_phids);

      $add_phids = array_diff_key($want_phids, $have_phids);
      $add_phids = array_keys($add_phids);

      $rem_sql = array();
      foreach ($rem_phids as $rem_phid) {
        $rem_sql[] = qsprintf(
          $conn_w,
          '%s',
          $rem_phid);
      }

      $add_sql = array();
      foreach ($add_phids as $add_phid) {
        $add_row = $want_phids[$add_phid];
        $add_sql[] = qsprintf(
          $conn_w,
          '(%s, %d, %s, %d, %d)',
          $project_phid,
          $material_type,
          $add_row['dst'],
          $add_row['dateCreated'],
          $add_row['seq']);
      }

      // Remove materialized members who are no longer project members.

      if ($rem_sql) {
        foreach (PhabricatorLiskDAO::chunkSQL($rem_sql) as $sql_chunk) {
          queryfx(
            $conn_w,
            'DELETE FROM %T
              WHERE src = %s AND type = %s AND dst IN (%LQ)',
            PhabricatorEdgeConfig::TABLE_NAME_EDGE,
            $project_phid,
            $material_type,
            $sql_chunk);
        }
      }

      // Add project members who are not yet materialized members.

      if ($add_sql) {
        foreach (PhabricatorLiskDAO::chunkSQL($add_sql) as $sql_chunk) {
          queryfx(
            $conn_w,
            'INSERT IGNORE INTO %T (src, type, dst, dateCreated, seq)
              VALUES %LQ',
            PhabricatorEdgeConfig::TABLE_NAME_EDGE,
            $sql_chunk);
        }
      }

      // Update the hasSubprojects flag.
      queryfx(
        $conn_w,
        'UPDATE %T SET hasSubprojects = %d WHERE id = %d',
        $project->getTableName(),
        (int)$has_subprojects,
        $project->getID());

      // Update the hasMilestones flag.
      queryfx(
        $conn_w,
        'UPDATE %T SET hasMilestones = %d WHERE id = %d',
        $project->getTableName(),
        (int)$has_milestones,
        $project->getID());

    $project->saveTransaction();
  }

}
