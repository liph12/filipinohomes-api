# Cutover

This document gives the status of the move to ECS and the steps that remain. When the cutover is complete, remove this document or mark it as complete. The full list of open items is in [todo.md](todo.md).

> [!CAUTION]
> **Immediate:** no worker processes the queue since 2026-10-31, and users do not get notifications. Do step 5 first. Refer to [queue-backlog.md](queue-backlog.md).

## Status on 2026-11-17

| Item | Status |
|---|---|
| Network, ECR, secret, CI roles | Deployed |
| Load balancer, cluster, services | Deployed. 2 of 2 web tasks are healthy. HTTP only. |
| Image on ECS | `7c9b4b8`, deployed by the deploy workflow on 2026-11-17 |
| Worker and scheduler on ECS | 0 tasks |
| Database access from ECS | Correct |
| Queue | 629,766 jobs at 02:39 UTC. No worker since 2026-10-31. **Immediate.** |
| Live traffic | Goes to the current EC2 server (`API_filipinohomes_official_laravel`) |
| ACM certificate for `api.filipinohomes.com` | Requested on 2026-11-16. The validation record is not in Cloudflare. |
| GitHub secret `AWS_DEPLOY_ROLE_ARN` | Set on 2026-11-17 |

The ECS tasks and the current server use the same database. Both can serve requests at the same time. The DNS record in Cloudflare selects the server that gets the traffic.

## Steps

Do the steps in this sequence. Step 5 does not depend on steps 1 to 4. Do it now.

### 1. Validate the certificate

> [!CAUTION]
> The deadline is about 2026-11-19 13:31 (UTC+8), 72 hours after the request. After the deadline, you must request a new certificate.

Do parts 1 and 2 of [add HTTPS](load-balancer.md#procedure-add-https).

### 2. Set the GitHub secret for the deployment

Complete on 2026-11-17. The first automatic deployment (`7c9b4b8`) passed.

### 3. Merge the upstream changes

Do the procedure [merge the upstream changes into the fork](ci-cd.md#procedure-merge-the-upstream-changes-into-the-fork).

On 2026-11-17, upstream had 2 new commits with no migrations. The 6 migrations from the merge of 2026-11-16 were not pending. The production database had them before the deployment.

### 4. Add HTTPS to the load balancer

Do part 3 of [add HTTPS](load-balancer.md#procedure-add-https). Keep `HttpAction=forward`.

### 5. Correct the queue, and start the worker

> [!CAUTION]
> Do not start the worker before actions 1 to 3 of [queue-backlog.md](queue-backlog.md#immediate-actions). Do not delete jobs without the approval of the database owner.

Do actions 1 to 4 of [queue-backlog.md](queue-backlog.md#immediate-actions).

The worker does not have to wait for the traffic move. The table `jobs` is in the shared database. A worker on ECS also processes the jobs that the current EC2 server adds.

### 6. Find all schedulers

> [!CAUTION]
> Only one scheduler must operate. The scheduler sends live NATCON and birthday emails. Two schedulers send each email two times.

1. Find the cron entry of the scheduler on the current EC2 server.
2. Ask the team if Laravel Cloud also runs the scheduler. The code mentions both EC2 and Laravel Cloud (`routes/console.php`, `app/Services/Inquiry/InquiryAiReplyService.php`).
3. Write down each scheduler that you find.

### 7. Test the API through the load balancer

1. Get the DNS name of the load balancer. Refer to [load-balancer.md](load-balancer.md#cloudflare).
2. In a terminal or in AWS CloudShell, send requests with the public hostname in the `Host` header:

   ```bash
   curl -sS -o /dev/null -w '%{http_code}\n' -H "Host: api.filipinohomes.com" "http://<alb-dns-name>/up"
   ```

3. Do the same test for the most important API routes.
4. Read the web log for errors. Refer to [operations.md](operations.md#procedure-read-the-logs).

### 8. Move the traffic

1. In the Cloudflare dashboard, open the zone `filipinohomes.com` and **DNS > Records**.
2. Write down the current record of `api.filipinohomes.com`: its type, its target and its proxy status. You use it for a rollback.
3. Edit the record. Set **Type** to `CNAME` and **Target** to the DNS name of the load balancer.
4. Keep the proxy status **Proxied** (orange cloud), and select **Save**.
5. For one hour, monitor these items:
   - The web log. Refer to [operations.md](operations.md#procedure-read-the-logs).
   - The HTTP 5xx count of the load balancer. In the AWS console, open **EC2 > Load Balancers > filipinohomes-alb**, and select the **Monitoring** tab.

To roll back, change the record back to its previous values. The current server stays ready until step 11.

### 9. Move the scheduler

Do these steps in a short time. Only one scheduler must operate.

1. Stop each scheduler that you found in step 6.
2. Set `SchedulerCount=1`. Refer to [operations.md](operations.md#procedure-change-the-number-of-tasks).
3. Read the scheduler log. Make sure that the commands run each minute.

### 10. Redirect HTTP to HTTPS

Do the procedure [redirect HTTP to HTTPS](load-balancer.md#procedure-redirect-http-to-https).

### 11. Stop the current server

1. Wait until the ECS setup operates correctly for an agreed period.
2. Stop the current EC2 server. Do not terminate it yet. In the AWS console, open **EC2 > Instances**, select the server, and select **Instance state > Stop instance**.
3. After a second agreed period, decide if you can terminate it.
