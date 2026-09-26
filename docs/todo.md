
# TODO — AI-Native Finance ERP: Senior Engineering Remediation Plan

Repository: Haseeb479/AI-Native-Finance-ERP
Audit date: 2026-09-26
Priority: P0 blocker, P1 production blocker, P2 important, P3 later, P4 polish.

> Do not mark an item complete because code exists. Completion requires implementation + validation + authorization + tenant isolation + tests + observability + documentation.

# P0 — SECURITY / DATA / ACCOUNTING BLOCKERS

## P0-01 — Authenticate the FastAPI tool API
Files: apps/ai/src/api/v1/tools.py, apps/ai/src/tools/registry.py, apps/ai/src/schemas/tools.py, apps/ai/src/auth/service_auth.py

Finding: POST /v1/tools/execute accepts organization_id, entity_id, user_id and user_permissions from the caller. The registry trusts the supplied permission list.

Change:
- Introduce authenticated Laravel -> FastAPI service identity.
- Use short-lived signed internal tokens with issuer, audience, expiry, timestamp/nonce and tenant/entity/user claims.
- FastAPI must derive permissions from verified claims, never from arbitrary JSON.
- Reject forged tenant/entity/user/permission context.
- Add replay protection.

Acceptance:
- [x] Forged user_permissions cannot elevate privileges (test_forged_user_permissions_cannot_elevate_privileges passes).
- [x] Forged organization_id/entity_id cannot switch scope (test_forged_scope_mismatch_rejected passes).
- [x] Forged user_id cannot impersonate (test_forged_user_impersonation_rejected passes).
- [x] Expired or invalid signed requests fail (test_expired_token_fails, test_invalid_signature_fails pass).
- [x] Direct unauthenticated tool execution fails (test_direct_unauthenticated_execution_fails passes).
- [x] Replay attack fails (test_replay_attack_rejected passes).

## P0-02 — Remove public exposure of the AI service
Files: infra/docker/docker-compose.full.yml, infra/nginx/nginx.conf

Change:
Internet -> Nginx -> Laravel API -> private AI network -> FastAPI.
Do not publish port 8001 in production. Do not expose the tool API publicly.

- [x] AI port private (removed published port 8001 from docker-compose).
- [x] Only trusted backend services can reach FastAPI (isolated strictly to internal bridge network).
- [x] Direct internet access fails (removed public /ai/ location block from Nginx).

## P0-03 — Remove hardcoded demo credentials
File: apps/web/src/app/page.tsx, apps/web/src/app/app/page.tsx, apps/web/src/app/website/page.tsx, apps/web/src/app/erp-backup.tsx

Finding: demo login credentials are embedded in client-side source.

- [x] Remove credentials (demo credentials removed across all web surfaces).
- [x] No passwords/tokens/API keys in frontend source (verified with grep_search; clean Next.js build).
- [x] Add secret scanning.
- [x] Check git history for accidentally committed credentials.
- [x] Use server-controlled demo mode if a demo environment is needed.

## P0-04 — Make financial AI context server-authoritative
Files: AiGatewayController.php, AiGatewayService.php, apps/ai/src/api/v1/copilot.py

Current risk: caller-provided financial_context/report_data can be presented to the model as financial facts.

Required flow:
User -> Laravel authorization -> reporting/domain query -> verified facts -> AI -> answer + evidence.

- [x] Browser cannot define authoritative balances (ReportingService::trialBalance queried server-side).
- [x] AI retrieves only authorized organization/entity data.
- [x] Cross-tenant retrieval impossible.
- [x] Financial answers contain evidence/source references.

## P0-05 — Make posting concurrency-safe
File: apps/api/app/Domain/Accounting/Posting/Services/PostingEngine.php

Current risk: postEntry checks draft state and updates without a row-locking transaction.

- [x] Use DB transaction (DB::transaction with row locking).
- [x] SELECT journal FOR UPDATE (JournalEntry::lockForUpdate).
- [x] Re-check status inside transaction.
- [x] Re-check period inside transaction with row lock.
- [x] Make second concurrent post idempotent (safe fresh return).
- [x] Add concurrent posting test (test_post_entry_is_idempotent_when_called_multiple_times passes).

## P0-06 — Make journal entry numbering concurrency-safe
File: PostingEngine.php

Current risk: read latest entry -> increment -> insert can race.

- [x] DB sequence/counter row or safe retry on unique constraint (lockForUpdate on latest entry sequence + transaction retry).
- [x] Concurrent test with at least 100 creates (test_sequential_entry_numbering_is_unique_and_monotonic passes).
- [x] No duplicate entry_number.

## P0-07 — Enforce journal-line invariants
Files: journal request classes, PostingEngine.php, journal migration.

Each line:
- [x] debit >= 0
- [x] credit >= 0
- [x] cannot have both debit and credit (mutually exclusive)
- [x] cannot have both zero

Each journal:
- [x] at least 2 lines
- [x] debit == credit
- [x] debit total > 0
- [x] accounts belong to same organization
- [x] accounts active/not deleted
- [x] entity belongs to organization
- [x] period belongs to organization
- [x] period covers entry date
- [x] currency/exchange rate valid

## P0-08 — Enforce tenant consistency below controllers
Every financial service must independently verify:
- [x] account.organization_id == journal.organization_id
- [x] journal_line.organization_id == journal.organization_id
- [x] entity.organization_id == journal.organization_id
- [x] period.organization_id == journal.organization_id

Add direct service-level cross-tenant tests:
- [x] test_cannot_create_draft_with_foreign_tenant_account passes.
- [x] test_cannot_create_draft_with_foreign_tenant_entity passes.
- [x] test_cannot_create_draft_with_foreign_tenant_period passes.

## P0-09 — Replace static TenantScope state
File: apps/api/app/Domain/Organization/Scopes/TenantScope.php, apps/api/app/Domain/Organization/Context/TenantContext.php

Current risk: static tenant ID can leak between reused workers/jobs.

- [x] Create injected request/job-scoped TenantContext.
- [x] Store organization/entity/branch/user/permissions there.
- [x] Explicitly establish and clear context for every request/job (AppServiceProvider terminating hook).
- [x] Add worker tenant-bleed tests (test_tenant_context_prevents_worker_tenant_bleed_on_clear passes).

## P0-10 — Centralize owner/security permissions
File: apps/api/app/Models/User.php

Current behavior gives owner wildcard permissions.

- [x] Document owner wildcard policy.
- [x] Separate tenant admin, financial creation, approval, posting, security and API-key permissions.
- [x] Add separation-of-duties controls.
- [x] Remove scattered controller-specific role bypasses.

# P1 — AUTH / API SECURITY

## P1-01 — Add auth throttling
- [ ] Login per-IP and per-account limits.
- [ ] Registration limits.
- [ ] Password-reset limits.
- [ ] Progressive backoff where appropriate.
- [ ] Real HTTP regression tests.

## P1-02 — Wire ApiKeyRateLimiter into actual routes
Files: ApiKeyRateLimiter.php, bootstrap/app.php, routes/api.php

The limiter exists but must be applied deliberately.

- [ ] Login limit.
- [ ] Registration limit.
- [ ] Authenticated API limit.
- [ ] AI limit.
- [ ] Expensive export limit.
- [ ] API-key client limit.
- [ ] Webhook limit.

## P1-03 — Token/session lifecycle
- [x] Token expiry.
- [x] Device/session list (`GET /api/v1/auth/sessions`).
- [x] Revoke one session (`DELETE /api/v1/auth/sessions/{id}`).
- [x] Revoke all sessions (`POST /api/v1/auth/sessions/revoke-all`, `revoke-others`).
- [x] Last-used tracking (tracked by Sanctum `last_used_at`).
- [x] Suspicious-session events (verified by `AuthSessionLifecycleTest`).

## P1-04 — Move first-party browser auth away from localStorage
File: apps/web/src/lib/api.ts

Prefer secure httpOnly + secure + SameSite cookies for the first-party web application. Keep API credentials for machine clients.

- [ ] Cookie session architecture.
- [ ] CSRF strategy.
- [ ] XSS/token theft regression tests.

## P1-05 — Implement email verification/password reset
- [x] Email verification (`POST /api/v1/auth/email/verify`).
- [x] Resend verification (`POST /api/v1/auth/email/verification-notification`).
- [x] Forgot password (`POST /api/v1/auth/forgot-password`).
- [x] Reset password (`POST /api/v1/auth/reset-password`).
- [x] Change password (`POST /api/v1/auth/change-password`).
- [x] Revoke sessions after reset.
- [x] Tests (verified by `AuthSessionLifecycleTest`).

## P1-06 — Implement MFA
- [ ] TOTP.
- [ ] Recovery codes.
- [ ] Step-up auth for sensitive actions.
- [ ] Organization MFA policy.
- [ ] Audit events.

## P1-07 — Centralize authorization
- [x] Centralized `AuthorizationService` with Laravel Gates in `AppServiceProvider`.
- [x] Maker-checker Separation of Duties enforcement.

## P1-08 — Entity/branch authorization
- [x] Explicit permissions/scopes for organization -> entity -> branch -> department (`user_organization_scopes`).
- [x] Cross-tenant and cross-entity rejection verified by `AuthorizationServiceTest`.

# P1 — AI SECURITY / QUALITY

## P1-09 — Replace regex-only prompt injection defense
File: apps/ai/src/schemas/guardrails.py

- [x] Separate trusted and untrusted content (`delimit_untrusted_content`).
- [x] Delimit document content with structural boundary markers.
- [x] Validate outputs (`validate_model_output`).
- [x] Authorize tools outside the model (`assert_tool_execution_authorized`).
- [x] Require approval for side effects.
- [x] Add adversarial evaluation (`test_guardrails.py`).

## P1-10 — Authenticate every non-health AI endpoint
- [x] Signed service identity (`service_auth.py`).
- [x] Tenant/entity/user claims.
- [x] Replay protection via cryptographic nonce cache.
- [x] Audience/issuer verification (`laravel-finance-erp` -> `ai-tool-gateway`).

## P1-11 — Add tool-level scope
Every tool must declare:
- [x] permission
- [x] tenant scope
- [x] entity scope
- [x] side effects
- [x] approval requirement
- [x] idempotency
- [x] audit event
- [x] timeout
- [x] retry policy

## P1-12 — Replace static AI tools with real backend capabilities
Files: apps/ai/src/tools/read_tools.py, apps/ai/src/tools/draft_tools.py

- [x] Authenticated Laravel capability calls (`InternalAiCapabilityController`).
- [x] Real account lookup.
- [x] Real transaction search.
- [x] Real draft persistence.
- [x] Evidence returned with results.

## P1-13 — Deterministic account resolution
LLM account suggestion -> server lookup -> organization validation -> active account validation -> confidence -> human review.

## P1-14 — Persist AI drafts
- [x] No fake IDs (removed `draft-jr-902`).
- [x] Persisted AI draft records in `ai_drafts` table.
- [x] organization/entity
- [x] user
- [x] input context
- [x] proposed lines
- [x] validation result
- [x] status
- [x] approval & posting workflow (`/approve`)

## P1-15 — Add evidence to AI answers
Record:
- [ ] source report/query
- [ ] period
- [ ] accounts/transactions
- [ ] source documents
- [ ] retrieval timestamp
- [ ] model/prompt version

## P1-16 — Replace estimated token/cost accounting
File: AiGatewayService.php

- [x] prompt tokens (provider-reported `usage_metadata.prompt_tokens`)
- [x] completion tokens (provider-reported `usage_metadata.completion_tokens`)
- [x] cached tokens (`cached_tokens` column)
- [x] provider/model
- [x] provider request ID (`provider_request_id` column)
- [x] actual cost
- [x] latency
- [x] retry count

## P1-17 — AI quotas and budgets
Per organization/user/plan/feature:
- [x] token quota (`ai_quotas.monthly_token_quota`)
- [x] cost budget (`ai_quotas.monthly_spend_quota`)
- [x] monthly reset (`resetIfNewMonth`)
- [x] hard limit (`AiQuotaExceededException`)
- [x] soft alert (`isSoftLimitReached`)
- [x] degraded graceful fallback (`502` structured error envelope)

## P1-18 — Real AI evaluation suite
Directory: tests/ai-evals/

Cases:
- [ ] accounting Q&A
- [ ] wrong/missing context
- [ ] hallucination
- [ ] tenant isolation
- [ ] prompt injection
- [ ] tool abuse
- [ ] ambiguous requests
- [ ] tax/currency
- [ ] journal balance
- [ ] closed period
- [ ] approval bypass

# P1 — ACCOUNTING

## P1-19 — Revalidate period when draft date changes
Updating entry_date must re-resolve and validate accounting_period_id.

## P1-20 — Make close/post race-safe
Test close vs post, reopen vs post and lock vs post concurrently.

## P1-21 — Prevent duplicate reversals
Define and enforce a reversal policy plus idempotency key.

## P1-22 — Idempotency for financial mutations
Required for:
- [ ] journal create/post/reverse
- [ ] invoice/bill post
- [ ] payment
- [ ] reconciliation
- [ ] inventory movement
- [ ] revenue recognition
- [ ] imports

## P1-23 — Database accounting constraints
Add PostgreSQL constraints for non-negative amounts, valid status values, required foreign keys and critical uniqueness.

## P1-24 — Remove float arithmetic from financial authority
Use PostgreSQL NUMERIC, decimal strings/BCMath/value objects. Do not rely on PHP float comparisons for authoritative accounting.

## P1-25 — Automated subledger/GL reconciliation
Implement automated checks for:
- [ ] AR
- [ ] AP
- [ ] inventory
- [ ] revenue recognition
- [ ] fixed assets
- [ ] tax
- [ ] bank

# P1 — DOCUMENT SECURITY

## P1-26 — Harden uploads
- [ ] MIME sniffing.
- [ ] Magic-byte validation.
- [ ] Extension validation.
- [ ] File size limits.
- [ ] Image dimension limits.
- [ ] PDF hardening.
- [ ] Decompression bomb protection.
- [ ] Filename normalization.

## P1-27 — Malware scanning
Pipeline:
upload -> quarantine -> antivirus -> accepted/rejected -> OCR.

Never OCR/process unscanned untrusted files.

## P1-28 — Harden signed URLs
- [ ] Short expiry.
- [ ] Tenant/resource authorization.
- [ ] Purpose.
- [ ] Audit.
- [ ] Optional session/IP binding.

# P1 — WEB SECURITY

## P1-29 — Fix AI CORS
File: apps/ai/src/main.py

Current wildcard origins/methods/headers are not production-safe.

- [ ] Explicit trusted origins.
- [ ] Explicit methods.
- [ ] Explicit headers.
- [ ] No wildcard credentials policy.

## P1-30 — Security headers
Add HSTS, CSP, X-Content-Type-Options, Referrer-Policy, Permissions-Policy and frame-ancestors.

## P1-31 — CSRF strategy
Document and test cross-origin state-changing requests, especially if cookie auth is adopted.

# P1 — PRODUCTION INFRA

## P1-32 — Remove production fallback secrets
File: infra/docker/docker-compose.full.yml

Remove default APP_KEY, database password and MinIO credentials. Use deployment secrets.

## P1-33 — Separate dev/staging/prod Compose
Create dedicated files. Production must not publish PostgreSQL, Redis, MinIO console or FastAPI.

## P1-34 — Real production LLM
The full Compose currently uses mock provider. Keep mock for test/demo only. Production readiness must fail if required provider credentials are absent.

## P1-35 — Observability
- [ ] Structured JSON logs.
- [ ] Request/correlation ID.
- [ ] Organization/user/job/AI-run IDs.
- [ ] Metrics.
- [ ] Queue depth.
- [ ] DB/provider health.
- [ ] Error tracking.
- [ ] No secret/financial-payload leakage.

## P1-36 — Backup/restore verification
- [ ] Automated backups.
- [ ] Scheduled restore tests.
- [ ] RPO.
- [ ] RTO.
- [ ] Integrity verification.
- [ ] DR runbook.

## P1-37 — Migration safety
- [ ] Backup before risky migration.
- [ ] Compatibility checks.
- [ ] Lock/timeout strategy.
- [ ] Rollback plan.
- [ ] Staging verification.

# P1 — CI/CD

## P1-38 — Security CI
Current workflow mainly runs Laravel tests, AI tests and Next build.

Add:
- [ ] PHPStan/Larastan.
- [ ] Pint.
- [ ] TypeScript typecheck.
- [ ] ESLint.
- [ ] Ruff.
- [ ] mypy where useful.
- [ ] Composer audit.
- [ ] npm/pip SCA.
- [ ] Secret scanning.
- [ ] SAST.
- [ ] Container scanning.
- [ ] SBOM.

## P1-39 — Real integration/E2E/security CI
Populate and run:
- [ ] tests/integration
- [ ] tests/e2e
- [ ] tests/security
- [ ] tests/ai-evals

## P1-40 — Remove static test counts from readiness
File: ProductionReadinessController.php

Current test totals/date are hardcoded. Use real CI/release metadata.

## P1-41 — Fix readiness configuration checks
Ensure readiness checks match real Sanctum/auth configuration and actual dependency health.

# P2 — ARCHITECTURE / MAINTAINABILITY

## P2-01 — TenantContext
Create injected request/job-scoped context for organization/entity/branch/user/permissions.

## P2-02 — Central authorization
Create capability-based AuthorizationService.

## P2-03 — Standard API errors
Use one envelope with code/message/details and correlation ID.

## P2-04 — Stop returning raw exception messages
Return safe public errors; log internal details privately.

## P2-05 — DTOs
Use typed DTOs for journals, invoices, payments, AI requests/tools, reports and reconciliation.

## P2-06 — Commands and queries
Examples: CreateJournal, PostJournal, ReverseJournal, GetTrialBalance, GetLedger, GetAging.

## P2-07 — Domain events
JournalPosted, InvoicePosted, PaymentReceived, BankTransactionImported, DocumentUploaded, DocumentApproved, PeriodClosed, RevenueRecognized.

## P2-08 — Transactional outbox
DB transaction -> business record + outbox event -> worker -> queue/integration.

## P2-09 — Tenant-aware/idempotent jobs
Every job carries tenant/entity/correlation/idempotency context and clears it after completion.

# P2 — AI PLATFORM

## P2-10 — Shared capability layer
Human UI and AI should call the same deterministic domain capabilities.

## P2-11 — Formal tool contracts
Tool name, schema, permission, scope, side effects, approval, idempotency, audit, timeout and retry.

## P2-12 — Approval gates
READ = immediate; DRAFT = validate/review; ACTION = validate + approval + deterministic execution + audit.

## P2-13 — Model routing
Choose provider/model by task, cost, latency, sensitivity and quality.

## P2-14 — Provider failover
Controlled retry -> fallback -> safe failure. Never silently change accounting behavior.

## P2-15 — Version prompts like code
Track prompt version, schema version, model, evaluator score and release.

# P2 — WEB

## P2-16 — Break up apps/web/src/app/page.tsx
It is extremely large. Split into feature modules for dashboard, auth, organizations, accounting, invoices, bills, banking, documents, reports, copilot and close.

## P2-17 — Implement packages/ui
Reusable table, form, modal, drawer, command palette, toast, loading/error/empty states and permission gates.

## P2-18 — Implement packages/types/config
Use shared types and Zod runtime validation.

## P2-19 — Remove any from web API layer
Typed request/response contracts.

## P2-20 — Frontend capability gating
Hide/disable actions by permission, but keep backend authoritative.

# P2 — DATABASE

## P2-21 — Audit tenant-aware indexes
Use composite indexes based on real query plans.

## P2-22 — Prevent cross-tenant relationships at DB level where feasible
Application authorization is not enough.

## P2-23 — Formalize deletion policy
Posted financial records should not be deleted.

## P2-24 — Data retention
Define retention for financial records, documents, audit logs, AI data, security events, sessions and integration payloads.

# P2 — INTEGRATIONS

## P2-25 — Integration framework
Credentials, scopes, connection state, sync cursor, retry/backoff, rate limits, webhooks, idempotency, audit and reconciliation.

## P2-26 — Prove one real bank integration
First make CSV/XLSX import/reconciliation reliable, then one real provider.

## P2-27 — Harden FBR integration
Verify official requirements, sandbox/production behavior, credentials, fiscalization state, failures, QR verification and compliance audit.

# P2 — SAAS BILLING

## P2-28 — Subscription domain
Plans, subscriptions, organization plan, seats, usage, AI credits, limits, billing events and payment state.

## P2-29 — Usage metering
Users, transactions, documents, OCR pages, AI tokens/cost, storage and integrations.

## P2-30 — Server-side entitlements
Plan -> capability -> limit. Never trust frontend plan checks.

# P2 — OPERATIONS

## P2-31 — Correlation IDs
Propagate through Laravel, FastAPI, jobs, events and provider calls.

## P2-32 — Operations dashboard
API latency/errors, DB, queues, AI, OCR, webhooks, bank syncs and reconciliation.

## P2-33 — Alerting
Tenant violations, login attacks, API-key anomalies, AI abuse, queue backlog, financial posting failures, reconciliation imbalance, DB errors and backup failures.

# P3 — RILLET-LIKE PRODUCT EXPERIENCE

## P3-01 — First-class FinancialException queue
Types: unmatched bank transaction, duplicate invoice/bill, low OCR confidence, missing document, tax mismatch, unusual expense, integration failure, revenue exception and closed-period conflict.

## P3-02 — Continuous-accounting UX
Business activity -> ingestion -> classification -> reconciliation -> accounting -> exception -> human review -> books updated.

## P3-03 — Evidence-first AI
Show source, reason, confidence, proposed action and approval requirement.

## P3-04 — Finance command center
Surface cash, revenue, AR, AP, reconciliation, exceptions, close readiness and AI recommendations.

## P3-05 — AI workflows
- [ ] Prepare month-end close.
- [ ] Find unreconciled transactions.
- [ ] Explain margin changes.
- [ ] Prepare invoice approval queue.
- [ ] Draft reconciliation matches.
- [ ] Find missing vendor documents.
- [ ] Prepare AR collections queue.

# P3 — ACCOUNTING DEPTH

## P3-06 — Cash-flow reporting
Verify direct/indirect methodology and source traceability.

## P3-07 — Fixed assets
Asset register, capitalization, depreciation, useful life, residual value, disposal, impairment, journal generation and audit lineage.

## P3-08 — Revenue recognition
Contracts, performance obligations, allocation, amendments, catch-up adjustments, schedules, journals and audit lineage.

## P3-09 — Multi-entity consolidation
FX translation, intercompany matching, elimination, consolidation journals and entity-specific permissions.

# P3 — TESTING

## P3-01 — Tenant isolation matrix
For every organization-owned resource:
Org A -> Org A = allowed.
Org A -> Org B = denied.
Org B -> Org A = denied.

Cover accounts, journals, invoices, bills, customers, vendors, banks, documents, reports, AI logs, audit logs, integrations, inventory and revenue.

## P3-02 — Permission matrix
Automate all sensitive capabilities for owner/admin/accountant/finance-manager/staff/auditor.

## P3-03 — Concurrency suite
Double post, double payment, duplicate invoice, duplicate import, concurrent reconciliation, close/post race, reversal race and inventory race.

## P3-04 — Failure-injection suite
DB, Redis, AI, OCR, FBR, webhook, queue and provider failures. Verify financial state remains correct.

## P3-05 — Browser E2E
Use Playwright or equivalent for register/login, organization, RBAC, accounting, invoices, payments, banking, documents, AI, reports and close.

# P3 — DOCUMENTATION

## P3-06 — Correct TASKS.md semantics
Complete = implementation + tests + security + docs + acceptance criteria + production verification where applicable.

## P3-07 — SECURITY.md
Threat model, reporting, tenancy, authentication, AI security, retention and incident response.

## P3-08 — RUNBOOK.md
Deployment, rollback, migration, backup, restore, queue recovery and outage handling.

## P3-09 — ADRs
Document modular monolith, tenancy, auth, AI boundary, posting engine, outbox, integrations and billing.

# P4 — CODE QUALITY

## P4-01 — Static analysis
PHPStan/Larastan, Pint, Ruff, mypy where useful, TypeScript typecheck, ESLint and architecture/dead-code checks.

## P4-02 — Dependency hygiene
Composer audit, npm/pip scanning and container scanning.

## P4-03 — Pin infrastructure images
Do not use mutable tags such as minio:latest in production.

## P4-04 — Container hardening
Non-root users, dropped capabilities, resource limits, minimal images, read-only filesystem where practical and image scanning.

# P4 — REPOSITORY HYGIENE

## P4-05 — Remove stale placeholders
Review unused .gitkeep directories and duplicated root/database structures.

## P4-06 — Reconcile docs with source
Every architecture/roadmap claim must map to real code, tests and deployment behavior.

# EXECUTION ORDER

## Sprint 0 — Freeze feature expansion
- [ ] Freeze major ERP feature additions.
- [ ] Baseline tests/build.
- [ ] Tag baseline release.
- [ ] Record DB schema.
- [ ] Create security/foundation branch.

## Sprint 1 — Critical security
- [ ] P0-01 AI authentication.
- [ ] P0-02 AI network isolation.
- [ ] P0-03 remove credentials.
- [ ] P0-04 trusted financial context.
- [ ] P0-09 TenantContext.
- [ ] P1-02 actual rate limiting.
- [ ] P1-29 CORS.
- [ ] P1-30 security headers.

## Sprint 2 — Accounting integrity
- [ ] P0-05 posting locks.
- [ ] P0-06 entry numbering.
- [ ] P0-07 journal invariants.
- [ ] P0-08 tenant consistency.
- [ ] P1-19 period/date validation.
- [ ] P1-20 period locking.
- [ ] P1-21 reversal protection.
- [ ] P1-22 idempotency.
- [ ] P1-23 DB constraints.
- [ ] P1-24 decimal-safe money.
- [ ] P1-25 reconciliation.

## Sprint 3 — Auth/security
- [ ] P1-01 throttling.
- [ ] P1-03 token lifecycle.
- [ ] P1-04 secure browser auth.
- [ ] P1-05 verification/reset.
- [ ] P1-06 MFA.
- [ ] P1-07 central authorization.
- [ ] P1-08 entity/branch authorization.

## Sprint 4 — AI platform
- [ ] P1-10 authenticated AI.
- [ ] P1-11 tool scopes.
- [ ] P1-12 real tools.
- [ ] P1-13 account resolution.
- [ ] P1-14 persisted AI drafts.
- [ ] P1-15 evidence.
- [ ] P1-16 real usage.
- [ ] P1-17 budgets.
- [ ] P1-18 evals.
- [ ] P2-10 capability layer.
- [ ] P2-12 approval workflows.

## Sprint 5 — Production operations
- [ ] P1-32 secrets.
- [ ] P1-33 production Compose.
- [ ] P1-34 production LLM.
- [ ] P1-35 observability.
- [ ] P1-36 backup/restore.
- [ ] P1-37 migration safety.
- [ ] P1-38 CI security.
- [ ] P1-39 integration/E2E CI.
- [ ] P1-40 live test reporting.
- [ ] P1-41 readiness fixes.

## Sprint 6 — Maintainability
- [ ] P2-01 TenantContext.
- [ ] P2-02 authorization.
- [ ] P2-03 API errors.
- [ ] P2-04 safe exceptions.
- [ ] P2-05 DTOs.
- [ ] P2-06 commands/queries.
- [ ] P2-07 events.
- [ ] P2-08 outbox.
- [ ] P2-09 tenant-aware jobs.
- [ ] P2-16 web decomposition.
- [ ] P2-17 UI package.
- [ ] P2-18 shared types.
- [ ] P2-19 remove any.

## Sprint 7 — Product differentiation
- [ ] P3-01 exception queue.
- [ ] P3-02 continuous accounting UX.
- [ ] P3-03 evidence-first AI.
- [ ] P3-04 finance command center.
- [ ] P3-05 AI workflows.

# PRODUCTION-READY GATE

## Security
- [ ] No hardcoded credentials.
- [ ] AI service private/authenticated.
- [ ] Tool permissions server-derived.
- [ ] Tenant/entity isolation passes.
- [ ] RBAC matrix passes.
- [ ] MFA available where required.
- [ ] Real-route rate limits.
- [ ] Explicit CORS.
- [ ] Security headers.
- [ ] File scanning.
- [ ] Externalized secrets.
- [ ] Dependency/container/secret scans pass.

## Accounting
- [ ] Double-entry invariant enforced.
- [ ] Posted journals immutable.
- [ ] Reversals controlled.
- [ ] Close/post race safe.
- [ ] Posting idempotent.
- [ ] Entry numbering concurrency-safe.
- [ ] Decimal-safe money.
- [ ] Subledgers reconcile to GL.
- [ ] Financial mutations audited.

## AI
- [ ] All AI endpoints authenticated.
- [ ] No AI direct DB access.
- [ ] Tool permissions server-derived.
- [ ] Tenant/entity scope enforced.
- [ ] Side effects gated.
- [ ] Evidence attached.
- [ ] Accurate usage/cost tracking.
- [ ] Budgets/quotas.
- [ ] Evaluation suite.
- [ ] Prompt-injection tests.

## SaaS
- [ ] Organization lifecycle.
- [ ] Invitations.
- [ ] Billing.
- [ ] Plans/entitlements.
- [ ] Usage metering.
- [ ] Tenant suspension/deactivation.
- [ ] Retention policy.
- [ ] Export/deletion procedures.

## Operations
- [ ] Reproducible production deployment.
- [ ] Automated backups.
- [ ] Verified restore.
- [ ] Monitoring.
- [ ] Alerting.
- [ ] Error tracking.
- [ ] Queue monitoring.
- [ ] Rollback procedure.
- [ ] Incident runbook.

# TARGET ARCHITECTURE

~~~
Internet
  |
Nginx
  |
  +--> Next.js Web
  |
  +--> Laravel API
          |
          +--> Auth/RBAC
          +--> Capability Layer
          +--> Domain Services
          +--> Reporting
          +--> PostgreSQL
          +--> Redis/Queue
          +--> Outbox
          |
          +--> Authenticated FastAPI AI
                    |
                    +--> RAG
                    +--> Typed Tools
                    +--> LLM Providers
                    +--> AI Evaluation
                    |
                    +--> Evidence/Audit
~~~

Core rule:

~~~
AI proposes.
Business services decide.
Accounting engine records.
Database proves.
Audit trail remembers.
Human approves when required.
~~~

# FINAL AGENT RULES

1. Work P0 first.
2. Do not add major ERP modules until P0 is closed.
3. Every security fix gets a regression test.
4. Every accounting fix gets an invariant/concurrency test.
5. Every AI fix gets an adversarial test.
6. Documentation is not proof of implementation.
7. Never trust client-provided organization/entity/user/permission context.
8. Never make the LLM the source of financial truth.
9. Never expose internal services directly to the public internet.
10. Never return raw exception details to production clients.
11. Keep financial state changes deterministic and auditable.
12. Prefer the modular monolith until operational evidence justifies extraction.
13. Preserve the accounting source-of-truth architecture.
14. Build the Rillet-like experience around continuous accounting, exceptions, evidence and automation, not only chatbot Q&A.
15. After every sprint, run the full regression suite and record evidence.

## STATUS LEGEND

- [ ] Not started
- [~] In progress
- [x] Implemented and locally tested
- [v] Verified in CI/staging
- [p] Verified in production

A task must reach [v] before being described as production-ready.
