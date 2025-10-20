#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

echo ">> Fetch latest"
git fetch --all --prune
git reset --hard origin/main

echo ">> Build images (changed layers only)"
docker compose -f docker-compose.yml -f docker-compose.prod.yml build

echo ">> Up containers"
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

echo ">> Containers:"
docker compose ps

# ---- Health check (fail fast if not 200 JSON) ----
APP_HEALTH_URL="${APP_HEALTH_URL:-http://localhost:8686/api/health}"
echo ">> Health check: ${APP_HEALTH_URL}"

# Optional: small wait to let Octane boot
sleep 2

# Try up to 10 times with brief backoff
attempt=1
max_attempts=10
ok=0
while [ $attempt -le $max_attempts ]; do
  code=$(curl -s -o /tmp/health.json -w "%{http_code}" "$APP_HEALTH_URL" || true)
  ctype=$(curl -sI "$APP_HEALTH_URL" | awk -F': ' 'tolower($1)=="content-type"{print tolower($2)}' | tr -d '\r')
  if [ "$code" = "200" ] && echo "$ctype" | grep -q "application/json"; then
    ok=1
    break
  fi
  echo "   attempt $attempt/$max_attempts: code=$code, content-type=$ctype (retrying...)"
  sleep 1
  attempt=$((attempt+1))
done

if [ "$ok" != "1" ]; then
  echo "✖ Health check failed."
  echo "---- Body ----"
  cat /tmp/health.json || true
  echo "--------------"
  echo "Recent app logs (last 120 lines):"
  docker compose logs --no-color --tail=120 app || true
  exit 1
fi

echo "✔ OK: /api/health is healthy"
