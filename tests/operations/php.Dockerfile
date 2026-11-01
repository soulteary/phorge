# Minimal runtime for the actual MySQL account audit in docker_roundtrip.py.
FROM php:8.5.12-cli
RUN docker-php-ext-install mysqli
RUN printf "opcache.jit=disable\nopcache.jit_buffer_size=0\n" > /usr/local/etc/php/conf.d/phorge-jit.ini
