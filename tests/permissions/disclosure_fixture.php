<?php

// Native query, pagination, and actual Gorge-backed export bytes, not a model.
if (!isset($fixture,$owner,$namespace) || PHP_SAPI !== 'cli') {
  throw new Exception('Owned native route fixture required.');
}
$extra=json_decode(file_get_contents(getenv('PERMISSION_ROUTES_PRIVATE').'/boundary-extra.json'),true);
$result=array();
foreach (array('owner','outsider') as $role) {
  $actor=id(new PhabricatorPeopleQuery())->setViewer($omni)
    ->withPHIDs(array($fixture['users'][$role]['phid']))->needUserSettings(true)->executeOne();
  $engine=id(new ManiphestTaskSearchEngine())->setViewer($actor);
  foreach (array('mixed','hidden-only') as $kind) {
    $saved=$engine->newSavedQuery()->setParameter('ids', $kind==='mixed' ? array_column($extra['tasks'],'id') : array($extra['tasks']['private']['id']));
    $engine->saveQuery($saved);$pager=$engine->newPagerForSavedQuery($saved)->setPageSize(1);
    $seen=array();$pages=0;
    do {
      $query=$engine->buildQueryFromSavedQuery($saved);
      $objects=$engine->executeQuery($query,$pager);
      foreach ($objects as $object) { $seen[]=$object->getPHID(); }
      $pages++;
      if ($pages>4) { throw new Exception('Disclosure pager exceeded fixture bound.'); }
      $more=$pager->getHasMoreResults();
      if ($more) { $pager=$engine->newPagerForSavedQuery($saved)->setPageSize(1)->setAfterID($pager->getNextPageID()); }
    } while ($more);
    $wanted=$kind==='hidden-only' ? ($role==='owner' ? array($extra['tasks']['private']['phid']) : array()) : ($role==='owner' ? array_column($extra['tasks'],'phid') : array($extra['tasks']['public']['phid']));
    sort($wanted);$sorted=$seen;sort($sorted);
    set_error_handler(static function($severity, $message, $file, $line) {
      throw new ErrorException($message, 0, $severity, $file, $line);
    }, E_DEPRECATED | E_USER_DEPRECATED);
    try {
      $file=id(new PhabricatorExportEngine())->setViewer($actor)->setSearchEngine($engine)
        ->setSavedQuery($saved)->setExportFormat(new PhabricatorJSONExportFormat())
        ->setFilename('isolated-boundary')->setTitle('Isolated boundary')->exportFile();
      $bytes=$file->loadFileData();
    } finally { restore_error_handler(); }
    $rows=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    $visible=true;
    foreach ($extra['tasks'] as $task) {
      $present=in_array($task['phid'],$wanted,true);
      if ($present) { $visible=$visible && str_contains($bytes,$task['title']); }
      else { $visible=$visible && !str_contains($bytes,$task['title']) && !str_contains($bytes,$task['phid']); }
    }
    $result[$role][$kind]=array('exactVisibleQuerySet'=>$sorted===$wanted && count($seen)===count(array_unique($seen)),
      'filteredCount'=>count($seen)===count($wanted),'exportRowsFiltered'=>count($rows)===count($wanted),
      'exportMetadataFiltered'=>$visible,'filePrivate'=>$file->getViewPolicy()===PhabricatorPolicies::POLICY_NOONE && $file->getAuthorPHID()===$actor->getPHID(),
      'queryKey'=>$saved->getQueryKey());
  }
}
echo json_encode($result,JSON_THROW_ON_ERROR);
