# Monitoring Hardening Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for implementation and superpowers:requesting-code-review for the final independent review.

**Goal:** Resolve all reviewed resilience, APM access/redaction and gauge findings.

**Architecture:** Keep Laravel/Prometheus contracts. Add a safe reporter, storage
availability exception, bounded write cooldown, lazy protected resolution, and an
APM Gate middleware plus payload sanitizer.

**Tech Stack:** Existing PHP 8.0+, Laravel 9-12, Prometheus, PHPUnit/Testbench.

**Spec:** `docs/superpowers/specs/2026-10-27-monitoring-hardening-design.md`

## Global Constraints

- No dependency upgrades, destructive DB commands, changes to application auth or subscriptions.
- Existing user change to `docs/UPGRADE_1.1.11.md` stays in the original checkout.
- Use existing `210926` branch for push; PRs target develop then main.

## Review Focus

- Dependency resolution can fail before handle/record methods enter their guards.
- Reporting can recurse through SQL/outgoing HTTP and can itself throw.
- Recovered stores must resume writes; denied business requests must never be retried.
- Existing APM records may contain secrets; redaction must happen on reads too.
- Auth middleware alone is not authorization for service-wide APM records.

### Task 1: Metrics and safe reporting

Files: adapters, providers, metrics controller/middleware, SQL/HTTP listeners,
Mongo/Redis stores, new `MonitoringErrorReporter`, availability exception; tests.

- [x] Add failing tests for non-throwing reporting, reentrancy, read/setup failures,
  resolution failures, one business execution, cooldown/recovery and gauge math.
- [x] Run focused tests and observe the failures.
- [x] Implement safe reporter, protected resolutions, 503 handling and cooldown.
- [x] Correct gauge command handling; run the entire unit suite.
- [ ] Commit source/config/tests atomically as `security(monitoring): isolate failures and protect telemetry`.

### Task 2: APM access and payload security

Files: APM middleware/controller/buffer, new sanitizer, provider/config, tests.

- [x] Add failing tests for anonymous/unauthorized/authorized APM access, middleware
  and subscription checks, JSON/form/header/query redaction and old-event redaction.
- [x] Run focused tests and observe the failures.
- [x] Implement scoped Gate authorization and safe payload capture/read sanitization.
- [x] Run unit and feature tests; update CHANGELOG and upgrade/technical docs.
- [ ] Commit documentation as `docs(release): document monitoring hardening upgrade`.

Execution ruling: source/config changes share provider wiring and the reporter;
commit them atomically so every commit remains loadable. Documentation is the
second logical commit. The existing source review is the approved specification;
execution follows the user's explicit fix/test/commit/push/tag authorization.

### Task 3: Review and release

- [x] Independent whole-change review; fix critical/important findings using TDD.
- [ ] Final suite, syntax checks, composer validate, diff check.
- [ ] Push `210926`, PR to develop and merge after checks; annotated PATCH tag on develop.
- [ ] PR develop to main and merge; verify tag, Packagist and Composer source SHA.
- [ ] Return migration/config notes and the exact package update command last.

## Verification ledger

- Baseline was v1.1.12; original checkout and its user-owned document edit are preserved.
- Task 1 RED: 14 resilience cases exposed 8 errors and 5 failures before fixes.
- Task 2 RED: 12 APM cases exposed 10 failures before fixes.
- Final review P1 fixed: relative/compound/protocol-relative URL credential leaks;
  `test_relative_link_and_protocol_relative_urls_are_redacted_on_capture_and_read` RED to GREEN.
- Final review P2 fixed: all metric metadata is invalidated after read/write outages;
  `test_all_metric_metadata_recovers_after_a_write_or_read_outage` RED to GREEN.
- Additional assertion exposed URL reprocessing corrupting structured JSON; URL
  detection now distinguishes a whole URL from JSON containing a URL.
- Ruling: real service and production verification and PHP/Laravel version matrix
  are not claimed by local PHP 8.3/Laravel 12 synthetic tests.
- Ruling: preexisting forwarded-IP trust, SQL-label/cardinality policy, and custom
  APM adapter internals remain outside this narrow package patch. Existing app
  auth/subscription middleware is preserved; APM authorization is explicit/fail-closed.
