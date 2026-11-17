# To-do list

This document lists the items that are not complete. Update it when an item changes. When an item is complete, move it to [Complete](#complete).

Status on 2026-11-17.

## Owners

| Owner | Who |
|---|---|
| AWS operator | The person who operates the AWS setup of this repository |
| Team | The developers of upstream (`liph12/filipinohomes-api`) |
| Cloudflare admin | A person with access to the zone `filipinohomes.com` in Cloudflare |
| Database owner | The owner of the LR database |

## 1. Immediate

> [!CAUTION]
> Do these items first. They affect users now.

| # | Item | Why | Owner | Reference |
|---|---|---|---|---|
| 1.1 | Change `increment('clicks')` to `incrementQuietly('clicks')` in `ListingController`, and deploy it to the server that gets live traffic | Each listing view adds one IndexNow job, about 41,000 jobs each day | Team | [queue-backlog.md](queue-backlog.md#1-stop-the-cause) |
| 1.2 | Decide what to do with the old notification jobs (send them late, or remove them) | About 800 notifications are 2 to 17 days old | Team | [queue-backlog.md](queue-backlog.md#2-decide-about-the-old-notifications) |
| 1.3 | Remove the old `PingIndexNow` jobs | About 629,000 jobs wait in the shared database | AWS operator, with approval of the database owner | [queue-backlog.md](queue-backlog.md#3-remove-the-old-pingindexnow-jobs) |
| 1.4 | Start the worker on ECS (`WorkerCount=1`) | No worker processed a job after 2026-10-31. Users do not get notifications. | AWS operator | [queue-backlog.md](queue-backlog.md#4-start-the-worker) |
| 1.5 | Add the ACM validation record in Cloudflare | The deadline is about 2026-11-19 13:31 (UTC+8). After it, the certificate request stops. | Cloudflare admin | [load-balancer.md](load-balancer.md#procedure-add-https) |

## 2. Before the pull request to upstream

| # | Item | Why | Owner | Reference |
|---|---|---|---|---|
| 2.1 | Commit `infra/aws/filipinohomes-network.yaml`, `infra/aws/filipinohomes-lr-db-access.yaml` and `infra/aws/shared-vpc-peering-default.yaml` | The documents describe 9 stacks. Only 6 templates are in Git. | AWS operator | [stacks.md](stacks.md) |
| 2.2 | Let the AWS steps of the workflows run only in `orenji-neko/filipinohomes-api` (condition `github.repository`) | In upstream, each push to `main` would start the workflow, and the AWS login would fail | AWS operator | [ci-cd.md](ci-cd.md) |
| 2.3 | Correct the comment "Production has had no queue worker" in `infra/aws/filipinohomes-ecs-services.yaml` | A worker operated until 2026-10-31 | AWS operator | [queue-backlog.md](queue-backlog.md) |
| 2.4 | Add `docs/**` to `paths-ignore` in `build-image.yml`, and `docs` to `.dockerignore` | A change to the documents only builds and deploys a new image | AWS operator | [ci-cd.md](ci-cd.md#workflow-build-image) |
| 2.5 | Merge the upstream commits `eda25d8` and `556c296` into the fork | The fork is 2 commits behind upstream. The merge has no conflicts and no migrations. | AWS operator | [ci-cd.md](ci-cd.md#procedure-merge-the-upstream-changes-into-the-fork) |
| 2.6 | Ask the team if the AWS templates and the documents must be in upstream | Only the fork can deploy. The AWS roles trust the fork only. | AWS operator, Team | [ci-cd.md](ci-cd.md#iam-roles-for-github-actions) |

## 3. Before the cutover

| # | Item | Why | Owner | Reference |
|---|---|---|---|---|
| 3.1 | Add HTTPS to the load balancer | The load balancer serves HTTP only | AWS operator | [load-balancer.md](load-balancer.md#procedure-add-https) |
| 3.2 | Find all schedulers: the cron of the current EC2 server, and Laravel Cloud | Two schedulers send each NATCON and birthday email two times | Team | [cutover.md](cutover.md#6-find-all-schedulers) |
| 3.3 | Find where the old worker operated, and why it stopped on 2026-10-31 | The cause is not known | Team | [queue-backlog.md](queue-backlog.md#open-questions) |
| 3.4 | Test the API through the load balancer | Find problems before users see them | AWS operator | [cutover.md](cutover.md#7-test-the-api-through-the-load-balancer) |
| 3.5 | Add CloudWatch alarms and a notification target, for example unhealthy targets, HTTP 5xx, CPU and memory | No alarms exist. Nobody gets a message when the API has a problem. | AWS operator | - |
| 3.6 | Decide if the Auto Scaling group must keep one instance in each zone (`MinSize=2`) | With 1 instance, the API stops if that instance or its zone stops. A second instance costs more. | AWS operator | [operations.md](operations.md#procedure-change-the-number-of-instances) |
| 3.7 | Confirm the settings `LR_API_TOKEN`, `EXPO_ACCESS_TOKEN`, `GOOGLE_CLIENT_ID` and `NEXT_PUBLIC_GOOGLE_MAPS_API_KEY` | They are not in the production settings, and the code has no default. The current server has the same problem. | Team | [settings.md](settings.md) |

## 4. Cutover

| # | Item | Why | Owner | Reference |
|---|---|---|---|---|
| 4.1 | Change the Cloudflare record of `api.filipinohomes.com` to the load balancer | This moves the live traffic to ECS | Cloudflare admin | [cutover.md](cutover.md#8-move-the-traffic) |
| 4.2 | Stop the other schedulers, and set `SchedulerCount=1` | Only one scheduler must operate | AWS operator, Team | [cutover.md](cutover.md#9-move-the-scheduler) |
| 4.3 | Set the Cloudflare SSL/TLS mode for `api.filipinohomes.com` to Full (strict), then redirect HTTP to HTTPS | All traffic to the origin uses HTTPS | Cloudflare admin, AWS operator | [load-balancer.md](load-balancer.md#procedure-redirect-http-to-https) |

## 5. After the cutover

| # | Item | Why | Owner | Reference |
|---|---|---|---|---|
| 5.1 | Stop the current EC2 server, and terminate it later | It costs money, and it is not necessary after the cutover | AWS operator, Team | [cutover.md](cutover.md#11-stop-the-current-server) |
| 5.2 | Mark [cutover.md](cutover.md) as complete, or remove it | It contains a dated status | AWS operator | - |
| 5.3 | Replace the instances when AWS releases a new ECS-optimized AMI | The instances do not get the new AMI automatically | AWS operator | [operations.md](operations.md#procedure-replace-the-instances) |
| 5.4 | Update the base images when Debian corrects the vulnerabilities | On 2026-11-14, the scan found 1 critical and 22 high findings outside the package `linux` | AWS operator | [container-image.md](container-image.md#vulnerability-scans) |

## Complete

| Date | Item |
|---|---|
| 2026-11-13 | Network: subnets, routes, peering, database access |
| 2026-11-14 | ECR repository, image build in CI, secret with the production settings |
| 2026-11-16 | Load balancer, ECS cluster, services. Database access from ECS tested. |
| 2026-11-17 | GitHub secret `AWS_DEPLOY_ROLE_ARN` set. First automatic deployment (`7c9b4b8`) complete. |
| 2026-11-17 | Documents in `docs/` |
