
## Code Quality Review Findings (REJECTED)

1. **Dockerfile Anti-patterns**:
   - `apt-get update` is run twice (lines 4 and 23). The second one should be combined with the first or removed if the cache is still valid.
   - Installing `nodejs`, `npm`, and `yt-dlp` via default `apt-get` repositories often results in outdated versions, which can cause build or runtime issues (especially for `yt-dlp` which needs frequent updates as noted in the AGENTS.md: "keep yt-dlp updated on servers").

2. **Nginx Security**:
   - Missing basic security headers in `docker/nginx/default.conf` (e.g., `add_header X-Frame-Options "SAMEORIGIN";`, `add_header X-Content-Type-Options "nosniff";`).

3. **Setup Script Inconsistency**:
   - `docker-setup.sh` runs `npm install` and `npm run build` on the host machine (lines 16-17), assuming the host has Node.js installed. This contradicts the containerized approach where Node.js is installed in the `php` container. The `entrypoint.sh` already handles this inside the container, making the host execution redundant and potentially problematic if host/container versions mismatch.

4. **WebSocket Connection Failure**:
   - The frontend attempts to connect to Reverb via `ws://127.0.0.1:8080/app/laravel-herd`.
   - Nginx intercepts this request but does not have a proxy rule for `/app/` to route it to the `reverb` container (port 9000).
   - As a result, Nginx routes it to `index.php`, which returns a 302 redirect, causing the WebSocket connection to fail with `Unexpected response code: 302`.
