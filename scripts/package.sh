#!/bin/bash

# Peanut Suite - WordPress Plugin Packaging Script
# Creates a distributable ZIP file of the plugin

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_NAME="peanut-suite"
VERSION=$(grep -o '"version": *"[^"]*"' "$ROOT_DIR/package.json" | sed 's/"version": *"\([^"]*\)"/\1/')

echo ""
echo "📦 Packaging $PLUGIN_NAME v$VERSION..."
echo ""

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
