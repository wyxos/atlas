#!/usr/bin/env bash
set -euo pipefail

# Run from repo root
cd "$(dirname "$0")/.."

echo "1/4 ESLint (fix)"
# Pass --fix even if the script already fixes, and tolerate missing script
npm run lint --if-present --silent -- --fix

echo "2/4 Laravel Pint (fix)"
if [ -x "./vendor/bin/pint" ]; then
  ./vendor/bin/pint
elif command -v pint >/dev/null 2>&1; then
  pint
else
  echo "Pint not found; skipping"
fi

echo "3/4 JS tests"
npm run test

echo "4/4 PHP tests"
php artisan test
