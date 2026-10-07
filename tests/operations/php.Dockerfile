# Minimal runtime for the actual MySQL account audit in docker_roundtrip.py.
FROM php:8.3-cli
RUN docker-php-ext-install mysqli
