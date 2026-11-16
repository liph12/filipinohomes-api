#!/bin/sh
# The ECS scheduler's command: runs `php artisan schedule:work`. When ECS stops the task
# (SIGTERM, on a deploy or an instance drain), it stops schedule:work so no new minute starts,
# then waits for the scheduled commands that are still running, so they aren't cut off mid-run.
# ECS kills whatever is left when the task definition's stopTimeout (120 seconds) runs out.
set -u

php artisan schedule:work &
scheduler=$!
trap 'kill -TERM "$scheduler" 2>/dev/null' TERM INT

# `wait` returns early when the trap runs, so wait again until schedule:work has really exited.
status=0
while kill -0 "$scheduler" 2>/dev/null; do
  wait "$scheduler"
  status=$?
done

# The commands schedule:work started outlive it. Wait while any artisan process is running.
artisan_running() {
  for cmdline in /proc/[0-9]*/cmdline; do
    case "$(tr '\0' ' ' 2>/dev/null < "$cmdline")" in
      *" artisan "*) return 0 ;;
    esac
  done
  return 1
}
while artisan_running; do
  sleep 1
done

exit "$status"
