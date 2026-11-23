# The MCP server: Claude reading Filipino Homes

`POST /mcp/filipinohomes` is an MCP (Model Context Protocol) endpoint. Claude (Claude Code, the Claude apps, or a program that uses the Claude API) connects to it with a key and can read Filipino Homes' numbers through ten tools. It is **read-only**: nothing it offers can create, change, delete or send anything, and it cannot reach the database, a file or a server.

The code is in `app/Mcp`, the route in `routes/ai.php`, the dials in `config/fhmcp.php`, the tests in `tests/Feature/FhMcpTest.php`.

## Who sees what

An admin and an agent are shown different tools. Each person sees only their own five. What differs is all in two small classes, `app/Mcp/Policies/AdminPolicy.php` and `AgentPolicy.php`.

| | Admin (`fh_*` tools) | Agent (`my_*` tools) |
|---|---|---|
| Tools | `fh_metrics_catalog`, `fh_metric`, `fh_metric_breakdown`, `fh_metric_records`, `fh_lookup` | `my_metrics_catalog`, `my_metric`, `my_metric_breakdown`, `my_metric_records`, `my_lookup` |
| Whose numbers | The whole company | **Only their own.** Every ask is forced to their agent id, after whatever they sent |
| Numbers | Every metric in the catalog | Those that can be cut to one agent. Not `agents.*` (headcount, dormant...), `usage.*`, `teams.*`, `leads.to_idle_agents` |
| Filters | Team, agent, office region, PSA region, island, province, city, project, type, category, status | Province, city, project, type, category, status, PSA region, island. Never agent, team or office |
| Splits | Team, office region, project, city, province, category, type, agent | Project, city, province, category, type |
| Lookups | Teams, projects, regions, provinces, cities, types | Projects, provinces, cities, types |

Anyone else (clients, editors, secretaries, an agent whose account is not active, an agent with no agent row) cannot hold a key, and a key whose person stops qualifying stops working on its next request.

## Why "delete all the tables" cannot happen

Not because Claude is told no, though the server's instructions do tell it to refuse. Because there is nothing to call. The protection is in layers, so one mistake is not enough:

1. **No tool writes.** There is no create, update, delete, send, SQL, shell, file or "run this" tool, and no generic tool one could be added through. Every tool is built on `FhTool`, which marks it read-only, and `tests/Feature/FhMcpTest` reads all of the code under `app/Mcp` (apart from the audit trail and the key store) and fails if any of it can write, run a command or reach out.
2. **A call can write nothing but its own record.** A test watches the database during a call of every tool and fails on any write that is not the audit row or the key's last-used stamp.
3. **The numbers are FH One's.** The tools call the same services as the AI Report and FH One's API (`MetricService`, `LookupService`), which are read-only and leave out phone numbers and email addresses.
4. **A key opens this endpoint and nothing else.** It is not a Sanctum token: it lives in its own table (`mcp_keys`), and the rest of the API does not know it. A key pasted into a connector, or leaked, cannot call any other endpoint, and an ordinary login token is no use here.
5. **The role is judged on every request**, not kept on the key, so demoting a person or switching off an agent cuts their keys at once. A tool the model was never shown is also refused if it is named anyway.
6. **Arguments are validated and only what passed reaches the tool.** An argument a tool did not declare is dropped. An agent's own agent id is applied last and wins over anything sent.
7. **What goes back is scrubbed and capped** (`McpOutput`): fields that carry contact details or secrets are dropped, an email or a Philippine mobile number written inside a text is blanked, and an answer over `max_result_bytes` is refused with advice to narrow the question.
8. **Every tool call is on record** in `mcp_calls`: who, which tool, with what (without secrets), and how it went. Wrong keys are counted per address and cut off; each key has a per-minute limit.

What is **not** covered, so you can decide about it:

- **What Claude is shown, Anthropic receives.** An admin key reads company-wide numbers and record lists (listing titles, agent and team names, counts). Keep to the key rules below and decide what you are comfortable sending.
- **Text inside records is data, not instructions**, and the server says so, but a model can still be misled by a listing title. That is why the tools only read: the worst it can do is say something wrong.
- **The database user is not read-only.** The tools never write, and the tests prove it, but the connection could. For a belt as well as braces, give the MCP its own read-only database user (not done).

## Making and managing keys

A key belongs to one person, is shown once when it is made, and only its SHA-256 is stored. It lasts 30 days unless asked otherwise, at most 90, and a person may hold three at a time.

```
php artisan fhmcp:make-key someone@filipinohomes.com --name="Claude Code on my laptop" --days=30
php artisan fhmcp:keys                       # who holds what (the first characters of each key, never the key)
php artisan fhmcp:keys --user=someone@filipinohomes.com --all
php artisan fhmcp:revoke-key 12              # one key, by its number
php artisan fhmcp:revoke-key --user=someone@filipinohomes.com   # all of a person's keys
```

On the ECS servers, run them as a one-off artisan task ([operations.md](operations.md#procedure-run-a-one-off-artisan-command)). **A key printed there lands in the task's CloudWatch log stream** (`/ecs/filipinohomes/artisan`): read it, then delete that log stream. Keys never go in a chat, a ticket or Git. To replace a key, make the new one, switch the connector over, then revoke the old one.

Revoke at once when someone leaves or a key may have been seen. The person's account does not need to change.

## Connecting Claude

The server is `https://<the API's address>/mcp/filipinohomes` and takes the key as `Authorization: Bearer <key>`.

- **Claude Code:** `claude mcp add --transport http filipinohomes https://<address>/mcp/filipinohomes --header "Authorization: Bearer <key>"`, then `/mcp` shows it connected.
- **claude.ai or Claude Desktop (a custom connector):** the URL above, with a request header `Authorization` = `Bearer <key>`. claude.ai probes a connector without a key when it is added and may refuse it on a 401. If it does, set `FH_MCP_OPEN_HANDSHAKE=true`: a client without a key can then start the conversation and list the tools, and still cannot run one.
- **The Claude API (FH One):** `mcp_servers: [{ type: "url", url: "...", name: "fh", authorization_token: "<key>" }]` with `tools: [{ type: "mcp_toolset", mcp_server_name: "fh" }]` and the beta header `mcp-client-2025-11-20`.
- **Trying it locally:** `php artisan mcp:inspector mcp/filipinohomes`.

## The dials (`config/fhmcp.php`)

| Env | Default | What |
|---|---|---|
| `FH_MCP_OPEN_HANDSHAKE` | false | A client with no key may do the handshake and list the tools (never run one) |
| `FH_MCP_RATE_LIMIT` | 60 | Requests a minute on one key |
| `FH_MCP_IP_FAILURES` | 20 | Wrong keys a minute from one address before it is cut off |
| `FH_MCP_IP_RATE_LIMIT` | 30 | Key-less requests a minute from one address (open handshake only) |

The number of keys, how long they last, the biggest answer (60,000 bytes) and the most records on a page (25) are set in the file.

## Deploying it

1. The change adds two tables, `mcp_keys` and `mcp_calls`. The deploy workflow stops while a migration is pending: run it as in [releases.md](releases.md#procedure-run-pending-migrations).
2. The endpoint has to be reachable from the internet over HTTPS at `/mcp/filipinohomes` (Claude's servers connect to it): check the load balancer and Cloudflare let it through, and that no bot rule blocks Anthropic.
3. `route:cache` includes `routes/ai.php`; nothing else to configure.

## Adding a tool safely

Extend one of the kinds in `app/Mcp/Tools/Kinds` (or `FhTool` for a new shape) and give it a policy. A tool returns data from a service that only reads. If a tool needs something that writes, it is not an MCP tool: build it with a person confirming in the FH admin. `FhMcpTest` has to stay green, including the scan of the tools' code and the watch on database writes. Do not add anything that takes a statement, a command, a path or a URL as an argument.

## Troubleshooting

- **401:** no key, a wrong one, an expired one or a revoked one (the answer is the same on purpose; the reason is in the log as `fhmcp.rejected`).
- **403:** the key is real but its person may no longer use the server (an agent switched off, a role changed).
- **429:** too many wrong keys from one address, or too many requests on one key. `Retry-After` says when.
- **"too big":** the answer passed `max_result_bytes`. Ask for a shorter period, a narrower filter or fewer rows.
