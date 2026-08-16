#!/bin/bash

# Peanut Suite — LOCAL packaging only.
#
# Builds a plugin ZIP for local testing. This artifact is NOT releasable: it
# carries no Ed25519 signature and no .manifest.json sidecar, and Suite's
# signed-update gate refuses exactly that. Handing this zip to a site as an
# update will fail closed, by design.
#
# To release, use the central publisher, which signs the artifact, ships the
# manifest, bumps and verifies the version constants, updates the license-server
# option and canaries on peanutgraphic.com:
#   Peanut-meta/scripts/publish-plugin.sh peanut-suite <version> [--ship]
#
# PAR-404.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_NAME="peanut-suite"
VERSION=$(grep -o '"version": *"[^"]*"' "$ROOT_DIR/package.json" | sed 's/"version": *"\([^"]*\)"/\1/')

echo ""
echo "📦 Packaging $PLUGIN_NAME v$VERSION..."
echo ""

# Fatal-references sweep — standard pre-ship gate (see Peanut Graphic creed §4).
# Refuses to build if any require/include path is missing on disk.
SWEEP="/Users/nattyb/Documents/Peanut/scripts/fatal-references-sweep.py"
if [[ -f "$SWEEP" ]]; then
    echo "▸ Running fatal-references sweep…"
    if ! /usr/bin/python3 "$SWEEP" "$ROOT_DIR"; then
        echo ""
        echo "❌ package.sh refuses to build: missing require/include targets."
        echo "   Fix the references above, or remove the dead require_once lines."
        exit 1
    fi
fi


# Create dist directory
DIST_DIR="$ROOT_DIR/dist"
BUILD_DIR="$DIST_DIR/$PLUGIN_NAME"
mkdir -p "$BUILD_DIR"

# Clean previous build
rm -rf "$BUILD_DIR"/*

echo "📁 Copying files..."

# Copy main plugin files
cp "$ROOT_DIR/peanut-suite.php" "$BUILD_DIR/"
cp "$ROOT_DIR/uninstall.php" "$BUILD_DIR/"

# Copy directories
cp -r "$ROOT_DIR/core" "$BUILD_DIR/"
cp -r "$ROOT_DIR/modules" "$BUILD_DIR/"

# Copy built assets
if [ -d "$ROOT_DIR/assets" ]; then
    cp -r "$ROOT_DIR/assets" "$BUILD_DIR/"
fi

# Copy languages if exists
if [ -d "$ROOT_DIR/languages" ]; then
    cp -r "$ROOT_DIR/languages" "$BUILD_DIR/"
fi

# Remove any development files that might have been copied
find "$BUILD_DIR" -name ".DS_Store" -delete 2>/dev/null || true
find "$BUILD_DIR" -name "*.map" -delete 2>/dev/null || true
find "$BUILD_DIR" -name ".gitkeep" -delete 2>/dev/null || true

# Create ZIP file
cd "$DIST_DIR"
ZIP_FILE="$PLUGIN_NAME-$VERSION.zip"
rm -f "$ZIP_FILE"
zip -r "$ZIP_FILE" "$PLUGIN_NAME" -x "*.DS_Store" -x "*/.git/*"

# Get file size
SIZE=$(du -h "$ZIP_FILE" | cut -f1)

echo ""
echo "✅ Package created successfully!"
echo "   📁 $DIST_DIR/$ZIP_FILE"
echo "   📊 Size: $SIZE"
echo ""

# Clean up build directory
rm -rf "$BUILD_DIR"
