#!/usr/bin/env bash
# Purges Moodle's caches in the dev container. Run it after changing language
# strings, templates, AMD modules or db/ definitions that Moodle caches.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"
mdc exec -T -u www-data webserver php admin/cli/purge_caches.php
