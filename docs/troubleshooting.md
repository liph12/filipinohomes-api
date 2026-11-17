# Troubleshooting

This document gives the known problems and their solutions.

## AWS CLI

### The AWS CLI shows resources from a different Region, or no resources

**Cause:** The default Region of the workstation is not `ap-southeast-1`.

**Solution:** Give `--region ap-southeast-1` in each command.

### In Git Bash, `aws logs` cannot find the log group `/ecs/filipinohomes/...`

**Cause:** Git Bash changes arguments that start with `/` into Windows paths.

**Solution:** Put `MSYS_NO_PATHCONV=1` before the command:

```bash
MSYS_NO_PATHCONV=1 aws logs tail /ecs/filipinohomes/web --region ap-southeast-1
```

### On Windows, `aws logs tail` stops with a `UnicodeEncodeError`

**Cause:** FrankenPHP writes an emoji in its start message. The Windows code page cannot show it.

**Solution:** Put `PYTHONUTF8=1` before the command.

## AWS console

### The console shows no Filipino Homes resources

**Cause:** The Region selector shows a different Region.

**Solution:** At the top of the console, set the Region selector to **Asia Pacific (Singapore) ap-southeast-1**.

### A one-off task from the console runs the wrong command

**Cause:** The field **Command override** uses each comma as a separator. A comma in the PHP code or in a value cuts the command into wrong parts.

**Solution:** Remove the commas from the command. If that is not possible, use the AWS CLI in a terminal or in AWS CloudShell. Refer to [operations.md](operations.md#procedure-run-a-one-off-artisan-command).

### The desired count of a service is different from the stack parameter

**Cause:** A person changed the desired tasks in the ECS console (**Update service**), or with `aws ecs update-service --desired-count`.

**Solution:** Set the count again with the stack parameter. Refer to [operations.md](operations.md#procedure-change-the-number-of-tasks).

### The task page does not show **Connect**

**Cause:** The console version, or your permissions, do not support ECS Exec from the console.

**Solution:** Open AWS CloudShell, and use the AWS CLI command. Refer to [operations.md](operations.md#procedure-open-a-shell-in-a-container).

## Containers

### CloudWatch Logs has no output from a container

**Cause:** On EC2, the `awslogs` log driver uses the credentials of the task execution role. If the role cannot write to the log group, the `non-blocking` mode drops all output and shows no error.

**Solution:**

1. Make sure that the statement `WriteContainerLogs` of `filipinohomes-ecs-task-execution-role` contains the log group.
2. If it does not, add the log group to the template, and deploy it.

Docker tries again each 32 seconds. After the fix, the tasks that run send their output again. You do not have to restart them.

### Tinker stops with an error about `/config/psysh`

**Cause:** The image sets `XDG_CONFIG_HOME=/config`. The user `app` cannot write in `/config`.

**Solution:** Give the environment variable `XDG_CONFIG_HOME=/tmp` in the task override. Refer to [operations.md](operations.md#procedure-run-a-read-only-query-with-tinker).

### The scheduler log does not show the output of the scheduled commands

**Cause:** One of these items is not correct:

- The scheduler task does not have `LOG_SCHEDULE_OUTPUT=/proc/1/fd/1`.
- The block with `logging.schedule_output` is not the last block of `routes/console.php`. This can occur after a merge from upstream.

**Solution:** Make sure that both items are correct. Refer to [container-image.md](container-image.md#output-of-the-scheduled-commands).

### Monolog cannot write to `/proc/1/fd/1`

**Cause:** The PHP of FrankenPHP is a ZTS build. It cannot open the links in `/proc/*/fd/*`.

**Solution:** This is known. The shell writes the output of the scheduled commands, not Monolog. Use `LOG_CHANNEL=stderr` for the application log.

### A command in an ECS Exec session makes files that belong to root

**Cause:** ECS Exec sessions run as root.

**Solution:** Run commands as the user `app`: `runuser -u app -- php artisan ...`.

## Releases

### The deploy workflow stops at the AWS login, and the role ARN is empty

The log shows `Invalid length for parameter RoleArn, value: 0`.

**Cause:** The GitHub secret `AWS_DEPLOY_ROLE_ARN` does not exist.

**Solution:** Set the secret. Refer to [ci-cd.md](ci-cd.md#procedure-set-a-github-secret).

### The deploy workflow stops with "deploy when it's in a COMPLETE state"

**Cause:** The status of `filipinohomes-ecs-services` does not end in `_COMPLETE`. Another update runs, or an update failed.

**Solution:**

1. Show the stack events. In the console, open **CloudFormation > Stacks > filipinohomes-ecs-services > Events**. With the AWS CLI:

   ```bash
   aws cloudformation describe-stack-events --region ap-southeast-1 --stack-name filipinohomes-ecs-services \
     --max-items 30 --query "StackEvents[].[Timestamp,LogicalResourceId,ResourceStatus,ResourceStatusReason]" \
     --output table
   ```

2. If an update runs, wait until it stops.
3. If an update failed, correct the cause.
4. Start the workflow again.

### `migrate:status --pending` always gives the exit code 0

**Cause:** Without a value, `--pending` only filters the list.

**Solution:** Use `--pending=1`. The command then gives the exit code 1 if a migration is pending.

### A change to a default value in a template has no effect

**Cause:** On a stack that exists, `aws cloudformation deploy` keeps the previous value of each parameter.

**Solution:** Give the value in `--parameter-overrides`.

### A push to ECR fails with `AccessDenied`, also for an administrator

**Cause:** The repository policy of `filipinohomes/api` lets only `filipinohomes-github-ecr-push-role` push. This is correct.

**Solution:** Push to `main`. The workflow `Build image` pushes the image.

## Load balancer

### The load balancer gives the code 502 or 503

**Cause:** No web target is healthy.

**Solution:**

1. Show the health of the targets. Refer to [operations.md](operations.md#procedure-examine-the-services).
2. Read the log of the web containers. Refer to [operations.md](operations.md#procedure-read-the-logs).
3. Make sure that `/up` gives the code 200 in a container.

### After the HTTPS change, each request redirects without end

**Cause:** `HttpAction` is `redirect`, and the Cloudflare SSL/TLS mode is `Flexible`. Cloudflare connects with HTTP, and the load balancer sends it to HTTPS again.

**Solution:** Set `HttpAction=forward`, or set the Cloudflare SSL/TLS mode to `Full (strict)`. Refer to [load-balancer.md](load-balancer.md#procedure-redirect-http-to-https).

## Instances

### The Auto Scaling group does not stop an instance

**Cause:** Managed termination protection is on. ECS removes the scale-in protection only after it drains the tasks from the instance.

**Solution:** This is correct. To replace instances, use an instance refresh. Refer to [operations.md](operations.md#procedure-replace-the-instances).

## CloudFormation drift

### Drift detection shows the tag `AmazonECSManaged` as removed from the Auto Scaling group

**Cause:** The tag has an empty value. Drift detection shows empty tags as removed.

**Solution:** Ignore this result. The tag is there.

## Docker on a workstation

### The local build fails, and `php` in the base image has 0 bytes

**Cause:** A layer of the base image in Docker Desktop is damaged.

**Solution:**

1. Remove the build cache:

   ```bash
   docker builder prune -af
   ```

2. Build the image again with `--pull`. Docker then pulls the base image again:

   ```bash
   docker build --pull --platform linux/amd64 --provenance=false -t filipinohomes/api:local .
   ```
