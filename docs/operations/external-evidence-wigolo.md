# Wigolo external-evidence service

GEOFlow uses Wigolo only when a quality-gated task has `external_evidence_policy=on_insufficient` and its frozen local evidence does not cover the requested claims. The feature is fail-closed and disabled by default.

## Current deployment status

The application integration is implemented and covered by isolated contract tests. Live crawling is intentionally **not enabled in this development environment**: an outbound firewall capable of blocking private, loopback, link-local, metadata and internal DNS destinations for every HTTP/browser redirect has not been independently verified. Keep `GEOFLOW_EXTERNAL_EVIDENCE_ENABLED=false` until operations completes that control and pins the reviewed container by digest.

The optional Compose file uses the upstream `0.2.0` tag for reproducible testing, exposes no host port, requires bearer authentication through a Docker secret, persists data privately, and caps browser concurrency/resources. Before production, replace the tag with the digest approved by operations.

Wigolo is AGPL-3.0-only. If the deployed service is modified and offered over a network, comply with the corresponding source-availability obligations.

## Setup after the egress review

1. Generate a long random token and save only the token value in `docker-data/secrets/wigolo_api_token` with restrictive filesystem permissions. Do not commit this file.
2. Configure the exact internal endpoint as an allowed private outbound target. For the default Compose service this is `wigolo:3333`; do not allow a subnet or wildcard.
3. Start the optional service:

   ```bash
   docker compose -f docker-compose.yml -f docker-compose.external-evidence.yml --profile external-evidence up -d wigolo
   ```

4. Verify `wigolo` is healthy from the Compose network. It provides `GET /health`; `/v1/*` requires the bearer token.
5. Configure GEOFlow:

   ```dotenv
   GEOFLOW_EXTERNAL_EVIDENCE_ENABLED=true
   GEOFLOW_WIGOLO_BASE_URL=http://wigolo:3333
   GEOFLOW_WIGOLO_API_TOKEN=<same secret value>
   GEOFLOW_OUTBOUND_PRIVATE_TARGETS=wigolo:3333
   ```

6. Clear the Laravel configuration cache and restart the queue worker. Create a quality-gated task, explicitly select the brand/site scope, then choose “local evidence insufficient → external verification”.

No cloud LLM provider is needed or configured for Wigolo. GEOFlow uses only `/v1/search` and `/v1/fetch`; it does not request Wigolo answer synthesis, research or autonomous agent output.

## Runtime behavior

- Local evidence is assessed first. When it is sufficient, there are zero Wigolo search/fetch calls.
- Known relevant URLs are fetched before search. Every initial and final URL is checked against the task's scoped allowlist.
- At most six page attempts and two search rounds are allowed per run, with a 180-second total preparation deadline by default.
- Empty, truncated, challenge-blocked, malformed, redirected-to-disallowed, authentication, rate-limit and upstream failures never count as evidence.
- Unverified optional claims are prohibited from the draft. Unverified or conflicting core claims produce retained `needs_evidence` output that cannot be adopted, approved, published or synchronized.
- The generation detail page shows the sealed input/result, attempt statuses and counters without displaying service credentials.
- Original external Markdown is archived on Laravel's private local disk under `external-evidence/run-<id>/` and is not publicly served.

## Troubleshooting and rollback

- `external_evidence_disabled`: the global flag, base URL or token is absent.
- `authentication_failed`: the Docker secret and Laravel token differ.
- `transport_error`: verify service health and the exact private-target exception.
- `blocked_by_challenge`, `empty_content`, `truncated_content`: the page is not accepted as evidence; do not broaden the allowlist to work around it.
- `needs_evidence`: inspect the run’s external-evidence audit panel. Correct the source scope or create a new run; sealed snapshots are immutable.

For immediate rollback, set `GEOFLOW_EXTERNAL_EVIDENCE_ENABLED=false`, clear config cache and restart workers. Existing evidence preparations remain as audit records; do not delete them. Tasks with the policy enabled will fail closed to prohibited optional claims or draft-only core gaps rather than silently publishing unsupported content.
