# Network

This document describes the VPC, the subnets, the routes and the access to the LR database.

## Summary

- Filipino Homes uses `shared-vpc` (10.0.0.0/16). Other systems also use this VPC.
- The ECS instances are in two private subnets of Filipino Homes, in `ap-southeast-1a` and `ap-southeast-1b`.
- The load balancer is in the two shared public subnets.
- Traffic to the internet goes through the shared NAT gateway.
- Traffic to the LR database goes through a VPC peering connection to the default VPC.

## shared-vpc

Before 2026-11-13, the name of `shared-vpc` was `homes-news-vpc`. Now many systems use it. Its shared parts got names that start with `shared-`, and a `FormerName` tag. homes-news keeps its own private subnets, route tables and endpoints in this VPC.

| Name | Type | Address range or zone |
|---|---|---|
| `shared-vpc` | VPC | 10.0.0.0/16 |
| `shared-igw` | Internet gateway | - |
| `shared-subnet-public1-ap-southeast-1a` | Public subnet | 10.0.0.0/20 |
| `shared-subnet-public2-ap-southeast-1b` | Public subnet | 10.0.16.0/20 |
| `shared-rtb-public` | Route table of the public subnets | - |
| `shared-rtb-main` | Main route table of the VPC | - |
| `shared-nat-public1-ap-southeast-1a` | NAT gateway, zonal | ap-southeast-1a |
| `shared-eip-ap-southeast-1a` | Elastic IP address of the NAT gateway | ap-southeast-1a |
| `shared-pcx-default-vpc` | VPC peering connection to the default VPC | - |

> [!CAUTION]
> Other systems use these parts. Do not change them from a Filipino Homes stack. The Filipino Homes templates refer to them only by ID.

## Filipino Homes subnets

Stack: `filipinohomes-network`.

| Name | Type | Address range | Zone |
|---|---|---|---|
| `filipinohomes-subnet-private1-ap-southeast-1a` | Private subnet | 10.0.160.0/22 | ap-southeast-1a |
| `filipinohomes-subnet-private2-ap-southeast-1b` | Private subnet | 10.0.164.0/22 | ap-southeast-1b |
| `filipinohomes-rtb-private` | Route table of the two private subnets | - | - |
| `filipinohomes-vpce-s3` | S3 gateway endpoint | - | - |

The subnets do not give public IP addresses to instances.

Routes in `filipinohomes-rtb-private`:

| Destination | Target | Function |
|---|---|---|
| 10.0.0.0/16 | `local` | Traffic in `shared-vpc`, for example from the load balancer |
| 0.0.0.0/0 | `shared-nat-public1-ap-southeast-1a` | The internet and the AWS APIs |
| 172.31.0.0/16 | `shared-pcx-default-vpc` | The default VPC, where the LR database is |
| The S3 prefix list | `filipinohomes-vpce-s3` | Amazon S3. The image layers of ECR also come from S3. |

The S3 gateway endpoint has no cost. Traffic through the endpoint does not go through the NAT gateway, so it has no NAT data cost.

One route table is sufficient for the two subnets, because there is only one NAT gateway.

## Address plan

Each system in `shared-vpc` gets one address block in the private half of the VPC (10.0.128.0/17).

| System | Address block |
|---|---|
| homes-news | 10.0.128.0/19 |
| Filipino Homes | 10.0.160.0/21 |

Give each new system a free /21 block in 10.0.128.0/17. The load balancer of each system uses the shared public subnets.

## Access to the LR database

The LR database (`lrdblaravelinstance`, RDS for MySQL 8.0) is in the default VPC (172.31.0.0/16). Many other LR applications also use it.

Three parts give the access:

1. The peering connection `shared-pcx-default-vpc` (stack `shared-vpc-peering-default`) connects `shared-vpc` and the default VPC.
2. The route 172.31.0.0/16 in `filipinohomes-rtb-private` sends the traffic from Filipino Homes into the peering connection.
3. The stack `filipinohomes-lr-db-access` adds two items in the default VPC:
   - A route to 10.0.160.0/21 through the peering connection, in the main route table of the default VPC. This route sends the return traffic.
   - An inbound rule on the security group of the database: TCP 3306 from 10.0.160.0/21.

When you delete the stack `filipinohomes-lr-db-access`, AWS removes these two items only.

The peering connection has the DNS option `AllowDnsResolutionFromRemoteVpc` on the accepter side. With this option, the endpoint hostname of the database resolves to its private IP address in `shared-vpc`. CloudFormation cannot set this option. Refer to [stacks.md](stacks.md#changes-outside-cloudformation).

Each other system in `shared-vpc` adds its own routes on the same peering connection.

> [!CAUTION]
> The LR database and its security group are shared LR infrastructure. Change them only through the stack `filipinohomes-lr-db-access`. Get the approval of the owner of the LR infrastructure first.

## Security groups

| Name | Inbound | Outbound |
|---|---|---|
| `filipinohomes-sg-alb` | TCP 80 and TCP 443 from 0.0.0.0/0 | TCP 32768–65535 to 10.0.160.0/21 only |
| `filipinohomes-sg-ecs` | TCP 32768–65535 from `filipinohomes-sg-alb` only | All traffic |

The ECS instances have no SSH key and no public IP address. For a shell on an instance, use Session Manager. Refer to [operations.md](operations.md).

## Verification

- **2026-11-13:** Reachability Analyzer found a path from a Filipino Homes subnet to the database on TCP 3306. It also found a path to the internet through the NAT gateway and the internet gateway.
- **2026-11-16:** On ECS, the artisan task ran `php artisan migrate:status` with exit code 0. This result shows that the DNS resolution, the network path and the MySQL login operate correctly.
