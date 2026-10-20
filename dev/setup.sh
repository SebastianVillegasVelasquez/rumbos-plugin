#!/usr/bin/env bash
# One-shot, idempotent bootstrap of the local Moodle 5.x test site.
#   dev/setup.sh            # pgsql (default)
#   MOODLE_DOCKER_DB=mariadb dev/setup.sh
set -euo pipefail
# Git Bash on Windows rewrites paths such as /dst in `docker run` arguments, but
# moodle-docker-compose needs that conversion, so it is switched off per command.
docker_run() { MSYS_NO_PATHCONV=1 docker run "$@"; }
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

mkdir -p "$RUMBOS_DEV_ROOT" "$MOODLE_DOCKER_WWWROOT"
if [ ! -d "$RUMBOS_DEV_ROOT/moodle-docker/.git" ]; then
  git clone --depth 1 https://github.com/moodlehq/moodle-docker.git "$RUMBOS_DEV_ROOT/moodle-docker"
fi

# Moodle's code and its Composer dependencies live in a named volume.
docker volume create "$RUMBOS_MOODLE_VOLUME" >/dev/null
if ! docker_run --rm -v "$RUMBOS_MOODLE_VOLUME:/app" alpine test -d /app/.git; then
  docker_run --rm -v "$RUMBOS_MOODLE_VOLUME:/dst" alpine/git clone --quiet --depth 1 \
    --branch "$RUMBOS_MOODLE_BRANCH" https://github.com/moodle/moodle.git /dst
  docker_run --rm -v "$RUMBOS_MOODLE_VOLUME:/app" -w /app composer:2 install \
    --no-interaction --prefer-dist --ignore-platform-reqs
fi

# Developer debugging is set in the database instead of config.php. A debug value
# in config.php makes Moodle skip its component cache and rescan every plugin
# directory on every request.
cat "$RUMBOS_DEV_ROOT/moodle-docker/config.docker-template.php" \
  | docker_run -i --rm -v "$RUMBOS_MOODLE_VOLUME:/dst" alpine \
    sh -c "sed '/^\\\$CFG->debug = /d; /^\\\$CFG->debugdisplay = /d' > /dst/config.php"

cp "$RUMBOS_REPO/dev/docker/local.yml" "$RUMBOS_DEV_ROOT/moodle-docker/local.yml"

mdc up -d
"$RUMBOS_DEV_ROOT/moodle-docker/bin/moodle-docker-wait-for-db"

mdc exec -u www-data webserver php admin/cli/install_database.php --agree-license \
  --fullname="Rumbos dev" --shortname="rumbos" \
  --adminpass="Admin123!" --adminemail="admin@example.com"
mdc exec -u www-data webserver php admin/cli/cfg.php --name=debug --set=32767
mdc exec -u www-data webserver php admin/cli/cfg.php --name=debugdisplay --set=1
echo "Moodle ready at http://localhost:${MOODLE_DOCKER_WEB_PORT} (admin / Admin123!)"
