#!/bin/sh
set -e

if [ -d /var/www/html/var ]; then
  chown -R www-data:www-data /var/www/html/var
  chmod -R ug+rwX /var/www/html/var
fi

exec "$@"
