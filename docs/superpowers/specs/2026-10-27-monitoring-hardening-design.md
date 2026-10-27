# Monitoring failure isolation and APM security

The approved scope is the findings reviewed in this task: keep business requests
and their authentication/subscription middleware working exactly once during
monitoring failures, make failed scrapes explicit, protect APM access and secrets,
and correct Redis gauges. No database migrations or dependency upgrades.

## Approach

Use the existing registry and storage contracts with a bounded, recoverable write
circuit and one non-throwing, reentrancy-safe reporter shared by monitoring code.
Resolve optional monitoring dependencies inside protected boundaries. Scrape
failures return 503 with a generic message, including setup fallback failures.
Do not retry business requests or replay failed metric writes.

APM endpoints keep configured project middleware, require an authenticated user
and an explicitly configured Laravel Gate ability (default
`viewServerOrchestratorApm`). They fail closed without that ability. This affects
only the package's APM feed; existing application auth/subscription rules remain.
New and previously stored events are sanitized before being exposed. Structured
JSON/form bodies redact sensitive field names recursively. Unstructured or
malformed bodies are omitted. Both header directions and URL/query secrets are
redacted. No raw binary prefix is retained.

Redis gauges honor SET versus INCREMENT commands. Failure cooldown defaults to
5 seconds, reporting cooldown to 60 seconds; both recover without process restart.
APM read/store failures remain best effort for capture; feed outages return 503.

## Verification and release

Test failure, recursive/throwing reporting, resolution failure, recovery, healthy
metrics, exact business response/exception preservation, all APM aliases, existing
middleware and Gate denial, subscription denial, nested secrets and old events.
Run unit/feature suites without destructive database operations. Review the full
change before committing. Publish a PATCH release through PRs to develop then main;
an annotated tag is made on develop before the release PR. Verify Packagist SHA.
