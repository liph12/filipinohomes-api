#!/bin/sh
# Entrypoint for every role (web, worker, scheduler, one-off artisan commands). Laravel's config,
# route, event and view caches are built here at start rather than in the image, because the
# config cache captures the settings and secrets the ECS task starts with.
set -e

# On ECS the production settings arrive as one JSON variable. Start over with them expanded into
# separate environment variables (env-from-secret.php removes FILIPINOHOMES_ENV_JSON, so this
# runs once).
if [ -n "${FILIPINOHOMES_ENV_JSON:-}" ]; then
  exec php /usr/local/lib/filipinohomes/env-from-secret.php /usr/local/bin/filipinohomes-entrypoint "$@"
fi

php artisan optimize

exec "$@"
