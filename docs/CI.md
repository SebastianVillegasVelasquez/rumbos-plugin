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

`dev/setup.sh` builds a moodle-docker site whose Moodle code lives in the Docker
volume `rumbos_moodle` (see `dev/env.sh` for why a volume and not a bind mount).
Run the commands below from the repository root in Git Bash; `MSYS_NO_PATHCONV=1`
stops Git Bash from rewriting container paths.

Everything the CI checks can be run locally with the same flags. Run the
**strict** forms: an earlier version of this page ran grunt without
`--max-lint-warnings 0` and missed a lint warning that then failed the CI.

```sh
export MSYS_NO_PATHCONV=1

# Code standard and PHPDoc. moodle-plugin-ci bundles them; no output means clean.
docker run --rm -v "$PWD/..:/work" -w /work composer:2 \
  create-project -n --no-dev --prefer-dist --ignore-platform-reqs \
  moodlehq/moodle-plugin-ci ci ^4                       # once
docker run --rm -v "$PWD/../ci:/ci" -v "$PWD:/plugin" -w /plugin composer:2 \
  /ci/vendor/bin/phpcs --standard=moodle --extensions=php --ignore=amd/build,node_modules .
docker run --rm -v "$PWD/../ci:/ci" -v "$PWD:/plugin" -w /plugin composer:2 \
  /ci/vendor/bin/phpcs --standard=moodle-extra --extensions=php --ignore=amd/build,node_modules .

# JavaScript protocol tests (plain Node, no dependencies).
pnpm test

# Grunt: ESLint and the AMD build, stylelint and gherkin-lint. Moodle needs
# Node 22, so use a container. Install the dependencies once:
docker run --rm -v rumbos_moodle:/app -w /app node:22 npm ci
GRUNT="docker run --rm -v rumbos_moodle:/app -v $PWD:/app/public/course/format/rumbos"
$GRUNT -w /app/public/course/format/rumbos/amd node:22 npx grunt amd --max-lint-warnings=0
$GRUNT -w /app/public/course/format/rumbos node:22 npx grunt stylelint:css gherkinlint --max-lint-warnings=0
git status --short amd/build                           # must be empty: the build is committed

# PHPUnit and Behat run in the web container. moodle-docker-compose needs Git
# Bash to convert paths, so switch the variable off again for these commands.
unset MSYS_NO_PATHCONV
source dev/env.sh
mdc exec -T webserver php public/admin/tool/phpunit/cli/init.php        # once
mdc exec -T webserver vendor/bin/phpunit --testsuite format_rumbos_testsuite
mdc exec -T webserver php public/admin/tool/behat/cli/init.php          # once
# The CI runs Behat in a stricter mode that fails on deprecated Bootstrap 4 classes.
# Without this step the scenarios can pass locally and still fail in the CI.
mdc exec -T webserver php public/admin/tool/behat/cli/util_single_run.php \
  --enable --add-core-features-to-theme --scss-deprecations
mdc exec -T webserver vendor/bin/behat \
  --config /var/www/behatdata/behatrun/behat/behat.yml --tags @format_rumbos
```

On this setup the 75 PHPUnit tests take about 30 seconds and the Behat scenarios
between 70 seconds and 2.5 minutes (the strict mode adds the core theme features). PHPUnit needs its `init.php` again whenever the plugin
version or the container changes.

The Behat scenarios that open activities play the part of the Rumbos app (the test site
has none): the steps in `tests/behat/behat_format_rumbos.php` put a stand-in window where
the iframe's window is and record what the host sends. The host gives up waiting for the
app after 8 seconds, longer than a Behat step sequence takes, so those scenarios raise it
with the hidden config value `timeoutms`. The Behat site is plain HTTP on `webserver`, so
Chrome sends no `Sec-Fetch-*` headers there: the scenarios cover the host and popup
behaviour, not the hidden Moodle chrome (see [MANUAL-TESTS.md](MANUAL-TESTS.md)).

## Releases

Pushing a `v*` tag runs `.github/workflows/release.yml`, a separate workflow that does not
run on branches or pull requests, so it costs no minutes until you release. It builds the
ZIP and attaches it to a release; see [RELEASE.md](RELEASE.md).

The CI also reruns failed Behat scenarios twice, so the summary at the end of the
log can describe only the rerun, not the first pass.
