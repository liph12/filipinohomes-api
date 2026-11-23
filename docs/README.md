# Filipino Homes API on AWS

These documents describe how the Filipino Homes API operates on Amazon Web Services (AWS). The API operates on Amazon ECS on EC2, in the Region `ap-southeast-1` (Singapore).

> [!CAUTION]
> **Immediate actions (2026-11-17)**
>
> 1. **No worker processes the queue since 2026-10-31.** Users do not get message notifications and inquiry review notifications. Refer to [queue-backlog.md](queue-backlog.md).
> 2. **The queue increases by about 41,000 jobs each day.** Each listing view adds one IndexNow job. A one-line code change stops it. Refer to [queue-backlog.md](queue-backlog.md#1-stop-the-cause).
> 3. **The ACM certificate must be validated before about 2026-11-19 13:31 (UTC+8).** Refer to [cutover.md](cutover.md#1-validate-the-certificate).
>
> The full list of open items is in [todo.md](todo.md).

> [!NOTE]
> Status on 2026-11-17: the ECS setup operates in parallel with the current EC2 server. The current server still receives all live traffic. Refer to [cutover.md](cutover.md) for the steps that remain.

## Documents

Read the documents in this sequence.

| Document | Subject |
|---|---|
| [todo.md](todo.md) | **The open items, from immediate to later** |
| [queue-backlog.md](queue-backlog.md) | **Immediate:** the jobs that wait in the queue, their cause and the actions |
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
| [mcp.md](mcp.md) | The MCP server (Claude reading the numbers): who sees what, the safety rules, the keys and how to connect |
| [troubleshooting.md](troubleshooting.md) | Known problems and their solutions |
| [cutover.md](cutover.md) | The steps that move live traffic to ECS |

## Conventions

### Language

These documents use ASD-STE100 Simplified Technical English. AWS, Docker, GitHub, Cloudflare and Laravel terms keep their usual names.

### Procedures: AWS console or AWS CLI

Most procedures have two parts. Use the part that you prefer:

- **AWS console:** the steps in the web browser, at <https://console.aws.amazon.com>.
- **AWS CLI:** the commands for a terminal.

Rules for the AWS console:

- Before each procedure, make sure that the Region selector at the top of the console shows **Asia Pacific (Singapore) ap-southeast-1**. In a different Region, the console shows none of these resources.
- The console uses the same IAM permissions as the AWS CLI.
- Some tasks are not possible in the console, for example a move of a secret version label. For these tasks, use AWS CloudShell.

**AWS CloudShell** is a terminal in the AWS console. It has the AWS CLI and your console credentials. You do not have to install software.

1. In the console, select the CloudShell icon (`>_`) in the top bar.
2. Make sure that the Region is `ap-southeast-1`.
3. Paste the AWS CLI command of the procedure.

> [!CAUTION]
> CloudShell keeps the files in your home folder between sessions. Do not keep a production `.env` file there. If you upload one, delete it after use.

### Commands

- The commands are for Bash. On Windows, use Git Bash or AWS CloudShell.
- Always give `--region ap-southeast-1` to the AWS CLI. The default Region of a workstation can be different.
- In Git Bash, put `MSYS_NO_PATHCONV=1` before each `aws logs` command that contains a log group name. Refer to [troubleshooting.md](troubleshooting.md).
- Text in angle brackets, for example `<task-id>`, is a value that you must write.

### GitHub

The GitHub procedures use the web interface of GitHub. Some procedures also give a `gh` command for the terminal.

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

In the console, you can find all Filipino Homes resources with the tag `Project` = `filipinohomes`. Use **Resource Groups & Tag Editor > Tag Editor**.

### Information that must not go into this repository

This repository is public. Do not write these items in the documents, in the templates or in the workflow logs:

- The values of the settings. Refer to [settings.md](settings.md).
- The AWS account ID, and ARNs that contain it.
- The DNS name of the load balancer.
- The endpoint of the database.

Do not show these items in a screenshot, in an issue or in a pull request.

## Quick reference

| Item | Name | Location in the AWS console |
|---|---|---|
| Region | `ap-southeast-1` | Region selector: **Asia Pacific (Singapore)** |
| VPC | `shared-vpc` (10.0.0.0/16) | **VPC > Your VPCs** |
| ECS cluster | `filipinohomes-ecs` | **Amazon ECS > Clusters** |
| ECS services | `filipinohomes-web`, `filipinohomes-worker`, `filipinohomes-scheduler` | **Amazon ECS > Clusters > filipinohomes-ecs > Services** |
| Task definition for one-off commands | `filipinohomes-artisan` | **Amazon ECS > Task definitions** |
| ECR repository | `filipinohomes/api` | **Amazon ECR > Private registry > Repositories** |
| Secret | `filipinohomes/api` | **Secrets Manager > Secrets** |
| Load balancer | `filipinohomes-alb` | **EC2 > Load Balancing > Load Balancers** |
| Target group | `filipinohomes-tg-web` | **EC2 > Load Balancing > Target Groups** |
| Auto Scaling group | `filipinohomes-asg` | **EC2 > Auto Scaling > Auto Scaling Groups** |
| Capacity provider | `filipinohomes-cp` | **Amazon ECS > Clusters > filipinohomes-ecs > Infrastructure** |
| Log groups | `/ecs/filipinohomes/web`, `/ecs/filipinohomes/worker`, `/ecs/filipinohomes/scheduler`, `/ecs/filipinohomes/artisan` | **CloudWatch > Logs > Log groups** |
| Stacks | `filipinohomes-*`, `shared-vpc-peering-default` | **CloudFormation > Stacks** |
| Certificate | `api.filipinohomes.com` | **Certificate Manager > List certificates** |
| Database | The LR database, `lrdblaravelinstance` (RDS for MySQL 8.0) | **RDS > Databases** |
| Public hostname | `api.filipinohomes.com` | Cloudflare dashboard, zone `filipinohomes.com` |
