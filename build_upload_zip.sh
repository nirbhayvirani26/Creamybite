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
# .env is the reason this list matters most.
#
# Secrets used to ship inside includes/secrets.php, so every upload replaced
# the server's keys with the developer's. A Stripe key rolled on live was
# silently reverted by the next deploy, and card payments broke every time
# with nothing in the code to blame. Shipping no secrets at all is the fix:
# each machine keeps its own .env and nothing that travels can touch it.
rsync -a \
    --exclude '.git' \
    --exclude '.claude' \
    --exclude '.env' \
    --exclude '.env.local' \
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

# .claude holds agent worktrees — complete duplicate copies of the site,
# frozen at whatever the code looked like when that worktree was made. Shipped
# to the server they are dead weight at best, and at worst a second, older set
# of admin pages and handlers sitting at a guessable path, missing every
# security fix made since. Never ship them.
if [ -d "$STAGE/$NAME/.claude" ]; then
    echo "STOPPED: .claude ended up in the package."
    exit 1
fi

# A package carrying a .env would overwrite the server's keys — the exact
# failure this whole arrangement exists to prevent. Fail loudly.
if [ -f "$STAGE/$NAME/.env" ]; then
    echo "STOPPED: .env ended up in the package. It must never ship."
    exit 1
fi

if grep -rqlE 'sk_live_[A-Za-z0-9]{20,}' "$STAGE/$NAME" 2>/dev/null; then
    echo "STOPPED: a live Stripe secret key is inside the package."
    grep -rlE 'sk_live_[A-Za-z0-9]{20,}' "$STAGE/$NAME" 2>/dev/null | sed "s|$STAGE/$NAME/|  |"
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
