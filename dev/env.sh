#!/usr/bin/env bash
# Source this file to get the Moodle development environment variables and the
# `mdc` helper (a wrapper around moodle-docker-compose).
#
#   source dev/env.sh
#   mdc up -d
#
# Moodle's code lives in a Docker named volume (rumbos_moodle), not in a bind
# mount: on Windows every file access through a bind mount costs tens of
# milliseconds, which made requests take 20-30 s and Behat time out. The
# plugin itself is bind-mounted from this repository, so edits show up at once.
# moodle-docker lives outside the repository (default: ../moodle-dev).

RUMBOS_REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export RUMBOS_REPO
export RUMBOS_DEV_ROOT="${RUMBOS_DEV_ROOT:-$(cd "$RUMBOS_REPO/.." && pwd)/moodle-dev}"

# Moodle branch under test (see docs/AUDIT.md).
export RUMBOS_MOODLE_BRANCH="${RUMBOS_MOODLE_BRANCH:-MOODLE_502_STABLE}"
export RUMBOS_MOODLE_VOLUME="${RUMBOS_MOODLE_VOLUME:-rumbos_moodle}"

# moodle-docker insists on an existing directory here. The real code comes from
# the volume (see dev/docker/local.yml), so this one stays empty.
export MOODLE_DOCKER_WWWROOT="$RUMBOS_DEV_ROOT/wwwroot-placeholder"
export MOODLE_DOCKER_DB="${MOODLE_DOCKER_DB:-pgsql}"
export MOODLE_DOCKER_PHP_VERSION="${MOODLE_DOCKER_PHP_VERSION:-8.3}"
export MOODLE_DOCKER_WEB_PORT="${MOODLE_DOCKER_WEB_PORT:-8085}"
# The same browser the CI uses for Behat.
export MOODLE_DOCKER_BROWSER="${MOODLE_DOCKER_BROWSER:-chrome}"

# Consumed by dev/docker/local.yml to mount the plugin inside Moodle's public/ tree.
export RUMBOS_PLUGIN_DIR="$RUMBOS_REPO"

mdc() {
  "$RUMBOS_DEV_ROOT/moodle-docker/bin/moodle-docker-compose" "$@"
}
