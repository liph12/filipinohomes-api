#!/usr/bin/env bash
# Tests a built image without any database. Settings are dummies, and DB_HOST is the container's
# own loopback, where nothing listens. Every container except the web one also runs with no
# network at all.
# Usage: tests/docker/smoke-test.sh <image>
set -euo pipefail

image="$1"
here="$(cd "$(dirname "$0")" && pwd)"
base="http://localhost:18080"
envs=(
  -e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
  -e APP_ENV=production -e APP_DEBUG=false -e LOG_CHANNEL=stderr
  -e DB_CONNECTION=mysql -e DB_HOST=127.0.0.1 -e DB_PORT=3306
  -e SESSION_DRIVER=array
)

fail() { echo "FAIL: $*"; docker logs fh-smoke 2>&1 | tail -50 || true; exit 1; }
trap 'docker rm -f fh-smoke >/dev/null 2>&1 || true' EXIT
# HTTP status of a path, or 000 if the request fails or takes longer than 10 seconds.
status() { curl -s -o /dev/null --max-time 10 -w '%{http_code}' "$base$1" || true; }

echo "--- PHP extensions and binaries"
docker run --rm --network none --entrypoint sh "$image" -ec '
  for e in pdo_mysql gd exif zip pcntl redis "Zend OPcache"; do
    php -m | grep -qx "$e" || { echo "missing PHP extension: $e"; exit 1; }
  done
  ffmpeg -version >/dev/null || { echo "ffmpeg is missing"; exit 1; }
  gs --version >/dev/null || { echo "ghostscript is missing"; exit 1; }
  [ "$(id -u)" != 0 ] || { echo "runs as root"; exit 1; }
  [ ! -e /app/.env ] || { echo "/app/.env is in the image"; exit 1; }
  echo "ok extensions, ffmpeg, ghostscript, non-root, no .env"' || fail "image contents check"

echo "--- web"
docker run -d --name fh-smoke -p 18080:8080 "${envs[@]}" "$image" >/dev/null
for i in $(seq 1 30); do
  [ "$(docker inspect -f '{{.State.Running}}' fh-smoke)" = true ] || fail "container stopped during startup"
  [ "$(status /up)" = 200 ] && break
  [ "$i" -lt 30 ] || fail "/up not ready after 30 seconds"
  sleep 1
done
expect() {
  local got
  got=$(status "$1")
  [ "$got" = "$2" ] || fail "$1 returned $got, expected $2"
  echo "ok $1 -> $got"
}
expect /up 200
expect / 200
expect /build/manifest.json 200
expect /.env 404
headers=$(curl -sS --max-time 10 -I "$base/up") || fail "HEAD /up failed"
if grep -qi '^x-powered-by' <<<"$headers"; then fail "X-Powered-By header is sent"; fi
echo "ok no X-Powered-By header"

echo "--- scheduler"
list=$(docker run --rm --network none "${envs[@]}" -e CACHE_STORE=array "$image" php artisan schedule:list) \
  || fail "schedule:list failed"
count=$(grep -c 'artisan' <<<"$list" || true)
[ "$count" -gt 0 ] || fail "schedule:list shows no commands"
echo "ok scheduler lists $count commands"

echo "--- ffmpeg video, ghostscript PDF and GD images, through the app's own code"
docker run --rm --network none "${envs[@]}" -v "$here/media-test.php:/tmp/media-test.php:ro" "$image" \
  php /tmp/media-test.php || fail "media test"
