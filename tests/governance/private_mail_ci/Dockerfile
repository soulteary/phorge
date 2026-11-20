# Test-only derivative. No application source, secrets or runtime config copied.
# Supply a verified immutable PHP 8.5.13 test image; there is no old-version fallback.
ARG PHP_TEST_IMAGE
FROM ${PHP_TEST_IMAGE}
ARG PHP_TEST_IMAGE
ARG DEBIAN_SNAPSHOT=20261120T000000Z
LABEL org.phorge.test.scope="private-mail-portable-ci" \
      org.phorge.test.base-image-id="${PHP_TEST_IMAGE}"
USER root
RUN set -eux; \
    php -r 'if (PHP_VERSION !== "8.5.13") { exit(1); }'; \
    . /etc/os-release; test "$ID" = debian; test "$VERSION_ID" = 13; \
    command -v git; \
    grep -E "^URIs:[[:space:]]+https://snapshot[.]debian[.]org/archive/debian/${DEBIAN_SNAPSHOT}(/|[[:space:]]|$)" /etc/apt/sources.list.d/debian.sources; \
    apt-get update; \
    apt-get install -y --no-install-recommends python3; \
    python3 -c 'import sys,os,signal,ctypes,email,http.client,unittest; assert sys.version_info >= (3,9); assert hasattr(os,"pidfd_open") and hasattr(os,"P_PIDFD") and hasattr(signal,"pidfd_send_signal")'; \
    mkdir -p /usr/local/share/phorge-test-image; \
    dpkg-query -W -f='${binary:Package}=${Version}\n' 'python3*' 'libpython3*' > /usr/local/share/phorge-test-image/python-packages.txt; \
    php -r 'if (PHP_VERSION !== "8.5.13") { exit(1); }'; \
    rm -rf /var/lib/apt/lists/*
# Never run the inherited production entrypoint or Apache by default.
ENTRYPOINT []
WORKDIR /tmp
CMD ["/usr/bin/python3", "--version"]
