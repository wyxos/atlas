#!/usr/bin/env bash
set -euo pipefail

# Atlas v2 installer (Linux/macOS/WSL)
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/wyxos/atlas/main/install.sh | bash
# Optional env:
#   ATLAS_DIR=Atlas   (target directory)
#   ATLAS_REF=main    (branch/tag/sha)

REPO_URL="${ATLAS_REPO_URL:-https://github.com/wyxos/atlas.git}"
ATLAS_DIR="${ATLAS_DIR:-Atlas}"
ATLAS_REF="${ATLAS_REF:-main}"

# Non-interactive setup (useful for CI)
# If these are set, we will pass them to app:setup via options.
ATLAS_SETUP_NAME="${ATLAS_SETUP_NAME:-}"
ATLAS_SETUP_EMAIL="${ATLAS_SETUP_EMAIL:-}"
ATLAS_SETUP_PASSWORD="${ATLAS_SETUP_PASSWORD:-}"

need() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1" >&2
    exit 1
  }
}

need git
need curl

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required. Install Docker (Docker Desktop on Windows/WSL, or Docker Engine on Linux), then re-run." >&2
  echo "https://www.docker.com/products/docker-desktop/" >&2
  exit 1
fi

if [[ -e "$ATLAS_DIR" ]] && [[ ! -d "$ATLAS_DIR" ]]; then
  echo "ATLAS_DIR exists and is not a directory: $ATLAS_DIR" >&2
  exit 1
fi

if [[ ! -d "$ATLAS_DIR/.git" ]]; then
  echo "Cloning Atlas into: $ATLAS_DIR"
  if [[ "$REPO_URL" == file://* ]]; then
    git clone "$REPO_URL" "$ATLAS_DIR"
    (cd "$ATLAS_DIR" && git checkout -q "$ATLAS_REF")
  else
    git clone --depth 1 --branch "$ATLAS_REF" "$REPO_URL" "$ATLAS_DIR"
  fi
else
  echo "Using existing repo at: $ATLAS_DIR"
fi

cd "$ATLAS_DIR"

# If this installer script is being run from a different ref than the repo, keep it simple:
# just run the repo's setup script.
if [[ ! -x "export ATLAS_ENV_AUTO=1
export ATLAS_SETUP_NAME ATLAS_SETUP_EMAIL ATLAS_SETUP_PASSWORD

./scripts/setup.sh" ]]; then
  echo "Missing export ATLAS_ENV_AUTO=1
export ATLAS_SETUP_NAME ATLAS_SETUP_EMAIL ATLAS_SETUP_PASSWORD

./scripts/setup.sh in repo. Are you on the right branch/tag?" >&2
  exit 1
fi

export ATLAS_ENV_AUTO=1
export ATLAS_SETUP_NAME ATLAS_SETUP_EMAIL ATLAS_SETUP_PASSWORD

./scripts/setup.sh
