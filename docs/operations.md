# Operations

This document gives the procedures for daily operation. For releases, refer to [releases.md](releases.md).

Each procedure has an **AWS console** part and an **AWS CLI** part. Use one of them. Refer to [README.md](README.md#procedures-aws-console-or-aws-cli).

> [!NOTE]
> In Git Bash on Windows, keep `MSYS_NO_PATHCONV=1` and `PYTHONUTF8=1` in the `aws logs` commands. Refer to [troubleshooting.md](troubleshooting.md).

## Procedure: examine the services

### AWS console

1. Open **Amazon ECS > Clusters > filipinohomes-ecs**.
2. Select the **Services** tab.
3. For each service, read the desired tasks, the running tasks and the task definition revision.
4. Open **EC2 > Load Balancing > Target Groups > filipinohomes-tg-web**.
5. Select the **Targets** tab. Make sure that each target shows **Healthy**.
6. Open **CloudFormation > Stacks > filipinohomes-ecs-services**.
7. Select the **Parameters** tab. Read `ImageTag`, `WebCount`, `WorkerCount` and `SchedulerCount`.

### AWS CLI

1. Show the desired count, the count of tasks that run, and the task definition of each service:

   ```bash
   aws ecs describe-services --region ap-southeast-1 --cluster filipinohomes-ecs \
     --services filipinohomes-web filipinohomes-worker filipinohomes-scheduler \
     --query "services[].[serviceName,desiredCount,runningCount,pendingCount,taskDefinition]" --output table
   ```

2. Show the health of the web targets:

   ```bash
   aws elbv2 describe-target-health --region ap-southeast-1 \
     --target-group-arn "$(aws elbv2 describe-target-groups --region ap-southeast-1 \
       --names filipinohomes-tg-web --query "TargetGroups[0].TargetGroupArn" --output text)" \
     --query "TargetHealthDescriptions[].[Target.Id,Target.Port,TargetHealth.State]" --output table
   ```

3. Show the parameters of the services stack, with the image tag and the counts:

   ```bash
   aws cloudformation describe-stacks --region ap-southeast-1 --stack-name filipinohomes-ecs-services \
     --query "Stacks[0].[StackStatus,Parameters]" --output json
   ```

## Procedure: read the logs

### AWS console

1. Open **CloudWatch > Logs > Log groups**.
2. Select the log group, for example `/ecs/filipinohomes/web`.
3. Select **Search all log streams** to search all tasks, or select one log stream.
4. To see new lines when they arrive, open **CloudWatch > Logs > Live Tail**, and select the log group.

You can also open **Amazon ECS > Clusters > filipinohomes-ecs > Services > filipinohomes-web**, and select the **Logs** tab.

### AWS CLI

1. Show the log of the last 30 minutes, and continue to show new lines:

   ```bash
   MSYS_NO_PATHCONV=1 PYTHONUTF8=1 aws logs tail /ecs/filipinohomes/web \
     --region ap-southeast-1 --since 30m --follow
   ```

2. For the other roles, replace `web` with `worker`, `scheduler` or `artisan`.
3. To show only the lines that contain a word, add `--filter-pattern`, for example `--filter-pattern ERROR`.

## Procedure: run a one-off artisan command

The task definition `filipinohomes-artisan` runs one command and then stops. Its default command is `php artisan migrate:status`, which changes nothing. Its image is `ArtisanImageTag`, or `ImageTag` if `ArtisanImageTag` is empty.

> [!CAUTION]
> Before you run a command that changes data, read the code of the command. The LR database contains production data for many applications.

### AWS console

1. Open **Amazon ECS > Clusters > filipinohomes-ecs**.
2. Select the **Tasks** tab, and select **Run new task**.
3. In **Compute configuration**, select **Capacity provider strategy**, and keep the cluster default.
4. In **Deployment configuration**, select the family `filipinohomes-artisan` and the latest revision. Keep 1 desired task.
5. Expand **Container overrides**, and expand the container `artisan`.
6. In **Command override**, write the command as a comma-separated list. Example: `php,artisan,migrate:status`.
7. If the procedure gives environment variables, add them in **Environment variables**.
8. Select **Create**.
9. On the **Tasks** tab, set the filter to **All** or **Stopped**. Wait until the task status is **Stopped**.
10. Open the task. In **Containers**, read the exit code. The value 0 means success.
11. Select the **Logs** tab to read the output.

> [!NOTE]
> The console uses each comma as a separator. If the command itself must contain a comma, use the AWS CLI or CloudShell.

### AWS CLI

1. Start the task with your command. This example runs `php artisan migrate:status`:

   ```bash
   TASK=$(aws ecs run-task --region ap-southeast-1 --cluster filipinohomes-ecs \
     --task-definition filipinohomes-artisan \
     --overrides '{"containerOverrides":[{"name":"artisan","command":["php","artisan","migrate:status"]}]}' \
     --query "tasks[0].taskArn" --output text)
   echo "$TASK"
   ```

2. If the output is `None`, the task did not start. Run the command again with `--query failures` to see the reason.
3. Wait until the task stops:

   ```bash
   aws ecs wait tasks-stopped --region ap-southeast-1 --cluster filipinohomes-ecs --tasks "$TASK"
   ```

4. Show the exit code and the reason for the stop:

   ```bash
   aws ecs describe-tasks --region ap-southeast-1 --cluster filipinohomes-ecs --tasks "$TASK" \
     --query "tasks[0].[containers[0].exitCode,stoppedReason]" --output text
   ```

5. Show the output of the command:

   ```bash
   MSYS_NO_PATHCONV=1 PYTHONUTF8=1 aws logs tail /ecs/filipinohomes/artisan --region ap-southeast-1 \
     --log-stream-names "artisan/artisan/${TASK##*/}" --since 1h
   ```

The default capacity provider of the cluster places the task. If no instance has sufficient memory, the capacity provider starts one more instance. The task then waits in the status `PROVISIONING`.

## Procedure: run a read-only query with Tinker

Tinker must have a writable configuration folder. The image sets `XDG_CONFIG_HOME=/config`, and the user `app` cannot write there. Give `XDG_CONFIG_HOME=/tmp` in the override.

### AWS console

1. Do the procedure [run a one-off artisan command](#procedure-run-a-one-off-artisan-command) with these overrides:
   - **Command override:** `php,artisan,tinker,--execute=echo DB::table('jobs')->count();`
   - **Environment variables:** key `XDG_CONFIG_HOME`, value `/tmp`
2. Replace the PHP code with your query. The query must not contain a comma.

### AWS CLI

1. Start the artisan task with `tinker --execute`. This example counts the rows in the table `jobs`:

   ```bash
   TASK=$(aws ecs run-task --region ap-southeast-1 --cluster filipinohomes-ecs \
     --task-definition filipinohomes-artisan \
     --overrides '{"containerOverrides":[{"name":"artisan",
       "command":["php","artisan","tinker","--execute=echo DB::table(\"jobs\")->count();"],
       "environment":[{"name":"XDG_CONFIG_HOME","value":"/tmp"}]}]}' \
     --query "tasks[0].taskArn" --output text)
   ```

2. Do steps 3 to 5 of the AWS CLI part of [run a one-off artisan command](#procedure-run-a-one-off-artisan-command).

## Procedure: open a shell in a container

ECS Exec is on for all services. The session runs as root, and it does not have the expanded settings. Run `artisan` as the user `app`, because it then reads the config cache that the container made at start. If you run it as root, the files that it makes in `storage/` belong to root.

### AWS console

1. Open **Amazon ECS > Clusters > filipinohomes-ecs > Services > filipinohomes-web**.
2. Select the **Tasks** tab, and open a task.
3. Select **Connect**, and select the container `web`. The console opens AWS CloudShell with an ECS Exec session.
4. Run commands as the user `app`, for example:

   ```bash
   runuser -u app -- php artisan about
   ```

If the console does not show **Connect**, use the AWS CLI command below in CloudShell.

### AWS CLI

The Session Manager plugin for the AWS CLI must be on your workstation. CloudShell has it.

1. Find the ID of a task:

   ```bash
   aws ecs list-tasks --region ap-southeast-1 --cluster filipinohomes-ecs \
     --service-name filipinohomes-web --query "taskArns" --output text
   ```

2. Run a command in the container as the user `app`:

   ```bash
   aws ecs execute-command --region ap-southeast-1 --cluster filipinohomes-ecs --task <task-id> \
     --container web --interactive --command "runuser -u app -- php artisan about"
   ```

## Procedure: open a shell on an instance

The instances have no SSH key. Use Session Manager.

### AWS console

1. Open **EC2 > Instances**.
2. Select an instance with the name `filipinohomes-ecs-instance`.
3. Select **Connect**.
4. Select the **Session Manager** tab, and select **Connect**.

### AWS CLI

1. Find the ID of an instance:

   ```bash
   aws autoscaling describe-auto-scaling-groups --region ap-southeast-1 \
     --auto-scaling-group-names filipinohomes-asg \
     --query "AutoScalingGroups[0].Instances[].[InstanceId,AvailabilityZone,LifecycleState,HealthStatus]" \
     --output table
   ```

2. Start a session:

   ```bash
   aws ssm start-session --region ap-southeast-1 --target <instance-id>
   ```

## Procedure: start new tasks

Do this procedure after a change to the settings. The new tasks read the secret again. This procedure does not change the task definition, so the stack and the services continue to agree.

### AWS console

1. Open **Amazon ECS > Clusters > filipinohomes-ecs > Services > filipinohomes-web**.
2. Select **Update service**.
3. Select **Force new deployment**.
4. Do not change other values.
5. Select **Update**.
6. On the **Deployments** tab, wait until the deployment shows **Completed**.
7. If the desired count of `filipinohomes-worker` or `filipinohomes-scheduler` is more than 0, do steps 1 to 6 for that service.

### AWS CLI

1. Start a new deployment of the web service:

   ```bash
   aws ecs update-service --region ap-southeast-1 --cluster filipinohomes-ecs \
     --service filipinohomes-web --force-new-deployment --query "service.serviceName" --output text
   ```

2. Wait until the service is stable:

   ```bash
   aws ecs wait services-stable --region ap-southeast-1 --cluster filipinohomes-ecs \
     --services filipinohomes-web
   ```

3. If the desired count of `filipinohomes-worker` or `filipinohomes-scheduler` is more than 0, do steps 1 and 2 for that service.

## Procedure: change the number of tasks

Change the counts with the stack parameters `WebCount`, `WorkerCount` and `SchedulerCount`.

> [!CAUTION]
> Do not change the desired tasks in the ECS console (**Update service**), and do not use `aws ecs update-service --desired-count`. The stack and the service then do not agree.

> [!CAUTION]
> Before you set `WorkerCount` or `SchedulerCount` to 1, read [queue-backlog.md](queue-backlog.md) and [cutover.md](cutover.md).

### AWS console

1. Open **CloudFormation > Stacks > filipinohomes-ecs-services**.
2. Select **Stack actions > Create change set for current stack**.
3. Select **Use current template**, and select **Next**.
4. Change the count parameter, for example `WebCount` to 3. Do not change other parameters.
5. Select **Next** two times.
6. Select the box that acknowledges the IAM resources, and select **Submit**.
7. Open the change set. Make sure that it changes only the service that you expect.
8. Select **Execute change set**.
9. Wait until the stack status is `UPDATE_COMPLETE`.

### AWS CLI

1. Make a change set. This example sets 3 web tasks:

   ```bash
   aws cloudformation deploy --region ap-southeast-1 --stack-name filipinohomes-ecs-services \
     --template-file infra/aws/filipinohomes-ecs-services.yaml --capabilities CAPABILITY_NAMED_IAM \
     --tags Project=filipinohomes --parameter-overrides WebCount=3 --no-execute-changeset
   ```

2. Make sure that the change set changes only the service that you expect.
3. Run the change set. Refer to [stacks.md](stacks.md#procedure-deploy-a-template-change).

> [!NOTE]
> The CLI procedure sends the template from your workstation. Make sure that your working tree is the same as `origin/main`. The console procedure uses the current template of the stack.

## Procedure: change the number of instances

The capacity provider sets the desired count of instances. You can change the minimum and the maximum with the parameters `MinSize` and `MaxSize` of `filipinohomes-ecs-cluster`.

> [!CAUTION]
> Do not change the group size in **EC2 > Auto Scaling Groups**. The stack and the group then do not agree.

### AWS console

1. Do steps 1 to 9 of [change the number of tasks](#procedure-change-the-number-of-tasks), with these changes:
   - Use the stack `filipinohomes-ecs-cluster`.
   - Change `MinSize` or `MaxSize`. Example: `MinSize` = 2 keeps one instance in each zone.

### AWS CLI

1. Make a change set. This example keeps one instance in each zone:

   ```bash
   aws cloudformation deploy --region ap-southeast-1 --stack-name filipinohomes-ecs-cluster \
     --template-file infra/aws/filipinohomes-ecs-cluster.yaml --capabilities CAPABILITY_NAMED_IAM \
     --tags Project=filipinohomes --parameter-overrides MinSize=2 --no-execute-changeset
   ```

2. Read and run the change set.

> [!NOTE]
> Each deploy of `filipinohomes-ecs-cluster` gets the newest ECS-optimized AMI. If AWS released a new AMI, the stack makes a new version of the launch template. Only new instances use it.

## Procedure: replace the instances

Do this procedure to move the instances to a new AMI or to a new launch template version. The refresh starts each new instance first. ECS then drains the tasks from the old instance before the group stops it.

ECS protects instances that have tasks from scale-in. For this reason, the refresh must also replace the instances with scale-in protection.

### AWS console

1. Update `filipinohomes-ecs-cluster` with the current template and no parameter change. This step makes the new launch template version.
2. Open **EC2 > Auto Scaling Groups > filipinohomes-asg**.
3. Select the **Instance refresh** tab, and select **Start instance refresh**.
4. Set **Minimum healthy percentage** to 100 % and **Maximum healthy percentage** to 200 %.
5. For the instances with scale-in protection, select the option that refreshes (replaces) them.
6. Select **Start instance refresh**.
7. Wait until the status of the refresh is **Successful**.
8. Do the procedure [examine the services](#procedure-examine-the-services).

### AWS CLI

1. Deploy `filipinohomes-ecs-cluster`. This step makes the new launch template version.
2. Start an instance refresh:

   ```bash
   aws autoscaling start-instance-refresh --region ap-southeast-1 --auto-scaling-group-name filipinohomes-asg \
     --preferences '{"ScaleInProtectedInstances":"Refresh","MinHealthyPercentage":100,"MaxHealthyPercentage":200}'
   ```

3. Monitor the refresh until its status is `Successful`:

   ```bash
   aws autoscaling describe-instance-refreshes --region ap-southeast-1 \
     --auto-scaling-group-name filipinohomes-asg \
     --query "InstanceRefreshes[0].[Status,PercentageComplete]" --output text
   ```

4. Do the procedure [examine the services](#procedure-examine-the-services).
