# ECS

This document describes the ECS cluster, the EC2 instances, the task definitions, the services, the logs and the IAM roles.

Stacks: `filipinohomes-ecs-cluster` and `filipinohomes-ecs-services`.

## Cluster

| Item | Value |
|---|---|
| Name | `filipinohomes-ecs` |
| Container Insights | Disabled |
| Default capacity provider | `filipinohomes-cp` |

## Instances

The Auto Scaling group `filipinohomes-asg` starts the instances from the launch template `filipinohomes-lt`.

| Item | Value |
|---|---|
| Instance type | t3.medium (2 vCPU, 4 GiB). The image is for `linux/amd64`. |
| AMI | ECS-optimized Amazon Linux 2023. CloudFormation gets the AMI ID from a public SSM parameter at each deploy. |
| Root volume | 30 GiB, gp3, encrypted |
| Subnets | The two Filipino Homes private subnets |
| Size of the group | Minimum 1, maximum 3. The capacity provider sets the desired count. |
| Instance role | `filipinohomes-ecs-instance-role` |
| Security group | `filipinohomes-sg-ecs` |
| SSH key | None |
| Public IP address | None |
| Instance metadata | IMDSv2 only, with a hop limit of 1 |

A container on the bridge network is one hop farther from the instance metadata than the instance is. With a hop limit of 1, the container cannot get the credentials of the instance role. Each task gets its own role from ECS.

With a minimum of 1, both web containers can be on one instance in one zone. With a minimum of 2, each zone has an instance. Then the API continues to operate if one instance stops.

### Settings of the ECS agent

The launch template writes these settings into `/etc/ecs/ecs.config`:

| Setting | Value | Reason |
|---|---|---|
| `ECS_CONTAINER_STOP_TIMEOUT` | `16m` | Applies to containers that have no `stopTimeout`, for example the worker. A queue job of up to 15 minutes (`RunSeoCommand`) can finish when ECS stops the worker. A task definition cannot set more than 120 seconds. |
| `ECS_RESERVED_MEMORY` | `256` | Keeps 256 MiB for the operating system. |
| `ECS_IMAGE_PULL_BEHAVIOR` | `prefer-cached` | Image tags are immutable. The agent does not pull an image tag again if the instance has it. |
| `ECS_ENABLE_TASK_IAM_ROLE` | `true` | Each task gets its own role. |
| `ECS_CONTAINER_INSTANCE_PROPAGATE_TAGS_FROM` | `ec2_instance` | The container instance gets the tags `Name` and `Project` of the EC2 instance. |

### Capacity provider

| Item | Value |
|---|---|
| Name | `filipinohomes-cp` |
| Managed scaling | Enabled, target capacity 100 %, step 1 instance, warm-up 180 seconds |
| Managed termination protection | Enabled |
| Managed draining | Enabled |

The capacity provider keeps sufficient instances for the CPU and the memory that the tasks reserve. When the tasks do not fit, it adds one instance. ECS removes the scale-in protection only from an instance that has no tasks.

## Task definitions

All task definitions use these values:

- Network mode `bridge`, launch type EC2
- Image `filipinohomes/api:<ImageTag>`
- `InitProcessEnabled: true`. An init process is PID 1. It sends stop signals to the command and removes processes that stopped.
- One secret, `FILIPINOHOMES_ENV_JSON`. Refer to [settings.md](settings.md).
- Log driver `awslogs`, mode `non-blocking`, buffer 4 MiB

| Family | Container | Command | CPU units | Memory reservation (MiB) | Memory limit (MiB) | Stop timeout |
|---|---|---|---|---|---|---|
| `filipinohomes-web` | `web` | The default command (FrankenPHP) | 512 | 768 | 2048 | 30 s |
| `filipinohomes-worker` | `worker` | `php artisan queue:work --queue=default --memory=384` | 256 | 512 | 1536 | 16 min (agent setting) |
| `filipinohomes-scheduler` | `scheduler` | `filipinohomes-scheduler` | 256 | 512 | 2048 | 120 s |
| `filipinohomes-artisan` | `artisan` | `php artisan migrate:status` | 256 | 512 | 2048 | 16 min (agent setting) |

- ECS places a task by its memory reservation. If a container uses more than its memory limit, the kernel stops it.
- The web container maps container port 8080 to host port 0. Docker then selects a free host port, and ECS registers it in the target group.
- The artisan task uses `ArtisanImageTag`. If `ArtisanImageTag` is empty, it uses `ImageTag`. Refer to [releases.md](releases.md).

### Worker

- The worker reads the queue `default` only. Run the backfill queues (`region-backfill`, `image-variants`, `photo-migration`) by hand with the artisan task.
- `--memory=384` restarts the worker after a job if the worker uses more than 384 MB. The PHP limit is 512 MB.

### Scheduler

The scheduler gets two more settings: `LOG_SCHEDULE_OUTPUT` and `SCHEDULE_CACHE_DRIVER`. Refer to [settings.md](settings.md#settings-that-the-task-definitions-replace).

## Services

| Service | Desired count | Parameter | Minimum healthy | Maximum | Notes |
|---|---|---|---|---|---|
| `filipinohomes-web` | 2 | `WebCount` | 50 % | 200 % | Registered in `filipinohomes-tg-web`. Health check grace period 60 seconds. |
| `filipinohomes-worker` | 0 | `WorkerCount` (0 or 1) | 100 % | 200 % | The new worker starts before the old worker stops. The old worker finishes its job. |
| `filipinohomes-scheduler` | 0 | `SchedulerCount` (0 or 1) | 0 % | 100 % | The old scheduler stops before the new scheduler starts. Two schedulers never run at the same time. |

All services have these settings:

- The deployment circuit breaker is on, with rollback. If the new tasks do not start, ECS goes back to the previous task definition.
- ECS Exec is on.
- ECS adds its managed tags, and the tasks get the tags of the service.

The web service spreads its tasks across the Availability Zones first, and then across the instances. The worker and the scheduler do not rebalance across zones.

> [!CAUTION]
> Do not set `WorkerCount` or `SchedulerCount` to 1 before you read [cutover.md](cutover.md).

## Logs

| Log group | Container | Stream name | Retention |
|---|---|---|---|
| `/ecs/filipinohomes/web` | `web` | `web/web/<task-id>` | 30 days |
| `/ecs/filipinohomes/worker` | `worker` | `worker/worker/<task-id>` | 30 days |
| `/ecs/filipinohomes/scheduler` | `scheduler` | `scheduler/scheduler/<task-id>` | 30 days |
| `/ecs/filipinohomes/artisan` | `artisan` | `artisan/artisan/<task-id>` | 30 days |

The parameter `LogRetentionDays` sets the retention. Refer to [operations.md](operations.md#procedure-read-the-logs).

## IAM roles

| Role | Used by | Permissions |
|---|---|---|
| `filipinohomes-ecs-instance-role` | The EC2 instances | `AmazonEC2ContainerServiceforEC2Role` (ECS agent) and `AmazonSSMManagedInstanceCore` (Session Manager) |
| `filipinohomes-ecs-task-execution-role` | ECS, when it starts a task | Pull images from `filipinohomes/api`. Read the secret `filipinohomes/api`. Write to the four log groups. |
| `filipinohomes-ecs-task-role` | The containers | ECS Exec only, and the logs of its sessions |

On EC2, the `awslogs` log driver uses the credentials of the task execution role. If this role cannot write to a log group, the `non-blocking` mode drops all output and shows no error. If you add a log group, add it to the statement `WriteContainerLogs` of this role.

The application uses the AWS keys in its settings. It does not use the task role for Amazon S3 or other AWS services.

The trust policies of the two task roles accept only ECS tasks of this account.
