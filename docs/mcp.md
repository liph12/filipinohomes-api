# The MCP server: Claude reading Filipino Homes

`POST /mcp/filipinohomes` is an MCP (Model Context Protocol) endpoint. Claude (Claude Code, the Claude apps, or a program that uses the Claude API) connects to it with a key and can read Filipino Homes' numbers through seventeen tools (an admin is shown seven, an agent five, a team leader ten). It is **read-only**: nothing it offers can create, change, delete or send anything, and it cannot reach the database, a file or a server.

The code is in `app/Mcp`, the route in `routes/ai.php`, the dials in `config/fhmcp.php`, the tests in `tests/Feature/FhMcpTest.php` and `FhMcpTeamLeaderTest.php`. Admins make and revoke keys on the website (System Settings > Claude access); that side is `app/Http/Controllers/Admin/McpKeyController.php` and `tests/Feature/FhMcpKeysAdminTest.php`.

## Who sees what

An admin, a team leader and an agent are shown different tools. An admin sees their own seven, an agent their own five; a team leader sees the agent's five (their own numbers) and five of their team's. What differs is all in three small classes, `app/Mcp/Policies/AdminPolicy.php`, `TeamLeaderPolicy.php` and `AgentPolicy.php`.

| | Admin (`fh_*` tools) | Team leader (`team_*` tools, and `my_*` for themselves) | Agent (`my_*` tools) |
|---|---|---|---|
| Tools | `fh_metrics_catalog`, `fh_metric`, `fh_metric_breakdown`, `fh_metric_records`, `fh_lookup`, `fh_traffic`, `fh_ai_visibility` | `team_roster`, `team_metrics_catalog`, `team_metric`, `team_metric_breakdown`, `team_metric_records` | `my_metrics_catalog`, `my_metric`, `my_metric_breakdown`, `my_metric_records`, `my_lookup` |
| Whose numbers | The whole company | **Their team and its members.** Every ask is held to a team they lead: left out it is their team (or they are asked which, if they lead several), and another team, 0 ("no team") or a member who is not theirs is refused | **Only their own.** Every ask is forced to their agent id, after whatever they sent |
| Numbers | Every metric in the catalog | Those that take a team filter, not `teams.*` and not `usage.*` | Those that can be cut to one agent. Not `agents.*` (headcount, dormant...), `usage.*`, `teams.*`, `leads.to_idle_agents` |
| Filters | Team, agent, office region, PSA region, island, province, city, project, type, category, status | Team (their own), agent (a member), PSA region, island, province, city, project, type, category, status | Province, city, project, type, category, status, PSA region, island. Never agent, team or office |
| Splits | Team, office region, project, city, province, category, type, agent | Member (agent), project, city, province, category, type | Project, city, province, category, type |
| Lookups | Teams, projects, regions, provinces, cities, types | `my_lookup`: projects, provinces, cities, types | Projects, provinces, cities, types |

**Website traffic (`fh_traffic`, admins only).** Where the website's visitors came from (google, facebook, direct, referral, chatgpt...): people and visits per source, new and returning clients each source brought, the sites behind "referral", and with `geography` the top countries, regions and cities. It is the admin Audience Insights page's own services (`TrafficSourceService`, `AudienceGeographyService`) over the `visits` table, so Claude and the page agree; it reads `visits` and never writes to it. It is not a catalog metric (visits have no team, agent or listing to filter by), so the admin catalog answer points to it, and it is admin-only like the page. Answers are cached 10 minutes (6 hours for a range that has ended).

**AI visibility (`fh_ai_visibility`, admins only).** How AI assistants see and send people to the site: views (an assistant opened a page to answer someone; a proxy), crawls (an AI search index read a page) and clicks (a person arrived from an assistant; exact), per assistant and for the top pages. It is the admin AI Visibility page's own report (`AiVisibilityService` over `ai_events`), real pages only; requests from scanners posing as AI bots are only counted, as `scanner_requests_left_out`.

**A team leader is not a role in FH.** There is no such role; a leader is an agent whose place in a team (`team_agents`) is flagged `is_leader` and active. So it is worked out from the data on every request (`McpTeams`), and a leader who stops leading is an ordinary agent on the next request. A team counts only if it is active and the reports can show it: the reports credit each agent to **one** team, the lowest active team id (`TeamDirectory`), so a team none of whose members is credited to it has no numbers, and its leader stays a plain agent rather than being shown an empty view.

What a team leader should know about their numbers:

- An agent who sits in two teams is counted in the lower-numbered one only. `team_roster` says, for every member, whether the team's numbers count them. The portal counts every place, so the two can differ.
- A member who has left can still hold an active place in the team: their listings count, but they are not in the headcounts.
- The `teams.*` numbers accept a team filter and still answer for the whole company (a test proves it), so they are closed to team leaders, and a second test asks every number open to a leader for two teams and for everyone to prove each one narrows.
- A leader of several teams (the data allows it) names which with `team_id`; `team_roster` lists them.

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
- **The limits are counted per server.** The per-key and per-address limits live in the cache. Where the cache is not shared between instances (Laravel Cloud), each instance counts on its own, so the real limit is higher than the number in the config.

## Making and managing keys

A key belongs to one person, is shown once when it is made, and only its SHA-256 is stored. It lasts 30 days unless asked otherwise, at most 90, or it is made **permanent**: no end date, it works until it is revoked or its person loses access. A person may hold three at a time, and a permanent key counts toward the three until it is revoked.

### On the website (admins)

System Settings > **Claude access (MCP)** (`/admin/system-settings`). Admins only: the card says so to anyone else, and the API refuses too (`/api/admin/mcp-keys*` is in the admin-only route group).

- **New key:** for an **admin** (any admin, the signed-in one listed first with "(you)"; their key sees the whole company), a **team leader** (their team and its members) or an **agent** (only their own numbers), each picked from a search, a name to tell it apart, and for how long: 7, 30, 60 or 90 days, or Permanent. The key is shown **once**, in the dialog, with a copy button and the ready-made `claude mcp add` line. The dialog cannot be closed by a stray click or Escape while the key is showing, and after it closes the key is nowhere: the list has only its first characters. A lost key is replaced, not recovered.
- **The list** shows who a key is for, what it can see (`No access now` once its person was switched off), its first characters, when it works until (or `Permanent`), when it was last used, how many calls it made in the last 7 days, and which admin made it.
- **Revoke** is a press-and-hold and cannot be undone. It takes effect on the key's next request and records which admin did it.
- **Recent activity** lists the last tool calls (who, which tool, what was asked, answered or refused, how long). It reads `GET /api/admin/mcp-calls`, which has the key's name but never a key's secret, nor anyone's IP address.
- The address the card shows for Claude to connect to is the one the website's own API calls go to. Give Claude that one.

A permanent key is the riskiest kind: if it leaks, it works until someone notices. It is still read-only, rate-limited, on record and cut off the moment its person loses their role, but prefer days unless it runs on a machine you control, and revoke the ones that are no longer used (the list shows when each was last used).

### In a terminal

```
php artisan fhmcp:make-key someone@filipinohomes.com --name="Claude Code on my laptop" --days=30
php artisan fhmcp:make-key someone@filipinohomes.com --name="The office server" --permanent   # instead of --days
php artisan fhmcp:keys                       # who holds what (the first characters of each key, never the key)
php artisan fhmcp:keys --user=someone@filipinohomes.com --all
php artisan fhmcp:revoke-key 12              # one key, by its number
php artisan fhmcp:revoke-key --user=someone@filipinohomes.com   # all of a person's keys
php artisan fhmcp:team-check                 # counts of teams, leaders and members as the MCP server reads them (counts only)
```

On the ECS servers, run them as a one-off artisan task ([operations.md](operations.md#procedure-run-a-one-off-artisan-command)). **A key printed there lands in the task's CloudWatch log stream** (`/ecs/filipinohomes/artisan`): read it, then delete that log stream. Keys never go in a chat, a ticket or Git. To replace a key, make the new one, switch the connector over, then revoke the old one.

Revoke at once when someone leaves or a key may have been seen. The person's account does not need to change.

## Sign-in: people connect their own Claude

Instead of an admin making a key and handing it over, an admin, a team leader or an agent connects their own Claude to their own FH account. It is the way most people should connect: nothing is copied anywhere, the person can end it from their portal, and in claude.ai a key only works for the accounts that have Claude's request-headers beta.

**What the person does**

1. In the portal (**Claude** in the menu) they press **Connect to Claude**. That opens Claude's own "Add custom connector" dialog with Filipino Homes and our address filled in. Claude asks them to confirm it and to press **Connect**: those two clicks are Claude's, and FH cannot skip them.
2. Claude sends their browser to the API (`/oauth/authorize`), which checks the request and sends it on to the page on the website (`FH_MCP_CONSENT_URL`, `/connect/claude`). They sign in to FH if they are not already, confirm which account it is (or choose "use another account") and press **Authorize**. An admin's, and a program on a computer's (Claude Code), take a press and hold.
3. Back in Claude they are connected. A bell notification says so, and the portal lists their connections, each with **Disconnect**. Admins see them all on System Settings > Claude access (the **Connections** tab) and can end one, or all.

**How it works**

- It is OAuth 2.1 with PKCE (S256), the way Claude's connectors expect (Claude's own documentation: connectors > authentication). A request with no key gets a `401` that points at `/.well-known/oauth-protected-resource/mcp/filipinohomes`; that and `/.well-known/oauth-authorization-server` say where to register, sign in and ask for tokens. All of it is at the root of the API (`routes/ai.php`, `app/Http/Controllers/Mcp`, `app/Services/McpOAuth`), plus `/register`, `/authorize` and `/token`, which Claude falls back to.
- **Registering stores nothing.** The only apps that may ask a person to sign in are Claude (return address `https://claude.ai/api/mcp/auth_callback`, or `claude.com`) and Claude Code (`http://localhost|127.0.0.1|[::1]:<any port>/callback`). A fixed client id comes back (`claude` or `claude-code`); the names the person sees are ours, never what the caller said. Anything asking to be sent back anywhere else is refused, at registration and again at sign-in, and a mistake before the return address is known to be good is shown on a page of ours and goes nowhere.
- **A code is good for a minute and for one try.** It is spent before it is checked, so a wrong guess does not leave it to be tried again.
- **Tokens.** An access token (`fhoat_...`, 8 hours) and a refresh token (`fhort_...`, 60 days, slides forward each time it is used, but never past 180 days from the day the connection was made: `FH_MCP_MAX_CONNECTION_DAYS`); only their SHA-256 is kept. A refresh makes the next pair. Claude has been seen to send the same refresh twice a few milliseconds apart, to ask again when an answer was lost, and to sign in again without saying the old connection is gone, so: an old refresh token used again within 30 seconds is a retry and gets a fresh pair, and so is one used later while the newer pair has never been used; one used later, after the newer pair has been used, is in other hands and the connection ends. A person keeps at most five connections; a sixth ends the one used least recently.
- **What a person agrees to is the account the page showed.** The page tells the API which account it described when the person says yes (`account_id`), and the API refuses if the signed-in account is another by then (a second tab, say).
- **The person's role is judged on every request**, as a key's is, and the connection remembers the role it was authorized for: a person who has since become a team leader, or stopped being one, has their connection ended and Claude asks them to connect again, so what they agreed to is always what Claude can see. A person who is switched off gets a `403` and the connection ends there: switching them back on does not bring it back, they agree again. A connection also ends when its person signs out everywhere, or has their password reset by the staff seeder (reasons `logged_out`, `no_access`, `role_changed`, `refresh_reuse`, `user`, `admin`, `claude`, `replaced`).
- **A connection opens `/mcp/filipinohomes` and nothing else.** It is not a Sanctum token and the rest of the API does not know it. Calls made through it are in `mcp_calls` with the connection (`mcp_connection_id`).

**The switch** (System Settings > Claude access; `Setting` `fhmcp.oauth`, first value from `FH_MCP_OAUTH`)

| | |
|---|---|
| **off** (the start) | No sign-in route answers (404) and a `401` says nothing about signing in. Also off on any address that is not `https` (plain `http` works for localhost only, for trying it) |
| **admins** | Only admins may connect or use a connection (everyone else gets a `403`). The documents and refreshes still answer, so connectors already made are not stranded. **This is also how to pause after launch**: turning it off would make Claude forget where to sign in |
| **on** | Everyone who has a role on the MCP server (an admin, a team leader, an active agent) |

Ending connections is a separate button (**Disconnect everyone**, press and hold), never a side effect of the switch. A keyless handshake (`FH_MCP_OPEN_HANDSHAKE`) is not allowed while sign-in is on: the `401` is what starts the sign-in.

**If the API's address ever changes**

Everyone's Claude is connected to the address in `FH_MCP_PUBLIC_URL`, so changing it means telling people to connect again. To make it painless:

1. Put the old address in `FH_MCP_FORMER_URLS` (comma separated) and the new one in `FH_MCP_PUBLIC_URL`. While an address is listed, connections made for it keep working, and their people see **Reconnect** on their portal page.
2. On System Settings > Claude access, press **Notify them**: a bell notification (and a push to those with the app) goes to every person with a connection made for the old address, and to every key holder, once for each person.
3. When they have moved over, take the old address out of `FH_MCP_FORMER_URLS`: what was made for it stops, and Claude asks the person to sign in again.

**Deploying it**

1. Run the pending migrations (`000004` makes `mcp_connections`, `mcp_oauth_tokens`, `mcp_oauth_requests` and adds `mcp_calls.mcp_connection_id`). Until it has run, nothing about sign-in works, and keys and the audit are unaffected.
2. Set `FH_MCP_PUBLIC_URL` to the API's `https` address and `FH_MCP_CONSENT_URL` to the website's page if it is not `https://filipinohomes.com/connect/claude`.
3. Claude connects from Anthropic's servers (`160.79.104.0/21`, IPv4): Cloudflare, or any firewall in front of the API, must let them through to `/mcp`, `/.well-known/*`, `/oauth/*`, `/register`, `/authorize` and `/token`, and the API's address must not redirect to another host (Claude drops the key on a redirect). From outside, `curl -i https://<address>/.well-known/oauth-authorization-server` should answer `200` once the switch is on, and `curl -i -X POST https://<address>/mcp/filipinohomes` should answer `401` with a `WWW-Authenticate` header that has `resource_metadata`.
4. Set the switch to **admins**, connect your own Claude (claude.ai and Claude Code), ask a question, and check the list, the activity and the bell. Then **on**.

## Connecting Claude

The server is `https://<the API's address>/mcp/filipinohomes` and takes the key as `Authorization: Bearer <key>`.

- **Claude Code:** `claude mcp add --transport http filipinohomes https://<address>/mcp/filipinohomes --header "Authorization: Bearer <key>"`, then `/mcp` shows it connected.
- **claude.ai or Claude Desktop (a custom connector):** a key only fits where the connector dialog has a **Request headers** section (a Claude beta that not every account has): the URL above, with `Authorization` = `Bearer <key>`. Without that section claude.ai wants a sign-in instead (the person signs in to FH and authorizes): see [Sign-in](#sign-in-people-connect-their-own-claude), which is how most people should connect. claude.ai probes a connector without a key when it is added and may refuse it on a 401. If it does, set `FH_MCP_OPEN_HANDSHAKE=true`: a client without a key can then start the conversation and list the tools, and still cannot run one.
- **The Claude API (FH One):** `mcp_servers: [{ type: "url", url: "...", name: "fh", authorization_token: "<key>" }]` with `tools: [{ type: "mcp_toolset", mcp_server_name: "fh" }]` and the beta header `mcp-client-2025-11-20`.
- **Trying it locally:** `php artisan mcp:inspector mcp/filipinohomes`.

## The dials (`config/fhmcp.php`)

| Env | Default | What |
|---|---|---|
| `FH_MCP_PUBLIC_URL` | (the request's own address) | The API's public address, like `https://xxx.laravel.cloud` (no path, no trailing slash). It is what the dashboard shows and what a new key's connect command uses. **Set it on production**: behind the proxy the request reads `http://`. It is also the address everyone's Claude is connected to, so changing it later means telling people to reconnect |
| `FH_MCP_OPEN_HANDSHAKE` | false | A client with no key may do the handshake and list the tools (never run one) |
| `FH_MCP_RATE_LIMIT` | 60 | Requests a minute on one key |
| `FH_MCP_IP_FAILURES` | 20 | Guessed keys (something shaped like a key that is not one) a minute from one address before more guesses from it are cut off. A real key is never turned away by this, and a request with no key does not count |
| `FH_MCP_OAUTH` | off | Where the sign-in switch starts, until someone sets it on System Settings (off, admins or on). It is a database setting, so changing it needs no deploy |
| `FH_MCP_CONSENT_URL` | `https://filipinohomes.com/connect/claude` | The website page where a person confirms the account |
| `FH_MCP_FORMER_URLS` | (none) | Addresses the API used to be reached at, comma separated: connections made for one keep working while it is listed |
| `FH_MCP_ACCESS_HOURS` | 8 | How long an access token lasts (every request re-checks the connection, the role and the switch, so this is not how long access lasts) |
| `FH_MCP_REFRESH_DAYS` | 60 | How long an unused connection survives; each use slides it forward |
| `FH_MCP_MAX_CONNECTION_DAYS` | 180 | The longest a connection lasts from the day it was made, however often it is used; then the person connects again |
| `MCP_ALLOWED_ORIGINS` | `https://claude.ai,https://claude.com` | Websites whose pages may call the server from a browser. Claude's own servers send no origin and are not affected |
| `FH_MCP_IP_RATE_LIMIT` | 30 | Key-less requests a minute from one address (open handshake only) |

The number of keys, how long they last (`max_days` does not apply to a permanent key), the biggest answer (60,000 bytes) and the most records on a page (25) are set in the file.

## Deploying it

1. Four migrations (the last is for [Sign-in](#sign-in-people-connect-their-own-claude)): `2026_11_24_000001` adds the tables `mcp_keys` and `mcp_calls`; `000002` adds who made and who revoked a key (the website's admin page); `000003` lets a key have no end date (`expires_at` may be empty). The deploy workflow stops while a migration is pending: run them as in [releases.md](releases.md#procedure-run-pending-migrations). Until `000002` has run, making or revoking a key on the website fails (the artisan commands keep working); until `000003` has run, only the permanent choice fails. Rolling `000003` back ends any permanent key (it gets an end date of now) rather than leave one with no end. `000004` is for sign-in: until it has run only sign-in is affected.
2. The endpoint has to be reachable from the internet over HTTPS at `/mcp/filipinohomes` (Claude's servers connect to it): check the load balancer and Cloudflare let it through, and that no bot rule blocks Anthropic.
3. `route:cache` includes `routes/ai.php`. Set `FH_MCP_PUBLIC_URL` (the dials below).
4. Before giving keys to team leaders, run `php artisan fhmcp:team-check` once on production: it prints counts only (teams, leaders, who would be shown the team tools, members in two teams) and changes nothing.
5. The website can be deployed before or after the API. Without the API routes, the card says Claude access is not available on this server yet and shows nothing else.

## Adding a tool safely

Extend one of the kinds in `app/Mcp/Tools/Kinds` (or `FhTool` for a new shape) and give it a policy. A tool returns data from a service that only reads. If a tool needs something that writes, it is not an MCP tool: build it with a person confirming in the FH admin. `FhMcpTest` has to stay green, including the scan of the tools' code and the watch on database writes. Do not add anything that takes a statement, a command, a path or a URL as an argument.

## Troubleshooting

- **401:** no key, a wrong one, an expired one or a revoked one (the answer is the same on purpose; the reason is in the log as `fhmcp.rejected`).
- **403:** the key is real but its person may no longer use the server (an agent switched off, a role changed).
- **429:** too many guessed keys from one address, or too many requests on one key. `Retry-After` says when. A refusal also carries `WWW-Authenticate: Bearer realm="mcp"` (plus `error="invalid_token"` when a key was sent): that header is set by the gate itself, not by the MCP package. While sign-in is on it is `Bearer resource_metadata="<API>/.well-known/oauth-protected-resource/mcp/filipinohomes", scope="fh:read"`, with `error="invalid_token"` and a description added only when a credential was sent and was not accepted (RFC 6750: a request with no credential is told where to sign in and nothing more).
- **Claude says it couldn't connect, or the connector stays on "Connect":** from outside, run the two `curl` checks under [Deploying it](#sign-in-people-connect-their-own-claude) (the documents answer `200`; the `401` carries `resource_metadata`). The usual causes are a firewall or Cloudflare rule blocking Anthropic's range, the API's address redirecting to another host, the switch being off, or `FH_MCP_PUBLIC_URL` not being the `https` address the person typed into Claude. The `fhmcp.rejected` log lines (reason `unknown_token`, `ended_token`, `no_access`, `paused`) and `fhmcp.connection.ended` say what happened to a connection.
- **"too big":** the answer passed `max_result_bytes`. Ask for a shorter period, a narrower filter or fewer rows.
- **The website card says "isn't available on this server yet":** the API it talks to does not have `/api/admin/mcp-keys` (the website is ahead of the API). **"The keys could not be loaded":** the API answered with an error; the usual cause is a pending migration, so check the log.
