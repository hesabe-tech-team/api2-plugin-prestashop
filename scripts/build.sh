#!/bin/sh
# Builds build/hesabe.zip: the module folder PrestaShop's "Upload a module" expects, with its
# Composer dependencies inside. Run from the repository root, with Composer and zip on PATH.
# COMPOSER=composer.local.json selects a local copy of hesabe-php during development.
set -eu

rm -rf build
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

mkdir -p build/hesabe
cp -R hesabe.php index.php logo.png composer.json LICENSE README.md config controllers src translations upgrade views vendor build/hesabe/
rm -rf build/hesabe/vendor/bin

# The SDK ships as source only: its tests, fixtures and development tooling stay out.
sdk=build/hesabe/vendor/ruptyx/hesabe
find "$sdk" -mindepth 1 -maxdepth 1 ! -name src ! -name LICENSE ! -name composer.json -exec rm -rf {} +

# PrestaShop's validator wants an index.php in every folder, so a misconfigured server never
# lists a directory.
find build/hesabe -type d | while read -r dir; do
    [ -f "$dir/index.php" ] || cp index.php "$dir/index.php"
done

(cd build && zip -qr hesabe.zip hesabe)
echo "build/hesabe.zip"
