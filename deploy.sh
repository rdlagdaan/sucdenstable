#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

COMPOSE_ARGS="-f docker-compose.yml -f docker-compose.prod.yml"

log() { printf '\n>> %s\n' "$*"; }

# 0) Optional env knobs (can be overridden when calling the script)
: "${SKIP_FETCH:=0}"
: "${APP_HEALTH_URLS:=http://localhost:3001/api/health http://localhost:8686/api/health}"
: "${HEALTH_MAX_ATTEMPTS:=10}"
: "${HEALTH_SLEEP_SECONDS:=1}"

# 1) Fetch latest (tolerate offline)
log "Fetch latest"
if [ "$SKIP_FETCH" = "1" ]; then
  echo "SKIP_FETCH=1 set; skipping git fetch/reset."
else
  if git ls-remote --exit-code origin &>/dev/null; then
    git fetch --all --prune
    git reset --hard origin/main
  else
    echo "Remote 'origin' not reachable; deploying local working tree (offline mode)."
  fi
fi

# 2) Build images
log "Build images (changed layers only)"
docker compose $COMPOSE_ARGS build

# 3) Up containers
log "Up containers"
docker compose $COMPOSE_ARGS up -d

# 4) Show state
log "Containers:"
docker compose $COMPOSE_ARGS ps

# 5) Health check (frontend proxy first, then app direct)
attempt=1
ok=0
tmp_body="/tmp/health.json"

log "Health check targets: $APP_HEALTH_URLS"
sleep 2  # small grace period for Octane to boot

while [ $attempt -le "$HEALTH_MAX_ATTEMPTS" ] && [ $ok -eq 0 ]; do
  for url in $APP_HEALTH_URLS; do
    code=$(curl -s -o "$tmp_body" -w "%{http_code}" "$url" || true)
    ctype=$(curl -sI "$url" | awk -F': ' 'tolower($1)=="content-type"{print tolower($2)}' | tr -d '\r')
    if [ "$code" = "200" ] && echo "$ctype" | grep -q "application/json"; then
      ok=1
      good_url="$url"
      break
    fi
  done
  if [ $ok -eq 0 ]; then
    echo "   attempt $attempt/$HEALTH_MAX_ATTEMPTS: last_code=${code:-n/a}, content-type=${ctype:-n/a} (retrying...)"
    sleep "$HEALTH_SLEEP_SECONDS"
    attempt=$((attempt+1))
  fi
done

if [ $ok -ne 1 ]; then
  echo "✖ Health check failed for all URLs: $APP_HEALTH_URLS"
  echo "---- Body (last attempt) ----"
  cat "$tmp_body" || true
  echo "-----------------------------"
  echo "Recent app logs (last 120 lines):"
  docker compose $COMPOSE_ARGS logs --no-color --tail=120 app || true
  echo
  echo "Recent frontend logs (last 120 lines):"
  docker compose $COMPOSE_ARGS logs --no-color --tail=120 frontend || true
  exit 1
fi

echo "✔ OK: $good_url is healthy"
