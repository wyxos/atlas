#!/usr/bin/env bash
set -euo pipefail

env_file=".env"

if [[ ! -f "$env_file" ]]; then
  cp .env.example "$env_file"
fi

AUTO=${ATLAS_ENV_AUTO:-0}

prompt() {
  local label="$1"
  local default="$2"
  local value
  if [[ "$AUTO" == "1" ]] || [[ ! -t 0 ]]; then
    value=""
  else
    read -r -p "$label [$default]: " value
  fi
  if [[ -z "$value" ]]; then
    value="$default"
  fi
  printf "%s" "$value"
}

set_env() {
  local key="$1"
  local value="$2"
  local tmp="${env_file}.tmp"
  awk -v key="$key" -v val="$value" '
    BEGIN { found=0 }
    $0 ~ "^"key"=" { print key"="val; found=1; next }
    { print }
    END { if (!found) print key"="val }
  ' "$env_file" > "$tmp"
  mv "$tmp" "$env_file"
}

echo "Atlas .env setup"
echo "Press Enter to accept defaults."
echo

app_url=$(prompt "APP_URL" "http://localhost:6363")
db_connection=$(prompt "DB_CONNECTION" "mariadb")
db_host=$(prompt "DB_HOST" "db")
db_port=$(prompt "DB_PORT" "3306")
db_database=$(prompt "DB_DATABASE" "atlas")
db_username=$(prompt "DB_USERNAME" "atlas")
db_password=$(prompt "DB_PASSWORD" "atlas")
redis_host=$(prompt "REDIS_HOST" "redis")
scout_driver=$(prompt "SCOUT_DRIVER" "typesense")
typesense_host=$(prompt "TYPESENSE_HOST" "typesense")
typesense_port=$(prompt "TYPESENSE_PORT" "8108")
typesense_api_key=$(prompt "TYPESENSE_API_KEY" "typesense")
ffmpeg_path=$(prompt "DOWNLOADS_FFMPEG_PATH" "ffmpeg")
atlas_storage=$(prompt "ATLAS_STORAGE" "/data/atlas")
reverb_host=$(prompt "REVERB_HOST" "localhost")
reverb_port=$(prompt "REVERB_PORT" "6364")
reverb_scheme=$(prompt "REVERB_SCHEME" "http")

set_env "APP_URL" "$app_url"
set_env "DB_CONNECTION" "$db_connection"
set_env "DB_HOST" "$db_host"
set_env "DB_PORT" "$db_port"
set_env "DB_DATABASE" "$db_database"
set_env "DB_USERNAME" "$db_username"
set_env "DB_PASSWORD" "$db_password"
set_env "REDIS_HOST" "$redis_host"
set_env "SCOUT_DRIVER" "$scout_driver"
set_env "TYPESENSE_HOST" "$typesense_host"
set_env "TYPESENSE_PORT" "$typesense_port"
set_env "TYPESENSE_API_KEY" "$typesense_api_key"
set_env "DOWNLOADS_FFMPEG_PATH" "$ffmpeg_path"
set_env "ATLAS_STORAGE" "$atlas_storage"
set_env "REVERB_HOST" "$reverb_host"
set_env "REVERB_PORT" "$reverb_port"
set_env "REVERB_SCHEME" "$reverb_scheme"
set_env "QUEUE_CONNECTION" "redis"
set_env "CACHE_STORE" "redis"

echo
echo "Updated $env_file"
