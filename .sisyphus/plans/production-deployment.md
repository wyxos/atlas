# Production Deployment & Authentik SSO Integration

## Goal

Deploy Atlas to the K3s Cloudhome cluster with Authentik SSO, hardened security, and all supporting infrastructure (MariaDB, Redis, Typesense, Reverb, Horizon).

## Open Decisions (user-confirmed before Phase 3)

| # | Decision | Default (proceed if no answer) | Impact if wrong |
|---|----------|-------------------------------|-----------------|
| D1 | Domain name for Atlas | `atlas.rustybret.com` | IngressRoute, Reverb WS host, APP_URL |
| D2 | Cloudflare Access in front of Traefik? | No (Authentik is sufficient) | Extra network hop, cookie conflicts |
| D3 | All users via Authentik, or local admin fallback? | Authentik-only + one local admin stored in K8s secret | Recovery access if Authentik is down |
| D4 | Auto-create users on first Authentik login? | Yes, map by email, assign `is_admin=false` | Orphan users if email mismatch |
| D5 | Extension auth strategy | Keep existing shared API key (no change) | Requires separate per-user token work later |
| D6 | PVC size for media storage | 100Gi (expandable) | Too small = resize; too large = waste |
| D7 | MariaDB: new K3s deployment or managed? | New MariaDB deployment in K3s (same as Docker) | Managed DB = cost, K3s = operational burden |
| D8 | First admin bootstrap | `app:setup` artisan command still works; first user created locally, then switch to Authentik | Need recovery path |

## Phases

### Phase 1: Security Hardening (parallel-safe, no K8s dependency)

**B1** — Restrict trusted proxies
- File: `bootstrap/app.php`
- Change `$middleware->trustProxies(at: '*')` → `$middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'])`
- Acceptance: `trustProxies` no longer accepts `*`; K8s internal CIDRs only

**B2** — Add login rate limiting
- File: `routes/web.php` — add `->middleware('throttle:6,1')` to login POST route
- File: `app/Http/Controllers/Auth/LoginController.php` — add `RateLimiter` check for email + IP
- Acceptance: 6+ failed login attempts within 1 minute returns 429

**B3** — Create production docker-compose override
- File: `docker-compose.prod.yml` (extends base, removes exposed ports)
- Remove `ports` from mariadb (3306), redis (6379), typesense (8108)
- Remove `phpmyadmin` service entirely
- Set strong DB passwords via env vars (not empty strings)
- Replace `TYPESENSE_API_KEY=xyz` with `${TYPESENSE_API_KEY}`
- Acceptance: `docker-compose -f docker-compose.yml -f docker-compose.prod.yml config` shows no exposed internal ports

**B4** — Externalize secrets in .env.docker
- Replace all hardcoded secrets with `${VAR}` references:
  - `DB_PASSWORD` → `${DB_PASSWORD}`
  - `TYPESENSE_API_KEY` → `${TYPESENSE_API_KEY}`
  - `REVERB_APP_SECRET` → `${REVERB_APP_SECRET}`
  - `REDIS_PASSWORD` → `${REDIS_PASSWORD}`
- Acceptance: No plaintext secrets in `.env.docker`; all reference env vars

**B5** — Generate and pin APP_KEY
- Add `APP_KEY=${APP_KEY}` to `.env.docker`
- Document: generate once with `php artisan key:generate --show`, store in K8s secret
- Acceptance: APP_KEY is never committed; injected via K8s secret at runtime

### Phase 2: K8s Manifests (parallel within phase, sequential after Phase 1)

**C1** — Create K8s directory structure
- Directory: `k8s/base/apps/atlas/`
- Files: `deployment.yaml`, `service.yaml`, `ingressroute.yaml`, `pvc.yaml`, `secrets.yaml`, `configmap.yaml`, `mariadb-deployment.yaml`, `mariadb-service.yaml`, `mariadb-pvc.yaml`, `redis-deployment.yaml`, `redis-service.yaml`, `typesense-deployment.yaml`, `typesense-service.yaml`, `reverb-deployment.yaml`, `reverb-service.yaml`, `horizon-deployment.yaml`, `scheduler-deployment.yaml`, `migration-job.yaml`

**C2** — Atlas Deployment manifest
- Image: built from `docker/php/Dockerfile`, pushed to OCIR
- Replicas: 2 (rolling update)
- Resources: 512Mi limit, 256Mi request
- Env vars from ConfigMap + Secrets
- Health check: HTTP GET `/up` on port 80
- Volume mount: PVC at `ATLAS_STORAGE` path
- Acceptance: `kubectl apply --dry-run=client -f deployment.yaml` succeeds

**C3** — Atlas Service + IngressRoute
- ClusterIP service on port 80
- Traefik IngressRoute with:
  - `authentik-forward-auth` middleware
  - TLS via cert-resolver (Let's Encrypt or cluster CA)
  - Host rule matching D1 domain
- Acceptance: `kubectl apply --dry-run=client -f service.yaml ingressroute.yaml` succeeds

**C4** — PVC for media storage
- StorageClass: local-path (or cluster default)
- Size: D6 default (100Gi)
- Mount: `/var/www/html/storage/app/atlas` (matches `ATLAS_STORAGE`)
- Acceptance: PVC binds successfully

**C5** — MariaDB K8s deployment
- Image: `mariadb:10.11`
- PVC: 20Gi for data
- Secrets: root password from K8s secret
- Internal Service (no LoadBalancer/NodePort)
- Acceptance: `mariadb-admin ping` health check passes

**C6** — Redis K8s deployment
- Image: `redis:7-alpine`
- Persistence: optional (cache-only is OK, session data is ephemeral)
- Internal Service
- Acceptance: `redis-cli ping` returns PONG

**C7** — Typesense K8s deployment
- Image: `typesense/typesense:27.0`
- PVC: 5Gi for data
- API key from K8s secret
- Internal Service
- Acceptance: `curl http://typesense:8108/health` returns `{"ok": true}`

**C8** — Reverb WebSocket deployment
- Same image as Atlas (php-fpm), different command: `php artisan reverb:start`
- Exposed via Traefik IngressRoute on `/app/` WebSocket path
- Host: D1 domain, WSS scheme
- Acceptance: WebSocket connection succeeds from browser

**C9** — Horizon + Scheduler deployments
- Horizon: same image, command `php artisan horizon`
- Scheduler: same image, command `["php", "artisan", "schedule:work"]`
- Both: 1 replica, same env vars as Atlas
- Acceptance: Horizon dashboard accessible at `/horizon`

**C10** — Database migration Job
- K8s Job: `php artisan migrate --force`
- Runs before Atlas deployment starts
- Acceptance: Job completes with exit 0; all tables created

**C11** — ConfigMap + Secrets
- ConfigMap: non-secret env vars (APP_ENV=production, APP_DEBUG=false, DB_HOST, REDIS_HOST, TYPESENSE_HOST, etc.)
- Secrets: APP_KEY, DB_PASSWORD, TYPESENSE_API_KEY, REVERB_APP_SECRET, SENTRY_LARAVEL_DSN, AUTHENTIK_CLIENT_SECRET
- ESO integration: annotate secrets for OCI Vault sync (optional, can be manual initially)
- Acceptance: `kubectl get secrets atlas-secrets` shows all keys present

### Phase 3: Authentik SSO Integration (sequential, after Phase 2)

**D1** — Install Laravel Socialite + Authentik provider
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
- Acceptance: `composer install` succeeds; no conflicts

**D2** — Configure Authentik application
- In Authentik admin: create Application + Provider (OIDC)
- Redirect URI: `https://{D1 domain}/auth/authentik/callback`
- Scopes: openid, email, profile
- Record: client_id, client_secret → store in K8s secret
- Acceptance: Authentik shows Atlas application in admin UI

**D3** — Implement Authentik login controller
- File: `app/Http/Controllers/Auth/AuthentikController.php`
- Methods: `redirect()` (→ Authentik), `callback()` (← Authentik)
- Callback: Socialite fetches user, finds or creates local User by email
- Auto-create: set `is_admin = false`, copy name/email from OIDC claims
- Acceptance: Login flow redirects to Authentik and back; user record created

**D4** — Update login route
- File: `routes/web.php`
- Add: `Route::get('/auth/authentik', [AuthentikController::class, 'redirect'])->name('auth.authentik');`
- Add: `Route::get('/auth/authentik/callback', [AuthentikController::class, 'callback']);`
- Keep local login route as admin fallback (gated by env var `AUTH_LOCAL_ENABLED`)
- Acceptance: `/login` page shows "Sign in with Authentik" button; local login hidden unless `AUTH_LOCAL_ENABLED=true`

**D5** — Update Vue frontend login page
- File: `resources/js/pages/Login.vue` (or equivalent auth page)
- Replace email/password form with Authentik redirect button
- Keep local login form behind `authLocalEnabled` flag (from `/api/auth/config` endpoint)
- Acceptance: Login page shows SSO button; local form hidden by default

**D6** — Protect Horizon with Authentik
- Ensure Horizon route uses `auth` middleware (already gated by `is_admin`)
- Admin assignment: first user manually set `is_admin=true` via `php artisan tinker`
- Acceptance: Non-admin users get 403 on `/horizon`; admin users see dashboard

**D7** — Update IngressRoute for forward-auth
- Traefik IngressRoute middleware: `authentik-forward-auth`
- This handles the redirect-to-Authentik flow for unauthenticated requests
- The Laravel OIDC flow handles the callback/session
- Acceptance: Unauthenticated request to Atlas redirects to Authentik login

### Phase 4: CI/CD & Verification

**E1** — Build & push pipeline
- GitHub Actions (or equivalent): on push to main
- Steps: build Docker image → push to OCIR → tag with git SHA
- Acceptance: Image appears in OCIR registry

**E2** — Deploy script
- Script: `k8s/deploy.sh` — updates deployment image tag, runs migration job
- Rolling update: `kubectl set image deployment/atlas atlas={OCIR}/atlas:{SHA}`
- Acceptance: New pods roll out without downtime; migration runs first

**E3** — End-to-end verification
- [ ] `kubectl get pods -l app=atlas` — all Running
- [ ] `curl -s https://{D1 domain}/up` — returns 200
- [ ] Authentik login flow succeeds (redirect → login → callback → dashboard)
- [ ] Browse page loads with items from CivitAI/Wallhaven
- [ ] Extension API key still works (`curl -H "X-Atlas-Api-Key: ..." /api/extension/ping`)
- [ ] Horizon dashboard accessible to admin
- [ ] WebSocket connections succeed (Reverb)
- [ ] `kubectl logs deployment/atlas` — no errors

**E4** — Rollback procedure
- `kubectl rollout undo deployment/atlas` — reverts to previous image
- If Authentik is down: set `AUTH_LOCAL_ENABLED=true` in ConfigMap, restart deployment
- Acceptance: Rollback completes within 60 seconds

## Parallelism Map

```
Phase 1: B1 | B2 | B3 | B4 | B5  (all parallel)
Phase 2: C1 → (C2, C3, C4, C5, C6, C7, C8, C9, C10, C11) (C1 first, then all parallel)
Phase 3: D1 → D2 → (D3, D4, D5) → D6 → D7  (sequential with some parallelism)
Phase 4: E1 | E2 (parallel) → E3 → E4
```

## Constraints

- Never commit secrets (APP_KEY, DB passwords, API keys, client secrets)
- All K8s manifests must pass `kubectl apply --dry-run=client`
- Keep local auth fallback behind env var gate (not deleted)
- Extension API key mechanism unchanged (per D5)
- No back-channel/headless OIDC flow — front-channel redirect only
- MariaDB only (no PostgreSQL switch)
- All manifests follow Cloudhome K8s conventions (see OpenCode deployment as reference)

## Risks

| Risk | Mitigation |
|------|------------|
| Authentik outage locks all users out | Local admin fallback via `AUTH_LOCAL_ENABLED` env var |
| Session storage lost (Redis restart) | Users re-authenticate via Authentik; no data loss |
| Typesense data corruption | PVC persists across pod restarts; rebuild from DB if needed |
| First deployment migration failure | Migration Job with `restartPolicy: OnFailure`; manual `app:setup` as backup |
| Reverb WebSocket not connecting through Traefik | Dedicated IngressRoute for WSS path; test with `wscat` |
| Image pull from OCIR fails | Use `imagePullSecrets` in deployment; verify OCIR credentials |
