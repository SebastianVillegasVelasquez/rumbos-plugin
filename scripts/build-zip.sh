#!/usr/bin/env bash
# Builds the installable plugin ZIP: dist/format_rumbos-<release>.zip.
#
# The archive has a single top-level folder named "rumbos" (the plugin directory
# name Moodle expects for course/format/rumbos) and contains only committed files
# that are not marked export-ignore in .gitattributes. It is built from a Git
# revision, so uncommitted changes are not included.
#
#   scripts/build-zip.sh [revision]     # default: HEAD
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

revision="${1:-HEAD}"
release="$(git show "$revision:version.php" | sed -n "s/^\$plugin->release = '\(.*\)';.*/\1/p")"
if [ -z "$release" ]; then
  echo "Could not read the release from version.php at $revision" >&2
  exit 1
fi

mkdir -p dist
out="dist/format_rumbos-${release}.zip"
rm -f "$out"
git archive --format=zip --prefix=rumbos/ --output="$out" "$revision"

echo "Built $out"
