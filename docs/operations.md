# Operations

This document gives the procedures for daily operation. For releases, refer to [releases.md](releases.md).

> [!NOTE]
> All commands are for Bash. In Git Bash on Windows, keep `MSYS_NO_PATHCONV=1` and `PYTHONUTF8=1` in the `aws logs` commands. Refer to [troubleshooting.md](troubleshooting.md).

## Procedure: examine the services

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

The cluster's default capacity provider places the task. If no instance has sufficient memory, the capacity provider starts one more instance. The task then waits in the status `PROVISIONING`.

## Procedure: run a read-only query with Tinker

Tinker must have a writable configuration folder. The image sets `XDG_CONFIG_HOME=/config`, and the user `app` cannot write there. Give `XDG_CONFIG_HOME=/tmp` in the override.

1. Start the artisan task with `tinker --execute`. This example counts the rows in the table `jobs`:

   ```bash
   TASK=$(aws ecs run-task --region ap-southeast-1 --cluster filipinohomes-ecs \
     --task-definition filipinohomes-artisan \
     --overrides '{"containerOverrides":[{"name":"artisan",
       "command":["php","artisan","tinker","--execute=echo DB::table(\"jobs\")->count();"],
       "environment":[{"name":"XDG_CONFIG_HOME","value":"/tmp"}]}]}' \
     --query "tasks[0].taskArn" --output text)
   ```

2. Do steps 3 to 5 of [run a one-off artisan command](#procedure-run-a-one-off-artisan-command).

## Procedure: open a shell in a container

ECS Exec is on for all services. The Session Manager plugin for the AWS CLI must be on your workstation.

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

The session runs as root, and it does not have the expanded settings. Run `artisan` as the user `app`, because it then reads the config cache that the container made at start. If you run it as root, the files that it makes in `storage/` belong to root.

## Procedure: open a shell on an instance

The instances have no SSH key. Use Session Manager.

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

Do this procedure after a change to the settings. The new tasks read the secret again.

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

This procedure does not change the task definition. The stack and the services continue to agree.

## Procedure: change the number of tasks

Change the counts with the stack parameters `WebCount`, `WorkerCount` and `SchedulerCount`. Do not use `aws ecs update-service --desired-count`. If you use it, the stack and the service do not agree.

> [!CAUTION]
> Before you set `WorkerCount` or `SchedulerCount` to 1, read [cutover.md](cutover.md).

1. Make a change set. This example sets 3 web tasks:

   ```bash
   aws cloudformation deploy --region ap-southeast-1 --stack-name filipinohomes-ecs-services \
     --template-file infra/aws/filipinohomes-ecs-services.yaml --capabilities CAPABILITY_NAMED_IAM \
     --tags Project=filipinohomes --parameter-overrides WebCount=3 --no-execute-changeset
   ```

2. Make sure that the change set changes only the service that you expect.
3. Run the change set. Refer to [stacks.md](stacks.md#procedure-deploy-a-template-change).

## Procedure: change the number of instances

The capacity provider sets the desired count of instances. You can change the minimum and the maximum.

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

Do this procedure to move the instances to a new AMI or to a new launch template version.

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

4. Examine the services. Refer to [examine the services](#procedure-examine-the-services).

The refresh starts each new instance first. ECS then drains the tasks from the old instance before the group stops it. ECS protects instances that have tasks from scale-in. For this reason, the refresh must have `ScaleInProtectedInstances` set to `Refresh`.
