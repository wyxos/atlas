#!/bin/bash
set -euo pipefail

if [ "$#" -eq 1 ] && [ "$1" = "-m" ]; then
    /usr/local/bin/php-real -m | perl -ne 'print unless $seen{$_}++'
    exit 0
fi

exec /usr/local/bin/php-real "$@"
