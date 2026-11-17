# CloudFormation stacks

This document lists the CloudFormation stacks of the setup. It also gives the procedures to deploy, change and delete them.

## Stacks

Deploy the stacks in this sequence. A stack can import an export only from a stack that is above it in the table.

| # | Stack | Contents | Imports from | Exports |
|---|---|---|---|---|
| 1 | `shared-vpc-peering-default` | The peering connection `shared-pcx-default-vpc` between `shared-vpc` and the default VPC | - | `PeeringConnectionId` |
| 2 | `filipinohomes-network` | Two private subnets, their route table, the S3 gateway endpoint | 1 | `VpcId`, `PrivateSubnet1Id`, `PrivateSubnet2Id`, `PrivateRouteTableId` |
| 3 | `filipinohomes-lr-db-access` | One route in the default VPC, one MySQL rule on the database security group | 1 | - |
| 4 | `filipinohomes-ecr` | The ECR repository `filipinohomes/api` | - | `RepositoryName`, `RepositoryUri`, `RepositoryArn` |
| 5 | `filipinohomes-secrets` | The secret `filipinohomes/api`, with no value | - | `ApiSecretArn` |
| 6 | `filipinohomes-github` | The IAM roles for GitHub Actions | 4 | `EcrPushRoleArn`, `EcsDeployRoleArn` |
| 7 | `filipinohomes-alb` | The load balancer, its security group, the target group, the listeners | 2 | `LoadBalancerDnsName`, `LoadBalancerFullName`, `AlbSecurityGroupId`, `WebTargetGroupArn` |
| 8 | `filipinohomes-ecs-cluster` | The cluster, the instance security group and role, the launch template, the Auto Scaling group, the capacity provider | 2, 7 | `ClusterName`, `CapacityProviderName`, `InstanceSecurityGroupId`, `AutoScalingGroupName` |
| 9 | `filipinohomes-ecs-services` | The log groups, the task roles, the task definitions, the services | 4, 5, 7, 8 | `TaskExecutionRoleArn`, `TaskRoleArn` |

The full name of an export is `{stack}-{OutputKey}`, for example `filipinohomes-network-VpcId`.

Each template is `infra/aws/{stack}.yaml`. The comments at the top of each template give its deploy command.

| Stack | Tag `Project` | Option `--capabilities` |
|---|---|---|
| `shared-vpc-peering-default` | `shared` | - |
| `filipinohomes-github`, `filipinohomes-ecs-cluster`, `filipinohomes-ecs-services` | `filipinohomes` | `CAPABILITY_NAMED_IAM` |
| All other stacks | `filipinohomes` | - |

Two resources stay when you delete their stack (`DeletionPolicy: Retain`):

- The ECR repository `filipinohomes/api`
- The secret `filipinohomes/api`

## Changes outside CloudFormation

These changes are not in a template. If you make the setup again, do them by hand.

| Change | Reason |
|---|---|
| The DNS option `AllowDnsResolutionFromRemoteVpc=true` on the accepter side of `shared-pcx-default-vpc` | CloudFormation cannot set this option. The command is in `infra/aws/shared-vpc-peering-default.yaml`. |
| The names and the `FormerName` tags of the shared parts of `shared-vpc` | These parts were in the VPC of homes-news before. Refer to [network.md](network.md). |
| The ACM certificate for `api.filipinohomes.com` | A person requested it on 2026-11-16. Refer to [load-balancer.md](load-balancer.md). |
| The tags on the launch template | CloudFormation sets the tags of a launch template only when it creates the launch template. |
| The tags on the instance profile `filipinohomes-ecs-instance-role` | CloudFormation cannot tag an instance profile. |
| The tag `Project` on the ACM certificate | The certificate is not in a stack. |
| The tags on the MySQL rule in the default VPC | CloudFormation does not tag a security group rule. |
| The value of the secret | Refer to [settings.md](settings.md). |
| The GitHub secrets `AWS_ROLE_ARN` and `AWS_DEPLOY_ROLE_ARN` | Refer to [ci-cd.md](ci-cd.md). |

The GitHub OIDC provider `token.actions.githubusercontent.com` is an account resource. Other systems also use it. It has no tags, and no stack of this repository manages it.

## Procedure: deploy a template change

> [!CAUTION]
> Deploy only templates from the `main` branch. Then the repository and AWS agree.

> [!CAUTION]
> For `filipinohomes-ecs-services`, do not put `ImageTag` in `--parameter-overrides`. If you do not give it, the stack keeps the image that the deploy workflow deployed.

1. Make a change set. Add `--capabilities CAPABILITY_NAMED_IAM` if the stack contains IAM resources:

   ```bash
   aws cloudformation deploy --region ap-southeast-1 \
     --stack-name <stack> --template-file infra/aws/<stack>.yaml \
     --tags Project=filipinohomes --no-execute-changeset
   ```

2. Read the change set. The output of step 1 shows the `describe-change-set` command for it.
3. Make sure that the change set adds, changes and removes only the resources that you expect.
4. Run the change set:

   ```bash
   aws cloudformation execute-change-set --region ap-southeast-1 --change-set-name <change-set-arn>
   ```

5. Wait until the update stops:

   ```bash
   aws cloudformation wait stack-update-complete --region ap-southeast-1 --stack-name <stack>
   ```

6. Make sure that the stack status is `UPDATE_COMPLETE`:

   ```bash
   aws cloudformation describe-stacks --region ap-southeast-1 --stack-name <stack> \
     --query "Stacks[0].StackStatus" --output text
   ```

7. Examine the changed resources with read-only commands (`describe-*`, `list-*`, `get-*`).

> [!NOTE]
> On a stack that exists, `deploy` keeps the previous value of each parameter. A new default value in a template does not change the deployed value. To change a value, give it in `--parameter-overrides`.

## Procedure: change the name of an export

Another stack can import the export. For this reason, you cannot remove or change the export in one step.

1. Add the new export, and keep the old export.
2. Deploy the stack.
3. Change each stack that imports the old export to the new name. Use `--parameter-overrides`.
4. Remove the old export.
5. Deploy the stack again.

## Procedure: delete the setup

Delete the stacks in the opposite sequence of the table.

1. Set `WebCount`, `WorkerCount` and `SchedulerCount` to 0 on `filipinohomes-ecs-services`. ECS protects instances that have tasks from scale-in.
2. Delete `filipinohomes-ecs-services`.
3. Delete `filipinohomes-ecs-cluster`.
4. Delete `filipinohomes-alb` and `filipinohomes-github`.
5. Delete `filipinohomes-lr-db-access`.
6. Delete `filipinohomes-network`.
7. Delete `shared-vpc-peering-default` only after all stacks that import its export are deleted. Other systems can use it.
8. If you must also remove the ECR repository or the secret, delete them by hand. Their stacks do not delete them.

## Drift detection

On 2026-11-16, all stacks were `IN_SYNC` with one exception. Drift detection shows the tag `AmazonECSManaged` on the Auto Scaling group as removed. This result is not correct. The tag is there, but its value is empty, and drift detection shows empty tags as removed. Ignore this result.
