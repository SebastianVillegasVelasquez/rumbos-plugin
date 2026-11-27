# Releasing

A release is a Git tag. Pushing a tag that starts with `v` runs
`.github/workflows/release.yml`, which builds the installable ZIP and publishes it, so
nobody has to build it by hand.

## Steps

1. Make sure `main` has everything you want to ship and the CI is green.
2. In `version.php`, set `$plugin->release` to the new release (for example `0.2.0`) and
   raise `$plugin->version` (the build number, `YYYYMMDDXX`) above the previous one.
   Moodle runs its upgrade only when `version` goes up; `release` is the label users see.
   Commit it, for example `chore: release 0.2.0`, and push to `main`.
3. Create the tag **`v` plus the release** on that commit and push it:

   ```sh
   git tag v0.2.0
   git push origin v0.2.0
   ```

4. Wait for the **Release** workflow. It:
   - fails if the tag is not `v` plus the release in `version.php`, so a tag cannot publish
     a ZIP whose name or content disagrees with it;
   - builds `dist/format_rumbos-<release>.zip` with `scripts/build-zip.sh` from the tagged
     revision (a single top-level `rumbos` folder, committed files only, nothing marked
     `export-ignore` in `.gitattributes`);
   - checks that the archive has exactly that one folder, with `version.php` and the
     compiled JavaScript in it;
   - keeps the ZIP as a workflow artifact (`format_rumbos-<release>`);
   - attaches it to a GitHub release for the tag, created as a pre-release because the
     plugin's maturity is alpha, with generated notes. Edit the notes on GitHub, and
     remove the pre-release flag when the maturity changes.
5. Install it from **Site administration > Plugins > Install plugins**, or download it
   from the release page.

## Releasing what is unreleased now

State on 2026-11-27. "Unreleased" in the changelog includes host contract v5.11, which is the version of the messages
between the page and the app, not a release number of the plugin.

To decide or fix first:

1. **CI is red on `main`** and was for at least the last four merges. Its PHPUnit failures were two tests that tampered
   with a signature in a way that sometimes changed nothing; that is fixed. The other failing steps are not:
   - Moodle Code Checker (run with `--max-warnings 0`): `COMPLETION_CRITERIA_TYPES` in `classes/local/result_course.php`;
     the `MOODLE_INTERNAL` check in `result_course_test.php`, `result_receiver_test.php` and
     `result_statement_test.php`; a long line in `tests/behat/behat_format_rumbos.php`; and warnings about the order of
     language strings.
   - Grunt: stylelint refuses an `!important` in `styles.css`.

   The steps after a failed one run too, so read the whole run, not only these.
2. **Result passback with the real service** now accepts the service's signed statements, but it was verified only
   with the golden vectors and locally, not with the deployed service. Before relying on it, follow
   [the production steps](RESULT-PASSBACK.md#production-configure-the-key-and-verify-with-a-real-statement) on staging,
   and decide about [exam definitions](RESULT-PASSBACK.md#exam-definitions), which the plugin does not store.
3. **Run the manual tests** of v5.11 in [MANUAL-TESTS.md](MANUAL-TESTS.md) on staging, with the app. What was not run is
   marked there.
4. **Legal review** of auto-completion and of the `uid` claim. Both settings are off by default.
5. **The native quiz mirror branch is not merged.** Its version (`2026112300`, `0.8.0`) is lower than this one, so its
   upgrade steps need new numbers if it is merged after this release.

Then:

1. Rename the changelog's `## Unreleased` to the release, `0.9.0` unless another number is chosen, and set
   `$plugin->release` to match.
2. Raise `$plugin->version` above `2026112401`. This change adds no database step, but it adds language strings and an
   event, and a site already on `2026112401` runs the upgrade, which refreshes them, only if the number goes up.
3. Decide `$plugin->maturity` (now `MATURITY_ALPHA`; the workflow publishes a pre-release).
4. Follow [Steps](#steps).

## Building the ZIP by hand

For a local check, from a clean working tree:

```sh
scripts/build-zip.sh            # builds HEAD
scripts/build-zip.sh v0.2.0     # builds a tag or any revision
```

The ZIP is written to `dist/`, which is ignored by Git: it is never committed.

## Things to remember

- The compiled JavaScript in `amd/build` is committed and checked by the CI, so the tag
  always contains it. The ZIP is built from Git, not from a working tree.
- `package.json` has no `version` field. The build reads the release from `version.php`,
  which is the only place the version lives.
- A tag that was pushed by mistake can be deleted (`git push origin :refs/tags/v0.2.0`)
  and its release removed on GitHub before a corrected tag is pushed.
