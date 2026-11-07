"""Versioned data-only approved origins. Never URL-selected execution authority.

The independently reviewed parent binds the Root record SHA in source. A native
four-field profile or its own digest/boolean can never supply that authority.
"""
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import stat
from urllib.parse import urlsplit

ROOT_APPROVED_ORIGIN_REVIEW_SHA256 = None
PSL_SHA256 = 'd0690c4291743adc4f60413942d95025b7de1d8fa0850a9f3496c53a6ef0f386'
_PROFILE_SEAL = object()
_RULES = None

class OriginUnproved(RuntimeError):
    pass

def need(value):
    if value is not True:
        raise OriginUnproved('browser_approved_origin_profile_unproved')

def digest(value):
    return type(value) is str and re.fullmatch('[a-f0-9]{64}', value) is not None

def decode(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            need(key not in result)
            result[key] = value
        return result
    try:
        return json.loads(raw, object_pairs_hook=pairs,
            parse_constant=lambda _: (_ for _ in ()).throw(OriginUnproved()))
    except Exception:
        raise OriginUnproved('browser_approved_origin_JSON_unproved') from None

def rules():
    global _RULES
    if _RULES is not None:
        return _RULES
    path = Path(__file__).absolute().with_name('origin_rules.json')
    parents = [(p, os.lstat(p)) for p in path.parents]
    need(all(stat.S_ISDIR(s.st_mode) for _, s in parents))
    def stamp(s):
        return (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid,
                s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_uid == os.getuid() and
             before.st_nlink == 1 and not before.st_mode & 0o022 and
             0 < before.st_size <= 262144)
        raw = b''
        while len(raw) < before.st_size:
            part = os.read(fd, min(65536, before.st_size - len(raw)))
            need(bool(part)); raw += part
        need(stamp(before) == stamp(os.fstat(fd)) == stamp(os.lstat(path)))
        for parent, old in parents:
            new = os.lstat(parent)
            need((old.st_dev, old.st_ino, old.st_uid, old.st_gid, old.st_mode) ==
                 (new.st_dev, new.st_ino, new.st_uid, new.st_gid, new.st_mode))
        need(hashlib.sha256(raw).hexdigest() == PSL_SHA256)
        data = decode(raw)
        need(type(data) is dict and data.get('schema') == 1 and
             type(data.get('schema')) is int and data.get('include_private') is True and
             type(data.get('rules')) is list and len(data['rules']) > 5000 and
             all(type(x) is str for x in data['rules']))
        _RULES = frozenset(data['rules'])
        return _RULES
    finally:
        os.close(fd)

def canonical_origin(value):
    need(type(value) is str and len(value) <= 1024 and
         re.fullmatch(r'https://[a-z0-9.-]+(?::[1-9][0-9]{0,4})?', value) is not None)
    uri = urlsplit(value)
    try:
        port = uri.port or 443
    except ValueError:
        raise OriginUnproved('browser_approved_origin_profile_unproved') from None
    host = uri.hostname
    need(type(host) is str and len(host) <= 253 and
         re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*', host) is not None)
    need(host != 'localhost' and not host.endswith('.localhost') and
         re.search(r'(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)$', host, re.I) is None)
    try:
        ipaddress.ip_address(host)
    except ValueError:
        pass
    else:
        raise OriginUnproved('browser_approved_origin_profile_unproved')
    need(1 <= port <= 65535 and value == 'https://' + host +
         ('' if port == 443 else ':' + str(port)))
    return value, host

def site_domain(host):
    # Exact native ICANN+PRIVATE PSL longest/wildcard/exception algorithm.
    labels = host.split('.'); count = len(labels); suffix = 1; current = rules()
    for i in range(count):
        candidate = '.'.join(labels[i:]); length = count - i
        if '!' + candidate in current:
            suffix = length - 1
            break
        if candidate in current:
            suffix = max(suffix, length)
        if i > 0 and '*.' + candidate in current:
            suffix = max(suffix, length + 1)
    need(count > suffix)
    return '.'.join(labels[-(suffix + 1):])

class ApprovedOrigins:
    __slots__ = ('_seal', '_raw', '_profile')
    def __init__(self, *args, **kwargs):
        raise OriginUnproved('browser_approved_origin_factory_required')
    def __setattr__(self, key, value):
        raise OriginUnproved('browser_approved_origin_immutable')
    def __repr__(self):
        return '<approved-media-browser-origin-profile-v2>'
    def origins(self):
        return verified(self)
    def native_profile(self):
        app, files = verified(self)
        return {'scope': 'owned-private-media-remote-native-fixture',
                'app_origin': app, 'file_origin': files,
                'origin_review_sha256': ROOT_APPROVED_ORIGIN_REVIEW_SHA256}

def approve(raw, native_profile):
    # Missing independent Root pin refuses before input decoding or PSL I/O.
    need(digest(ROOT_APPROVED_ORIGIN_REVIEW_SHA256))
    need(type(raw) is bytes and 0 < len(raw) <= 16384 and
         hashlib.sha256(raw).hexdigest() == ROOT_APPROVED_ORIGIN_REVIEW_SHA256)
    value = decode(raw)
    need(type(value) is dict and set(value) == {'scope', 'passed', 'app_origin',
         'file_origin', 'public_route_authorized'} and
         value['scope'] == 'owned-private-native-media-origin-fixture' and
         value['passed'] is True and value['public_route_authorized'] is False)
    app, app_host = canonical_origin(value['app_origin'])
    files, file_host = canonical_origin(value['file_origin'])
    need(app_host != file_host and site_domain(app_host) == site_domain(file_host))
    expected = {'scope': 'owned-private-media-remote-native-fixture',
                'app_origin': app, 'file_origin': files,
                'origin_review_sha256': ROOT_APPROVED_ORIGIN_REVIEW_SHA256}
    need(type(native_profile) is dict and native_profile == expected and
         set(native_profile) == set(expected))
    profile = object.__new__(ApprovedOrigins)
    object.__setattr__(profile, '_seal', _PROFILE_SEAL)
    object.__setattr__(profile, '_raw', raw)
    object.__setattr__(profile, '_profile', (app, files))
    return profile

def verified(profile):
    need(type(profile) is ApprovedOrigins and
         getattr(profile, '_seal', None) is _PROFILE_SEAL and
         digest(ROOT_APPROVED_ORIGIN_REVIEW_SHA256))
    need(type(profile._raw) is bytes and
         hashlib.sha256(profile._raw).hexdigest() == ROOT_APPROVED_ORIGIN_REVIEW_SHA256)
    value = decode(profile._raw)
    need(profile._profile == (value['app_origin'], value['file_origin']))
    return profile._profile
