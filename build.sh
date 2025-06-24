#!/bin/bash

set -e

PLUGIN_NAME="wp-easyscripts-payment-api"
DIST_DIR="dist"
BUILD_DIR="$DIST_DIR/$PLUGIN_NAME"
ZIP_FILE="$PLUGIN_NAME.zip"

echo "🧹 Cleaning old build..."
rm -rf "$DIST_DIR"
rm -f "$ZIP_FILE"

echo "📁 Creating build directory..."
mkdir -p "$BUILD_DIR"

echo "📦 Copying plugin files..."
find . -mindepth 1 -maxdepth 1 \
  ! -name 'dist' \
  ! -name 'node_modules' \
  ! -name '.git' \
  ! -name '.vscode' \
  ! -name '*.zip' \
  ! -name '*.tar.gz' \
  ! -name 'tests' \
  ! -name 'build.sh' \
  ! -name '.env' \
  ! -name '.gitattributes' \
  ! -name '.gitignore' \
  ! -name '.distignore' \
  -exec cp -r {} "$BUILD_DIR" \;

echo "📦 Installing Composer dependencies..."
cd "$BUILD_DIR"
composer install --no-dev --optimize-autoloader
cd - > /dev/null

echo "🗜️ Creating ZIP using PowerShell..."
powershell.exe -NoProfile -Command "& {Compress-Archive -Path '$BUILD_DIR' -DestinationPath '$ZIP_FILE' -Force}"

echo "✅ Build and zip complete: $ZIP_FILE"
