# Production Deployment & Authentik SSO Integration

## Goal

Deploy Atlas to the K3s Cloudhome cluster with Authentik SSO, hardened container runtime defaults, Nginx/PHP-FPM media offload, and all supporting infrastructure: MariaDB, Redis, Typesense, Reverb, Horizon, and Scheduler.

The deployment plan must preserve the working Docker setup assumptions while carrying forward the Docker hardening work: Opcache, multi-stage runtime images, production-safe environment defaults, tuned PHP-FPM, X-Accel-Redirect media serving, liveness health checks, resource limits, non-root runtime, and full host-level and cluster-level verification.

## Open Decisions (user-confirmed before Phase 3)

| # | Decision | Default (proceed if no answer) | Impact if wrong |
|---|----------|-------------------------------|-----------------|
| D1 | Domain name for Atlas | `atlas.rustybret.com` | IngressRoute, Reverb WS host, APP_URL, Authentik redirect URI |
| D2 | Cloudflare Access in front of Traefik? | No (Authentik is sufficient) | Extra network hop, cookie conflicts, duplicated auth behavior |
| D3 | All users via Authentik, or local admin fallback? | Authentik-only + one local admin stored in K8s secret | Recovery access if Authentik is down |
| D4 | Auto-create users on first Authentik login? | Yes, map by email, assign `is_admin=false` | Orphan users if email mismatch |
| D5 | Extension auth strategy | Keep existing shared API key (no change) | Requires separate per-user token work later |
| D6 | PVC size for media storage | 100Gi (expandable) | Too small = resize; too large = waste |
| D7 | MariaDB: new K3s deployment or managed? | New MariaDB deployment in K3s (same as Docker) | Managed DB = cost, K3s = operational burden |
| D8 | First admin bootstrap | `app:setup` artisan command still works; first user created locally, then switch to Authentik | Need recovery path |
| D9 | Web pod shape | Nginx + PHP-FPM sidecar in one Atlas web Deployment | If omitted, a PHP-FPM-only pod cannot serve HTTP port 80 |
| D10 | Registry target | OCIR image tagged by git SHA | Wrong registry breaks rollout and rollback |

## Phases

### Phase 1: Container and Application Hardening (parallel-safe, no K8s dependency)

**B1** - Restrict trusted proxies
- File: `bootstrap/app.php`
- Change `$middleware->trustProxies(at: '*')` to K8s/internal CIDRs only: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`.
- Keep Traefik and pod network ranges covered.
- Acceptance: `trustProxies` no longer accepts `*`; requests behind Traefik still resolve scheme, host, and client IP correctly.

**B2** - Add login rate limiting
- File: `routes/web.php` - add `->middleware('throttle:6,1')` to the login POST route.
- File: `app/Http/Controllers/Auth/LoginController.php` - add a RateLimiter check keyed by email + IP.
- Acceptance: 6+ failed login attempts within 1 minute returns 429; successful Authentik redirects are not rate-limited incorrectly.

**B3** - Externalize production secrets and env defaults
- Files: `.env.docker`, `k8s/base/apps/atlas/configmap.yaml`, `k8s/base/apps/atlas/secrets.yaml`
- Set production-safe defaults:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `SESSION_DRIVER=redis`
  - `QUEUE_CONNECTION=redis`
  - `CACHE_STORE=redis`
- Replace all committed secret values with references:
  - `APP_KEY=${APP_KEY}`
  - `DB_PASSWORD=${DB_PASSWORD}`
  - `TYPESENSE_API_KEY=${TYPESENSE_API_KEY}`
  - `REVERB_APP_SECRET=${REVERB_APP_SECRET}`
  - `REDIS_PASSWORD=${REDIS_PASSWORD}` if Redis auth is enabled
  - `AUTHENTIK_CLIENT_SECRET=${AUTHENTIK_CLIENT_SECRET}`
- Document APP_KEY generation: `php artisan key:generate --show`, then store once in K8s secret.
- Acceptance: no plaintext production secrets are committed; runtime config reports production and no-debug.

**B4** - Harden PHP runtime image
- File: `docker/php/Dockerfile`
- Refactor to a multi-stage image:
  - Builder stage installs Composer + Node/npm, runs `composer install --no-dev --optimize-autoloader --classmap-authoritative`, runs `npm ci && npm run build`.
  - Runtime stage is based on `php:8.4-fpm`, contains only app source, `vendor`, built assets, runtime packages, PHP extensions, and config.
- Runtime must include: `ffmpeg`, `yt-dlp`, `opcache`, `pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`, `zip`, `sockets`, `redis`, `imagick`, `xml`, and `curl` if required by the app.
- Runtime must not include: Node, npm, Composer, Xdebug, or build toolchains beyond unavoidable shared libraries.
- Runtime user: `www-data` or an equivalent non-root app user.
- Acceptance: image builds cleanly; `which node`, `which npm`, and `which composer` fail in runtime; `which ffmpeg` and `which yt-dlp` succeed; `whoami` returns the non-root app user.

**B5** - Add Opcache and PHP-FPM production tuning
- Files: `docker/php/opcache.ini`, `docker/php/zz-docker.conf`, `docker/php/Dockerfile`
- Opcache config:
  ```ini
  opcache.enable=1
  opcache.enable_cli=0
  opcache.memory_consumption=256
  opcache.interned_strings_buffer=16
  opcache.max_accelerated_files=65407
  opcache.validate_timestamps=0
  opcache.revalidate_freq=0
  opcache.save_comments=1
  opcache.max_wasted_percentage=10
  ```
- PHP-FPM pool override:
  ```ini
  [www]
  pm = dynamic
  pm.max_children = 16
  pm.start_servers = 4
  pm.min_spare_servers = 4
  pm.max_spare_servers = 8
  pm.max_requests = 500
  request_terminate_timeout = 120s
  request_slowlog_timeout = 10s
  slowlog = /proc/self/fd/2
  php_admin_value[memory_limit] = 256M
  ```
- Acceptance: `php -m` shows Zend OPcache; `php -i` shows Opcache enabled and CLI disabled; `php-fpm -t` passes.

**B6** - Simplify PHP entrypoint to runtime-only concerns
- File: `docker/php/entrypoint.sh`
- Remove runtime `composer install`, `npm install`, and `npm run build` blocks.
- Keep only:
  - `set -e`
  - storage symlink creation/check
  - writable path ownership and permissions for `storage`, `bootstrap/cache`, temp paths, and any Atlas media directories
  - final `exec "$@"`
- Acceptance: entrypoint contains no build commands and still exits via `exec "$@"`.

**B7** - Harden Nginx for assets, forwarded headers, and X-Accel media
- Files: `docker/nginx/default.conf`, `k8s/base/apps/atlas/nginx-configmap.yaml`
- Configure Laravel/PHP-FPM routing and SPA fallback.
- Configure hashed asset caching only under `/build/`:
  - `Cache-Control: public, max-age=31536000, immutable`
- Configure X-Accel internal media path:
  ```nginx
  location /_media/ {
      internal;
      alias /var/www/html/storage/app/atlas/.app/;
      add_header Accept-Ranges bytes always;
      sendfile on;
      tcp_nopush on;
      sendfile_max_chunk 1m;
  }
  ```
- Configure Nginx performance tuning: `sendfile`, `tcp_nopush`, `tcp_nodelay`, `open_file_cache`, and gzip for text/JSON/JS/CSS/SVG/wasm only.
- Do not gzip images or video.
- Set forwarded headers needed by Laravel:
  - `X-Forwarded-Host`
  - `X-Forwarded-Proto`
  - `X-Forwarded-For`
  - `X-Forwarded-Port`
- Acceptance: direct `/_media/...` requests return 404; built assets have immutable cache headers; redirects and generated URLs include the correct external scheme/host/port.

**B8** - Add X-Accel support with non-Docker fallback
- File: `app/Services/FileStorageResponseService.php`
- Keep existing authorization and route middleware unchanged.
- Add X-Accel response mode when behind Nginx:
  - Map files under `storage/app/atlas/.app/` to `/_media/{relativePath}`.
  - Return headers for `X-Accel-Redirect`, `Content-Type`, `Content-Disposition`, and `Accept-Ranges`.
- Preserve existing PHP streaming fallback for Herd/local/non-Nginx development.
- Preserve byte-range, HEAD, Content-Type, Content-Disposition, 206, and 416 behavior.
- Acceptance: authenticated media requests are offloaded to Nginx in Docker/K8s; local development still streams through PHP.

**B9** - Verify Laravel health route and liveness boundaries
- File: `bootstrap/app.php`
- Verify `->withRouting(health: '/up')` exists.
- Health checks must be liveness-only for the app container and should not fail solely because DB, Redis, or Typesense are temporarily unavailable.
- Dependency-specific readiness checks belong in K8s readiness probes or final verification, not in `/up` liveness.
- Acceptance: `/up` returns 200 when Laravel boots.

**B10** - Preserve production-safe Docker fallback path
- File: `docker-compose.prod.yml`
- Extend local Docker Compose for production-like smoke tests.
- Remove public `ports` from MariaDB, Redis, Typesense, and any internal services.
- Remove `phpmyadmin` entirely from production override.
- Keep Nginx as the only public HTTP entry point.
- Add resource limits and conservative container security:
  - app/Nginx/Horizon/Reverb/Scheduler/Typesense memory limits
  - `cap_drop: [ALL]` where safe
  - `security_opt: [no-new-privileges:true]`
  - `tmpfs` for `/tmp` and `/var/run` where needed
- Do not set `read_only: true` until all writable paths are enumerated per service.
- Acceptance: `docker compose -f docker-compose.yml -f docker-compose.prod.yml config` shows no exposed internal service ports and no phpMyAdmin service.

### Phase 2: K8s Manifests (parallel within phase, sequential after Phase 1)

**C1** - Create K8s directory structure
- Directory: `k8s/base/apps/atlas/`
- Files:
  - `web-deployment.yaml`
  - `web-service.yaml`
  - `ingressroute.yaml`
  - `nginx-configmap.yaml`
  - `pvc.yaml`
  - `configmap.yaml`
  - `secrets.yaml`
  - `mariadb-deployment.yaml`
  - `mariadb-service.yaml`
  - `mariadb-pvc.yaml`
  - `redis-deployment.yaml`
  - `redis-service.yaml`
  - `typesense-deployment.yaml`
  - `typesense-service.yaml`
  - `typesense-pvc.yaml`
  - `reverb-deployment.yaml`
  - `reverb-service.yaml`
  - `horizon-deployment.yaml`
  - `scheduler-deployment.yaml`
  - `migration-job.yaml`
  - `bootstrap-admin-job.yaml` if `app:setup` cannot be folded into migration flow
- Acceptance: every manifest passes `kubectl apply --dry-run=client`.

**C2** - Atlas web Deployment manifest
- File: `k8s/base/apps/atlas/web-deployment.yaml`
- Pod contains two containers:
  - `nginx`: serves HTTP on port 80 using `nginx-configmap.yaml`.
  - `php-fpm`: runs the hardened Atlas PHP-FPM image.
- Both containers mount the same app/media PVC path needed for X-Accel aliasing.
- Replicas: 2 with rolling update.
- Resources:
  - PHP-FPM request 256Mi, limit 512Mi.
  - Nginx request 64Mi, limit 128Mi.
- Probes:
  - Nginx readiness/liveness HTTP GET `/up` on port 80.
  - PHP-FPM liveness via `php-fpm -t` or a local FastCGI-safe check if available.
- Security context:
  - non-root where image supports it
  - `allowPrivilegeEscalation: false`
  - drop all capabilities where safe
  - writable mounts only for `storage`, `bootstrap/cache`, temp, and media paths
- Acceptance: web pods become Ready; `curl http://atlas-web/up` returns 200 from inside the cluster.

**C3** - Atlas Service + IngressRoute
- Files: `web-service.yaml`, `ingressroute.yaml`
- ClusterIP service exposes web pods on port 80.
- Traefik IngressRoute:
  - host rule for D1 domain
  - TLS via cert resolver or cluster CA
  - Authentik forward-auth middleware only if D2 changes to yes; default is no Cloudflare Access and Authentik handled by app flow
  - preserves `X-Forwarded-*` headers for Laravel URL generation
- Acceptance: `https://{D1 domain}/up` returns 200, `https://{D1 domain}/login` returns the login page, and app-generated redirects use the correct HTTPS host.

**C4** - PVC for media storage
- File: `pvc.yaml`
- StorageClass: `local-path` or cluster default.
- Size: D6 default, `100Gi` expandable.
- Mount path: `/var/www/html/storage/app/atlas`.
- Must preserve existing media layout, including `.app/` for X-Accel.
- Acceptance: PVC binds; web pods, Horizon, Scheduler, Reverb if needed, and migration/setup jobs mount the same path consistently.

**C5** - MariaDB K8s deployment
- Files: `mariadb-deployment.yaml`, `mariadb-service.yaml`, `mariadb-pvc.yaml`
- Image: `mariadb:10.11`.
- PVC: 20Gi for data.
- Secrets: root password and app password from K8s secret.
- Internal ClusterIP only; no NodePort/LoadBalancer.
- Health check: `mariadb-admin ping`.
- Acceptance: app can migrate and connect using `DB_CONNECTION=mariadb`.

**C6** - Redis K8s deployment
- Files: `redis-deployment.yaml`, `redis-service.yaml`
- Image: `redis:7-alpine`.
- Internal ClusterIP only.
- Persistence optional; sessions may be ephemeral as long as re-authentication is acceptable.
- Health check: `redis-cli ping`.
- Acceptance: cache, queue, and session drivers use Redis successfully.

**C7** - Typesense K8s deployment
- Files: `typesense-deployment.yaml`, `typesense-service.yaml`, `typesense-pvc.yaml`
- Image: `typesense/typesense:27.0` or the currently validated version.
- PVC: 5Gi for data.
- API key from K8s secret.
- Runtime command includes `--memory-used-max-percentage=80`.
- Internal ClusterIP only.
- Health check: `curl http://127.0.0.1:8108/health` returns `{"ok": true}`.
- Acceptance: Browse API returns 200 with Typesense available.

**C8** - Reverb WebSocket deployment
- File: `reverb-deployment.yaml`, `reverb-service.yaml`
- Same hardened Atlas image, command: `php artisan reverb:start`.
- One or more replicas only if the app/Reverb config supports it; otherwise default to 1.
- Expose via Traefik on `/app/` WebSocket path using WSS on D1 domain.
- Health check: port check or Reverb-aware endpoint; do not make it depend on unrelated services.
- Acceptance: browser WebSocket connection succeeds through Traefik.

**C9** - Horizon + Scheduler deployments
- Files: `horizon-deployment.yaml`, `scheduler-deployment.yaml`
- Horizon command: `php artisan horizon`.
- Scheduler command: `php artisan schedule:work`.
- Scheduler replicas: exactly 1.
- Horizon resources: request 512Mi, limit 2Gi unless load tests justify another value.
- Both use the same ConfigMap/Secrets and same media/storage mount if jobs access files.
- Acceptance: `php artisan horizon:status` reports running; exactly one scheduler process is active.

**C10** - Database migration and setup Jobs
- File: `migration-job.yaml`
- Command: `php artisan migrate --force`.
- Runs before web rollout completes.
- Optional `bootstrap-admin-job.yaml` runs `app:setup` or equivalent once, using secret-provided admin credentials.
- Acceptance: migration job exits 0; first admin bootstrap path is documented and repeat-safe.

**C11** - ConfigMap + Secrets
- Files: `configmap.yaml`, `secrets.yaml`
- ConfigMap contains non-secret env vars:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_URL=https://{D1 domain}`
  - `SESSION_DRIVER=redis`
  - `QUEUE_CONNECTION=redis`
  - `CACHE_STORE=redis`
  - `DB_HOST=mariadb`
  - `REDIS_HOST=redis`
  - `TYPESENSE_HOST=typesense`
  - `REVERB_HOST={D1 domain}` and WSS-facing values
  - `AUTH_LOCAL_ENABLED=false` by default
- Secrets contain:
  - `APP_KEY`
  - `DB_PASSWORD`
  - `TYPESENSE_API_KEY`
  - `REVERB_APP_SECRET`
  - `SENTRY_LARAVEL_DSN` if used
  - `AUTHENTIK_CLIENT_SECRET`
  - initial local admin credential or bootstrap token if needed
- ESO/OCI Vault integration optional for first deploy but the manifest shape must support later vault sync.
- Acceptance: `kubectl get secret atlas-secrets` contains all required keys; no secret value is committed in plaintext.

**C12** - Production-only surface area audit
- Verify no production manifest exposes phpMyAdmin.
- Verify MariaDB, Redis, and Typesense have internal ClusterIP services only.
- Verify only Atlas HTTPS and Reverb WSS paths are ingress-routable.
- Verify direct `/_media/` access is blocked by Nginx `internal`.
- Acceptance: there is no public route to DB, Redis, Typesense, phpMyAdmin, raw media, Horizon for non-admin users, or any debugging endpoint.

### Phase 3: Authentik SSO Integration (sequential, after Phase 2)

**D1** - Install Laravel Socialite + Authentik provider
- `composer require laravel/socialite socialiteproviders/authentik`
- Register provider in `config/services.php`:
  ```php
  'authentik' => [
      'client_id' => env('AUTHENTIK_CLIENT_ID'),
      'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
      'redirect' => env('AUTHENTIK_REDIRECT_URI'),
      'base_url' => env('AUTHENTIK_BASE_URL'),
  ],
  ```
- Acceptance: composer install/build succeeds in the builder stage with no runtime Composer dependency.

**D2** - Configure Authentik application
- In Authentik admin: create Application + Provider (OIDC).
- Redirect URI: `https://{D1 domain}/auth/authentik/callback`.
- Scopes: `openid`, `email`, `profile`.
- Store client ID and secret in K8s secret.
- Acceptance: Authentik shows Atlas application in admin UI.

**D3** - Implement Authentik login controller
- File: `app/Http/Controllers/Auth/AuthentikController.php`
- Methods: `redirect()` and `callback()`.
- Callback finds or creates a local User by email.
- Auto-created users default to `is_admin=false`.
- Acceptance: Authentik login redirects out and back; session is stored in Redis; user record exists.

**D4** - Update login routes
- File: `routes/web.php`
- Add `GET /auth/authentik` and `GET /auth/authentik/callback`.
- Keep local login route as admin fallback gated by `AUTH_LOCAL_ENABLED`.
- Keep login POST rate limiting from B2.
- Acceptance: `/login` shows SSO by default; local form appears only when enabled.

**D5** - Update Vue frontend login page
- File: `resources/js/pages/Login.vue` or equivalent.
- Replace default email/password focus with an Authentik sign-in button.
- Keep local login form behind `authLocalEnabled` from `/api/auth/config`.
- Acceptance: login page supports SSO and preserves recovery fallback.

**D6** - Protect Horizon with app auth and admin gate
- Ensure Horizon route uses `auth` and admin authorization.
- Admin assignment path: first local admin or manual `php artisan tinker` if needed.
- Acceptance: non-admin users receive 403; admin users can access Horizon.

**D7** - Reconcile Authentik app auth with Traefik forward-auth
- Default: app-level OIDC flow only.
- If D2 changes to use Cloudflare Access or Traefik forward-auth, document header ownership and avoid double redirects/cookie conflicts.
- Acceptance: unauthenticated request has exactly one coherent login path and no redirect loop.

### Phase 4: CI/CD, Deployment, and Verification

**E1** - Build, test, and push pipeline
- GitHub Actions or equivalent.
- Steps:
  - lint and unit tests
  - build hardened Docker image
  - verify runtime image contents: no Node/npm/Composer, ffmpeg/yt-dlp present, Opcache present, non-root runtime
  - push to OCIR tagged by git SHA and optionally `main`
- Acceptance: image appears in OCIR and build evidence confirms hardened runtime properties.

**E2** - Local production-like Docker smoke verification
- Script: `scripts/test-docker.sh`
- Run from a clean state with the production override.
- Must verify:
  - all services healthy
  - Nginx -> PHP-FPM -> Laravel works from host perspective
  - `/up`, `/`, and `/login` return 200
  - SPA assets are served
  - app URLs include correct host/scheme/port
  - auth flow works with demo/local admin path where applicable
  - Browse API returns 200 with Typesense connected
  - `/_media/` direct access returns 404
  - media range/HEAD requests work through X-Accel after auth
  - Horizon, Scheduler, Reverb are running
  - phpMyAdmin is absent when production override is used
- Acceptance: test script passes all sections and fails loudly on any broken full-stack HTTP path.

**E3** - Deploy script
- Script: `k8s/deploy.sh`
- Steps:
  - apply ConfigMap/Secret prerequisites
  - run migration job
  - update web, horizon, scheduler, and reverb image tags
  - wait for rollout
  - run post-deploy smoke checks
- Acceptance: new pods roll out without downtime; migration runs before app serves traffic.

**E4** - Cluster end-to-end verification
- Required checks:
  - `kubectl get pods -n apps -l app=atlas` all Running/Ready
  - `kubectl logs` for web/php-fpm/nginx/horizon/reverb/scheduler show no boot errors
  - `curl -fsS https://{D1 domain}/up` returns 200
  - `curl -fsS https://{D1 domain}/` returns the SPA shell
  - `curl -fsS https://{D1 domain}/login` returns 200
  - Authentik login flow succeeds: redirect -> login -> callback -> authenticated app
  - Browse page loads with local feed data or a valid empty state
  - Browse API returns 200 and does not show Typesense unavailable errors
  - Extension API key still works: `curl -H "X-Atlas-Api-Key: ..." https://{D1 domain}/api/extension/ping`
  - Direct `https://{D1 domain}/_media/test.jpg` returns 404
  - Authenticated media request supports byte ranges and expected Content-Type/Disposition
  - Horizon dashboard is accessible to admin and forbidden to non-admin
  - Reverb WebSocket connection succeeds via WSS
  - Scheduler has exactly one active replica/process
- Acceptance: all checks pass and evidence is saved under `.sisyphus/evidence/production-deploy/`.

**E5** - Rollback procedure
- `kubectl rollout undo deployment/atlas-web` reverts the web deployment.
- Roll back Horizon/Reverb/Scheduler image tags if they changed.
- If Authentik is down: set `AUTH_LOCAL_ENABLED=true` in ConfigMap, restart web deployment, use local admin fallback.
- If migration introduced incompatible schema: execute documented DB restore or forward-fix; do not blindly roll back app only.
- Acceptance: rollback or recovery path completes within 60 seconds for app image rollback; auth fallback can be enabled without editing code.

## Parallelism Map

```
Phase 1: B1 | B2 | B3 | B4 | B5 | B6 | B7 | B9  (mostly parallel)
         B8 after B7
         B10 after B3, B4, B7
Phase 2: C1 -> (C2, C3, C4, C5, C6, C7, C8, C9, C10, C11, C12)
         C2 depends on B4, B5, B6, B7, B8, B9
Phase 3: D1 -> D2 -> (D3, D4, D5) -> D6 -> D7
Phase 4: E1 | E2 -> E3 -> E4 -> E5
```

## Constraints

- Never commit secrets: APP_KEY, DB passwords, Typesense keys, Reverb secrets, Authentik secrets, admin bootstrap credentials.
- All K8s manifests must pass `kubectl apply --dry-run=client`.
- Keep local auth fallback behind `AUTH_LOCAL_ENABLED`; do not delete it.
- Extension API key mechanism remains unchanged.
- No back-channel/headless OIDC flow; use front-channel browser redirect only.
- MariaDB only; do not switch to PostgreSQL or SQLite for production.
- Preserve existing media paths, volume names, and directory layout.
- Media must not be publicly exposed; `/_media/` must remain Nginx `internal`.
- Preserve non-Docker/Herd PHP streaming fallback for media.
- Do not add CDN, S3, external object storage, transcoding infrastructure, or monitoring as part of this deployment unless separately planned.
- Do not use immutable cache headers on user media or API responses.
- Do not enable `read_only: true` until writable paths are fully enumerated and tested per container.
- Do not run more than one scheduler replica.
- Production must not expose phpMyAdmin, DB, Redis, or Typesense publicly.
- All manifests follow Cloudhome K8s conventions and should be comparable to the existing OpenCode deployment reference.

## Risks

| Risk | Mitigation |
|------|------------|
| PHP-FPM-only Deployment cannot serve HTTP | Use Nginx + PHP-FPM sidecar web pods and expose only Nginx port 80 |
| Authentik outage locks all users out | Local admin fallback via `AUTH_LOCAL_ENABLED` env var and local admin secret |
| X-Accel bypass exposes media | Keep Nginx `internal;`, keep auth middleware, verify direct `/_media/` returns 404 |
| Media behavior regression | Preserve PHP fallback and test Range, HEAD, 206, 416, Content-Type, and Content-Disposition |
| Session storage lost after Redis restart | Users re-authenticate via Authentik; no media/app data loss |
| Typesense data corruption | PVC persists; rebuild index from DB if needed |
| First deployment migration failure | Migration Job with `restartPolicy: OnFailure`; manual app setup as backup |
| Reverb WebSocket not connecting through Traefik | Dedicated WSS route on `/app/`; test with browser and `wscat` |
| Image pull from OCIR fails | Use `imagePullSecrets`; verify OCIR credentials during deploy script |
| Wrong forwarded headers break redirects/assets | Set and verify `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Forwarded-For`, and `X-Forwarded-Port` |
| Build tools remain in runtime image | CI image inspection fails if Node/npm/Composer are present |
| Scheduler duplication | Scheduler deployment fixed at one replica; final verification checks exactly one active scheduler |
