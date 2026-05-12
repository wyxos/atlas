#!/bin/bash
# k8s/deploy.sh - Deploy Atlas to K3s cluster
# Usage: ./k8s/deploy.sh [namespace]

set -e

NAMESPACE="${1:-apps}"
TIMEOUT="${2:-300}"

echo "=== Atlas K8s Deployment ==="
echo "Namespace: $NAMESPACE"

# Check kubectl connectivity
echo "=== Checking cluster connectivity ==="
kubectl cluster-info || { echo "FAIL: Cannot connect to cluster"; exit 1; }

# Apply ConfigMap and Secrets first
echo "=== Applying ConfigMap and Secrets ==="
kubectl apply -f k8s/base/apps/atlas/configmap.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/secrets.yaml -n $NAMESPACE

# Wait for secrets to be applied
sleep 2

# Run database migration
echo "=== Running database migration ==="
kubectl apply -f k8s/base/apps/atlas/migration-job.yaml -n $NAMESPACE
kubectl wait --for=condition=complete job/atlas-migration -n $NAMESPACE --timeout=$TIMEOUT"s" || {
    echo "FAIL: Migration job failed"
    kubectl logs job/atlas-migration -n $NAMESPACE
    exit 1
}

# Bootstrap admin (optional - only if needed)
echo "=== Bootstrap admin (optional) ==="
kubectl apply -f k8s/base/apps/atlas/bootstrap-admin-job.yaml -n $NAMESPACE || true
kubectl wait --for=condition=complete job/atlas-bootstrap-admin -n $NAMESPACE --timeout=60s" || true

# Deploy infrastructure services
echo "=== Deploying MariaDB ==="
kubectl apply -f k8s/base/apps/atlas/mariadb-pvc.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/mariadb-service.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/mariadb-deployment.yaml -n $NAMESPACE

echo "=== Deploying Redis ==="
kubectl apply -f k8s/base/apps/atlas/redis-service.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/redis-deployment.yaml -n $NAMESPACE

echo "=== Deploying Typesense ==="
kubectl apply -f k8s/base/apps/atlas/typesense-pvc.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/typesense-service.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/typesense-deployment.yaml -n $NAMESPACE

# Wait for infrastructure
echo "=== Waiting for infrastructure services ==="
kubectl wait --for=condition=available deployment/atlas-mariadb -n $NAMESPACE --timeout=120s" || true
kubectl wait --for=condition=available deployment/atlas-redis -n $NAMESPACE --timeout=60s" || true
kubectl wait --for=condition=available deployment/atlas-typesense -n $NAMESPACE --timeout=120s" || true

# Deploy worker services
echo "=== Deploying Reverb ==="
kubectl apply -f k8s/base/apps/atlas/reverb-service.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/reverb-deployment.yaml -n $NAMESPACE

echo "=== Deploying Horizon ==="
kubectl apply -f k8s/base/apps/atlas/horizon-deployment.yaml -n $NAMESPACE

echo "=== Deploying Scheduler ==="
kubectl apply -f k8s/base/apps/atlas/scheduler-deployment.yaml -n $NAMESPACE

# Deploy web (nginx + php-fpm)
echo "=== Deploying Atlas Web ==="
kubectl apply -f k8s/base/apps/atlas/pvc.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/web-service.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/nginx-configmap.yaml -n $NAMESPACE
kubectl apply -f k8s/base/apps/atlas/web-deployment.yaml -n $NAMESPACE

# Deploy IngressRoute
echo "=== Deploying IngressRoute ==="
kubectl apply -f k8s/base/apps/atlas/ingressroute.yaml -n $NAMESPACE

# Wait for web rollout
echo "=== Waiting for web deployment rollout ==="
kubectl rollout status deployment/atlas-web -n $NAMESPACE --timeout=$TIMEOUT"s" || {
    echo "FAIL: Web deployment failed"
    kubectl describe deployment atlas-web -n $NAMESPACE
    kubectl logs -l app=atlas-web -n $NAMESPACE
    exit 1
}

echo ""
echo "=== Deployment complete! ==="
echo ""
echo "Pod status:"
kubectl get pods -n $NAMESPACE -l app=atlas

echo ""
echo "To check logs: kubectl logs -n $NAMESPACE -l app=atlas-web"
echo "To restart: kubectl rollout restart deployment/atlas-web -n $NAMESPACE"
echo "To rollback: kubectl rollout undo deployment/atlas-web -n $NAMESPACE"