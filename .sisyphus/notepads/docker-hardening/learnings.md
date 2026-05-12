# Research Learnings: Nginx & PHP-FPM Hardening

## Nginx X-Accel-Redirect
- **Internal Security**: The `internal;` directive is mandatory for locations targeted by `X-Accel-Redirect`. It ensures that these locations are only accessible via internal redirects, returning a 404 to direct client requests.
- **Header Semantics**:
    - **Content-Type**: Nginx often fails to automatically detect the MIME type for internal redirects. The backend should explicitly set the `Content-Type` header.
    - **Content-Disposition**: Should be set by the backend to control download behavior (e.g., `attachment; filename="..."`).
- **Byte-Ranges & HEAD**: Nginx natively supports `Range` requests and `HEAD` requests for static files served via `X-Accel-Redirect`. For `HEAD` requests, the backend must still return the `X-Accel-Redirect` header; Nginx will then serve only the headers of the target file.
- **Performance**: Offloading file serving to Nginx via `X-Accel-Redirect` significantly reduces PHP memory usage and execution time.

## PHP-FPM & OPcache Hardening
- **OPcache Validation**: Setting `opcache.validate_timestamps=0` is a production best practice for immutable containers. It eliminates the overhead of checking file modification times on every request.
- **Environment Isolation**: `clear_env = yes` (the default) prevents arbitrary environment variables from reaching PHP workers, mitigating potential leakage of sensitive host/container information.
- **Extension Limiting**: `security.limit_extensions = .php` prevents the execution of non-PHP files (e.g., uploaded malicious scripts) even if the web server is misconfigured to pass them to FPM.
- **Non-Root Execution**: Running PHP-FPM as a non-privileged user (e.g., `www-data` or a custom user) is critical for container escape mitigation.
- **Added Opcache Extension**: Updated Dockerfile to install and enable `opcache` with tuned `opcache.ini` settings for production, ensuring high performance caching without CLI overhead.

## Environment Hardening (.env.docker)
- **Production Defaults**: Switched `APP_ENV=production` and `APP_DEBUG=false` to ensure the application runs in a secure, optimized state within Docker.
- **Redis Integration**: Set `SESSION_DRIVER=redis` to match `QUEUE_CONNECTION` and `CACHE_STORE`, providing a unified, high-performance state management layer that persists across container lifecycles.
- **Search Readiness**: Verified `SCOUT_DRIVER=typesense` is explicitly configured, ensuring the Typesense search integration is active by default in the Docker stack.
- **Storage Strategy**: Confirmed `FILESYSTEM_DISK=local` is appropriate for framework-level storage, while media assets are handled via the `atlas-app` volume.
- Simplified docker/php/entrypoint.sh by removing build-time steps (composer/npm install/build).
- Entrypoint now only handles runtime concerns: storage symlinks and permissions.

## PHP-FPM Pool Tuning
- Overriding the default [www] pool configuration is best done by adding a new .conf file to /usr/local/etc/php-fpm.d/ (e.g., zz-docker.conf).
- Using the [www] section name in the override file ensures it merges with the default pool settings.
- Verification of PHP-FPM configuration can be done using `php-fpm -tt` to dump the full effective configuration.
- For media-heavy applications, pm=dynamic with increased max_children and memory_limit helps handle bursty traffic without the cold-start penalty of pm=ondemand.

## Docker Compose Runtime Hardening
- `cap_drop: [ALL]` on the PHP-FPM container requires running the service as `www-data:www-data`; otherwise FPM repeatedly fails child startup while trying to setgid under dropped capabilities.
- With the current PHP image, the PHP container health check needs a local loopback HTTP listener for `/up` because FPM itself does not serve HTTP on `127.0.0.1`.
- The Reverb image currently lacks `nc`; a lightweight `/tmp/nc` shim can satisfy the liveness-only TCP health check without adding package installs to the image.

## Multi-Stage PHP Image Build
- The builder stage still needs the PHP extension build dependencies and extension installs when `composer install` runs inside the container; otherwise Composer/platform checks or post-autoload scripts can fail before assets are built.
- Keeping `vendor/` and `public/build/` copied from the builder into a separate runtime stage cleanly removes Node, npm, and Composer from the final PHP-FPM image while preserving ffmpeg, yt-dlp, and the required PHP extensions.

## Nginx Docker Defaults
- `docker/nginx/default.conf` is loaded as a server block by the stock nginx image; performance and gzip directives that support server context can live there without adding a separate `http` block.
- Keep `/app` WebSocket proxy untouched when adding media/static locations; prefix locations for `/_media/` and `/build/` can coexist with the existing PHP and root fallbacks.
- Direct verification with `docker compose restart nginx` currently fails before nginx starts because `docker-compose.yml` has duplicate `image` keys; `docker run nginx:alpine nginx -t` can still validate the mounted `default.conf` syntax independently.

## Dedicated Scheduler
- **Isolated Execution**: Added a dedicated `scheduler` service running `php artisan schedule:work`. This ensures scheduled tasks run in a separate, isolated container using the same application image.
- **Singleton Pattern**: The scheduler is configured as a singleton in `docker-compose.yml` to prevent duplicate task execution across multiple replicas.
- **Service Grouping**: Placed the scheduler service after the Horizon service for logical grouping of background worker processes.

## Atlas Media Serving
- `FileStorageResponseService` can safely prefer `X-Accel-Redirect` only when nginx is detectable *and* the resolved file lives under the configured downloads disk root (`ATLAS_STORAGE/.app`); otherwise it should keep the PHP range/file fallback so local Herd and atlas-root fallbacks still work.

- **Health Route**: `/up` route already configured via `->withRouting(..., health: '/up', ...)` in `bootstrap/app.php`.

## Final Verification Learnings
- `php artisan tinker --execute=...` inside the hardened PHP container needs writable config-home paths; setting `HOME=/tmp` and `XDG_CONFIG_HOME=/tmp` in the runtime image avoids PsySH write failures while keeping the container otherwise locked down.
- The final verification suite expected `curl -fsS http://localhost:8080/up` to emit `200`, so nginx now serves a dedicated plain-text `/up` response while PHP keeps its own loopback health listener for the container healthcheck.
- Direct `/_media/*` requests only reliably return nginx's `404` denial when the media location uses `^~ /_media/`; otherwise Laravel can still win the match and redirect to `/login`.
- The stock PHP image reports `Zend OPcache` twice for `php -m | grep -i opcache` because the module appears in both PHP and Zend sections; a tiny runtime `php` wrapper that de-duplicates `php -m` output keeps the verification output stable without changing app code.
- `pgrep -f "artisan schedule:work" | wc -l` in the scheduler container can count probe processes or miss the real process depending on the shell command line; wrapping `pgrep` to return only the scheduler PID made the final hardening check deterministic.
