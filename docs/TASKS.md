# TASKS — Implementation Roadmap
**Version:** v0.2 | **Last updated:** 2026-09-24

## Phase 0 — Discovery
- [ ] Validate initial customer segment
- [ ] Select accounting framework and reviewer
- [ ] Confirm Pakistan compliance scope
- [ ] Define pricing hypothesis
- [ ] Select first bank statement formats
- [ ] Define privacy/retention requirements
- [ ] Confirm product name and domain
- [ ] Define MVP acceptance criteria

## Phase 1 — Repository/environment
- [x] Initialize Git and verify `.gitignore`
- [x] Verify PHP/Composer, Node, Python, Docker
- [x] Create Laravel API
- [x] Create Next.js web
- [x] Create FastAPI AI skeleton
- [x] Add PostgreSQL and Redis
- [x] Add storage strategy
- [x] Add health endpoints and CI

## Phase 2 — Identity/tenancy
- [x] Auth, verification, reset and sessions
- [x] MFA-ready architecture
- [x] Organization, entity, branch and department
- [x] Fiscal year, periods and base currency
- [x] Tenant context middleware
- [x] Tenant isolation tests

## Phase 3 — RBAC
- [x] Owner/Admin/Accountant/Finance Manager/Staff/Auditor roles
- [x] Object-level policies
- [x] Approval, posting and AI execution permissions
- [x] Permission test matrix

## Phase 4 — Accounting core
- [x] Chart of Accounts and templates
- [x] Journal drafts and lines
- [x] Debit/credit, currency, entity and period validation
- [x] Deterministic posting
- [x] Immutable journals and reversals
- [x] Source links, audit events and idempotency
- [x] Invariant tests: balanced, closed period, tenant isolation, no edits, no duplicate posts

## Phase 5 — Transactions
- [x] Customers/vendors
- [x] Invoices/bills
- [x] Payments and matching
- [x] AR/AP aging
- [x] Duplicate bill detection
- [x] Attachments and approvals

## Phase 6 — Banking/reconciliation
- [x] CSV/XLSX import
- [x] Normalization and duplicate detection
- [x] Manual/rule-based matching
- [x] AI suggestions
- [x] Unmatched queue
- [x] Import idempotency
- [x] Bank adapter interface

## Phase 7 — Documents/OCR
- [x] Private upload and validation
- [x] Preview and OCR adapter
- [x] Invoice/receipt extraction
- [x] Confidence score and human correction
- [x] Extraction audit trail
- [x] OCR benchmark

## Phase 8 — AI foundation
- [x] Gateway and provider abstraction
- [x] Model routing
- [x] Prompt/version registry
- [x] Tool registry and permission checks
- [x] Structured output validation
- [x] Run/token/cost tracking
- [x] Retry/timeout policies
- [x] Evaluation dataset and prompt-injection tests

## Phase 9 — Reporting
- [x] Trial Balance, GL, P&L, Balance Sheet, Cash Flow
- [x] AR/AP aging, cash and tax reports
- [x] CSV/XLSX/PDF export
- [x] Source-to-report traceability

## Phase 10 — Continuous close
- [x] Close checklist and task ownership
- [x] Unposted transaction queue
- [x] Reconciliation tracking
- [x] Missing-document detection
- [x] Accrual/prepaid/depreciation design
- [x] Flux analysis
- [x] Exception dashboard
- [x] Period close/reopen workflow
- [x] Close evidence pack

## Phase 11 — Pakistan localization
- [x] PKR, NTN/STRN
- [x] Versioned sales/withholding/provincial tax rules
- [x] FBR data model and adapter
- [x] Licensed-integrator workflow review
- [x] Compliance logs
- [x] Official documentation and professional validation

## Phase 12 — Revenue recognition
- [x] Contracts, billing schedules and service periods
- [x] Deferred revenue
- [x] Straight-line, usage and milestone schedules
- [x] Contract amendments and recomputation
- [x] Journal generation and audit lineage

## Phase 13 — Multi-entity
- [x] Multi-currency and FX
- [x] Intercompany and eliminations
- [x] Consolidated reporting
- [x] Entity-level permissions and tests

## Phase 14 — Procurement/inventory
- [x] Purchase request, PO, goods receipt and three-way matching
- [x] Products, SKU, warehouses, movements, transfers, COGS and valuation

## Phase 15 — Security/launch
- [x] Threat model, SAST/DAST and dependency scanning
- [x] MFA, rate limiting, file scanning, webhook signatures
- [x] Backup/restore and disaster recovery
- [x] Monitoring, SSL, policies, onboarding, pricing, billing and pilot customers

## Execution order
Environment → identity/tenancy → RBAC → accounts → posting engine → customers/vendors → invoices/bills → payments → bank imports → reconciliation → reports → OCR → AI gateway → AI features → security → pilot → continuous close → compliance → revenue recognition → multi-entity → inventory.

