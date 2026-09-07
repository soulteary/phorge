<?php

/**
 * File storage engine backed by the Gorge file storage service.
 *
 * The service fronts MySQL blob storage, local disk and S3-compatible object
 * stores with one HTTP API, ordering them by priority and moving down the
 * list when a write fails. Compare @{class:PhabricatorS3FileStorageEngine},
 * which builds AWS requests here in PHP: none of that lives in this engine,
 * and in exchange none of it can be tuned from this side either. What an
 * install gets instead is one place to configure three backends, and a
 * storage layer which can be redeployed without touching Phorge.
 *
 * The engine is discovered automatically by
 * @{method:PhabricatorFileStorageEngine::loadAllEngines}, so setting
 * `gorge.file.uri` is enough to make it writable -- but not enough to make it
 * win. Phorge's own engines have lower priority numbers and are selected
 * first, so an install which wants files to land here must also turn those
 * off; see "Configuring File Storage" and the file storage section of
 * DOCKER.md.
 *
 * @task meta Engine Metadata
 * @task file Managing File Data
 */
final class PhabricatorGorgeFileStorageEngine
  extends PhabricatorFileStorageEngine {


/* -(  Engine Metadata  )---------------------------------------------------- */


  /**
   * @return string Engine identifier string: "gorge"
   * @task meta
   */
  public function getEngineIdentifier() {
    return 'gorge';
  }


  /**
   * One HTTP round trip to a service on the same network, which is a little
   * more than MySQL (priority 1) and much less than S3 (priority 100). The
   * service is usually fronting one of those anyway, so this number describes
   * the hop rather than the storage behind it.
   *
   * @task meta
   */
  public function getEnginePriority() {
    return 2;
  }

  public function canWriteFiles() {
    return PhabricatorGorgeFileStorageClient::isConfigured();
  }


  // NOTE: hasFilesizeLimit() and getFilesizeLimit() are deliberately not
  // overridden, so this engine keeps the 8MB limit the base class defines.
  //
  // Declaring no limit would look like an improvement -- the service streams
  // to disk or to S3 and does not need to hold a file in memory -- but it
  // would silently switch off chunking for every large file:
  // PhabricatorChunkedFileStorageEngine only takes over when no unchunked
  // engine will accept the file, so an engine which accepts everything means
  // a 2GB upload arrives as one 2GB request body. That costs resumable
  // uploads, bounded memory on both sides, and a bounded request body limit
  // on the service.
  //
  // With the default limit in place, chunking cuts large files into 4MB
  // pieces and each piece is stored through this engine, so the service still
  // holds all of the data, one bounded request at a time. The base class
  // limit is 8MB precisely so that it clears the 4MB chunk size which
  // PhabricatorChunkedFileStorageEngine::getWritableEngine() requires of its
  // candidates: raising it is safe, lowering it below 4MB would make this
  // engine ineligible to store chunks.


/* -(  Managing File Data  )------------------------------------------------- */


  /**
   * Hand file data to the service and return a composite handle.
   *
   * The handle records which backend accepted the file, because the service
   * chooses that at write time and a read has to name it again. Chunked
   * storage means a large file produces one of these per 4MB chunk.
   *
   * @param string $data File data to write.
   * @param map<string, wild> $params File metadata, if available.
   * @return string Handle of the form "engine/handle".
   * @task file
   */
  public function writeFile($data, array $params) {
    $client = new PhabricatorGorgeFileStorageClient();

    $options = array();

    $name = idx($params, 'name');
    if (phutil_nonempty_string($name)) {
      $options['name'] = $name;
    }

    // The MIME type is deliberately not sent, even though the service accepts
    // it. What arrives here is the file data after the storage format has run
    // over it, so for an encrypted file it is not data of that type at all,
    // and the service would label the stored object with a type its bytes do
    // not have. Phorge's own S3 engine does not set a content type either.

    // Which engine to use is deliberately not sent either: the service orders
    // its backends by priority and falls through to the next one when a write
    // fails, which is the same thing loadStorageEngines() does on this side.
    // Pinning an engine from here would turn a recoverable write failure into
    // a failed upload.

    $result = $client->writeFile($data, $options);

    $engine = idx($result, 'engine');
    $handle = idx($result, 'handle');

    if (!phutil_nonempty_string($engine) ||
        !phutil_nonempty_string($handle)) {
      // Guard this rather than trusting the response: a half-empty answer
      // would compose into a handle like "local-disk/" which is nonempty, so
      // the caller's own validation would accept it and the file would be
      // unreadable from then on.
      throw new PhabricatorFileStorageConfigurationException(
        pht(
          'The Gorge file storage service stored a file but did not report '.
          'both the engine and the handle it used, so there is no way to '.
          'read the file back.'));
    }

    return $engine.'/'.$handle;
  }


  /**
   * @task file
   */
  public function readFile($handle) {
    $parts = $this->parseHandle($handle);

    $client = new PhabricatorGorgeFileStorageClient();

    return $client->readFile($parts['engine'], $parts['handle']);
  }


  /**
   * @task file
   */
  public function deleteFile($handle) {
    $parts = $this->parseHandle($handle);

    $client = new PhabricatorGorgeFileStorageClient();

    $client->deleteFile($parts['engine'], $parts['handle']);
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * Split a stored handle back into a backend and a backend handle.
   *
   * Splitting on the first slash rather than the last is what makes the
   * composite work: backend identifiers ("blob", "local-disk", "amazon-s3")
   * have no slashes in them, while the handles do -- local disk uses
   * "ab/cd/{28 hex digits}" and S3 uses a "phabricator/..." key. The longest
   * combination is well inside the 255 characters the base class allows for a
   * handle.
   *
   * @param string $handle Handle as it was stored.
   * @return map<string, string> Map with "engine" and "handle" keys.
   */
  private function parseHandle($handle) {
    $slash = strpos($handle, '/');

    if ($slash === false || $slash === 0) {
      throw new Exception(
        pht(
          'Gorge file storage handle "%s" is malformed: it should name the '.
          'backend which holds the file and the handle that backend '.
          'returned, as "engine/handle".',
          $handle));
    }

    return array(
      'engine' => substr($handle, 0, $slash),
      'handle' => substr($handle, $slash + 1),
    );
  }

}
