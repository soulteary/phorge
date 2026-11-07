"""Actual-CDP certificate failure collector; no certificate bypass or launcher."""
from urllib.parse import urlsplit


class TrustUnproved(RuntimeError):
    pass


class FailureObservation:
    def __init__(self, target, code):
        self.target, self.code = target, code
        self.requests, self.failures = set(), set()
        self.invalid, self.events = False, 0

    def event(self, kind, value):
        self.events += 1
        if self.events > 128 or type(value) is not dict:
            self.invalid = True
            return
        identity = value.get('requestId')
        if type(identity) is not str or not 0 < len(identity) <= 128:
            self.invalid = True
            return
        if kind == 'request':
            request = value.get('request')
            if type(request) is not dict:
                self.invalid = True
                return
            if request.get('url') != self.target:
                return
            if value.get('type') != 'Document' or request.get('method') != 'GET' or \
                    'redirectResponse' in value or identity in self.requests or self.requests:
                self.invalid = True
                return
            self.requests.add(identity)
        elif identity in self.requests:
            if kind == 'response':
                self.invalid = True  # Any HTTP response means TLS was accepted.
            elif kind == 'failure':
                if value.get('type') != 'Document' or value.get('errorText') != self.code or \
                        value.get('canceled') is not False or 'blockedReason' in value or \
                        identity in self.failures:
                    self.invalid = True
                self.failures.add(identity)

    def verify(self):
        if self.invalid or len(self.requests) != 1 or self.failures != self.requests:
            raise TrustUnproved('browser_actual_certificate_failure_unproved')


def negative(context, origin, phase):
    hosts = {'untrusted-root': 'app.media.localhost',
             'wrong-leaf': 'app.media.localhost', 'wrong-hostname': 'wrong.media.localhost'}
    if phase not in hosts or type(origin) is not str:
        raise TrustUnproved('browser_trust_negative_profile_unknown')
    uri = urlsplit(origin)
    try:
        port = uri.port
    except ValueError:
        raise TrustUnproved('browser_trust_negative_profile_unknown') from None
    if uri.scheme != 'https' or uri.hostname != hosts[phase] or uri.username is not None or \
            uri.password is not None or uri.path or uri.query or uri.fragment or \
            type(port) is not int or not 0 < port <= 65535 or \
            origin != 'https://' + hosts[phase] + ':' + str(port):
        raise TrustUnproved('browser_trust_negative_profile_unknown')
    code = 'net::ERR_CERT_COMMON_NAME_INVALID' if phase == 'wrong-hostname' else 'net::ERR_CERT_AUTHORITY_INVALID'
    observer = FailureObservation(origin + '/', code)
    page = context.new_page()
    session, primary, cleanup_error = None, None, None
    try:
        session = context.new_cdp_session(page)
        session.send('Network.enable')
        for event, kind in [('Network.requestWillBeSent', 'request'),
                            ('Network.loadingFailed', 'failure'),
                            ('Network.responseReceived', 'response')]:
            session.on(event, lambda value, kind=kind: observer.event(kind, value))
        try:
            page.goto(origin + '/', wait_until='domcontentloaded', timeout=10000)
        except Exception:
            # Exception text may contain a private URL. It is not proof and is
            # deliberately not read/retained. Only actual CDP events count.
            pass
        observer.verify()
    except Exception as error:
        primary = error
    finally:
        if session is not None:
            try:
                session.detach()
            except Exception:
                cleanup_error = True
        try:
            page.close()
        except Exception:
            cleanup_error = True
    if primary is not None or cleanup_error:
        raise TrustUnproved('browser_trust_negative_or_cleanup_unproved') from None
    return {'phase': phase, 'actual_document_certificate_failure_observed': True,
            'certificate_source_binding_proved': False, 'browser_normal_TLS_complete': False,
            'deployment': False}
