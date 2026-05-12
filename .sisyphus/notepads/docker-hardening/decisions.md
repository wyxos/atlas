# Architectural Decisions: Docker Hardening

## Media Serving
- **Decision**: Implement `X-Accel-Redirect` for all protected media serving.
- **Rationale**: Offloads heavy I/O to Nginx while maintaining Laravel-based authentication and authorization.
- **Implementation**:
    - Nginx: Define `location /internal-media/ { internal; alias /storage/app/public/; }`.
    - Laravel: `FileStorageResponseService` will return a response with `X-Accel-Redirect: /internal-media/path/to/file`.

## PHP Configuration
- **Decision**: Disable OPcache timestamp validation (`opcache.validate_timestamps=0`).
- **Rationale**: Optimized for immutable production images where code does not change at runtime.
- **Decision**: Enforce strict PHP-FPM pool settings (`clear_env=yes`, `security.limit_extensions=.php`).
- **Rationale**: Minimizes attack surface and prevents environment variable leakage.

## Container Security
- **Decision**: Use non-root users for both Nginx and PHP-FPM processes.
- **Rationale**: Follows the principle of least privilege and aligns with `cap_drop ALL` and `no-new-privileges` constraints.

