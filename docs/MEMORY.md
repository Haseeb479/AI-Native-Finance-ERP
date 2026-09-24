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
- [x] Core Implementation (Phases 1 through 15 complete, 139/139 tests passing)
- [x] Revenue Recognition (ASC 606 / IFRS 15 engine, schedules, contract amendments)
- [x] Multi-container Docker orchestration (apps/api, apps/ai, apps/web, Nginx, Compose)
- [x] Automated CI/CD quality gate (.github/workflows/ci.yml)
- [x] Pakistani SME Pilot onboarding engine (erp:pilot-onboard CLI)
- [ ] Initial customer segment interviews
- [ ] Live cloud deployment & pilot customer validation

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
Current status: All 15 Architecture Phases + 3 Operational Milestones implemented; 139/139 PHPUnit tests, 15/15 Pytest tests, Next.js build clean.
Completed:
- Phase 12 Revenue Recognition engine (ASC 606 / IFRS 15), straight-line amortization schedules, contract amendments, deterministic double-entry posting.
- Docker multi-container stack: apps/api/Dockerfile, apps/ai/Dockerfile, apps/web/Dockerfile, infra/docker/docker-compose.full.yml, infra/nginx/nginx.conf.
- Pakistani SME Pilot Onboarding Command (php artisan erp:pilot-onboard) & test suite (PilotOnboardingTest).
- GitHub Actions CI quality gate (.github/workflows/ci.yml) validating API, AI, and Web on every push/PR.
In progress: Full suite verification and push to origin main.
Blocked: None.
Decisions: Dedicated multi-service docker compose; direct seeder execution in artisan commands; strict CI concurrency groups.
Known bugs: None.
Tests: 139 passed in Laravel (1,140 assertions); 15 passed in FastAPI (100%); Next.js static build passing.
Next 3 actions:
1. Conduct customer interview sessions with Pakistani SME accountants.
2. Select staging cloud host (AWS ECS, Hetzner, or DigitalOcean Kubernetes).
3. Test end-to-end webhook ingestion with local bank statement feeds.
```



