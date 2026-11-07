"""Fixed, private real-browser media flow. No service/browser launcher or authority.

The parent must supply a fresh browser context with normally verified TLS. This
module never installs trust, changes host resolution, seeds cookies or replaces
the application's JavaScript. Public results contain only fixed check names.
"""
import hashlib
import hmac
import os
import re
from urllib.parse import urlsplit
try:
    from . import approved_origins
except ImportError:  # Standalone host controls use the same fixed sibling.
    import importlib.util
    from pathlib import Path
    _spec = importlib.util.spec_from_file_location('approved_media_origins',
        Path(__file__).with_name('approved_origins.py'))
    approved_origins = importlib.util.module_from_spec(_spec)
    _spec.loader.exec_module(approved_origins)

APP = 'app.media.localhost'
FILES = 'files.media.localhost'
MEDIA = {'__Host-phorge-media-browser', '__Host-phorge-media'}


class Refused(RuntimeError):
    """Fixed, nonsecret diagnostic category."""


def require(value, category):
    if value is not True:
        raise Refused(category)


def origins(manifest, approved_profile=None):
    if approved_profile is not None:
        try:
            app, files = approved_origins.verified(approved_profile)
            require(type(manifest) is dict and manifest.get('app_origin') == app and
                    manifest.get('files_origin') == files and
                    manifest.get('remote_origin_profile') == approved_profile.native_profile(),
                    'browser_approved_native_profile_mismatch')
            return app, files
        except approved_origins.OriginUnproved:
            raise Refused('browser_approved_origin_profile_unproved') from None
    values = []
    for name, host in [('app_origin', APP), ('files_origin', FILES)]:
        value = manifest.get(name)
        require(type(value) is str, 'browser_origin_unknown')
        parsed = urlsplit(value)
        try:
            port = parsed.port
        except ValueError:
            raise Refused('browser_origin_unknown') from None
        require(parsed.scheme == 'https' and parsed.hostname == host and
                parsed.username is None and parsed.password is None and
                parsed.path == '' and parsed.query == '' and parsed.fragment == '' and
                type(port) is int and 1 <= port <= 65535 and
                value == 'https://' + host + ':' + str(port), 'browser_origin_unknown')
        values.append(value)
    require(urlsplit(values[0]).port == urlsplit(values[1]).port,
            'browser_origin_pair_unknown')
    return tuple(values)


def validate_manifest(manifest, approved_profile=None):
    require(type(manifest) is dict, 'browser_manifest_unknown')
    app, files = origins(manifest, approved_profile)
    require(manifest.get('scope') == 'private-synthetic-native-password-browser-fixture'
            and manifest.get('browser_session_preseeded') is False,
            'browser_native_login_required')
    for key in ['login_username', 'login_password', 'private_content_marker']:
        require(type(manifest.get(key)) is str and 0 < len(manifest[key]) <= 4096,
                'browser_manifest_unknown')
    require(type(manifest.get('expected_content_sha256')) is str and
            re.fullmatch('[a-f0-9]{64}', manifest['expected_content_sha256']) is not None and
            hashlib.sha256(manifest['private_content_marker'].encode()).hexdigest() ==
            manifest['expected_content_sha256'], 'browser_content_binding_unknown')
    for key, origin, path_prefix in [
        ('login_uri', app, '/auth/login/'),
        ('manual_authorize_uri', app, '/auth/media/authorize/'),
        ('private_file_uri', files, '/file/data/')]:
        value = manifest.get(key)
        require(type(value) is str and value.startswith(origin + path_prefix) and
                urlsplit(value).fragment == '' and urlsplit(value).username is None,
                'browser_target_unknown')
    return app, files


def cookie_values(raw):
    require(type(raw) is str and len(raw) <= 8192, 'browser_cookie_header_unknown')
    values = {}
    for part in raw.split(';'):
        if not part.strip():
            continue
        name, separator, value = part.strip().partition('=')
        require(bool(separator) and re.fullmatch('[A-Za-z0-9_-]{1,128}', name) is not None,
                'browser_cookie_header_unknown')
        require(name not in values, 'browser_cookie_duplicate')
        values[name] = value
    return values


def cookie_names(raw):
    return set(cookie_values(raw))


class OldMediaCookies:
    """Ephemeral private HMAC bindings, not public evidence or credentials."""
    def __init__(self, cookies, approved_profile=None):
        self._approved_profile = approved_profile
        self._key = os.urandom(32)
        self._digests = self._jar(cookies)

    def __repr__(self):
        return '<private-old-media-cookie-binding>'

    def _value(self, value):
        require(type(value) is str and re.fullmatch('[a-f0-9]{64}', value) is not None,
                'browser_original_media_cookie_value_unknown')
        return hmac.new(self._key, value.encode(), hashlib.sha256).digest()

    def _jar(self, cookies):
        require(type(cookies) is list and len(cookies) <= 32,
                'browser_original_media_cookie_jar_unknown')
        values = {}
        file_host = FILES if self._approved_profile is None else urlsplit(approved_origins.verified(self._approved_profile)[1]).hostname
        for cookie in cookies:
            require(type(cookie) is dict, 'browser_original_media_cookie_jar_unknown')
            name = cookie.get('name')
            if name not in MEDIA:
                continue
            require(name not in values and cookie.get('domain') == file_host and
                    cookie.get('path') == '/' and cookie.get('secure') is True and
                    cookie.get('httpOnly') is True and cookie.get('sameSite') == 'Strict',
                    'browser_original_media_cookie_scope_changed')
            values[name] = self._value(cookie.get('value'))
        require(set(values) == MEDIA, 'browser_original_media_cookie_missing')
        return values

    def assert_jar(self, cookies):
        values = self._jar(cookies)
        require(all(hmac.compare_digest(values[name], self._digests[name]) for name in MEDIA),
                'browser_original_media_cookie_rotated')

    def assert_header(self, raw):
        values = cookie_values(raw)
        require(set(values) == MEDIA, 'browser_original_media_cookie_not_sent')
        require(all(hmac.compare_digest(self._value(values[name]), self._digests[name])
                    for name in MEDIA), 'browser_original_media_cookie_sent_value_changed')


class Observer:
    """Retain only fixed counters, never URL/cookie/origin/identity values."""
    def __init__(self, app, files):
        self.app, self.files = app, files
        self.count = 0
        self.bridge_same_site = 0
        self.posts = set()
        self.error = None
        self.allow_file_probe_top_level = False
        self.old_media = None
        self.old_media_target = None
        self.logout_probes = set()

    def request(self, url, method, headers):
        if self.error is not None:
            return
        try:
            self._request(url, method, headers)
        except Refused as error:
            self.error = str(error)
        except Exception:
            self.error = 'browser_request_observation_unproved'

    def _request(self, url, method, headers):
        self.count += 1
        require(self.count <= 2048 and type(headers) is dict, 'browser_observation_budget')
        parsed = urlsplit(url)
        origin = parsed.scheme + '://' + parsed.netloc
        require(origin in (self.app, self.files) and parsed.fragment == '',
                'browser_external_request_refused')
        names = cookie_names(headers.get('cookie', ''))
        if origin == self.files:
            require(not (names - MEDIA), 'browser_application_cookie_reached_file_origin')
        else:
            require(not (names & MEDIA), 'browser_media_cookie_reached_app_origin')
        if self.old_media is not None and url == self.old_media_target:
            probe = headers.get('x-phorge-browser-probe')
            require(probe in {'get', 'head', 'range', 'conditional'} and
                    probe not in self.logout_probes and
                    method == ('HEAD' if probe == 'head' else 'GET') and
                    headers.get('sec-fetch-site') == 'same-origin' and
                    (probe != 'range' or headers.get('range') == 'bytes=0-3') and
                    (probe != 'conditional' or bool(headers.get('if-none-match'))),
                    'browser_logout_probe_request_unknown')
            self.old_media.assert_header(headers.get('cookie', ''))
            self.logout_probes.add(probe)
        if origin == self.files and parsed.path == '/media/session/bridge/':
            site = headers.get('sec-fetch-site')
            require(site == 'same-site' or (self.allow_file_probe_top_level and
                    site == 'none' and headers.get('sec-fetch-dest') == 'document'),
                    'browser_actual_same_site_unproved')
            if site == 'same-site':
                self.bridge_same_site += 1
        actions = {'/media/session/prepare/': self.files,
                   '/media/session/redeem/': self.files,
                   '/media/session/ready/': self.files,
                   '/auth/media/issue/': self.app}
        if parsed.path in actions:
            require(origin == actions[parsed.path] and method == 'POST' and
                    headers.get('origin') == origin and
                    headers.get('sec-fetch-site') == 'same-origin',
                    'browser_actual_post_origin_unproved')
            self.posts.add(parsed.path)

    def assert_complete(self):
        if self.error is not None:
            raise Refused(self.error)
        require(self.bridge_same_site >= 1 and self.posts == {
            '/media/session/prepare/', '/media/session/redeem/',
            '/media/session/ready/', '/auth/media/issue/'}, 'browser_protocol_not_observed')
        if self.old_media is not None:
            require(self.logout_probes == {'get', 'head', 'range', 'conditional'},
                    'browser_old_cookie_probe_send_unproved')


def verify_cookies(cookies, approved_profile=None):
    require(type(cookies) is list and len(cookies) <= 32, 'browser_cookie_jar_unknown')
    app_host, file_host = (APP, FILES) if approved_profile is None else tuple(urlsplit(x).hostname for x in approved_origins.verified(approved_profile))
    media = set()
    session = False
    for cookie in cookies:
        name = cookie.get('name')
        if name in MEDIA:
            require(cookie.get('domain') == file_host and cookie.get('path') == '/' and
                    cookie.get('secure') is True and cookie.get('httpOnly') is True and
                    cookie.get('sameSite') == 'Strict', 'browser_media_cookie_attributes')
            require(name not in media, 'browser_media_cookie_duplicate')
            media.add(name)
        elif name == 'phsid':
            require(cookie.get('domain') == app_host and cookie.get('secure') is True and
                    cookie.get('httpOnly') is True, 'browser_native_session_cookie_scope')
            require(not session, 'browser_native_session_duplicate')
            session = True
    require(media == MEDIA and session, 'browser_real_session_and_media_cookies_required')


EXPECTED_CHECKS = ('actual-native-password-login-no-preseeded-session', 'actual-original-iframe-and-postMessage-media-exchange', 'browser-same-site-frame-and-same-origin-POST-evidence', 'actual-host-only-secure-httpOnly-strict-media-cookies', 'no-native-session-cookie-on-file-origin-or-media-cookie-on-app', 'real-private-file-bytes-and-no-store', 'real-file-origin-fetch-positive-before-logout-not-opaque-sandbox', 'actual-native-CSRF-form-logout', 'same-old-media-cookie-values-present-and-sent-on-all-four-probes', 'old-media-cookie-GET-HEAD-Range-conditional-denied-after-real-logout')


def run(context, manifest, *, approved_profile=None):
    """Run with a fresh real Playwright BrowserContext; no ignored TLS errors.

    A context is deliberately supplied by the separately reviewed owned parent.
    No success from this function implies parent/process/SQL cleanup completion.
    """
    app, files = validate_manifest(manifest, approved_profile)
    app_host = urlsplit(app).hostname
    require(context.cookies() == [], 'browser_context_not_fresh')
    observer = Observer(app, files)
    def observe(request):
        try:
            observer.request(request.url, request.method, request.all_headers())
        except Exception:
            observer.error = 'browser_request_observation_unproved'
    context.on('request', observe)
    page = context.new_page()
    checks = []
    try:
        page.goto(manifest['login_uri'], wait_until='domcontentloaded')
        page.locator('input[name="username"]').fill(manifest['login_username'])
        page.locator('input[name="password"]').fill(manifest['login_password'])
        page.get_by_role('button', name='Log In', exact=True).click()
        page.wait_for_load_state('domcontentloaded')
        require(any(c.get('name') == 'phsid' and c.get('domain') == app_host
                    for c in context.cookies()), 'browser_native_login_not_observed')
        checks.append('actual-native-password-login-no-preseeded-session')
        with page.expect_response(lambda response: response.url == manifest['private_file_uri']
                                  and response.request.method == 'GET') as response_info:
            page.goto(manifest['manual_authorize_uri'], wait_until='domcontentloaded')
            page.wait_for_url(manifest['private_file_uri'])
        response = response_info.value
        require(response.status == 200 and hashlib.sha256(response.body()).hexdigest() ==
                manifest['expected_content_sha256'], 'browser_private_file_bytes_unproved')
        require('no-store' in response.all_headers().get('cache-control', ''),
                'browser_private_file_cache_unknown')
        observer.assert_complete()
        verify_cookies(context.cookies(), approved_profile)
        original_media = OldMediaCookies(context.cookies(), approved_profile)
        checks += ['actual-original-iframe-and-postMessage-media-exchange',
                   'browser-same-site-frame-and-same-origin-POST-evidence',
                   'actual-host-only-secure-httpOnly-strict-media-cookies',
                   'no-native-session-cookie-on-file-origin-or-media-cookie-on-app',
                   'real-private-file-bytes-and-no-store']
        # The data response is intentionally CSP-sandboxed and may have an
        # opaque origin. Probes must run on the genuine file-origin bridge,
        # with a successful fetch before logout, or denial would be vacuous.
        observer.allow_file_probe_top_level = True
        probe = context.new_page()
        probe.goto(files + '/media/session/bridge/', wait_until='domcontentloaded')
        probe_fetch = '''async input => {
          const r=await fetch(input.url,{method:input.method,headers:input.headers,
            credentials:'include',cache:'no-store',redirect:'error'});
          const body=await r.text(); return {status:r.status,body};
        }'''
        positive = probe.evaluate(probe_fetch, {'url': manifest['private_file_uri'],
                                               'method': 'GET', 'headers': {}})
        require(positive.get('status') == 200 and hashlib.sha256(
                positive.get('body', '').encode()).hexdigest() ==
                manifest['expected_content_sha256'], 'browser_file_origin_probe_not_live')
        checks.append('real-file-origin-fetch-positive-before-logout-not-opaque-sandbox')
        logout = context.new_page()
        logout.goto(app + '/logout/', wait_until='domcontentloaded')
        logout.get_by_role('button', name=re.compile(r'^Log Out(?: Anyway)?$')).click()
        logout.wait_for_url(app + '/auth/loggedout/')
        original_media.assert_jar(context.cookies())
        observer.old_media = original_media
        observer.old_media_target = manifest['private_file_uri']
        checks.append('actual-native-CSRF-form-logout')
        # Use the page's real file-origin fetch; APIRequestContext is not a browser
        # cookie/SameSite observation and is intentionally not used here.
        for marker, method, headers in [('get', 'GET', {}), ('head', 'HEAD', {}),
                                ('range', 'GET', {'Range': 'bytes=0-3'}),
                                ('conditional', 'GET', {'If-None-Match': response.all_headers().get('etag', '"synthetic-owned-validator"')})]:
            headers = dict(headers, **{'X-Phorge-Browser-Probe': marker})
            result = probe.evaluate(probe_fetch, {'url': manifest['private_file_uri'],
                                                 'method': method, 'headers': headers})
            require(result.get('status') == 403 and
                    manifest['private_content_marker'] not in result.get('body', ''),
                    'browser_logout_private_bytes_or_cache_not_denied')
        observer.assert_complete()
        original_media.assert_jar(context.cookies())
        checks.append('same-old-media-cookie-values-present-and-sent-on-all-four-probes')
        checks.append('old-media-cookie-GET-HEAD-Range-conditional-denied-after-real-logout')
        require(tuple(checks) == EXPECTED_CHECKS, 'browser_fixed_obligation_set_changed')
        result = {'schema_version': 1, 'scope': 'two-host-same-site-native-media-browser',
                'passed': True, 'checks': checks, 'check_count': len(checks),
                'real_browser_flow_executed': True, 'SQL_cleanup_proved': False,
                'process_cleanup_proved': False, 'TLS_trust_and_binary_provenance_proved': False,
                'restoration_authorized': False, 'all_notification_producers_proved': False,
                'deployment': False}
        if approved_profile is not None:
            approved_origins.verified(approved_profile)
            result.update(schema_version=2, scope='two-host-same-site-native-media-browser-approved-origins-v2',
                          approved_origin_profile_bound=True, public_route_authorized=False)
        return result
    finally:
        context.remove_listener('request', observe)
