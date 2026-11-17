# Filipino Homes API on AWS

These documents describe how the Filipino Homes API operates on Amazon Web Services (AWS). The API operates on Amazon ECS on EC2, in the Region `ap-southeast-1` (Singapore).

> [!NOTE]
> Status on 2026-11-17: the ECS setup operates in parallel with the current EC2 server. The current server still receives all live traffic. Refer to [cutover.md](cutover.md) for the steps that remain.

## Documents

Read the documents in this sequence.

| Document | Subject |
|---|---|
| [architecture.md](architecture.md) | The parts of the system, and how a request goes through them |
| [stacks.md](stacks.md) | The CloudFormation stacks, their sequence and their exports |
| [network.md](network.md) | The VPC, the subnets, the routes, the NAT gateway and the database access |
| [load-balancer.md](load-balancer.md) | The load balancer, the health check, HTTPS and Cloudflare |
| [container-image.md](container-image.md) | The Docker image, its entrypoint and its smoke test |
| [settings.md](settings.md) | The production settings in Secrets Manager |
| [ecs.md](ecs.md) | The cluster, the instances, the services, the logs and the IAM roles |
| [ci-cd.md](ci-cd.md) | The GitHub Actions workflows, the ECR repository and the Git remotes |
| [releases.md](releases.md) | How to release, how to run migrations and how to roll back |
| [operations.md](operations.md) | Procedures for daily operation |
| [troubleshooting.md](troubleshooting.md) | Known problems and their solutions |
| [cutover.md](cutover.md) | The steps that move live traffic to ECS |

## Conventions

### Language

These documents use ASD-STE100 Simplified Technical English. AWS, Docker, GitHub, Cloudflare and Laravel terms keep their usual names.

### Commands

- The commands are for Bash. On Windows, use Git Bash.
- Always give `--region ap-southeast-1` to the AWS CLI. The default Region of a workstation can be different.
- In Git Bash, put `MSYS_NO_PATHCONV=1` before each `aws logs` command that contains a log group name. Refer to [troubleshooting.md](troubleshooting.md).
- Text in angle brackets, for example `<task-id>`, is a value that you must write.

### Names

All AWS names follow one scheme:

- **Name tag:** `{scope}-{type}[-{role}{n}][-{zone}]`. Example: `filipinohomes-subnet-private1-ap-southeast-1a`.
  - `scope` is `shared` for a resource that many systems use. For all other resources, `scope` is the system name, for example `filipinohomes`.
  - `type` is the prefix of the AWS ID: `vpc`, `subnet`, `rtb`, `igw`, `nat`, `eip`, `vpce`, `pcx` or `sg`.
  - `zone` is only in the name of a resource that is in one Availability Zone.
- **Tag `Project`:** the scope. All resources have this tag.
- **Tag `FormerName`:** the old name. Only a resource that got a new name has this tag.
- **Stack:** `{scope}-{component}`. **Template:** `infra/aws/{stack}.yaml`. **Export:** `{stack}-{OutputKey}`.

The resources that AWS owns and the default VPC are exceptions.

### Information that must not go into this repository

This repository is public. Do not write these items in the documents, in the templates or in the workflow logs:

- The values of the settings. Refer to [settings.md](settings.md).
- The AWS account ID, and ARNs that contain it.
- The DNS name of the load balancer.
- The endpoint of the database.

## Quick reference

| Item | Name |
|---|---|
| Region | `ap-southeast-1` |
| VPC | `shared-vpc` (10.0.0.0/16) |
| ECS cluster | `filipinohomes-ecs` |
| ECS services | `filipinohomes-web`, `filipinohomes-worker`, `filipinohomes-scheduler` |
| Task definition for one-off commands | `filipinohomes-artisan` |
| ECR repository | `filipinohomes/api` |
| Secret | `filipinohomes/api` |
| Load balancer | `filipinohomes-alb` |
| Target group | `filipinohomes-tg-web` |
| Auto Scaling group | `filipinohomes-asg` |
| Capacity provider | `filipinohomes-cp` |
| Log groups | `/ecs/filipinohomes/web`, `/ecs/filipinohomes/worker`, `/ecs/filipinohomes/scheduler`, `/ecs/filipinohomes/artisan` |
| Database | The LR database, `lrdblaravelinstance` (RDS for MySQL 8.0) |
| Public hostname | `api.filipinohomes.com` (DNS zone in Cloudflare) |
