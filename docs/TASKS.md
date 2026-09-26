# TASKS — Implementation Roadmap
**Version:** v0.3 | **Last updated:** 2026-09-24  
**Framework:** Phased MVP with Strict Accounting Safeguards

---

## Phase 0 — Discovery and Stakeholder Confirmation
*Goal: Finalize provisional assumptions with business owners, pilot customers, and accounting advisors before production lock.*
- [ ] Confirm target customer segment: B2B Software/Service Firms vs. Single-Warehouse Wholesale Distributors (`docs/DECISIONS.md #1`)
- [ ] Confirm accounting framework: IFRS for SMEs vs. Pakistan AFRS for Non-SMEs (`docs/DECISIONS.md #5`)
- [ ] Confirm Pakistan tax boundaries: Federal GST (18%), Provincial Services (SRB/PRA/KPRA), and WHT Section 153 deduction rates (`docs/DECISIONS.md #6`)
- [ ] Confirm FBR digital invoicing route: Simulated adapter & offline queue vs. direct live PRAL sandbox (`docs/DECISIONS.md #3`)
- [ ] Collect real bank statement sample files (HBL, Meezan Bank) for parsing test fixtures (`docs/DECISIONS.md #4`)
- [ ] Confirm initial pilot acceptance criteria and data retention policies

---

## Phase 1 — Accounting Foundation
*Goal: Build the mathematically uncompromised financial engine and tenant security boundary.*
- [x] Multi-tenant organization isolation with server-side context middleware and strict SQL tenant checks
- [x] RBAC permission matrix (Owner, Admin, Accountant, Finance Manager, Staff, Auditor)
- [x] Fiscal years and Two-Stage accounting periods (`open`, `soft_closed`, `hard_closed`)
- [x] Chart of Accounts with Control Account locks (`is_control_account` preventing direct manual journals to 1010, 1030, 2010)
- [x] Journal drafts and line models with non-negative invariants (`debit >= 0`, `credit >= 0`, `debit XOR credit`)
- [x] Deterministic double-entry posting engine with mandatory idempotency keys
- [x] Immutable posted journal enforcement and automated reversal workflows
- [x] Mathematical invariant tests: balanced journals, closed period rejection, tenant isolation, zero tampering

---

## Phase 2 — Core Finance MVP
*Goal: Execute day-to-day transactional workflows, tax calculation, and financial statements.*
- [x] Customers and Accounts Receivable subledger
- [x] Sales Invoices with date-versioned Pakistan sales tax calculation and simulated QR fiscalization
- [x] Vendors and Accounts Payable subledger
- [x] Vendor Bills with tax categorization and approval statuses
- [x] Payment matching with automatic Withholding Tax (WHT Section 153) deduction mapping to Account 2040
- [x] AR and AP aging reports with subledger-to-GL reconciliation integrity checks
- [x] Core Financial Statements: Trial Balance, General Ledger, Profit & Loss (P&L), Balance Sheet
- [x] Audit event logging on all financial mutations

---

## Phase 3 — Banking and Reconciliation
*Goal: Accurate cash tracking, statement ingestion, and statement-to-ledger matching.*
- [x] Corporate Bank Account model linked to Cash/Bank Chart of Accounts
- [x] CSV/XLSX statement import parser with SHA-256 fingerprint deduplication
- [x] Statement line normalization and duplicate import rejection
- [x] Rule-assisted and manual transaction matching (Statement Line ↔ Invoice / Bill / Journal)
- [x] Unmatched transaction queue and periodic reconciliation summary report

---

## Phase 4 — Controlled AI Features
*Goal: Repetitive task reduction under strict read-only and draft-only boundaries.*
- [x] AI Gateway abstraction with provider adapters (Mock, Gemini, OpenAI) and cost/token tracking
- [x] Asynchronous background queue architecture for document extraction
- [x] Prompt-injection isolation (treating OCR and uploaded text strictly as escaped JSON data payloads)
- [x] Automated transaction account categorization suggestions with confidence scoring
- [x] Balanced draft journal generation (AI produces drafts only; human review required to post)
- [x] Evidence-grounded natural language financial Q&A (read-only access; zero execution privileges)

---

## Phase 5+ — Post-MVP Features (Deferred)
*Goal: Enterprise extensions, complex multi-entity consolidation, and advanced operational modules.*
- [x] **Multi-Entity Consolidation & Intercompany Eliminations:**
  - Multi-currency revaluations, intercompany invoicing, and automated consolidated financial reporting
- [ ] **Advanced Perpetual Inventory & Manufacturing:**
  - Multi-warehouse inventory, perpetual FIFO/WAC cost layer depletion, goods receipt 3-way matching, and automated COGS posting
- [ ] **Complex Revenue Recognition (ASC 606 / IFRS 15 Advanced):**
  - Variable consideration, milestone schedules, usage-based metering, and retrospective contract modifications (Straight-line monthly retained in core)
- [ ] **Direct FBR PRAL Production Integration:**
  - Licensed integrator workflow, production cryptographic signing, and real-time FBR gateway sync
- [ ] **Continuous Close Automation:**
  - Automated flux variance analysis, automated accrual reversals, and close evidence pack compilation
- [ ] **Outbound Webhooks & Integration Hub:**
  - Real-time HMAC-signed event broadcast and third-party SaaS connector marketplace
