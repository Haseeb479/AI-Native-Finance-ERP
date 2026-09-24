# ARCHITECTURE — AI-Native Finance ERP
**Version:** v0.2 | **Last updated:** 2026-09-24

## Principles
Start with a modular monolith. Optimize for accounting correctness, tenant isolation, auditability, testability, AI safety and clear boundaries. Extract services only when operational evidence justifies it.

## Stack
- Web: Next.js, React, TypeScript strict, Tailwind, TanStack Query, Zod.
- API: Laravel, PHP 8.3+, PostgreSQL, Redis, queues, policies, events.
- AI: Python, FastAPI, Pydantic, provider adapter, structured outputs, tool registry, evaluation and cost tracking.
- Infra: Docker Compose, S3-compatible storage, GitHub Actions, observability.

## Structure
```text
apps/{web,api,ai}
packages/{ui,types,config,accounting-rules}
docs/
database/{migrations,seeders,schemas}
infra/{docker,nginx,deployment}
tests/{integration,e2e,security,ai-evals}
scripts/
.github/workflows
```

## Source of truth
```text
Source document/event
→ validated transaction
→ accounting mapping
→ journal draft
→ validation/approval
→ posting engine
→ general ledger
→ reports
→ AI interpretation
```

## Accounting invariants
- Debits equal credits for every posted journal.
- Posted journals are immutable.
- Corrections use reversals/adjustments.
- Closed periods reject unauthorized posting.
- Money uses decimal/numeric types.
- Currency conversion is explicit.
- Every entry has tenant/entity/source/actor context.
- Posting is idempotent.
- Subledgers reconcile to the GL.

## Domains
Identity, Organization, Accounting, Sales, Purchasing, Banking, Reconciliation, Expenses, Documents, Reporting, Close, Revenue, Tax, Integrations, Workflow and Audit.

## Continuous-close foundation
Create events such as invoice.posted, bill.approved, payment.received, bank.transaction.imported, reconciliation.completed, journal.posted, document.uploaded and period.closed. Use queues for imports, extraction, reconciliation and exception detection.

## Revenue architecture
Contract/billing/usage event → performance obligation and policy mapping → recognition schedule → validation → journal draft → approval/posting → audit lineage. Schedule changes must be versioned and recomputable.

## AI architecture
Request → intent/context → tenant/entity/permission check → structured retrieval → tool selection → validated tool execution → evidence → answer/draft. AI tools require schema, permission, side effects, idempotency and audit behavior.

## Integration hub
Use adapters with credentials reference, scopes, sync cursor, retries, webhook verification, idempotency and audit trail. Start with CSV/XLSX, storage and OCR; add banking, payment, FBR and messaging integrations after validation.

## Security
MFA-ready authentication, RBAC, object authorization, signed URLs, file validation/scanning, webhook signatures, rate limits, secret management, audit logs, prompt-injection defenses and backup/restore testing.

## Testing
Unit, API, integration, E2E, accounting invariants, tenant isolation, permission, AI evaluation, OCR, load and disaster-recovery tests.

