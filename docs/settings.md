# Settings

This document describes where the production settings are, how a container gets them, and how to change them.

## Summary

- The secret `filipinohomes/api` in Secrets Manager contains all production settings.
- The value of the secret is the production `.env` file, as one JSON object of names and values.
- The secret contains all settings, not only passwords. Internal settings, for example host names and bucket names, stay out of this public repository.
- Each container gets the full secret in one variable, `FILIPINOHOMES_ENV_JSON`. The entrypoint expands it.
- A new value applies only to the tasks that start after the change.

> [!CAUTION]
> Do not write the value of a setting in the repository, in an issue, in a pull request or in a workflow log. Do not print the secret.

## The secret

| Item | Value |
|---|---|
| Name | `filipinohomes/api` |
| Stack | `filipinohomes-secrets` |
| Export | `filipinohomes-secrets-ApiSecretArn` |
| Format | One JSON object. Each name is a setting. Each value is a string. |
| Number of settings | 90 on 2026-11-14 |
| Deletion policy | `Retain`. The secret stays if you delete the stack. |

The stack makes the secret with no value. No value goes through the repository or through CloudFormation. When you update the stack, the value does not change.

## How a container gets the settings

1. Each task definition has one secret: `FILIPINOHOMES_ENV_JSON`, with the full value of `filipinohomes/api`.
2. When a task starts, ECS reads the secret with the task execution role.
3. The entrypoint expands the JSON into one environment variable for each setting. Refer to [container-image.md](container-image.md#container-start).
4. `php artisan optimize` writes the settings into the config cache.

The sequence of precedence is:

1. The `Environment` entries of the task definition
2. The secret
3. The default value in the code

`AWS_REGION` and `AWS_DEFAULT_REGION` are exceptions. For these two, the secret has precedence over the value that the ECS agent sets.

## Settings that the task definitions replace

The `Environment` entries of the task definitions replace these settings:

| Setting | Value | Containers | Reason |
|---|---|---|---|
| `LOG_CHANNEL` | `stderr` | All | The logs go to the output of the container, and from there to CloudWatch Logs. |
| `CACHE_STORE` | `database` (parameter `CacheStore`) | All | A file cache is not shared between containers. The database store uses the tables `cache` and `cache_locks`. |
| `LOG_SCHEDULE_OUTPUT` | `/proc/1/fd/1` | Scheduler | The output of the scheduled commands goes to the log of the container. |
| `SCHEDULE_CACHE_DRIVER` | `file` | Scheduler | The locks of `withoutOverlapping` stay in the container. If the container stops during a command, no lock stays in the database. A lock in the database can block the command for up to 24 hours. |

To change these values, change the template `infra/aws/filipinohomes-ecs-services.yaml`. Refer to [stacks.md](stacks.md#procedure-deploy-a-template-change).

## Procedure: show the names of the settings

> [!CAUTION]
> The AWS console has no view with the names only. **Retrieve secret value** shows all values on the screen. Use the AWS CLI command below, in a terminal or in AWS CloudShell.

This command shows the names only. It does not show the values. In CloudShell, use `python3` in place of `python`.

```bash
aws secretsmanager get-secret-value --region ap-southeast-1 --secret-id filipinohomes/api \
  --query SecretString --output text \
  | python -c "import json, sys; print('\n'.join(sorted(json.load(sys.stdin))))"
```

## Procedure: change the settings

There are two methods:

| Method | Use it for | Tool |
|---|---|---|
| A: the script | Many changes, or a full new `.env` file | AWS CLI on a workstation |
| B: the AWS console | One or two values | Web browser |

The production `.env` file is the source of the secret. Both methods must keep the file and the secret the same.

### Method A: the script (AWS CLI)

The script `infra/aws/scripts/put-env-secret.py` writes a `.env` file into the secret. It reads the file with the same rules as Laravel (a port of `vlucas/phpdotenv` 5.6.3). It never prints a value.

> [!CAUTION]
> The script replaces the full secret. A setting that is not in the file is removed from the secret.

1. Get the production `.env` file. Keep it out of Git. The file `.gitignore` contains `.env` and `.env.production`.
2. Change the values in the file.
3. Make sure that the script can read the file:

   ```bash
   python infra/aws/scripts/put-env-secret.py <path-to-env-file> --dry-run
   ```

   The script shows each name and the length of each value. With `--dry-run`, it does not connect to AWS.

4. Run the script without `--dry-run`:

   ```bash
   python infra/aws/scripts/put-env-secret.py <path-to-env-file>
   ```

5. Read the names that the script shows as `added`, `removed` and `changed`.
6. If the names are correct, type `y`. If they are not correct, type `n`.
7. Start new tasks for each service. Refer to [operations.md](operations.md#procedure-start-new-tasks).

The AWS user must have the permissions `secretsmanager:GetSecretValue` and `secretsmanager:PutSecretValue`.

### Method B: the AWS console

> [!CAUTION]
> The console shows all values on the screen. Make sure that nobody else can see your screen. Do not make a screenshot.

> [!CAUTION]
> Also change the value in the production `.env` file. If you do not, the next run of the script (method A) replaces your change with the old value.

1. Open **Secrets Manager > Secrets > filipinohomes/api**.
2. In **Secret value**, select **Retrieve secret value**.
3. Select **Edit**.
4. On the **Key/value** tab, change the value. To add a setting, select **Add row**.
5. Write the value as Laravel reads it, with no quotes from the `.env` syntax. Example: for `APP_NAME="Filipino Homes"`, write `Filipino Homes`.
6. Select **Save**. Secrets Manager makes a new version.
7. Change the same value in the production `.env` file.
8. Start new tasks for each service. Refer to [operations.md](operations.md#procedure-start-new-tasks).

## Procedure: go back to the previous value

Secrets Manager keeps the previous value with the label `AWSPREVIOUS`.

The console cannot move a version label. Use the AWS CLI in a terminal or in AWS CloudShell.

1. Show the versions and their labels:

   ```bash
   aws secretsmanager list-secret-version-ids --region ap-southeast-1 --secret-id filipinohomes/api \
     --query "Versions[].[VersionId,join(',',VersionStages),CreatedDate]" --output table
   ```

2. Move the label `AWSCURRENT` to the previous version:

   ```bash
   aws secretsmanager update-secret-version-stage --region ap-southeast-1 --secret-id filipinohomes/api \
     --version-stage AWSCURRENT \
     --move-to-version-id <previous-version-id> --remove-from-version-id <current-version-id>
   ```

3. Start new tasks for each service. Refer to [operations.md](operations.md#procedure-start-new-tasks).

## AWS credentials of the application

The application calls Amazon S3 and other AWS services with the AWS keys in its settings. The task role does not give these permissions. The task role lets ECS Exec operate only. Refer to [ecs.md](ecs.md#iam-roles).
