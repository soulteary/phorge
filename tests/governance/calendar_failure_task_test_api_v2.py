"""Explicit CURRENT Calendar diagnostic CLI for owned synthetic controls.

The original diagnostic CLI and its fail-closed legacy source route remain
unchanged. This successor acquires the actual source owner, executes captured
CURRENT code, and uses the separately bounded CURRENT Calendar source view.
It proves diagnostic projection only, never native SQL or mail delivery.
"""
import sys
import types
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/governance'))
sys.path.insert(0, str(ROOT / 'tests/governance'))
from calendar_sql_task_test_api_v2 import CalendarCurrent
from task_inverse_test_api_v2 import owner

DIAGNOSTIC = 'scripts/governance/project_calendar_failure.py'


def main():
    with owner(ROOT) as operation:
        current = CalendarCurrent(operation)
        current.require()
        context = operation._task_contexts[ROOT]
        raw = context.current(DIAGNOSTIC)
        if type(raw) is not bytes or raw != (ROOT / DIAGNOSTIC).read_bytes():
            raise ValueError('Calendar_diagnostic_current_bytes_refused')
        diagnostic = types.ModuleType('Calendar_CURRENT_owned_failure_diagnostic')
        diagnostic.__file__ = str(ROOT / DIAGNOSTIC)
        exec(compile(raw, diagnostic.__file__, 'exec'), diagnostic.__dict__)
        diagnostic.calendar = current.gate
        diagnostic.main()
        current.require()


if __name__ == '__main__':
    main()
