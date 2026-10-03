# FINOVA / AI-Native Finance ERP — FIX.md

## Purpose

This file is the implementation checklist for the remaining production-hardening issues found during the senior developer / tester review.

**Target:** move the project from a production candidate to a safe, testable staging release and then public production.

**Important agent rules**
- Fix the issues listed here; do not rewrite working architecture.
- Preserve existing accounting, AI, RBAC, tenant isolation, UI, API, and workflow behavior unless a change is explicitly required by a fix below.
- Do not replace deterministic accounting logic with LLM logic.
- Do not allow the AI layer to become an authority for financial balances, journal posting, prices, tax totals, permissions, or tenant scope.
- Prefer small, isolated changes with tests.
- Do not weaken validation or authorization to make tests pass.
- Do not use fake/hard-coded test results.
- After each section, run the relevant tests.
- Do not mark an item complete unless the implementation and a regression test both exist.

---

# P0 — MUST FIX BEFORE PUBLIC PRODUCTION

## 1. Password-reset token exposure

### Current issue

The password-reset flow has exposed the reset token directly through the API response in the reviewed implementation.

A password-reset token is a credential. Returning it from an API response defeats the purpose of sending the reset link through a verified communication channel.

### Why this must be fixed

If an attacker can obtain the API response, logs, browser history, proxy traces, screenshots, or another exposed response, the attacker may be able to reset the user's password.

This is a direct account-takeover risk.

### Required fix

Implement this flow:

1. User submits email.
2. Server creates a cryptographically random, short-lived reset token.
3. Store only a secure hash of the token in the database.
4. Associate it with the user and expiration time.
5. Send the reset URL through the verified email channel.
6. API response must NOT contain the raw token.
7. API response must not reveal whether an email exists.
8. Reset endpoint accepts the token from the reset link.
9. Hash the supplied token and compare against the stored hash.
10. Reject expired, used, malformed, or already-consumed tokens.
11. On successful password reset:
   - update password;
   - invalidate/consume the reset token;
   - invalidate existing sessions/tokens where appropriate;
   - record the security event.

### Expected behavior after fixing

**Request password reset**
- User receives a generic success response.
- No reset token is returned in JSON.
- Existing/non-existing email addresses should not be distinguishable.

**Open reset link**
- Valid token → password reset form works.
- Expired/invalid/used token → clear error and no password change.

**After successful reset**
- Token cannot be reused.
- Previous sessions are invalidated according to the application's session policy.

### Tests required

- reset request does not return raw token;
- reset request does not reveal account existence;
- valid token works;
- invalid token fails;
- expired token fails;
- reused token fails;
- password changes successfully;
- old password fails after reset;
- session invalidation works.

---

## 2. Email-verification token exposure

### Current issue

The reviewed authentication flow exposes the email-verification token through the API response instead of treating the token as a private credential delivered through the verification channel.

### Why this must be fixed

A verification token can be used to prove control over an account/email address. Exposing it in API responses increases the attack surface and can result in account verification without email ownership.

### Required fix

Use the same secure pattern as password reset:

1. Generate a cryptographically random token.
2. Store only a hash.
3. Store an expiration timestamp.
4. Send the raw token only inside the verification email link.
5. Never return the raw token in API responses.
6. Make the token single-use.
7. Add rate limiting to verification/resend endpoints.

### Expected behavior

- Registration creates an unverified account.
- API response never contains the verification credential.
- User receives a verification link.
- Valid link verifies the account.
- Invalid/expired/used link cannot verify the account.
- Resend is rate-limited.

### Tests required

- no token in registration response;
- valid verification works;
- invalid token fails;
- expired token fails;
- reused token fails;
- resend rate limit works.

---

## 3. Production web/API deployment architecture

### Current issue

The project has production-oriented configuration, but the reviewed deployment setup does not provide a complete production process architecture.

The root compose file currently contains PostgreSQL, Redis, and MinIO, while the application services/reverse proxy/worker/scheduler need to be explicitly represented in the production deployment architecture.

The reviewed API container configuration also uses Laravel's development-style `php artisan serve` pattern rather than a proper production PHP runtime behind a reverse proxy.

### Why this must be fixed

A public finance application needs:
- HTTPS;
- proper reverse proxying;
- process isolation;
- health checks;
- background workers;
- scheduled jobs;
- graceful restart behavior;
- production PHP runtime;
- controlled exposure of internal services.

Without this, the application can appear healthy while background accounting/AI/billing jobs silently stop working.

### Required fix

Create a real production deployment configuration without changing application behavior.

Required service architecture:

- reverse proxy / TLS termination;
- Next.js web;
- Laravel API;
- Laravel queue worker;
- Laravel scheduler;
- FastAPI AI service;
- PostgreSQL;
- Redis;
- object storage;
- optional monitoring/health service.

Internal services should not be publicly exposed unless explicitly required.

Use a production PHP setup such as:
- PHP-FPM behind Nginx/Caddy/managed reverse proxy,
- or an equivalent production-grade runtime.

Do not use `php artisan serve` as the public production web server.

### Expected behavior

- HTTPS is the only public API/web path.
- API and web are reachable through the reverse proxy.
- PostgreSQL/Redis/object storage are private.
- Queue jobs continue independently of the HTTP process.
- Scheduler runs continuously.
- Restarting the API does not lose queued jobs.
- Health checks detect failed dependencies.

### Tests/checks required

- production containers start cleanly;
- API health endpoint works;
- web health/build works;
- queue worker processes a real test job;
- scheduler executes a test scheduled task;
- AI service health check works;
- internal ports are not publicly exposed;
- HTTPS terminates correctly;
- restart test passes.

---

## 4. Queue worker and scheduler

### Current issue

Redis/queue functionality exists in the architecture, but production must explicitly run the Laravel queue worker and scheduler.

### Why this must be fixed

Finance ERP work should not depend on a single HTTP request surviving.

Background jobs may include:
- email;
- AI processing;
- document processing;
- FBR submission;
- retries;
- notifications;
- reconciliation;
- billing;
- imports/exports.

Without a persistent worker, jobs can remain queued indefinitely.

Without the scheduler, recurring tasks never execute.

### Required fix

Add dedicated production processes/containers for:

- Laravel queue worker;
- Laravel scheduler.

Use Redis as the queue backend where already designed.

Configure:
- retries;
- backoff;
- timeout;
- max attempts;
- failed-job storage;
- graceful shutdown.

Do not run queue processing inside the web request lifecycle.

### Expected behavior

A request that dispatches a background job returns normally.

The worker picks up the job.

If a transient failure occurs, the job retries according to policy.

After retry exhaustion, the job becomes a failed job and is observable.

The scheduler executes recurring jobs without manual intervention.

### Tests required

- dispatch job;
- worker processes it;
- retry works;
- failed job is recorded;
- scheduler runs;
- duplicate processing is prevented where idempotency is required.

---

## 5. Pin all production container versions

### Current issue

The reviewed compose configuration uses:

`quay.io/minio/minio:latest`

Mutable `latest` tags are unsafe for reproducible production deployments.

### Why this must be fixed

A future image update can change behavior without a code change.

This can introduce:
- unexpected breaking changes;
- security regressions;
- incompatible storage behavior;
- difficult rollback.

### Required fix

Pin production images to explicit versions/digests.

Apply the same principle to:
- PostgreSQL;
- Redis;
- MinIO/object storage;
- PHP;
- Node;
- Python;
- Nginx/reverse proxy;
- AI dependencies.

Use Dependabot/Renovate/manual update policy later, but never rely on `latest` in production.

### Expected behavior

The same deployment configuration produces the same application environment until versions are intentionally updated.

---

## 6. Backup and restore must be actually tested

### Current issue

Backup/restore documentation and recovery design exist, but documentation alone is not proof that recovery works.

### Why this must be fixed

A finance system cannot claim disaster recovery readiness until a real backup can be restored into a clean environment and the accounting data passes integrity checks.

### Required fix

Perform an actual restore drill:

1. Create a production-like database backup.
2. Restore it into a fresh isolated PostgreSQL instance.
3. Run migrations/schema checks if appropriate.
4. Run accounting integrity checks.
5. Verify tenants.
6. Verify users/roles.
7. Verify journals.
8. Verify invoices/bills.
9. Verify audit records.
10. Verify uploaded document references where applicable.
11. Verify balances/reports.
12. Record restore duration.

Document:
- RPO;
- RTO;
- backup frequency;
- retention;
- encryption;
- backup verification;
- restore procedure.

### Expected behavior

If the primary database is lost, the team can restore a known backup into a clean database and continue operating with the documented data-loss window.

### Required regression check

Add a repeatable restore verification procedure to the repository/runbook.

---

## 7. Remove fake CI test-result generation

### Current issue

The current CI workflow runs PHPUnit and then writes a hard-coded result:

`passed: 65, failed: 0, total: 65`

This does not represent the actual test result.

### Why this must be fixed

A quality gate must report real test outcomes.

Hard-coded test numbers create false confidence and can hide regressions.

### Required fix

Delete the hard-coded test-results generation.

If test result artifacts are required:
- use PHPUnit's real JUnit/XML output;
- use Pytest's real JUnit/XML output;
- publish the generated reports as CI artifacts;
- optionally add coverage reports.

Never manually write pass/fail counts.

### Expected behavior

If one test fails, CI fails.

If 100 tests pass, the report says 100.

If 99 pass and 1 fails, the report says 99/1.

No code should be able to manufacture a green test report.

### Tests/checks required

Intentionally make one test fail locally/temporarily and verify CI reports failure. Restore the test afterward.

---

## 8. Automated tenant-isolation / IDOR tests

### Current issue

Tenant-aware architecture and authorization exist, but finance applications require adversarial tests proving that one organization cannot access another organization's records.

### Why this must be fixed

A single missing tenant scope can expose:
- invoices;
- bills;
- customers;
- vendors;
- bank accounts;
- documents;
- journals;
- reports;
- audit logs;
- AI context;
- subscriptions;
- exceptions.

This is a critical SaaS security requirement.

### Required fix

Create automated cross-tenant tests for every organization-owned resource.

Pattern:

Tenant A creates resource.

Tenant B attempts:
- GET;
- POST using foreign ID;
- PUT/PATCH;
- DELETE;
- export;
- report access;
- AI lookup;
- document download;
- audit lookup.

Every unauthorized cross-tenant access must fail.

Prefer policy/service-level tenant enforcement plus database/query scoping where appropriate.

### Expected behavior

Tenant A can access only Tenant A data.

Tenant B can access only Tenant B data.

Knowing a UUID/ID from another tenant must not bypass authorization.

The AI service must also receive and enforce tenant/entity scope.

---

# P1 — REQUIRED BEFORE SERIOUS CUSTOMER ONBOARDING

## 9. Password policy and account security

### Current issue

The reviewed password-change flow allows a shorter password threshold than the intended production policy.

### Required fix

Use a minimum password length of **12 characters** across:
- registration;
- password change;
- reset password.

Do not rely only on complexity rules.

Also implement:
- login rate limiting;
- per-account lockout/backoff;
- reset rate limiting;
- verification resend rate limiting;
- session invalidation after sensitive security changes.

### Expected behavior

Repeated login failures trigger protection.

A password shorter than 12 characters is rejected.

Changing/resetting a password invalidates sessions according to the security policy.

---

## 10. MFA for high-risk finance operations

### Current issue

MFA/TOTP/recovery functionality exists, but high-risk operations should have explicit step-up authentication.

### Required fix

Require recent MFA/step-up authentication for operations such as:

- bank account changes;
- organization owner/admin changes;
- period close/reopen;
- bulk reversal;
- sensitive billing/subscription changes;
- security setting changes;
- other irreversible or high-impact finance operations.

### Expected behavior

Normal low-risk browsing does not constantly prompt for MFA.

High-risk operations require an additional authentication factor.

---

## 11. AI must never directly control accounting truth

### Current issue

The AI architecture is strong, but prompt injection detection must not be treated as the primary security boundary.

### Why this must be fixed

User-controlled text can contain instructions such as:
- ignore previous instructions;
- change the amount;
- post this journal;
- bypass approval;
- reveal another tenant's data.

Regex detection can miss new variants.

### Required fix

Keep the existing AI tool permission system, but strengthen the architecture:

**User/untrusted text**
→ AI interpretation  
→ structured output/schema validation  
→ permission check  
→ tenant/entity scope check  
→ deterministic application service  
→ approval if required  
→ accounting/posting service  
→ database

Never:

**User text → LLM → database mutation**

The AI must not be the source of truth for:
- account balances;
- journal totals;
- tax totals;
- invoice totals;
- permissions;
- tenant IDs;
- approval status;
- period state.

### Expected behavior

The AI may suggest or classify.

The deterministic backend decides whether an action is valid.

The accounting engine calculates and posts financial values.

A malicious prompt cannot directly change financial data.

### Tests required

Add prompt-injection regression tests for:
- amount manipulation;
- tenant escape;
- role escalation;
- journal manipulation;
- approval bypass;
- SQL-style instructions;
- hidden instructions in uploaded/document text.

---

## 12. Structured AI input/output

### Current issue

Financial objects are passed into prompts in a way that can mix trusted financial data with user-controlled text.

### Required fix

Where practical:
- pass structured JSON/object payloads;
- clearly separate trusted system fields from untrusted user text;
- use structured output schemas;
- validate every AI-generated field;
- reject unexpected fields;
- apply deterministic business validation after AI output.

### Expected behavior

AI output is treated as an untrusted suggestion.

Invalid totals, IDs, dates, account references, or tenant IDs are rejected before business logic executes.

---

## 13. Object storage security

### Current issue

MinIO is suitable for development/self-hosting, but the current local configuration is not sufficient as-is for public production.

### Required fix

For production either:
- use managed S3-compatible storage; or
- operate MinIO with production-grade security.

Required controls:

- private buckets;
- signed URLs;
- encryption;
- tenant ownership checks;
- file size limits;
- allowed MIME/type validation;
- safe filename handling;
- malware scanning where appropriate;
- retention/lifecycle policy;
- backup;
- deletion policy;
- no public bucket access by default.

Do not expose the MinIO admin console publicly.

### Expected behavior

Users can download only documents they are authorized to access.

A tenant cannot guess another tenant's object key and retrieve its file.

---

## 14. Database accounting invariants

### Current issue

Application-level accounting protections exist, but critical financial invariants should be reinforced at the database/service boundary.

### Required fix

Verify and enforce:

- posted journal entries are immutable;
- changes happen through reversals/adjustments;
- debit total = credit total;
- closed periods reject posting;
- journal posting is idempotent;
- monetary values use decimal-safe types;
- account/organization ownership is enforced;
- duplicate external references are handled;
- audit attribution is stored.

Use database constraints where practical, not only controller validation.

### Expected behavior

No API/AI request can create an unbalanced posted journal.

No ordinary update can silently rewrite historical posted accounting.

Retrying the same idempotent operation does not duplicate financial entries.

---

## 15. Control-account and subledger reconciliation

### Current issue

AP/AR and other subledgers must not drift away from the general ledger.

### Required fix

Add automated reconciliation checks between:

- AR subledger ↔ AR control account;
- AP subledger ↔ AP control account;
- inventory subledger ↔ inventory control account where applicable;
- bank ledger ↔ bank accounts where applicable.

### Expected behavior

Reconciliation differences are detected and surfaced.

The system must not silently hide discrepancies.

---

## 16. Period close safety

### Required fix

Implement a controlled period lifecycle:

Open
→ Close requested
→ Validation/reconciliation
→ Closed

Reopening a closed period must require elevated authorization and audit logging.

### Validation before close

Check for:
- unposted drafts where applicable;
- unbalanced journals;
- reconciliation differences;
- required approvals;
- pending critical integrations;
- tax/FBR submission state where relevant.

### Expected behavior

A normal user cannot casually reopen a closed accounting period.

---

## 17. FBR / Pakistan tax integration reliability

### Current issue

FBR-related architecture exists, but production tax integration cannot be treated as complete merely because endpoints exist.

### Required fix

Implement/verify:

- current official FBR API requirements;
- signed/validated payloads where required;
- submission queue;
- retries;
- idempotency;
- response persistence;
- failure state;
- audit trail;
- reconciliation;
- handling of FBR downtime.

Do not claim full Pakistan tax compliance until the relevant current legal/API requirements have been validated against official sources.

### Expected behavior

A temporary FBR outage does not silently lose an invoice/submission.

The submission remains traceable and retryable.

---

## 18. Provincial tax and withholding tax engine

### Current issue

A Pakistan-first ERP needs jurisdiction-aware tax rules rather than a single generic tax percentage.

### Required fix

Design tax rules around:

- jurisdiction;
- effective date;
- tax type;
- taxable base;
- exemptions;
- customer/vendor classification;
- withholding requirements;
- filing/reporting requirements.

Do not hard-code one global tax rate.

### Expected behavior

Tax calculations are deterministic and versioned by effective date.

Changing a future tax rule does not rewrite historical transactions.

---

## 19. Entitlement/billing enforcement

### Current issue

Billing and entitlement architecture exists, but production SaaS behavior must be enforced server-side.

### Required lifecycle

Trial
→ Active subscription
→ Usage limits
→ Upgrade
→ Payment failure
→ Grace period
→ Suspension
→ Retention/deletion policy

### Required fix

Never trust frontend entitlement checks.

Every protected operation must be validated server-side.

### Expected behavior

Changing frontend code cannot bypass plan limits.

A suspended account cannot perform operations restricted by the subscription state.

---

## 20. Production secrets

### Required fix

Do not commit production secrets.

Move all production credentials to secure environment/secret management.

Rotate any credentials that were ever committed or exposed during development.

Separate:
- local;
- test;
- staging;
- production

credentials.

### Expected behavior

Repository code contains no live production credentials.

---

## 21. Rate limiting and AI cost controls

### Required fix

Add rate limits/cost controls for:
- login;
- password reset;
- verification;
- AI chat;
- AI tool execution;
- expensive reports;
- file uploads;
- exports.

Add per-tenant AI usage limits where appropriate.

### Expected behavior

One user/tenant cannot unintentionally or maliciously generate unlimited AI/API cost.

---

## 22. Observability and alerts

### Required fix

Add structured logging and monitoring for:

- API errors;
- queue failures;
- scheduler failures;
- database connectivity;
- Redis connectivity;
- AI service failures;
- FBR failures;
- authentication anomalies;
- backup failures;
- storage failures.

Do not log:
- passwords;
- reset tokens;
- verification tokens;
- API keys;
- access tokens;
- unnecessary PII.

### Expected behavior

A failed worker, database, AI service, or scheduled job becomes visible to operators.

---

# P2 — SCALE / POST-PILOT

These are important for the long-term SaaS but should not delay the focused P0/P1 hardening work.

## 23. Staging environment

Create a production-like staging environment.

Flow:

PR
→ CI
→ merge
→ build immutable images
→ deploy staging
→ migrations check
→ smoke tests
→ manual approval
→ production

Do not use production as the first place to discover deployment errors.

---

## 24. Migration safety

For production migrations:

- backup before risky changes;
- test migrations on staging;
- use migration locks;
- avoid long table locks;
- use expand/contract patterns for large schema changes;
- verify rollback/recovery strategy;
- test with production-like data volume.

---

## 25. Disaster-recovery rehearsal

Periodically simulate:

- database loss;
- Redis loss;
- object-storage failure;
- AI service outage;
- FBR outage;
- application container failure;
- full server failure.

Document:
- detection;
- response;
- restore;
- validation;
- recovery time.

---

## 26. Data export and deletion

Provide a controlled process for:

- organization data export;
- document export;
- audit export where appropriate;
- account deletion;
- retention;
- legally required record preservation.

Do not delete accounting records merely because a user deletes a UI object.

---

## 27. Multi-entity / multi-currency readiness

For future SaaS scale, plan support for:

- multiple legal entities;
- multiple currencies;
- exchange rates;
- consolidated reporting;
- intercompany transactions.

Do not introduce these features by bypassing current accounting invariants.

---

# Definition of Done

The project is ready for public production only when all P0 items are implemented and tested.

For every fix:

- [ ] implementation complete;
- [ ] regression test added;
- [ ] existing tests still pass;
- [ ] no unrelated workflow broken;
- [ ] authorization verified;
- [ ] tenant isolation verified where applicable;
- [ ] logs do not expose secrets/PII;
- [ ] deployment behavior verified;
- [ ] documentation/runbook updated where required.

## Final validation sequence

Run the complete validation in this order:

1. Laravel unit tests.
2. Laravel PostgreSQL integration tests.
3. Tenant-isolation/IDOR tests.
4. Authentication/security tests.
5. FastAPI tests.
6. Next.js production build.
7. Composer dependency audit.
8. Container image/security checks.
9. Production compose/config validation.
10. Queue worker test.
11. Scheduler test.
12. Backup/restore drill.
13. Database accounting invariant checks.
14. AI prompt-injection regression suite.
15. Staging deployment.
16. Staging smoke tests.
17. Final production readiness review.

## Important

Do not add more AI features until the P0 security, accounting integrity, deployment, backup/restore, and tenant-isolation items are complete.

The core product principle is:

**AI assists. Deterministic finance services decide. The accounting engine posts. PostgreSQL is the financial source of truth.**

