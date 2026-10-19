# Continuous integration

The workflow is `.github/workflows/ci.yml`. It runs `moodle-plugin-ci` (the Moodle
project's own CI tool) and one small JavaScript job.

## Private repository: keeping the minutes low

Actions minutes on a private repository are limited (2,000 a month on a free
plan; Linux runners count 1x). The workflow is built around that:

| Measure | Effect |
| --- | --- |
| Runs on pushes to `main` and on pull requests only | Pushing a feature branch costs nothing until a pull request is opened |
| Skips changes that only touch `*.md`, `docs/` or `dev/` | Documentation pushes are free |
| `concurrency` with `cancel-in-progress` | A newer push cancels the run it supersedes |
| Four hand-picked combinations, not the full cross product | See below |
| Behat in two of the four jobs | Behat is the slowest step |
| `timeout-minutes` | A stuck job cannot burn the monthly budget |

### Matrix

| Moodle | PHP | Database | Behat |
| --- | --- | --- | --- |
| `MOODLE_502_STABLE` | 8.3 | PostgreSQL | yes |
| `MOODLE_502_STABLE` | 8.4 | MariaDB | no |
| `MOODLE_503_STABLE` | 8.3 | MariaDB | no |
| `MOODLE_503_STABLE` | 8.4 | PostgreSQL | yes |

Each Moodle branch, PHP version and database appears twice. Moodle 5.2 and 5.3
both require PHP 8.3 or newer. The full cross product would be 8 jobs.

### Expected cost (an estimate, not yet measured)

A job installs Moodle and runs lint, code checks, PHPUnit and, in two jobs,
Behat. Plan on roughly 15 to 25 minutes without Behat and 30 to 45 with it, so a
full run is on the order of 100 minutes. At that rate a free plan covers about
15 to 20 full runs a month. **Measure the first runs and adjust this table**: if
the budget is tight, shrink the matrix to the 5.2 PostgreSQL job on every push and
run the rest only on pull requests or manually (`workflow_dispatch`).

## Steps and policy

| Step | Policy |
| --- | --- |
| PHP lint | must pass |
| PHP mess detector | **advisory** (`continue-on-error`), as in the official template |
| Code checker (`phpcs`) | `--max-warnings 0` |
| PHPDoc checker | `--max-warnings 0` |
| Validate, savepoints, mustache, grunt | must pass (grunt with `--max-lint-warnings 0`) |
| PHPUnit | `--fail-on-warning` |
| Behat | two jobs, Chrome profile |

The mess detector is advisory because it flags parameters that an interface forces
on us (the privacy provider, `get_template_name()`) and Moodle's `phpcs` rejects the
`@SuppressWarnings` tag that would silence it.

The compiled JavaScript in `amd/build` is committed. The `grunt` step fails if it
is out of date with `amd/src`, so rebuild it after changing the sources (see
below).

## Running the checks locally

Moodle core and `moodle-docker` live outside this repository (`dev/setup.sh`).
`moodle-plugin-ci` also bundles the Moodle code standard:

```sh
# Once: install moodle-plugin-ci next to Moodle.
docker run --rm -v "$PWD/..:/work" -w /work composer:2 \
  create-project -n --no-dev --prefer-dist --ignore-platform-reqs \
  moodlehq/moodle-plugin-ci ci ^4

# Code standard and PHPDoc (run from the plugin root; no output means clean).
docker run --rm -v "$PWD/../ci:/ci" -v "$PWD:/plugin" -w /plugin composer:2 \
  /ci/vendor/bin/phpcs --standard=moodle --extensions=php --ignore=amd/build,node_modules .
docker run --rm -v "$PWD/../ci:/ci" -v "$PWD:/plugin" -w /plugin composer:2 \
  /ci/vendor/bin/phpcs --standard=moodle-extra --extensions=php --ignore=amd/build,node_modules .

# JavaScript protocol tests (plain Node, no dependencies).
pnpm test

# Rebuild amd/build. Moodle needs Node 22, so use a container.
docker run --rm -v "<moodle>:/app" -v "$PWD:/app/public/course/format/rumbos" \
  -w /app/public/course/format/rumbos/amd node:22 npx grunt amd

# PHPUnit and Behat run inside the moodle-docker web container (dev/env.sh).
```

PHPUnit needs `php public/admin/tool/phpunit/cli/init.php` once, and again whenever
the plugin version or the container changes.
