"""Offline content checks for private Task wire evidence; not a runtime permit.

Raw capture must come from the authenticated owned sink and the parent must bind
it to native producer/outbox/worker records. This module cannot attest provenance.
No network, SQL, service bootstrap or production writes occur here.
"""
import hashlib
import re
from email import policy
from email.parser import BytesParser
from email.utils import getaddresses
from urllib.parse import urlsplit


def require(condition, code):
    if not condition:
        raise ValueError(code)


def validate_mime(raw, *, recipient, origin, task_phid, task_id, forbidden, void_address=None):
    require(type(raw) is bytes and 0 < len(raw) <= 10 * 1024 * 1024, 'mime_size')
    require(type(recipient) is str and re.fullmatch(r'[a-z0-9._+-]+@example\.invalid', recipient), 'synthetic_recipient')
    require(type(task_phid) is str and re.fullmatch(r'PHID-TASK-[A-Za-z0-9]{20}', task_phid), 'task_phid')
    require(type(task_id) is int and task_id > 0, 'task_id')
    parsed = urlsplit(origin)
    require(parsed.scheme in ('http', 'https') and parsed.hostname == '127.0.0.1'
            and parsed.port is not None and 0 < parsed.port <= 65535
            and parsed.path == '' and not parsed.query and not parsed.fragment
            and not parsed.username and not parsed.password, 'owned_loopback_origin')
    require(type(forbidden) is tuple and len(forbidden) >= 3
            and all(type(x) is bytes and len(x) >= 12 for x in forbidden), 'canaries_required')
    message = BytesParser(policy=policy.default).parsebytes(raw)
    require(not message.defects, 'mime_parse')
    require(not message.get_all('Bcc'), 'roster_leak')
    to = getaddresses(message.get_all('To', []))
    cc = getaddresses(message.get_all('Cc', []))
    require(len(message.get_all('To', [])) == 1 and len(message.get_all('Cc', [])) <= 1, 'recipient_split')
    if cc:
        require(type(void_address) is str and re.fullmatch(r'[a-z0-9._+-]+@example\.invalid', void_address)
                and void_address != recipient, 'synthetic_void_required')
        require(to == [('', void_address)] and cc == [('', recipient)], 'roster_leak')
    else:
        require(to == [('', recipient)], 'recipient_split')
    require(len(message.get_all('Subject', [])) == 1 and str(message['Subject']) == 'Private notification', 'private_subject')
    require(not message.is_multipart() and message.get_content_type() == 'text/plain'
            and not message.get_filename() and not message.get('Content-Disposition'), 'attachment_or_html')
    body = message.get_content()
    expected = origin + '/object/' + task_phid + '/'
    require(body.strip() == 'Sign in to view this notification.\n\n' + expected, 'exact_task_login_route')
    require(not any(name.lower() in ('authorization','cookie','set-cookie') or
                    name.lower().startswith('x-phabricator-') for name in message.keys()), 'private_header_leak')
    decoded = ('\n'.join(str(value) for _, value in message.items()) + body).encode('utf-8')
    for marker in forbidden:
        require(marker not in raw and marker not in decoded, 'private_content_leak')
    return {'mime_sha256': hashlib.sha256(raw).hexdigest(), 'mime_bytes': len(raw),
            'recipient_count': 1, 'attachments': 0, 'task_route_bound': True,
            'task_target_sha256': hashlib.sha256((task_phid + ':' + str(task_id)).encode()).hexdigest(),
            'SMTP_proved': False, 'browser_proved': False,
            'provenance_requires_owned_parent': True}


def validate_case_counts(case, *, before, after, expected_delivery_id, observed_delivery_ids):
    """Strict sink deltas, never infer successful SMTP from worker exit status."""
    require(case in ('allow', 'retry-after-ack', 'revoke-before-prepare',
                     'revoke-after-prepare', 'nonrecipient', 'rollback'), 'case')
    require(type(before) is int and type(after) is int and 0 <= before <= after, 'sink_counts')
    require(type(expected_delivery_id) is str and re.fullmatch(r'[A-Za-z0-9_./:-]{1,256}', expected_delivery_id), 'delivery_binding')
    require(type(observed_delivery_ids) is tuple and
            len(observed_delivery_ids) == after - before, 'sink_observation_count')
    require(case != 'retry-after-ack' or before >= 1, 'prior_delivery_required')
    positive = case == 'allow'
    require(after - before == (1 if positive else 0), 'unexpected_physical_delivery')
    require(observed_delivery_ids == ((expected_delivery_id,) if positive else ()), 'wrong_delivery')
    return {'case': case, 'new_mime_count': after - before,
            'SMTP_proved': False, 'browser_proved': False,
            'provenance_requires_owned_parent': True}
