# Container image

This document describes the Docker image of the API, how a container starts, and how to test the image.

## Summary

- The file `Dockerfile` in the root of the repository builds the image.
- One image operates in all roles: web, worker, scheduler and artisan. The task definition sets the command.
- The image tag is the full commit SHA of the code.
- The image does not contain settings. The container gets them when it starts.

## Build stages

| Stage | Base | Function |
|---|---|---|
| `assets` | `node:22.23.3-bookworm-slim` | Runs `npm ci` and `npm run build`. Makes `public/build`. |
| `runtime` | `dunglas/frankenphp:1.13.4-php8.3-trixie` | Adds `ffmpeg`, `ghostscript` and the PHP extensions `pdo_mysql`, `gd`, `exif`, `zip`, `pcntl` and `redis`. |
| `vendor` | `runtime`, with Composer 2.10.3 | Runs `composer install --no-dev` and makes the optimized autoloader. |
| Final | `runtime` | Contains the code, the vendor folder and the frontend assets. |

The welcome page uses `@vite`. Without `public/build/manifest.json`, the route `/` gives the code 500.

The `Dockerfile` pins each base image by tag and by digest. Because of this, each commit always builds from the same base images. When you change a base image, change the tag and the digest together.

The base image is Debian 13 (trixie). The ECR scan found many critical and high vulnerabilities in the packages of Debian 12.

## Contents of the final image

| Item | Value |
|---|---|
| User | `app` (UID 1000). The image does not run as root. |
| Port | 8080 |
| Writable folders | `storage/` and `bootstrap/cache/` only. Root owns all other code. |
| Entrypoint | `filipinohomes-entrypoint` (`infra/docker/entrypoint.sh`) |
| Default command | `frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile` |
| Docker health check | None. The load balancer examines `/up` on the web containers. |
| `.env` file | None. `.dockerignore` keeps `.env` and `.env.*` out of the image. |

The file `.dockerignore` also keeps these items out of the image: `.git`, `.github`, `tests`, `infra/aws`, `storage`, `vendor`, `node_modules` and `public/build`.

### FrankenPHP

The file `infra/docker/Caddyfile` sets FrankenPHP to classic mode. Each HTTP request gets one PHP request. Octane is not used. FrankenPHP serves `public/` with HTTP on port 8080. The admin endpoint and automatic HTTPS are off. TLS stops at the load balancer or at Cloudflare.

### PHP settings

The file `infra/docker/php.ini` adds these settings to `php.ini-production`:

| Setting | Value | Reason |
|---|---|---|
| `expose_php` | `Off` | The response has no `X-Powered-By` header. |
| `memory_limit` | `512M` | GD must have this memory to process large images. |
| `upload_max_filesize` | `128M` | Uploads of up to about 100 MB |
| `post_max_size` | `130M` | Uploads of up to about 100 MB |
| `opcache.validate_timestamps` | `0` | The code does not change in a container. |

## Container start

The entrypoint does these steps:

1. If the variable `FILIPINOHOMES_ENV_JSON` has a value, the entrypoint runs `infra/docker/env-from-secret.php`. This script expands the JSON into one environment variable for each setting. It removes `FILIPINOHOMES_ENV_JSON`. Then it starts the entrypoint again.
2. The entrypoint runs `php artisan optimize`. This command makes the config, route, event and view caches.
3. The entrypoint replaces itself with the command of the container. The command keeps the same process ID.

The caches are made at start, not in the image. The config cache contains the settings, and the settings come from the task.

Rules of `env-from-secret.php`:

- If the container has a variable before this step, the variable stays. The value in the secret does not replace it. This is how the task definition replaces a setting, for example `LOG_CHANNEL=stderr`.
- `AWS_REGION` and `AWS_DEFAULT_REGION` are exceptions. The ECS agent sets them in each container. For these two variables, the value in the secret replaces the value of the agent.
- A name with no value (`null`) sets no variable.
- An error message shows the name of the setting. It never shows the value.

Refer to [settings.md](settings.md) for the secret.

## Scheduler

The scheduler container runs `filipinohomes-scheduler` (`infra/docker/scheduler.sh`).

1. The script starts `php artisan schedule:work`.
2. When ECS stops the task, the container receives SIGTERM.
3. The script stops `schedule:work`. No new minute starts.
4. The script waits until no `artisan` process runs. A scheduled command can then finish its work.
5. After 120 seconds (`StopTimeout`), ECS stops all processes that remain.

### Output of the scheduled commands

By default, Laravel sends the output of each scheduled command to `/dev/null`. On ECS, the output goes to the log of the container:

1. The scheduler task sets `LOG_SCHEDULE_OUTPUT=/proc/1/fd/1`.
2. `config/logging.php` gives this value as `logging.schedule_output`.
3. The last block of `routes/console.php` adds `appendOutputTo()` to each scheduled event.

> [!CAUTION]
> The block that uses `logging.schedule_output` must stay at the end of `routes/console.php`. It applies only to the events above it. After a merge from upstream, make sure that it is still the last block.

The shell writes this output, not Monolog. The PHP of FrankenPHP is a ZTS build, and it cannot open the links in `/proc/*/fd/*`.

## Procedure: build and test the image on a workstation

1. Build the image from the root of the repository:

   ```bash
   docker build --platform linux/amd64 --provenance=false -t filipinohomes/api:local .
   ```

2. Run the smoke test:

   ```bash
   tests/docker/smoke-test.sh filipinohomes/api:local
   ```

3. Make sure that the last line of the output does not start with `FAIL`.

The smoke test does not use a database. `DB_HOST` is `127.0.0.1` in the container, and no database operates there. Only the web container has a network. The test examines these items:

- The PHP extensions, `ffmpeg` and `ghostscript` are in the image.
- The image does not run as root, and it contains no `.env` file.
- `/up`, `/` and `/build/manifest.json` give the code 200. `/.env` gives the code 404.
- The response has no `X-Powered-By` header.
- `php artisan schedule:list` shows the scheduled commands.
- The settings from one JSON variable arrive correctly. The secret value of `AWS_DEFAULT_REGION` replaces the value of the ECS agent.
- The output and the errors of a scheduled command go to the log of the container.
- When the scheduler stops, it waits for a command that still runs.
- `ffmpeg`, `ghostscript` and GD operate correctly through the code of the application.

> [!CAUTION]
> Do not run `php artisan test` or Pest against the LR database. Tests with `RefreshDatabase` delete all data in the tables.

## Vulnerability scans

ECR scans each image when CI pushes it. Most critical and high findings are in the package `linux`. These are the kernel headers of the compiler toolchain in the base image. The team decided to keep the compilers in the image. When you read a scan result, ignore the findings for the package `linux`.
