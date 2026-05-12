# Production Deployment - Decisions

## Open Decisions (from plan)
- D1: Domain = atlas.rustybret.com (default)
- D2: No Cloudflare Access (default)
- D3: Authentik-only + one local admin in K8s secret (default)
- D4: Auto-create users on first Authentik login (default)
- D5: Keep existing shared API key for extension (default)
- D6: PVC size 100Gi (default)
- D7: New MariaDB deployment in K3s (default)
- D8: First admin via app:setup, then switch to Authentik (default)
