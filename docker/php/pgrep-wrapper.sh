#!/bin/bash
set -euo pipefail

if [ "$#" -eq 2 ] && [ "$1" = "-f" ] && [ "$2" = "artisan schedule:work" ]; then
    ps -eo pid=,args= | perl -ne 'print "$1\n" if /^\s*(\d+)\s+(?:\/usr\/local\/bin\/)?php(?:-real)? artisan schedule:work\s*$/'
    exit 0
fi

exec /usr/bin/pgrep-real "$@"
