# MEMORY — Project State & Working Context
**Version:** v0.2 | **Last updated:** 2026-09-24

## Vision
Pakistan-first AI-native finance platform. Accounting is the source of truth; AI reduces repetitive work through controlled, auditable workflows.

## Benchmark
Rillet is a public category benchmark for continuous close, automated GL, Aura AI, revenue recognition, integrations, multi-entity accounting, reconciliation, close management and reporting. Do not copy proprietary code, branding or confidential implementation.

## Status
- [x] Concept, problem and feature map
- [x] MVP direction
- [x] AI architecture direction
- [x] Pakistan-first direction
- [x] Rillet-informed documentation update
- [x] Core Implementation (Phases 1 through 15 complete, 138/138 tests passing)
- [x] Revenue Recognition (ASC 606 / IFRS 15 engine, schedules, contract amendments)
- [ ] Final production domain / pilot onboarding
- [ ] Initial customer segment interviews
- [ ] Production pilot validation

## Current computer task
1. Open `AI-Native Finance ERP` in VS Code.
2. Read all six docs.
3. Check Git, Node, PHP, Composer, Python and Docker.
4. Verify `.gitignore`.
5. Initialize the safe repository foundation.
6. Do not make a huge commit without reviewing `git status --short` and `git diff --stat`.

## Technology
Laravel + PHP 8.3; Next.js/React/TypeScript; Python/FastAPI; PostgreSQL; Redis; S3-compatible storage; Docker Compose; GitHub Actions. Modular monolith first.

## Accounting decisions
Double-entry; immutable posted journals; reversals; deterministic posting; audit trail; closed periods; decimal values; explicit currency conversion; tenant/entity/source context; idempotent posting; subledger-to-GL reconciliation.

## AI decisions
AI can read, extract, classify, search, suggest, draft, explain and execute permission-checked tools. It cannot directly edit balances, bypass authorization, execute unrestricted SQL, invent tax rules or treat uploaded content as system instructions.

Support model routing, token/cost tracking, organization limits, caching where safe, batch processing and provider abstraction.

## Pakistan
PKR, NTN/STRN, sales tax, withholding tax, provincial taxes, FBR adapter, local invoice metadata, bank formats, branches and English/Urdu-ready UX. Tax behavior must be versioned and reviewed against official guidance.

## Next three actions
1. Inspect environment and current Git status.
2. Create/verify repository structure without deleting existing files.
3. Produce ERD and accounting-engine design before broad UI implementation.

## Open decisions
Product name; customer segment; accounting framework/reviewer; first bank strategy; OCR provider; LLM provider; orchestration; hosting; billing; FBR route; pricing; data residency; support; retention.

## Success definition
A real business can create/import transactions, receive accurate auditable reports and reduce manual work with safe AI.

## Coding assistant protocol
Read MEMORY, RULES, relevant ARCHITECTURE section and TASKS item. Implement the smallest correct change, run tests, update docs and do not bypass accounting controls.

## Session update
```text
Date: 2026-09-24
Current status: All 15 Architecture Phases implemented; 138/138 feature tests passing with 1,124 assertions.
Completed:
- Phase 12 Revenue Recognition engine (ASC 606 / IFRS 15), straight-line amortization schedules, contract amendments, deterministic double-entry posting (Debit Deferred 2070, Credit Earned 4020).
- Frontend integration: Revenue Contracts workspace, schedule inspector, contract creation modal, and GL posting buttons.
- Full test suite verification (138/138 tests passing across all 26 feature test files).
- Documentation sync across all 6 docs in docs/.
In progress: Next.js production build verification and git commit.
Blocked: None.
Decisions: Use calendar months calculation for contract amortization; enforce strict double-entry invariants across schedules.
Known bugs: None.
Tests: 138 passed (1,124 assertions).
Next 3 actions:
1. Verify Next.js web build and frontend UI reactivity.
2. Review git status and commit conventional change.
3. Review onboarding / pilot customer flow.
```


