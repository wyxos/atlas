# Implementation Caveats & Gotchas

## Nginx X-Accel-Redirect
- **URI Encoding**: The path passed in `X-Accel-Redirect` must be properly URI-encoded. Failure to do so can lead to 404s or incorrect file resolution.
- **Redirect Limit**: Nginx enforces a limit of 10 internal redirects per request. Complex `try_files` or nested redirects combined with `X-Accel-Redirect` could hit this limit.
- **Header Preservation**: Nginx may override or ignore certain headers from the backend during the redirect. `Content-Type` is a known case where explicit backend setting is safer.

## OPcache
- **Immutability Requirement**: With `opcache.validate_timestamps=0`, any change to the filesystem (e.g., via a volume mount in dev or a hotfix) will NOT be reflected until the FPM process is restarted or `opcache_reset()` is called.

## PHP-FPM clear_env
- **Environment Access**: When `clear_env=yes` is set, PHP's `getenv()` will only return variables explicitly defined in the FPM pool configuration (e.g., `env[DB_HOST] = $DB_HOST`). This may require explicit mapping of all required `APP_*` variables in `zz-docker.conf`.


## F2 code quality review findings
- Reject: Docker healthcheck command uses curl in php:8.4-fpm image, but Dockerfile does not install curl; php service healthcheck will fail.
- Reject: php command starts a built-in server on port 80 as www-data, which cannot bind privileged ports and is an unnecessary sidecar beside php-fpm.
- Reject: X-Accel media alias points at storage/app/atlas/.app while service emits paths relative to the full downloads disk root, likely producing /_media/atlas/.app/... and aliasing to duplicated atlas/.app/atlas/.app paths.
- Concern: php and pgrep wrapper scripts replace system binaries for narrow output shims; prefer fixing callers/healthchecks instead of global binary interception.
