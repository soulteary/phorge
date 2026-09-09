<?php

/**
 * HTTP client for the Gorge task queue service.
 *
 * Gorge is a Go service which fronts the worker task queue: it owns the
 * `{namespace}_worker.worker_activetask` table and exposes the queue
 * operations -- enqueue, lease, complete, fail, yield, cancel, awaken -- over
 * an authenticated HTTP API at `/api/queue`. When the service is configured,
 * the PHP daemon stops driving the queue with SQL directly and calls these
 * routes instead; when it is not configured, the native SQL path is used and
 * this client is never constructed.
 *
 * Unlike the webhook service, this is an "active client": the PHP side
 * initiates every queue operation over HTTP rather than handing a table over
 * and stepping back. @{method:isConfigured} is therefore the switch each
 * caller checks before choosing the Go path over its native SQL fallback:
 *
 *   - @{class:PhabricatorWorkerLeaseQuery} leases and lists tasks,
 *   - @{class:PhabricatorWorkerActiveTask} reports completion/failure/yield,
 *   - @{class:PhabricatorWorker} enqueues and awakens tasks.
 *
 * Request building and envelope parsing live in
 * @{class:PhabricatorGorgeServiceClient}, which all of the Gorge clients
 * share. The one route-specific addition here is the "X-Lease-Owner" header,
 * which the lease route uses to record which daemon holds a claim.
 */
final class PhabricatorGorgeTaskQueueClient
  extends PhabricatorGorgeServiceClient {

  const PATH_ENQUEUE  = '/api/queue/enqueue';
  const PATH_LEASE    = '/api/queue/lease';
  const PATH_COMPLETE = '/api/queue/complete';
  const PATH_FAIL     = '/api/queue/fail';
  const PATH_YIELD    = '/api/queue/yield';
  const PATH_CANCEL   = '/api/queue/cancel';
  const PATH_AWAKEN   = '/api/queue/awaken';
  const PATH_STATS    = '/api/queue/stats';
  const PATH_TASKS    = '/api/queue/tasks';

  private $leaseOwner;

  public function __construct() {
    $service = PhabricatorGorgeServiceRegistry::getService('taskqueue');
    $uri = $service->getConfiguredURI();

    if ($uri === null) {
      throw new Exception(
        pht(
          'Configuration option "%s" is required to reach the Gorge task '.
          'queue service, but it is not set.',
          'gorge.taskqueue.uri'));
    }

    $this->setURI($uri);
    $this->setToken($service->getConfiguredToken());
  }

  protected static function getServiceName() {
    return pht('Gorge task queue service');
  }

  protected function getDefaultTimeout() {
    // The queue operations are single-row reads and writes against one table,
    // so they are quick. This is on the daemon's hot path (every lease, every
    // completion), so keep it modest: a stalled service should fail fast and
    // let the caller fall back rather than block the daemon.
    return 10;
  }


/* -(  Configuration  )------------------------------------------------------ */


  /**
   * Read the configured base URI of the service.
   *
   * @return string|null Base URI with any trailing slash removed, or null if
   *   the service is not configured.
   */
  public static function getConfiguredURI() {
    return PhabricatorGorgeServiceRegistry::getService('taskqueue')
      ->getConfiguredURI();
  }

  /**
   * Decide whether the worker queue is fronted by the service.
   *
   * This is the guard behind every Go path in the worker core:
   * @{class:PhabricatorWorkerLeaseQuery}, @{class:PhabricatorWorkerActiveTask}
   * and @{class:PhabricatorWorker} all ask this method before choosing the
   * service over their native SQL implementation, so that an install which
   * has not configured the service keeps working exactly as before.
   *
   * @return bool True if the service owns the task queue.
   */
  public static function isConfigured() {
    return PhabricatorGorgeServiceRegistry::getService('taskqueue')
      ->isOwnedBy('gorge');
  }


  /**
   * Set the lease owner sent with queue operations.
   *
   * The lease route records which daemon holds a claim on a task; the value
   * travels in an "X-Lease-Owner" header so the service can attribute the
   * lease without the caller having to thread it through every body.
   *
   * @param string $owner Lease ownership name.
   * @return $this
   */
  public function setLeaseOwner($owner) {
    $this->leaseOwner = $owner;
    return $this;
  }


/* -(  Queue Operations  )--------------------------------------------------- */


  /**
   * Enqueue a task.
   *
   * @param string $task_class Worker class name.
   * @param wild $data Task data; encoded to JSON as the service expects.
   * @param map<string, wild> $options Optional priority/objectPHID/
   *   containerPHID/delayUntil.
   * @return wild The "data" section of the envelope, including the new task
   *   "id".
   */
  public function enqueue($task_class, $data, array $options = array()) {
    $body = array(
      'taskClass' => $task_class,
      'data'      => phutil_json_encode($data),
    );

    $priority = idx($options, 'priority');
    if ($priority !== null) {
      $body['priority'] = (int)$priority;
    }

    $object_phid = idx($options, 'objectPHID');
    if ($object_phid) {
      $body['objectPHID'] = $object_phid;
    }

    $container_phid = idx($options, 'containerPHID');
    if ($container_phid) {
      $body['containerPHID'] = $container_phid;
    }

    $delay = idx($options, 'delayUntil');
    if ($delay) {
      $body['delayUntil'] = (int)$delay;
    }

    return $this->callPost(self::PATH_ENQUEUE, $body);
  }

  /**
   * Lease up to $limit tasks for execution.
   *
   * @param int $limit Maximum number of tasks to lease.
   * @return wild The "data" section of the envelope, a list of leased task
   *   rows.
   */
  public function lease($limit = 1) {
    return $this->callPost(self::PATH_LEASE, array('limit' => (int)$limit));
  }

  /**
   * Mark a task complete.
   *
   * @param int $task_id Task ID.
   * @param int $duration Execution duration in microseconds.
   * @return wild The "data" section of the envelope.
   */
  public function complete($task_id, $duration = 0) {
    return $this->callPost(self::PATH_COMPLETE, array(
      'taskID'   => (int)$task_id,
      'duration' => (int)$duration,
    ));
  }

  /**
   * Mark a task failed, permanently or with a retry delay.
   *
   * @param int $task_id Task ID.
   * @param bool $permanent True to fail permanently rather than retry.
   * @param int|null $retry_wait Seconds to wait before retrying, or null for
   *   the service default.
   * @return wild The "data" section of the envelope.
   */
  public function fail($task_id, $permanent = false, $retry_wait = null) {
    $body = array(
      'taskID'    => (int)$task_id,
      'permanent' => (bool)$permanent,
    );

    if ($retry_wait !== null) {
      $body['retryWait'] = (int)$retry_wait;
    }

    return $this->callPost(self::PATH_FAIL, $body);
  }

  /**
   * Yield a task, releasing the lease for a period.
   *
   * @param int $task_id Task ID.
   * @param int $duration Seconds to yield for.
   * @return wild The "data" section of the envelope.
   */
  public function yield($task_id, $duration) {
    return $this->callPost(self::PATH_YIELD, array(
      'taskID'   => (int)$task_id,
      'duration' => (int)$duration,
    ));
  }

  /**
   * Cancel a task.
   *
   * @param int $task_id Task ID.
   * @return wild The "data" section of the envelope.
   */
  public function cancel($task_id) {
    return $this->callPost(self::PATH_CANCEL, array(
      'taskID' => (int)$task_id,
    ));
  }

  /**
   * Awaken yielded tasks so they run sooner.
   *
   * @param list<int> $task_ids Task IDs to awaken.
   * @return wild The "data" section of the envelope.
   */
  public function awaken(array $task_ids) {
    return $this->callPost(self::PATH_AWAKEN, array(
      'taskIDs' => array_values(array_map('intval', $task_ids)),
    ));
  }


/* -(  Diagnostics  )-------------------------------------------------------- */


  /**
   * Read the service's view of the queue.
   *
   * @return wild The "data" section of the envelope, with queue counts.
   */
  public function getStats() {
    return $this->callGet(self::PATH_STATS);
  }

  /**
   * List tasks in the queue.
   *
   * @param int $limit Maximum number of tasks to return.
   * @param int $offset Number of tasks to skip.
   * @return wild The "data" section of the envelope, a list of task rows.
   */
  public function getTasks($limit = 100, $offset = 0) {
    return $this->callGet(self::PATH_TASKS, array(
      'limit'  => (int)$limit,
      'offset' => (int)$offset,
    ));
  }

  /**
   * Read a single task by ID.
   *
   * @param int $task_id Task ID.
   * @return wild The "data" section of the envelope, a single task row.
   */
  public function getTask($task_id) {
    return $this->callGet(self::PATH_TASKS.'/'.(int)$task_id);
  }


/* -(  Requests  )----------------------------------------------------------- */


  /**
   * Issue an authenticated POST carrying a JSON body and unwrap the envelope.
   *
   * @param string $path Route path, appended to the base URI.
   * @param wild $body Structure to encode as the request body.
   * @return wild The "data" section of the envelope.
   */
  private function callPost($path, $body) {
    $uri = $this->getURI().$path;

    $future = $this->newJSONRequestFuture($uri, $body);

    if (phutil_nonempty_string($this->leaseOwner)) {
      $future->addHeader('X-Lease-Owner', $this->leaseOwner);
    }

    return self::parseResponseEnvelope($uri, $future->resolve());
  }

  /**
   * Issue an authenticated GET and unwrap the envelope.
   *
   * @param string $path Route path, appended to the base URI.
   * @param map<string, wild> $params Optional query parameters.
   * @return wild The "data" section of the envelope.
   */
  private function callGet($path, array $params = array()) {
    $uri = $this->getURI().$path;

    if ($params) {
      $uri .= '?'.http_build_query($params, '', '&');
    }

    $future = $this->newRequestFuture($uri);

    return self::parseResponseEnvelope($uri, $future->resolve());
  }

}
