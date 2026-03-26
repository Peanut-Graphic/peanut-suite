#!/bin/bash

# Build Peanut Suite
# Double-click this file to build the plugin

cd "$(dirname "$0")"

# Get version from main plugin file
VERSION=$(grep -m1 "Version:" peanut-suite.php | sed 's/.*Version: //' | tr -d ' \r')

echo "================================"
echo "  Building Peanut Suite v$VERSION"
echo "================================"
echo ""

# Build frontend
echo "Building frontend..."
cd frontend
npm run build
cd ..

echo ""
echo "Creating plugin zip file..."
echo ""

# Go to parent directory and create versioned zip
cd ..
ZIP_NAME="peanut-suite-${VERSION}.zip"
rm -f "$ZIP_NAME"
zip -r "$ZIP_NAME" peanut-suite \
    -x "peanut-suite/frontend/node_modules/*" \
    -x "peanut-suite/frontend/src/*" \
    -x "peanut-suite/.git/*" \
    -x "*.command" \
    -x "peanut-suite/frontend/*.json" \
    -x "peanut-suite/frontend/*.config.*" \
    -x "peanut-suite/frontend/*.ts"

echo ""
echo "================================"
echo "  Build complete!"
echo ""
echo "  ZIP file created:"
echo "  $(pwd)/$ZIP_NAME"
echo ""
echo "  Upload this to WordPress:"
echo "  Plugins > Add New > Upload Plugin"
echo "================================"
echo ""

# Open the folder containing the zip
open .

read -p "Press Enter to close..."
