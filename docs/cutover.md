# Cutover

This document gives the status of the move to ECS and the steps that remain. When the cutover is complete, remove this document or mark it as complete.

## Status on 2026-11-17

| Item | Status |
|---|---|
| Network, ECR, secret, CI roles | Deployed |
| Load balancer, cluster, services | Deployed. 2 of 2 web tasks are healthy. HTTP only. |
| Worker and scheduler on ECS | 0 tasks |
| Database access from ECS | Correct. `migrate:status` operated on 2026-11-16. |
| Live traffic | Goes to the current EC2 server (`API_filipinohomes_official_laravel`) |
| ACM certificate for `api.filipinohomes.com` | Requested on 2026-11-16. The validation record is not in Cloudflare. |
| GitHub secret `AWS_DEPLOY_ROLE_ARN` | Not set |

The ECS tasks and the current server use the same database. Both can serve requests at the same time. The DNS record in Cloudflare selects the server that gets the traffic.

## Steps

Do the steps in this sequence.

### 1. Validate the certificate

> [!CAUTION]
> The deadline is about 2026-11-19 13:31 (UTC+8), 72 hours after the request. After the deadline, you must request a new certificate.

Do steps 1 to 6 of [add HTTPS](load-balancer.md#procedure-add-https).

### 2. Set the GitHub secret for the deployment

1. Set `AWS_DEPLOY_ROLE_ARN`. Refer to [ci-cd.md](ci-cd.md#procedure-set-a-github-secret).
2. Push `main` to `origin`.

Without this secret, each push to `main` builds and pushes an image, but the job `deploy` fails.

### 3. Merge the upstream changes

Do the procedure [merge the upstream changes into the fork](ci-cd.md#procedure-merge-the-upstream-changes-into-the-fork).

The merge of 2026-11-16 added 6 migrations. The image on ECS on 2026-11-17 does not contain them. The next deployment examines them. If they are pending, do the procedure [run pending migrations](releases.md#procedure-run-pending-migrations).

### 4. Add HTTPS to the load balancer

Do steps 7 to 9 of [add HTTPS](load-balancer.md#procedure-add-https). Keep `HttpAction=forward`.

### 5. Decide about the queue

> [!CAUTION]
> Do not start the worker before this decision. Do not delete jobs before this decision.

Measured on 2026-11-16 at about 07:15 UTC:

- The table `jobs` had 596,086 jobs, all on the queue `default`.
- The oldest job was from 2026-10-31 19:01 UTC.
- The number increased by about 30,000 jobs each day.
- About 99.9 % of the jobs were `App\Jobs\PingIndexNow`. The other jobs were `SendMessageNotification` and `SendInquiryReviewNotification`.
- The table `failed_jobs` had 51 rows. The newest was from 2026-10-26.

A worker operated until about 2026-10-26 to 2026-10-31, and then it stopped.

The `Listing` model dispatches `PingIndexNow` on each `updated` event. The `Project` and `Agent` code also dispatch it. Each job sends a POST request to `INDEXNOW_SUBMIT_URL`, an endpoint of the Filipino Homes frontend. If `INDEXNOW_ENABLED` is `false`, the job stops and sends nothing.

If the worker starts with no change, it sends these items:

- About 596,000 POST requests to the frontend
- Notifications to users that are some weeks old

Decide these items:

1. What to do with the old `PingIndexNow` jobs. One possibility: run the worker with `INDEXNOW_ENABLED=false` until the queue is empty. The jobs then do nothing.
2. What to do with the old notification jobs.
3. Why about 30,000 listings change each day. Find the cause.
4. Where the old worker operated, and why it stopped.

### 6. Find all schedulers

> [!CAUTION]
> Only one scheduler must operate. The scheduler sends live NATCON and birthday emails. Two schedulers send each email two times.

1. Find the cron entry of the scheduler on the current EC2 server.
2. Ask the team if Laravel Cloud also runs the scheduler. The code mentions both EC2 and Laravel Cloud (`routes/console.php`, `app/Services/Inquiry/InquiryAiReplyService.php`).
3. Write down each scheduler that you find.

### 7. Test the API through the load balancer

1. Get the DNS name of the load balancer. Refer to [load-balancer.md](load-balancer.md#cloudflare).
2. Send requests with the public hostname in the `Host` header:

   ```bash
   curl -sS -o /dev/null -w '%{http_code}\n' -H "Host: api.filipinohomes.com" "http://<alb-dns-name>/up"
   ```

3. Do the same test for the most important API routes.
4. Read the web log for errors. Refer to [operations.md](operations.md#procedure-read-the-logs).

### 8. Move the traffic

1. Write down the current DNS record of `api.filipinohomes.com` in Cloudflare. You use it for a rollback.
2. Change the record to a CNAME record to the DNS name of the load balancer.
3. Keep the proxy status **Proxied**.
4. Monitor the web log and the 5xx count of the load balancer for one hour.

To roll back, change the record back to its previous value. The current server stays ready until step 11.

### 9. Move the scheduler

Do these steps in a short time. Only one scheduler must operate.

1. Stop each scheduler that you found in step 6.
2. Set `SchedulerCount=1`. Refer to [operations.md](operations.md#procedure-change-the-number-of-tasks).
3. Read the scheduler log. Make sure that the commands run each minute.

### 10. Start the worker

Do this step only after the decision in step 5.

1. Set `WorkerCount=1`. Refer to [operations.md](operations.md#procedure-change-the-number-of-tasks).
2. Read the worker log.
3. Monitor the number of rows in the table `jobs`. Refer to [operations.md](operations.md#procedure-run-a-read-only-query-with-tinker).

### 11. Redirect HTTP to HTTPS

Do the procedure [redirect HTTP to HTTPS](load-balancer.md#procedure-redirect-http-to-https).

### 12. Stop the current server

1. Wait until the ECS setup operates correctly for an agreed period.
2. Stop the current EC2 server. Do not terminate it yet.
3. After a second agreed period, decide if you can terminate it.
