#!/usr/bin/env bash
set -euo pipefail

# Build a properly structured WordPress plugin ZIP for release.
# Usage: ./build-zip.sh [version]
#   version: optional, e.g. "0.2.0". Defaults to reading from guardify-pro.php.

PLUGIN_SLUG="guardify-pro"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR=$(mktemp -d)
TARGET_DIR="$BUILD_DIR/$PLUGIN_SLUG"

# Read version from main plugin file if not supplied
if [[ -n "${1:-}" ]]; then
  VERSION="$1"
else
  VERSION=$(grep -oP "Version:\s*\K[0-9]+\.[0-9]+\.[0-9]+[^\s]*" "$SCRIPT_DIR/guardify-pro.php" | head -1)
fi

echo "Building $PLUGIN_SLUG v$VERSION ..."

# Copy plugin files (excluding dev/git files)
rsync -a \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='build-zip.sh' \
  --exclude='*.zip' \
  --exclude='.DS_Store' \
  --exclude='node_modules' \
  "$SCRIPT_DIR/" "$TARGET_DIR/"

# Create ZIP (from build dir so paths start with guardify-pro/)
OUTPUT="$SCRIPT_DIR/$PLUGIN_SLUG.zip"
cd "$BUILD_DIR"
rm -f "$OUTPUT"
zip -r "$OUTPUT" "$PLUGIN_SLUG/"

# Cleanup
rm -rf "$BUILD_DIR"

echo ""
echo "✓ Created $OUTPUT"
echo "  Verify: unzip -l $OUTPUT | head -10"
echo ""
echo "To release:"
echo "  gh release delete-asset v$VERSION $PLUGIN_SLUG.zip --yes 2>/dev/null || true"
echo "  gh release upload v$VERSION $OUTPUT --clobber"
