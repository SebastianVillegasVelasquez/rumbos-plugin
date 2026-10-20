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
