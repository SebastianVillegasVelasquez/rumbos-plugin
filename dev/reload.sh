#!/usr/bin/env bash
# Makes the dev web container pick up PHP changes (see dev/docker/opcache.ini)
# and purges Moodle caches. Run it after editing PHP, config.php or db/ files.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"
# Signal the main Apache process (it keeps the right environment, unlike apachectl).
mdc exec -T webserver bash -c 'kill -USR1 "$(cat /var/run/apache2/apache2.pid)"'
mdc exec -T webserver php admin/cli/purge_caches.php
