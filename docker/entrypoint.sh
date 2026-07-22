#!/bin/sh
set -e

# The whole /data tree must belong to www-data — php-fpm workers and the
# queue worker (M2) open the SQLite files, and WAL sidecars need write
# access even for reads. Never create DB files as root.
mkdir -p /data
chown -R www-data:www-data /data /app/var

# Apply pending migrations (engine + every site DB) before serving.
# Idempotent — safe on every container start.
su-exec www-data php /app/bin/fastsite migrate

exec supervisord -c /etc/supervisord.conf
