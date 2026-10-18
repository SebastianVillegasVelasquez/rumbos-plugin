#!/usr/bin/env bash
# Source this file to get the Moodle development environment variables and the
# `mdc` helper (a wrapper around moodle-docker-compose).
#
#   source dev/env.sh
#   mdc up -d
#
# Moodle core and moodle-docker live OUTSIDE this repository (default: a
# sibling directory called moodle-dev) so the plugin repo stays clean.

RUMBOS_REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export RUMBOS_REPO
export RUMBOS_DEV_ROOT="${RUMBOS_DEV_ROOT:-$(cd "$RUMBOS_REPO/.." && pwd)/moodle-dev}"

# Moodle branch under test (see docs/AUDIT.md).
export RUMBOS_MOODLE_BRANCH="${RUMBOS_MOODLE_BRANCH:-MOODLE_502_STABLE}"

export MOODLE_DOCKER_WWWROOT="$RUMBOS_DEV_ROOT/moodle"
export MOODLE_DOCKER_DB="${MOODLE_DOCKER_DB:-pgsql}"
export MOODLE_DOCKER_PHP_VERSION="${MOODLE_DOCKER_PHP_VERSION:-8.3}"
export MOODLE_DOCKER_WEB_PORT="${MOODLE_DOCKER_WEB_PORT:-8085}"

# Consumed by dev/docker/local.yml to mount the plugin inside Moodle's public/ tree.
export RUMBOS_PLUGIN_DIR="$RUMBOS_REPO"

mdc() {
  "$RUMBOS_DEV_ROOT/moodle-docker/bin/moodle-docker-compose" "$@"
}
