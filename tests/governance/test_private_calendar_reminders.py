"""Native Calendar/Mail construction regression; no SQL or physical-mail proof."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]

class PrivateCalendarReminderTests(unittest.TestCase):
    def invoke(self, enabled=True, events=(2, 1)):
        result = subprocess.run(
            ['php', str(ROOT / 'support/governance/notification/tests/calendar-reminder-host.php')],
            input=json.dumps({'enabled': enabled, 'events': events}),
            capture_output=True, text=True, check=True, timeout=15)
        self.assertEqual('', result.stderr)
        return json.loads(result.stdout)

    def test_private_two_events_retain_two_independently_bound_destinations(self):
        value = self.invoke()
        self.assertIsNone(value['error'])
        self.assertEqual(['PHID-CEVT-' + str(i) * 20 for i in (2, 1)],
                         [row['related'] for row in value['rows']])
        for row in value['rows']:
            self.assertEqual('Private notification', row['subject'])
            self.assertEqual('Sign in to view this notification.\n\nhttps://app.example.invalid/object/' + row['related'] + '/', row['body'])
            self.assertIsNone(row['html'])
            self.assertEqual([], row['attachments'])
            self.assertNotIn('SYNTHETIC_PRIVATE', json.dumps(row))

    def test_off_retains_exact_original_native_digest_and_nearest_event(self):
        value = self.invoke(False)
        self.assertIs(value['off_exact_native_digest'], True)
        self.assertEqual(1, len(value['rows']))
        row = value['rows'][0]
        self.assertEqual('PHID-CEVT-' + '1' * 20, row['related'])
        self.assertIn('(+1 more...)', row['subject'])
        self.assertIn('SYNTHETIC_PRIVATE_EVENT_1', row['body'])
        self.assertIn('SYNTHETIC_PRIVATE_EVENT_2', row['body'])
        self.assertIs(row['same_parameters_after_sanitizing'], True)

    def test_each_message_preserves_native_recipient_and_fixed_producer(self):
        for row in self.invoke()['rows']:
            self.assertEqual(['PHID-USER-' + 'u' * 20], row['to'])
            self.assertEqual([], row['cc'])
            self.assertEqual('PhabricatorCalendarNotificationEngine', row['producer'])

    def test_single_event_is_not_duplicated(self):
        self.assertEqual(1, len(self.invoke(events=(1,))['rows']))
        self.assertEqual(1, len(self.invoke(False, (1,))['rows']))

    def test_no_private_event_creates_no_message(self):
        self.assertEqual([], self.invoke(events=())['rows'])

    def test_existing_view_occurrence_identity_not_deduplicated_by_phid(self):
        # Native upstream selection/marking owns occurrence deduplication. This
        # projection must not add PHID-only suppression of recurring instances.
        self.assertEqual(2, len(self.invoke(events=(1, 1))['rows']))

    def test_non_boolean_flag_fails_closed(self):
        for enabled in (None, 'false', 0, 1, [], {}):
            with self.subTest(enabled=enabled):
                self.assertEqual({'error': 'private_mail_control_unknown'}, self.invoke(enabled))

if __name__ == '__main__':
    unittest.main()
