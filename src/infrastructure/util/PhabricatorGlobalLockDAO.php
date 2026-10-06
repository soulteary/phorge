<?php

/**
 * Connection source for @{class:PhabricatorGlobalLock}.
 *
 * Global locks must all be taken on one fixed database: a partitioned install
 * may place different databases on different hosts, and a MySQL advisory lock
 * is only visible to other connections on the same host. No table is read or
 * written here -- only the connection is used.
 *
 * This exists so that choice is explicit and cannot be removed by retiring an
 * application. It previously borrowed `PhabricatorRepository`, which was
 * chosen only because it happened to be a convenient Lisk DAO, and which is
 * gone with tracked repositories.
 */
final class PhabricatorGlobalLockDAO extends PhabricatorSystemDAO {

}
