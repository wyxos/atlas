# Production Deployment - Learnings

## 2026-05-12 Session Start
- Plan has 4 phases: Security Hardening (B1-B5), K8s Manifests (C1-C11), Authentik SSO (D1-D7), CI/CD & Verification (E1-E4)
- Phase 1 tasks (B1-B5) are all parallel-safe with no K8s dependency
- Phase 2 depends on Phase 1 completion
- Phase 3 depends on Phase 2 completion
- Phase 4 depends on Phase 3 completion
- Multiple duplicate boulder work entries exist in boulder.json (production-deployment-43d62ed7, adde95f4, 08284ae1) - only production-deployment-6b44d98e is the active one

## K8s Directory Structure (Phase 2 - K1)
- Created `k8s/base/apps/atlas/` with 21 placeholder YAML files
- All files use minimal valid YAML structure with `# TODO: fill in spec` comments
- Files cover: web, mariadb, redis, typesense, reverb, horizon, scheduler, migration, bootstrap
- Pattern: each file has apiVersion, kind, metadata.name, metadata.namespace, and spec placeholder
