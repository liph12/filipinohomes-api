# CI/CD

This document describes the GitHub Actions workflows, their IAM roles, the ECR repository and the Git remotes.

## Summary

- A push to `main` starts the workflow `Build image`.
- The workflow builds the image, tests it, and pushes it to ECR as `filipinohomes/api:<commit-sha>`.
- Then it calls the workflow `Deploy to ECS`, which deploys the image to the services.
- The workflows store no AWS keys. They get temporary credentials with the GitHub OIDC token.
- Only workflow runs on `main` of `orenji-neko/filipinohomes-api` can get these credentials.

## Workflow: Build image

File: `.github/workflows/build-image.yml`.

| Trigger | Result |
|---|---|
| Push to `main` | Build, test, push to ECR, deploy |
| Push to a `ci/**` branch | Build and test only |
| Manual start (`workflow_dispatch`) on `main` | Build, test, push to ECR, deploy |
| Push that changes only `infra/aws/**` | No run. These files are not in the image. |

Steps of the job `build`:

1. Build the image for `linux/amd64`.
2. Run `tests/docker/smoke-test.sh`. Refer to [container-image.md](container-image.md#procedure-build-and-test-the-image-on-a-workstation).
3. On `main` only: get credentials for `filipinohomes-github-ecr-push-role`.
4. If ECR has the tag of this commit, do not push.
5. Push the image to ECR.
6. Show the result of the ECR scan in the job summary. If the scan has a problem, the step shows a warning. The run continues.
7. Log out of ECR.

The job `deploy` then calls `Deploy to ECS` with the commit SHA. This job runs on `main` only.

The workflow gets the credentials only after the test passes.

## Workflow: Deploy to ECS

File: `.github/workflows/deploy-ecs.yml`.

| Trigger | Input |
|---|---|
| Called by `Build image` | The commit SHA of the push |
| Manual start on `main`: **Actions > Deploy to ECS > Run workflow** | A full commit SHA (40 characters) whose image is in ECR |

Only one deployment runs at a time. If many deployments wait, only the newest one runs.

Steps:

1. Make sure that the image tag is a full commit SHA.
2. Get credentials for `filipinohomes-github-ecs-deploy-role`.
3. Make sure that ECR has the image.
4. Make sure that the status of `filipinohomes-ecs-services` ends in `_COMPLETE`. If the services use the image, stop with no change.
5. Set `ArtisanImageTag` to the new image. The services continue on the old image.
6. Run `php artisan migrate:status --pending=1` with the artisan task.
7. If a migration is pending, stop. The job summary shows the names of the pending migrations. Refer to [releases.md](releases.md#procedure-run-pending-migrations).
8. Set `ImageTag` to the new image, and set `ArtisanImageTag` to empty. CloudFormation waits until each service has new tasks and the health checks pass.
9. Make sure that `filipinohomes-web` uses the new image and that `/up` gives the code 200 through the load balancer.

The workflow changes only `ImageTag` and `ArtisanImageTag`. It uses the current template of the stack. All other parameters keep their values. The workflow never runs `migrate`, and it never changes the service counts.

## GitHub secrets

| Secret | Value | Used by |
|---|---|---|
| `AWS_ROLE_ARN` | The ARN of `filipinohomes-github-ecr-push-role` | `Build image` |
| `AWS_DEPLOY_ROLE_ARN` | The ARN of `filipinohomes-github-ecs-deploy-role` | `Deploy to ECS` |

The role ARNs contain the account ID. The repository is public, so the ARNs are secrets. The workflows also hide the account ID, the DNS name of the load balancer and the output of the artisan task in their logs.

### Procedure: set a GitHub secret

1. Get the ARN from the output of the stack `filipinohomes-github`, and write it into the secret. This example sets `AWS_DEPLOY_ROLE_ARN`:

   ```bash
   aws cloudformation describe-stacks --region ap-southeast-1 --stack-name filipinohomes-github \
     --query "Stacks[0].Outputs[?OutputKey=='EcsDeployRoleArn'].OutputValue" --output text \
     | gh secret set AWS_DEPLOY_ROLE_ARN -R orenji-neko/filipinohomes-api
   ```

2. For `AWS_ROLE_ARN`, use the output key `EcrPushRoleArn`.
3. Make sure that the secret exists:

   ```bash
   gh secret list -R orenji-neko/filipinohomes-api
   ```

## IAM roles for GitHub Actions

Stack: `filipinohomes-github`.

Both roles trust the GitHub OIDC provider of the account. Each role accepts only one token subject:

```text
repo:orenji-neko@<owner-id>/filipinohomes-api@<repository-id>:ref:refs/heads/main
```

The repository uses the immutable OIDC subjects of GitHub, which contain numeric IDs. If a person makes a new repository with the same name, that repository cannot get the roles.

| Role | Permissions |
|---|---|
| `filipinohomes-github-ecr-push-role` | Push images to `filipinohomes/api`, and read its images and scan results |
| `filipinohomes-github-ecs-deploy-role` | Update the stack `filipinohomes-ecs-services`, register task definitions, update the `filipinohomes-*` services, run the task `filipinohomes-artisan`, read its log, pass the two task roles to ECS |

The stack `filipinohomes-ecs-services` has no CloudFormation service role. For this reason, CloudFormation uses the permissions of the deploy role. The deploy role cannot make or change other resources. This is also true if a workflow sends a different template. Template changes stay manual. Refer to [stacks.md](stacks.md#procedure-deploy-a-template-change).

## ECR repository

Stack: `filipinohomes-ecr`.

| Item | Value |
|---|---|
| Name | `filipinohomes/api` |
| Tags | Immutable. A tag always refers to the same image. |
| Scan | On each push (basic scan) |
| Lifecycle | Keep the 30 newest images. Remove untagged images after 7 days. |
| Deletion policy | `Retain` |
| Encryption | AES-256 |

The repository policy denies a push from all principals except `filipinohomes-github-ecr-push-role`. This rule also applies to administrators. Other systems in the account have CI roles that can push to all repositories. Without this policy, they could push a tag before CI pushes it. The policy does not stop pulls or scans.

> [!NOTE]
> The IAM policy simulator ignores the statements of an ECR repository policy. To test the repository policy, try a real push.

## Git remotes

| Remote | Repository | Function |
|---|---|---|
| `origin` | `orenji-neko/filipinohomes-api` (public fork) | The AWS and ECS work. CI builds and deploys from its `main`. |
| `upstream` | `liph12/filipinohomes-api` | The feature work of the team |

### Procedure: merge the upstream changes into the fork

> [!CAUTION]
> Do not push to `upstream`. Do not rebase `main`. Its commits are on GitHub, and CI built images from them.

1. Get the upstream changes:

   ```bash
   git fetch upstream
   ```

2. Look for conflicts. This command does not change the working tree:

   ```bash
   git merge-tree --write-tree main upstream/main
   ```

3. Look for new migrations in the upstream changes:

   ```bash
   git diff --stat main...upstream/main -- database/migrations
   ```

4. Merge with a merge commit:

   ```bash
   git merge --no-ff upstream/main
   ```

5. Make sure that the block with `logging.schedule_output` is still the last block of `routes/console.php`.
6. Push to `origin`:

   ```bash
   git push origin main
   ```

The push starts a build and a deployment. If the merge contains new migrations, the deployment stops before it changes the services. Refer to [releases.md](releases.md#procedure-run-pending-migrations).
