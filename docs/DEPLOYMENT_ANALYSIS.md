# Atlas Production Deployment & Authentik SSO Integration

## 1. Atlas Application Analysis

**Architecture:** Atlas is a Laravel 12 application with a Vue 3 SPA frontend, designed as a media curation tool. It uses a service-based architecture for browsing external APIs (CivitAI, Wallhaven), Eloquent API Resources for structured responses, and a hybrid authentication approach (session-based for the web, potential for API tokens for the extension).

**Current State Assessment:**

- **`docker-compose.yml`**: Uses a multi-stage Dockerfile (`docker/php/Dockerfile`) and runs as `www-data`. However, it has several security issues: exposed database/Redis/Typesense ports, empty database passwords, hardcoded secrets (e.g., `TYPESENSE_API_KEY=xyz`), and `phpmyadmin` included.
- **`bootstrap/app.php`**: Uses `trustProxies('*')`, which is dangerous in production.
- **Authentication**: Currently relies on Laravel's default `LoginController` (email/password). No brute-force protection is implemented.
- **Environment**: `.env` file has `APP_ENV=local` and `APP_DEBUG=true`, which are unsuitable for production.
- **Database**: Currently configured to use `sqlite` in `.env.example`, while `.env` overrides to `mariadb`. The `docker-compose.yml` sets up a MariaDB container with empty passwords.
- **Frontend**: Vite build pipeline for the Vue SPA.

## 2. Cloudhome / K3s Deployment Strategy

Based on the Opencode deployment pattern, the recommended approach for Atlas is a Kubernetes-native deployment within the existing K3s cluster.

**Proposed K8s Structure (following Cloudhome conventions):**

- **`apps/atlas/`** in the Cloudhome `k8s/base/` directory.
- **Deployment**: A Docker image built from the `docker/php/Dockerfile` and pushed to the OCIR.
- **Service**: A `ClusterIP` service for the Atlas pods.
- **IngressRoute**: A Traefik `IngressRoute` using `authentik-forward-auth` middleware for SSO protection.
- **Persistent Storage**: A PVC for the actual media storage (`ATLAS_STORAGE`).
- **Database**: A dedicated MariaDB instance or a connection to the existing Authentik PostgreSQL if appropriate (not recommended). More likely, a new MariaDB deployment or a managed DB instance.
- **Secret Management**: K8s secrets for DB credentials and other secrets, with a preference for External Secret Operator (ESO) to sync from OCI Vault.

## 3. Production Hardening Requirements

Based on the security analysis, here are the critical items to address for production:

| Severity | Issue | Recommendation |
| :--- | :--- | :--- |
| **Critical** | Exposed internal ports (3306, 6379, 8108). | Remove `ports` from internal services in `docker-compose.yml`. |
| **Critical** | Empty database passwords (`MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes`). | Set strong passwords via K8s secrets. |
| **Critical** | Hardcoded secrets in `.env` and `docker-compose.yml`. | Externalize all secrets to K8s secrets or a vault. |
| **Critical** | `APP_DEBUG=true` & `APP_ENV=local`. | Set to `false` and `production` respectively. |
| **High** | `trustProxies('*')` in `bootstrap/app.php`. | Restrict to the K8s cluster internal IP ranges. |
| **High** | No brute-force protection on login. | Implement rate limiting. |
| **High** | `phpmyadmin` in production stack. | Remove from production `docker-compose.yml` or restrict access heavily. |
| **Medium** | `typesense` API key. | Use a proper secret, not `xyz`. |
| **Low** | `FETCH` dependency in `LoginController`. | No issues found. |

## 4. Authentik SSO Integration Requirements & Questions

The goal is to replace Laravel's default session-based authentication with Authentik (OIDC) for the web UI and potentially for the extension API.

**Reference Integration (OpenCode):**
The OpenCode deployment shows a clean OIDC integration:

- **Middleware**: `authentik-forward-auth` intercepts requests to `code.rustybret.com`.
- **Environment Variables**: `OPENCODE_OIDC_*` variables point to `https://auth.rustybret.com/application/o/opencode/`.
- **Secret**: The `OPENCODE_OIDC_CLIENT_SECRET` is pulled from a K8s secret (`opencode-oidc-secret`).

**Proposed Atlas Integration:**

- **Laravel Side**: Use a package like `socialiteproviders/authentik` or a generic OIDC package for Laravel Socialite to handle the OAuth2/OIDC flow.
- **Frontend (Vue)**: The SPA needs to be aware of the SSO flow. It will likely need to handle the redirect to Authentik and back, then establish a session with the Laravel backend.
- **Extension**: The browser extension needs a way to authenticate. It could use a Personal Access Token (PAT) or a short-lived token issued by Authentik. This is the most complex part.

## Questions

### 1. Authentik Scope & User Mapping

- Do you want **all Atlas users** to go through Authentik, or should there still be a local admin fallback?
- How should Atlas map Authentik users to its local `users` table? By `email`? Should Atlas auto-create users on first login, or should they be pre-provisioned?
- What Authentik provider should be used (e.g., Google, local users, both)?

### 2. Extension Authentication

- The extension currently uses API tokens (implied by `api/extension/*` routes). How should the extension authenticate with the Atlas backend when Authentik is enabled?
- Should the extension also go through the Authentik flow, or should it use a long-lived API token that you manually create in Atlas for each user?

### 3. K8s Deployment Details

- **Domain Name**: What will the public domain for Atlas be? (e.g., `atlas.rustybret.com`?)
- **Cloudflare Access**: Should Atlas be behind Cloudflare Access (like OpenCode), or directly accessible via the Traefik ingress? The current Cloudhome setup uses both (Cloudflare Access for the outer layer, Authentik for the app layer).
- **Persistent Storage**: How much storage do you anticipate needing for media files? This will determine the PVC size.
- **Database**: Do you want to provision a new MariaDB instance within K3s for Atlas, or do you have another database preference/plan?

### 4. SSO Flow Preference

- Do you want the **front-channel** login where the user is redirected to `auth.rustybret.com`, logs in, and is redirected back to Atlas?
- Or do you prefer a **back-channel** or **headless** flow where your own UI handles the Authentik interaction? (This is much more complex and generally not recommended for simplicity).