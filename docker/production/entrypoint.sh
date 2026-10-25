#!/usr/bin/env bash
set -euo pipefail

# No `config:cache`: config/scramble.php's security_strategy holds an actual
# SecurityScheme object, which var_export() (what config:cache uses) can't
# serialize — `php artisan config:cache` hard-fails on this app as-is. Laravel
# runs correctly without it, just resolves config live each request; fine at
# this scale. (Flagged separately — not fixed here.)

# The Cloud Run *service* (web) runs this image with no command, so it falls
# through to supervisord (nginx + php-fpm). The Cloud Run *jobs* (queue
# worker pass, failed-analysis retry, one-off artisan commands) run the same
# image with an explicit command/args override — respect that instead of
# always booting the web stack.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
