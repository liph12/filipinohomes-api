# Load balancer and HTTPS

This document describes the load balancer, the target group, HTTPS, Cloudflare and the client IP address.

Stack: `filipinohomes-alb`. Template: `infra/aws/filipinohomes-alb.yaml`.

## Load balancer

| Item | Value |
|---|---|
| Name | `filipinohomes-alb` |
| Type | Application Load Balancer, internet-facing, IPv4 |
| Subnets | `shared-subnet-public1-ap-southeast-1a`, `shared-subnet-public2-ap-southeast-1b` |
| Security group | `filipinohomes-sg-alb` |
| Idle timeout | 120 seconds |
| Header fields that are not valid | Dropped |

The idle timeout is longer than the origin timeout of Cloudflare (100 seconds). Because of this, Cloudflare stops a slow request before the load balancer stops it.

## Target group

| Item | Value |
|---|---|
| Name | `filipinohomes-tg-web` |
| Target type | Instance |
| Protocol | HTTP/1.1 |
| Port | Dynamic. Each web container registers with its own host port. |
| Health check | `GET /up` each 15 seconds, timeout 5 seconds |
| Healthy threshold | 2 results in sequence |
| Unhealthy threshold | 3 results in sequence |
| Success code | 200 |
| Deregistration delay | 30 seconds |

`/up` is the health route of Laravel (`bootstrap/app.php`). It gives the code 200 when the application starts correctly. It does not use the database.

## Listeners

| Port | Condition | Action |
|---|---|---|
| 80 (HTTP) | No certificate | Forward to `filipinohomes-tg-web` |
| 80 (HTTP) | Certificate, and `HttpAction=forward` | Forward to `filipinohomes-tg-web` |
| 80 (HTTP) | Certificate, and `HttpAction=redirect` | Redirect to HTTPS (code 301) |
| 443 (HTTPS) | Certificate only | Forward to `filipinohomes-tg-web`, with the TLS policy `ELBSecurityPolicy-TLS13-1-2-2021-06` |

> [!NOTE]
> On 2026-11-17, the load balancer has no certificate. It serves HTTP on port 80 only.

## Parameters

| Parameter | Default | Function |
|---|---|---|
| `CertificateArn` | Empty | The ACM certificate for port 443. If it is empty, there is no HTTPS listener. |
| `HttpAction` | `forward` | The action of port 80 when there is a certificate |
| `IdleTimeoutSeconds` | 120 | The idle timeout |
| `TargetCidr` | 10.0.160.0/21 | The only destination of the outbound traffic of the load balancer |
| `PublicSubnet1Id`, `PublicSubnet2Id` | The two shared public subnets | The subnets of the load balancer |

## Procedure: add HTTPS

An ACM certificate for `api.filipinohomes.com` was requested on 2026-11-16, with DNS validation. The DNS zone of `filipinohomes.com` is in Cloudflare.

> [!CAUTION]
> ACM stops the validation if it does not find the record in 72 hours. The status then changes to `VALIDATION_TIMED_OUT`. You must then request a new certificate and do this procedure again.

1. Find the ARN and the status of the certificate:

   ```bash
   aws acm list-certificates --region ap-southeast-1 \
     --query "CertificateSummaryList[?DomainName=='api.filipinohomes.com'].[CertificateArn,Status]" \
     --output text
   ```

2. Get the validation record:

   ```bash
   aws acm describe-certificate --region ap-southeast-1 --certificate-arn <certificate-arn> \
     --query "Certificate.DomainValidationOptions[0].ResourceRecord"
   ```

3. In Cloudflare, add this record as a CNAME record.
4. Set the proxy status of the record to **DNS only**.
5. Keep the record after the validation. ACM uses it again when it renews the certificate.
6. Wait until the status of the certificate is `ISSUED`:

   ```bash
   aws acm wait certificate-validated --region ap-southeast-1 --certificate-arn <certificate-arn>
   ```

7. Make a change set that adds the certificate. Keep `HttpAction=forward`:

   ```bash
   aws cloudformation deploy --region ap-southeast-1 --stack-name filipinohomes-alb \
     --template-file infra/aws/filipinohomes-alb.yaml --tags Project=filipinohomes \
     --parameter-overrides CertificateArn=<certificate-arn> --no-execute-changeset
   ```

8. Read and run the change set. Refer to [stacks.md](stacks.md#procedure-deploy-a-template-change).
9. Make sure that there is a listener on port 443:

   ```bash
   aws elbv2 describe-listeners --region ap-southeast-1 \
     --load-balancer-arn "$(aws elbv2 describe-load-balancers --region ap-southeast-1 \
       --names filipinohomes-alb --query "LoadBalancers[0].LoadBalancerArn" --output text)" \
     --query "Listeners[].[Port,Protocol]" --output table
   ```

## Procedure: redirect HTTP to HTTPS

> [!CAUTION]
> Do this procedure only when the Cloudflare SSL/TLS mode for `api.filipinohomes.com` is **Full (strict)**. In the **Flexible** mode, Cloudflare connects to the origin with HTTP. Each request then redirects to the same address again, without end.

1. In Cloudflare, set the SSL/TLS mode for `api.filipinohomes.com` to **Full (strict)**.

   > [!NOTE]
   > The SSL/TLS mode on the overview page applies to all hostnames in the zone. To change it for `api.filipinohomes.com` only, use a Cloudflare configuration rule.

2. Make sure that `https://api.filipinohomes.com/up` gives the code 200.
3. Make a change set with `HttpAction=redirect`:

   ```bash
   aws cloudformation deploy --region ap-southeast-1 --stack-name filipinohomes-alb \
     --template-file infra/aws/filipinohomes-alb.yaml --tags Project=filipinohomes \
     --parameter-overrides HttpAction=redirect --no-execute-changeset
   ```

4. Read and run the change set.

## Cloudflare

- The DNS zone of `filipinohomes.com` is in Cloudflare. The zone of `filipinohomes.ph` is in Route 53.
- At the cutover, `api.filipinohomes.com` becomes a proxied CNAME record to the DNS name of the load balancer.
- The stack output `LoadBalancerDnsName` gives the DNS name:

  ```bash
  aws cloudformation describe-stacks --region ap-southeast-1 --stack-name filipinohomes-alb \
    --query "Stacks[0].Outputs[?OutputKey=='LoadBalancerDnsName'].OutputValue" --output text
  ```

- Do not write this DNS name in the repository. The deploy workflow also hides it in its logs.

## Client IP address

The application gets the IP address of the client from the header `X-Forwarded-For`. The file `bootstrap/app.php` trusts this header only from these sources:

- The IP ranges of Cloudflare
- `127.0.0.1` and `::1`
- The two shared public subnets (10.0.0.0/20 and 10.0.16.0/20), where the load balancer is

If the load balancer moves to other subnets, change the list in `bootstrap/app.php`.
