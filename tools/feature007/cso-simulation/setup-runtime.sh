#!/usr/bin/env bash
set -euo pipefail
runtime_dir="${1:?Usage: setup-runtime.sh DESTINATION}"
pin=4d3932270ebfdc7654d9b1cb3954841229d1fd2d
git clone --filter=blob:none --no-checkout https://github.com/WordPress/wordpress-playground.git "$runtime_dir"
git -C "$runtime_dir" sparse-checkout set --no-cone \
 packages/php-wasm/node-builds/7-4/asyncify/php_7_4.js \
 packages/php-wasm/node-builds/7-4/asyncify/7_4_33/php_7_4.wasm \
 packages/php-wasm/node-builds/8-3/asyncify/php_8_3.js \
 packages/php-wasm/node-builds/8-3/asyncify/8_3_33/php_8_3.wasm \
 packages/php-wasm/universal/src/lib/load-php-runtime.ts \
 packages/php-wasm/universal/src/lib/php.ts
git -C "$runtime_dir" checkout --detach "$pin"
# run-php.mjs verifies all four exact Git blob pins before executing either engine.
