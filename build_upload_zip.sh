#!/bin/bash
# ============================================================
#  Creamy Bite – build the upload package
#
#  Produces ONE zip containing the whole website, ready to extract over
#  the live site. Always the complete site, never a subset.
#
#  Uploading only the files that changed sounds tidier, but it leaves the
#  server holding a mixture of old and new code, and that mixture is what
#  produced the wrong prices and the dead buttons. A full replacement
#  cannot half-apply.
#
#  Run from this folder:
#      ./build_upload_zip.sh
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"

OUT_DIR="$HOME/Desktop"
STAGE="$(mktemp -d)"
NAME="creamybite-site"
ZIP="$OUT_DIR/$NAME.zip"

trap 'rm -rf "$STAGE"' EXIT

echo "Packaging the site…"

# Everything except the things that must never reach a web server:
#   .git         – the entire source history, readable over HTTP if exposed
#   *.log        – may contain customer data
#   .DS_Store    – macOS clutter
rsync -a \
    --exclude '.git' \
    --exclude '.DS_Store' \
    --exclude '*.log' \
    --exclude 'node_modules' \
    --exclude 'build_upload_zip.sh' \
    ./ "$STAGE/$NAME/"

FILES=$(find "$STAGE/$NAME" -type f | wc -l | tr -d ' ')

# Fail loudly rather than shipping a package that silently misses something.
for required in \
    "$STAGE/$NAME/index.php" \
    "$STAGE/$NAME/includes/config.php" \
    "$STAGE/$NAME/includes/secrets.php" \
    "$STAGE/$NAME/.htaccess" \
    "$STAGE/$NAME/admin/index.php" \
    "$STAGE/$NAME/vendor/autoload.php"
do
    if [ ! -f "$required" ]; then
        echo "STOPPED: ${required#$STAGE/$NAME/} is missing — not building an incomplete package."
        exit 1
    fi
done

if [ -d "$STAGE/$NAME/.git" ]; then
    echo "STOPPED: .git ended up in the package."
    exit 1
fi

rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" "$NAME" )

echo
echo "  Done:  $ZIP"
echo "  $FILES files, $(du -h "$ZIP" | cut -f1)"
echo
echo "  Upload that one file to hPanel, extract it into public_html,"
echo "  then move everything out of the '$NAME' folder up one level."
