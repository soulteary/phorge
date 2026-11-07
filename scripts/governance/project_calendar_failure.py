"""Owned Calendar failure metadata only; never raw receipt, SQL or mail output."""
import argparse
import json
import os
from pathlib import Path
import stat
import sys

import check_completion as completion
import check_private_calendar_reminders as calendar
import check_recovery_partial_composition as shared

FAILURE_DIRECTORY = 'governance-private-calendar-failure'
RECEIPT_DIRECTORY = 'governance-private-calendar-reminders'
MAX_OUTPUT_BYTES = 16384
ERROR_TYPES = frozenset({
    'Error', 'TypeError', 'ValueError', 'Exception', 'RuntimeException',
    'InvalidArgumentException', 'LogicException', 'BadMethodCallException',
    'PhutilAggregateException', 'PhutilProxyException', 'PhutilMethodNotImplementedException',
    'PhutilMissingSymbolException', 'PhutilPropertyNotFoundException',
    'PhutilBootloaderException', 'PhabricatorPolicyException',
    'PhabricatorMetaMTAPermanentFailureException', 'mysqli_sql_exception',
    'AphrontQueryException', 'AphrontSchemaQueryException',
    'AphrontConnectionQueryException', 'AphrontAccessDeniedQueryException',
    'AphrontDuplicateKeyQueryException', 'AphrontDeadlockQueryException',
    'AphrontLockTimeoutQueryException', 'AphrontSchemaObjectMissingQueryException',
})
SOURCE_FILES = frozenset(Path(name).name for name in calendar.SOURCE_INPUTS) | frozenset({
    'Phobject.php', 'LiskDAO.php', 'PhabricatorLiskDAO.php', 'PhabricatorCalendarDAO.php',
    'PhabricatorUser.php', 'PhabricatorUserEmail.php', 'PhabricatorPeopleQuery.php',
    'PhabricatorObjectQuery.php', 'PhabricatorWorkerActiveTask.php',
    'PhabricatorWorkerTaskData.php', 'PhabricatorMetaMTAGorgeOutbox.php',
    'PhabricatorGorgeTaskQueueClient.php', 'PhabricatorGorgeServiceClient.php',
    'PhutilBootloader.php', 'PhutilSymbolLoader.php', 'utils.php', 'queryfx.php',
    'AphrontBaseMySQLDatabaseConnection.php', 'AphrontMySQLiDatabaseConnection.php',
})
STAGES = frozenset(calendar.obligations()) | frozenset({
    'preflight', 'full-native-source-restore', 'owned-real-gorge-file-service',
    'focused-native-calendar-reminders',
})
CLEANUP_STAGES = frozenset({
    'owned-failure-rollback', 'write-guard-cleanup', 'owned-writer-pool-cleanup',
    'environment-cleanup', 'owned-file-service-cleanup', 'owned-worker-cleanup',
    'owned-cleanup',
})


def integer(value, minimum=0, maximum=128):
    return value if type(value) is int and minimum <= value <= maximum else None


def flag(value):
    return value if type(value) is bool else None


def known(value, allowed):
    return value if type(value) is str and value in allowed else None


def failure_fields(value, stages=STAGES):
    if type(value) is not dict:
        return None
    projected = {'stage': known(value.get('stage'), stages),
        'error_type': known(value.get('error_type'), ERROR_TYPES),
        'source_file': known(value.get('source_file'), SOURCE_FILES),
        'source_line': integer(value.get('source_line'), 1, 1000000)}
    if 'sql_errno' in value:
        projected['sql_errno'] = integer(value['sql_errno'], 1000, 9999)
    return projected


def unavailable():
    return {'scope': 'native-calendar-failure-diagnostic-v1', 'passed': False,
        'diagnostic_available': False, 'SQL_success_proved': False}


def project(value):
    # Only this exact failed phase may populate a diagnostic. No success,
    # another native profile or a caller-supplied scope is adopted.
    if (type(value) is not dict or value.get('passed') is not False or
            value.get('batch') != 'native-private-calendar-reminders' or
            value.get('scope') != 'owned-native-calendar-reminders-no-SMTP'):
        return unavailable()
    checks = value.get('checks')
    checks_valid = (type(checks) is list and len(checks) <= len(calendar.obligations()) and
        all(type(item) is str and item in calendar.obligations() for item in checks) and
        len(checks) == len(set(checks)) and type(value.get('check_count')) is int and
        value['check_count'] == len(checks))
    cleanup = value.get('cleanup')
    cleanup = cleanup if type(cleanup) is dict else {}
    workers = value.get('worker_cleanup')
    workers = workers if type(workers) is dict else {}
    file_service = value.get('file_service_cleanup')
    file_service = file_service if type(file_service) is dict and file_service.get('kind') == 'file' else {}
    errors = value.get('cleanup_failures')
    errors_valid = type(errors) is list and len(errors) <= 16 and all(type(item) is dict for item in errors)
    return {'scope': 'native-calendar-failure-diagnostic-v1', 'passed': False,
        'diagnostic_available': True, 'SQL_success_proved': False,
        'failure': failure_fields(value.get('failure')),
        'checks_valid': checks_valid,
        'completed_obligations': sorted(checks) if checks_valid else [],
        'cleanup': {
            'owned_schemas_removed': integer(cleanup.get('ownedSchemasRemoved'), 0, 55),
            'owned_runtime_users_removed': integer(cleanup.get('ownedRuntimeUsersRemoved'), 0, 4),
            'reader_removed': flag(cleanup.get('readerRemoved')),
            'registered_workers': integer(workers.get('workers_registered')),
            'live_processes_after_cleanup': integer(workers.get('live_processes_after_cleanup')),
            'private_directories_remaining': integer(workers.get('private_directories_remaining')),
            'worker_cleanup_failures': integer(workers.get('cleanup_failures')),
            'file_process_stopped': flag(file_service.get('process_stopped')),
            'file_private_storage_removed': flag(file_service.get('private_storage_removed')),
            'terminal_failure_cleanup_rollbacks': integer(value.get('terminal_failure_cleanup_rollbacks')),
            'cleanup_error_list_valid': errors_valid,
            'cleanup_error_count': len(errors) if errors_valid else None,
            'cleanup_errors': [failure_fields(item, CLEANUP_STAGES) for item in errors] if errors_valid else [],
        }}


def identities(value):
    return (value.st_dev, value.st_ino, value.st_uid, value.st_gid, value.st_mode)


def stamp(value):
    return identities(value) + (value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def roles(receipt, output):
    selected = os.environ.get('RUNNER_TEMP')
    if not selected:
        raise ValueError('fixed runner roles required')
    runner = Path(selected)
    if not runner.is_absolute() or runner.resolve(strict=True) != runner:
        raise ValueError('canonical runner role required')
    if receipt != runner / RECEIPT_DIRECTORY / 'integration.json' or output != runner / FAILURE_DIRECTORY / 'diagnostic.json':
        raise ValueError('fixed Calendar roles required')
    for parent in output.parents:
        info = os.lstat(parent)
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
            raise ValueError('output parent alias refused')


def write_exclusive(output, raw):
    """Hold the created directory/file FDs through write and final path proof.

    The same captured safe bytes are returned to stderr; no cat/path reopen.
    This proves ownership at capture, not perpetual control of a CI runner.
    """
    if type(raw) is not bytes or not 0 < len(raw) <= MAX_OUTPUT_BYTES:
        raise ValueError('bounded safe output required')
    parent_fd = os.open(output.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    fd = None
    try:
        parent = os.fstat(parent_fd)
        if (parent.st_uid != os.getuid() or stat.S_IMODE(parent.st_mode) != 0o700 or
                identities(os.lstat(output.parent)) != identities(parent)):
            raise ValueError('private owned failure directory required')
        fd = os.open(output.name, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_NONBLOCK,
            0o600, dir_fd=parent_fd)
        initial = os.fstat(fd)
        if (not stat.S_ISREG(initial.st_mode) or initial.st_uid != os.getuid() or
                initial.st_nlink != 1 or stat.S_IMODE(initial.st_mode) != 0o600):
            raise ValueError('private exclusive diagnostic required')
        offset = 0
        while offset < len(raw):
            written = os.write(fd, raw[offset:])
            if written <= 0:
                raise ValueError('diagnostic short write')
            offset += written
        os.fsync(fd)
        before_read = os.fstat(fd)
        os.lseek(fd, 0, os.SEEK_SET)
        captured = bytearray()
        while len(captured) < len(raw):
            chunk = os.read(fd, len(raw) - len(captured))
            if not chunk:
                raise ValueError('diagnostic readback incomplete')
            captured.extend(chunk)
        final = os.fstat(fd)
        if bytes(captured) != raw or stamp(before_read) != stamp(final):
            raise ValueError('diagnostic captured bytes changed')
        if (identities(final) != identities(initial) or final.st_nlink != 1 or
                stat.S_IMODE(final.st_mode) != 0o600 or final.st_size != len(raw) or
                stamp(final) != stamp(os.stat(output.name, dir_fd=parent_fd, follow_symlinks=False)) or
                identities(os.fstat(parent_fd)) != identities(parent) or
                identities(os.lstat(output.parent)) != identities(parent)):
            raise ValueError('diagnostic ownership changed')
    finally:
        if fd is not None:
            os.close(fd)
        os.close(parent_fd)


class SafeParser(argparse.ArgumentParser):
    def error(self, message):
        raise SystemExit('Calendar diagnostic invocation unavailable')


def main():
    parser = SafeParser(description=__doc__)
    parser.add_argument('--receipt', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    result = unavailable()
    try:
        roles(args.receipt, args.output)
    except (ValueError, OSError):
        raise SystemExit('Calendar diagnostic role unavailable') from None
    try:
        calendar.sources()  # Exact reviewed PHP sources, not raw-receipt code.
        def nonfinite(_):
            raise ValueError('nonfinite JSON refused')
        data = json.loads(shared.read_checked(args.receipt),
            object_pairs_hook=completion.strict_object, parse_constant=nonfinite)
        result = project(data)
    except (ValueError, TypeError, OSError, KeyError, UnicodeError, RecursionError):
        pass
    raw = (json.dumps(result, sort_keys=True, allow_nan=False) + '\n').encode('ascii')
    try:
        write_exclusive(args.output, raw)
    except (ValueError, OSError):
        raise SystemExit('Calendar diagnostic output unavailable') from None
    # The safe in-memory projection is emitted only after exclusive-file proof.
    # Never read the output path again, or print input/error/SQL/body strings.
    sys.stderr.write(raw.decode('ascii'))


if __name__ == '__main__':
    main()
