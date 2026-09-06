<?php

/**
 * Preamble for running behind an SSL-terminating reverse proxy (e.g. Traefik).
 *
 * When the proxy sends X-Forwarded-Proto: https, this sets $_SERVER['HTTPS']
 * so Phorge correctly detects HTTPS and stops showing the HTTP/HTTPS mismatch
 * warning. Mount this file as support/preamble.php when using Traefik/nginx
 * in front of the app (e.g. in docker-compose).
 *
 * @see src/docs/user/configuration/configuring_preamble.diviner
 */

if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
  $_SERVER['HTTPS'] = 'on';
  $_SERVER['SERVER_PORT'] = 443;
}
