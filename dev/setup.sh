#!/usr/bin/env bash
# One-shot, idempotent bootstrap of the local Moodle 5.x test site.
#   dev/setup.sh            # pgsql (default)
#   MOODLE_DOCKER_DB=mariadb dev/setup.sh
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

mkdir -p "$RUMBOS_DEV_ROOT"
if [ ! -d "$RUMBOS_DEV_ROOT/moodle/.git" ]; then
  git clone --depth 1 --branch "$RUMBOS_MOODLE_BRANCH" https://github.com/moodle/moodle.git "$RUMBOS_DEV_ROOT/moodle"
fi
if [ ! -d "$RUMBOS_DEV_ROOT/moodle-docker/.git" ]; then
  git clone --depth 1 https://github.com/moodlehq/moodle-docker.git "$RUMBOS_DEV_ROOT/moodle-docker"
fi

cp "$RUMBOS_REPO/dev/docker/local.yml" "$RUMBOS_DEV_ROOT/moodle-docker/local.yml"
cp "$RUMBOS_DEV_ROOT/moodle-docker/config.docker-template.php" "$MOODLE_DOCKER_WWWROOT/config.php"

mdc up -d
"$RUMBOS_DEV_ROOT/moodle-docker/bin/moodle-docker-wait-for-db"

mdc exec webserver php admin/cli/install_database.php --agree-license \
  --fullname="Rumbos dev" --shortname="rumbos" \
  --adminpass="Admin123!" --adminemail="admin@example.com"
echo "Moodle ready at http://localhost:${MOODLE_DOCKER_WEB_PORT} (admin / Admin123!)"
