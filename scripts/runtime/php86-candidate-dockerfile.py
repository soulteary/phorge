"""Render the production recipe with an explicit, non-production PHP profile."""
import argparse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PROFILE = 'php86-isolated-v1'

def render(source):
    baseline = 'ARG PHP_BASE_IMAGE=php:8.5.13-apache\nFROM ${PHP_BASE_IMAGE}\n'
    if source.count(baseline) != 1:
        raise ValueError('production Dockerfile baseline drift')
    candidate = ('ARG PHP_BASE_IMAGE=php:8.6.0-apache\nFROM ${PHP_BASE_IMAGE}\n'
                 'ENV PHORGE_PHP_COMPATIBILITY_PROFILE=php86-isolated-v1\n'
                 'ENV PHORGE_GOVERNANCE_ISOLATED_TEST=1\n')
    pecl = '    && pecl install /tmp/apcu.tgz '+chr(92)+'\n'
    native = ('    && mkdir /tmp/apcu-build '+chr(92)+'\n'
              '    && tar -xzf /tmp/apcu.tgz -C /tmp/apcu-build --strip-components=1 '+chr(92)+'\n'
              '    && cd /tmp/apcu-build && phpize && ./configure --enable-apcu && make -j"$(nproc)" && make install '+chr(92)+'\n'
              '    && cd / && rm -rf /tmp/apcu-build '+chr(92)+'\n')
    replacements = {
      baseline: candidate,
      pecl: native,
      'ARG APCU_SHA256=ca9c1820810a168786f8048a4c3f8c9e3fd941407ad1553259fb2e30b5f057bf':
        'ARG APCU_SHA256=33f9038e98d236d4833b3e311c21e1d969a96d06b0d1e4427c8a0db2dd7123e2',
      'https://pecl.php.net/get/apcu-${APCU_VERSION}.tgz':
        'https://codeload.github.com/krakjoe/apcu/tar.gz/90899684be91c4f750512c2f840f487286ecb807',
    }
    for old,new in replacements.items():
        if source.count(old) != 1:
            raise ValueError('production APCu recipe drift')
        source=source.replace(old,new,1)
    return source

def main():
    parser = argparse.ArgumentParser(allow_abbrev=False)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    path = args.output
    if not path.is_absolute() or path.resolve().is_relative_to(ROOT):
        parser.error('fresh output outside source required')
    payload = render((ROOT/'Dockerfile').read_text())
    with path.open('x') as stream:
        stream.write(payload)
    path.chmod(0o600)

if __name__ == '__main__':
    main()
