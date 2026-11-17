# Queue backlog

This document describes the jobs that wait in the queue, their cause, and the actions that correct the problem.

> [!CAUTION]
> **Immediate action is necessary.**
>
> - Since 2026-10-31, no worker processes the queue. Users do not get message notifications and inquiry review notifications.
> - The queue increases by about 41,000 jobs each day.
>
> Refer to [Immediate actions](#immediate-actions).

## Status

Measured on 2026-11-17 at 02:29 UTC, with a read-only query:

| Item | Value |
|---|---|
| Jobs in the table `jobs` | 629,497, all on the queue `default` |
| Oldest job | 2026-10-31 19:01 UTC |
| Increase | About 41,000 jobs each day. The last 30,000 jobs arrived in 17 hours and 23 minutes. |
| `App\Jobs\PingIndexNow` | About 99.8 % of the jobs |
| `App\Jobs\SendMessageNotification` and `App\Jobs\SendInquiryReviewNotification` | About 0.1 % to 0.2 % of the jobs, or about 800 jobs. This is an estimate from two samples of 5,000 jobs. |
| Failed jobs | 51. The newest is from 2026-10-26. |
| Previous measurement | 596,086 jobs on 2026-11-16 at about 07:15 UTC |

The oldest job did not change between the two measurements. No worker processed a job in that period.

## Cause

1. A client opens a listing page.
2. `ListingController` increments the column `clicks` of the listing with `$found->increment('clicks')` (`app/Http/Controllers/ListingController.php`, line 315). It does this one time for each device, listing and day.
3. In Laravel 12, `increment()` fires the model event `updated`.
4. The `updated` hook of the `Listing` model (`app/Models/Listing.php`, line 256) dispatches one `PingIndexNow` job for each update. The hook does not examine which column changed.
5. As a result, each counted listing view adds one job to the queue.

Each `PingIndexNow` job sends the URL of the listing to the IndexNow endpoint of the frontend. IndexNow is for pages with changed content. A view does not change the page.

The same code also writes the table `listing_view_days`. Its daily totals agree with the increase of the queue:

| Date (Asia/Manila) | Counted listing views |
|---|---|
| 2026-11-09 | 47,993 |
| 2026-11-10 | 38,480 |
| 2026-11-11 | 44,326 |
| 2026-11-12 | 34,825 |
| 2026-11-13 | 40,818 |
| 2026-11-14 | 35,930 |
| 2026-11-15 | 39,336 |
| 2026-11-16 | 36,300 |

## Effects

- **Users:** The application did not send message notifications and inquiry review notifications after 2026-10-31. This problem occurs now, on the current EC2 server.
- **Database:** The table `jobs` in the shared LR database increases by about 41,000 rows each day.
- **Worker start:** If a worker starts with no change, it sends about 629,000 POST requests to the frontend for pages that did not change. It also sends the old notifications.

## Immediate actions

Do the actions in this sequence.

### 1. Stop the cause

Change line 315 of `app/Http/Controllers/ListingController.php`:

```php
// Before
$found->increment('clicks');

// After
$found->incrementQuietly('clicks');
```

`incrementQuietly()` increments the column and fires no model events. No other code uses the `updated` event for `clicks`. The column is not in `AGENT_CARD_COLUMNS`, and no observer examines it.

This change is in the application code. Make it in upstream (`liph12/filipinohomes-api`). Then deploy it to the server that gets the live traffic. Until then, the queue continues to increase.

### 2. Decide about the old notifications

The old notifications are 2 to 17 days old. The team must select one of these options:

- Send them late.
- Remove them.

### 3. Remove the old `PingIndexNow` jobs

> [!CAUTION]
> The table `jobs` is in the shared LR database. Get the approval of the database owner before you remove rows. Show the number of rows and the exact SQL first.

| Method | Description | Advantages | Disadvantages |
|---|---|---|---|
| A (recommended) | Delete the `PingIndexNow` rows with SQL, in batches | Fast. Low load on the database. The notifications stay for step 2. | Direct SQL on the production database |
| B | Run a one-off worker with `INDEXNOW_ENABLED=false`. The `PingIndexNow` jobs then do nothing. | Uses the application code | Also sends the old notifications. Processes about 629,000 jobs one at a time, with load on the shared database for many hours. |

Rules for method A:

- Before you start, write down the highest `id` in the table `jobs`. Do not delete rows above this `id`.
- Delete only the rows that have the display name `App\Jobs\PingIndexNow` in their payload.
- Delete in small batches, for example 5,000 rows.
- After the last batch, measure the queue again. Refer to [measure the queue](#procedure-measure-the-queue).

Overrides for method B, in the procedure [run a one-off artisan command](operations.md#procedure-run-a-one-off-artisan-command):

| Tool | Command | Environment variable |
|---|---|---|
| AWS console | **Command override:** `php,artisan,queue:work,--queue=default,--stop-when-empty,--memory=384` | Key `INDEXNOW_ENABLED`, value `false` |
| AWS CLI | `--overrides '{"containerOverrides":[{"name":"artisan","command":["php","artisan","queue:work","--queue=default","--stop-when-empty","--memory=384"],"environment":[{"name":"INDEXNOW_ENABLED","value":"false"}]}]}'` | In the override |

If the task stops before the queue is empty, start it again.

### 4. Start the worker

The table `jobs` is in the shared database. A worker on ECS also processes the jobs that the current EC2 server adds. For this reason, you can start the worker before the live traffic moves to ECS. This step sends the notifications to users again.

1. Do actions 1 to 3 first.
2. Set `WorkerCount=1` with the AWS console or the AWS CLI. Refer to [operations.md](operations.md#procedure-change-the-number-of-tasks).
3. Read the worker log. Refer to [operations.md](operations.md#procedure-read-the-logs).
4. Measure the queue again. The oldest job must be from the last minutes.

> [!NOTE]
> Two workers can operate at the same time. Laravel locks each job, and only one worker gets it.

## Open questions

- Where did the old worker operate, and why did it stop on 2026-10-31?
- Who on the team decides about the old notifications?

## Procedure: measure the queue

This procedure reads data only. The query shows the number of jobs and the time of the oldest job. The output is similar to `629766 jobs; oldest 2026-10-31T19:01:57+00:00` (the times are in UTC).

### AWS console

1. Do the AWS console part of [run a one-off artisan command](operations.md#procedure-run-a-one-off-artisan-command) with these overrides:
   - **Command override:**

     ```text
     php,artisan,tinker,--execute=echo DB::table('jobs')->count().' jobs; oldest '.now()->setTimestamp(DB::table('jobs')->orderBy('id')->value('created_at'))->toIso8601String().PHP_EOL;
     ```

   - **Environment variables:** key `XDG_CONFIG_HOME`, value `/tmp`
2. Read the output on the **Logs** tab of the task.

### AWS CLI

1. Start the artisan task with the Tinker query:

   ```bash
   TASK=$(aws ecs run-task --region ap-southeast-1 --cluster filipinohomes-ecs \
     --task-definition filipinohomes-artisan \
     --overrides '{"containerOverrides":[{"name":"artisan",
       "command":["php","artisan","tinker","--execute=echo DB::table(\"jobs\")->count().\" jobs; oldest \".now()->setTimestamp(DB::table(\"jobs\")->orderBy(\"id\")->value(\"created_at\"))->toIso8601String().PHP_EOL;"],
       "environment":[{"name":"XDG_CONFIG_HOME","value":"/tmp"}]}]}' \
     --query "tasks[0].taskArn" --output text)
   ```

2. Do steps 3 to 5 of the AWS CLI part of [run a one-off artisan command](operations.md#procedure-run-a-one-off-artisan-command).
