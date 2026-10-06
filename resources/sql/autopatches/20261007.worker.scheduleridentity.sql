ALTER TABLE {$NAMESPACE}_worker.worker_gorgeschedulercontrol
  ADD databaseID VARBINARY(36) NOT NULL DEFAULT '';
UPDATE {$NAMESPACE}_worker.worker_gorgeschedulercontrol
  SET databaseID = UUID() WHERE id = 1 AND databaseID = '';
