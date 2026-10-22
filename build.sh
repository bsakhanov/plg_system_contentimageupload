#!/usr/bin/env bash
# Builds the release archives into dist/:
#   plg_system_contentimageupload-<version>.zip           install package (git archive, export-ignore applies)
#   plg_system_contentimageupload-<version>-site-root.zip  files where Joomla keeps them; register with System → Install → Discover
set -euo pipefail
cd "$(dirname "$0")"

NAME=plg_system_contentimageupload
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' contentimageupload.xml | head -n 1)

rm -rf dist
mkdir -p dist

git archive --format=zip -o "dist/${NAME}-${VERSION}.zip" HEAD

ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT

mkdir -p "$ROOT/plugins/system/contentimageupload" "$ROOT/media/plg_system_contentimageupload"
cp -R contentimageupload.xml script.php services src language "$ROOT/plugins/system/contentimageupload/"
cp -R media/. "$ROOT/media/plg_system_contentimageupload/"

for dir in language/*/; do
    tag=$(basename "$dir")
    mkdir -p "$ROOT/administrator/language/$tag"
    cp "$dir"*.ini "$ROOT/administrator/language/$tag/"
done

(cd "$ROOT" && zip -qr - .) > "dist/${NAME}-${VERSION}-site-root.zip"

ls -l dist
