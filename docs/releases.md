# Releases

This document describes how to release a new version, how to run migrations and how to roll back.

## Summary

- A push or a merge to `main` releases the code. Refer to [ci-cd.md](ci-cd.md).
- A release changes the image of the web, worker and scheduler services, and of the artisan task. It does not change the service counts.
- If the new code has pending migrations, the release stops before it changes the services. A person runs the migrations.
- To roll back, deploy the image of a previous commit.

> [!CAUTION]
> The LR database contains production data for many applications. Never run `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `migrate:refresh` or `db:wipe` against it.

## Procedure: release

1. Merge the change into `main`.
2. Push `main` to `origin`.
3. Open **Actions > Build image** on GitHub.
4. Wait until the run stops.
5. Make sure that the job `deploy` passed.
6. Read the job summary. It shows the new image, the previous image and the status of `filipinohomes-web`.

If the job `deploy` stopped because of pending migrations, do the procedure [run pending migrations](#procedure-run-pending-migrations).

## Procedure: run pending migrations

When the deploy workflow finds pending migrations, it stops. The artisan task then uses the new image. The services continue on the previous image.

1. Read the names of the pending migrations in the job summary.
2. Show the SQL of the migrations. Use the procedure [run a one-off artisan command](operations.md#procedure-run-a-one-off-artisan-command) with this command:

   ```json
   ["php", "artisan", "migrate", "--pretend", "--force"]
   ```

   With `--pretend`, Laravel shows the SQL and does not run it.

3. Read the SQL.
4. Get approval for the SQL from the owner of the LR database.
5. Run the migrations. Use the same procedure with this command:

   ```json
   ["php", "artisan", "migrate", "--force"]
   ```

6. Make sure that the exit code of the task is 0.
7. Read the log of the task. Make sure that each migration shows `DONE`.
8. Start the deployment again. Do one of these steps:
   - On GitHub, open the failed run of **Build image**, and select **Re-run failed jobs**.
   - Start **Actions > Deploy to ECS > Run workflow** with the same commit SHA.

> [!NOTE]
> Another team can run the same migrations from the current EC2 server before you do. Then `migrate:status` shows no pending migrations, and the deployment continues.

## Procedure: roll back

> [!CAUTION]
> A rollback changes the code only. It does not change the database. Do not use `migrate:rollback`. Make sure that the previous code operates correctly with the current tables.

1. Find the commit SHA of the previous image. Use one of these sources:
   - The job summary of the last deployment shows `Previous image`.
   - This command shows the 10 newest images in ECR:

     ```bash
     aws ecr describe-images --region ap-southeast-1 --repository-name filipinohomes/api \
       --query "sort_by(imageDetails,&imagePushedAt)[-10:].[imageTags[0],imagePushedAt]" --output table
     ```

2. On GitHub, open **Actions > Deploy to ECS**.
3. Select **Run workflow** on the branch `main`.
4. Write the full commit SHA (40 characters) in `image_tag`.
5. Select **Run workflow**.
6. Wait until the run stops.
7. Make sure that the run passed.

ECR keeps the 30 newest images. You can roll back to one of these images only.

## Automatic rollback

Each service has the deployment circuit breaker. If the new tasks do not start or do not pass the health checks, ECS goes back to the previous task definition. The CloudFormation update then fails, and CloudFormation rolls back the stack. The deploy workflow shows the failed resources.

## Procedure: release without GitHub

Use this procedure only if GitHub Actions is not available.

> [!CAUTION]
> This procedure deploys the template on your workstation. Make sure that your working tree is the same as `origin/main`.

1. Make sure that ECR has the image of the commit:

   ```bash
   aws ecr describe-images --region ap-southeast-1 --repository-name filipinohomes/api \
     --image-ids imageTag=<commit-sha>
   ```

2. Set the artisan task to the new image, and deploy:

   ```bash
   aws cloudformation deploy --region ap-southeast-1 --stack-name filipinohomes-ecs-services \
     --template-file infra/aws/filipinohomes-ecs-services.yaml --capabilities CAPABILITY_NAMED_IAM \
     --tags Project=filipinohomes --parameter-overrides ArtisanImageTag=<commit-sha>
   ```

3. Run `php artisan migrate:status` with the artisan task. Refer to [operations.md](operations.md#procedure-run-a-one-off-artisan-command).
4. If a migration is pending, do steps 2 to 7 of [run pending migrations](#procedure-run-pending-migrations).
5. Set the services to the new image, and set `ArtisanImageTag` to empty:

   ```bash
   aws cloudformation deploy --region ap-southeast-1 --stack-name filipinohomes-ecs-services \
     --template-file infra/aws/filipinohomes-ecs-services.yaml --capabilities CAPABILITY_NAMED_IAM \
     --tags Project=filipinohomes --parameter-overrides ImageTag=<commit-sha> ArtisanImageTag=
   ```

6. Make sure that the stack status is `UPDATE_COMPLETE`.
7. Examine the services. Refer to [operations.md](operations.md#procedure-examine-the-services).
