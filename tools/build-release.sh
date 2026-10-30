#!/bin/sh
# Builds the release archives of the plugin: tools/build-release.sh <version>
# Output: dist/glpi-azuredevops-<version>.tar.bz2 and .zip, both containing a "azuredevops/" folder
# (the folder name GLPI requires), built from the committed files only (git archive, .gitattributes applies).
set -e
VERSION="${1:?usage: tools/build-release.sh <version>}"
NAME=azuredevops
cd "$(dirname "$0")/.."
rm -rf build dist
mkdir -p build dist
git archive --format=tar --prefix="$NAME/" HEAD | tar -x -C build
tar cjf "dist/glpi-$NAME-$VERSION.tar.bz2" -C build "$NAME"
(cd build && zip -qr "../dist/glpi-$NAME-$VERSION.zip" "$NAME")
rm -rf build
ls -l dist
