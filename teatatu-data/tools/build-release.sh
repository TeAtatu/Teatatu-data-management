#!/bin/sh
# Builds the zip to attach to a GitHub Release, so installs update cleanly.
#
# Usage (from the repository root or anywhere inside it):
#   sh teatatu-data/tools/build-release.sh
#
# Checks that the version in the plugin header, TEATATU_DATA_VERSION and
# readme.txt's "Stable tag" all match, and that the plugin folder has no
# uncommitted changes; then writes teatatu-data-<version>.zip (a single
# teatatu-data/ folder, from the committed code) to the repository root.
#
# Then on GitHub: Releases > Draft a new release > tag v<version> > attach
# the zip > Publish. Sites see the update within 12 hours (or straight away
# with "Check for Updates Now" in the plugin settings).

set -e

root=$(git rev-parse --show-toplevel)
cd "$root"
plugin="teatatu-data"

header=$(sed -n 's/^ \* Version: *//p' "$plugin/teatatu-data.php" | head -1 | tr -d '\r ')
constant=$(sed -n "s/^define( 'TEATATU_DATA_VERSION', '\([^']*\)' );.*/\1/p" "$plugin/teatatu-data.php" | head -1)
stable=$(sed -n 's/^Stable tag: *//p' "$plugin/readme.txt" | head -1 | tr -d '\r ')

if [ -z "$header" ] || [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
	echo "Version mismatch: header '$header', TEATATU_DATA_VERSION '$constant', readme Stable tag '$stable'." >&2
	echo "Set all three to the new version and commit first." >&2
	exit 1
fi

if [ -n "$(git status --porcelain -- "$plugin")" ]; then
	echo "The $plugin/ folder has uncommitted changes. Commit them first (the zip is built from the last commit)." >&2
	exit 1
fi

out="$root/teatatu-data-$header.zip"
rm -f "$out"
git archive --format=zip --prefix="teatatu-data/" -o "$out" "HEAD:$plugin"

echo "Built $out"
echo "Next: create GitHub release tag v$header on this commit ($(git rev-parse --short HEAD)), attach the zip, publish."
