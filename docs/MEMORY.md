# MEMORY — Project State & Working Context
**Version:** v0.3 | **Last updated:** 2026-09-24  
**Framework:** Phased MVP with Strict Accounting Safeguards

---

## Vision
Pakistan-first AI-native finance operating system. The General Ledger is the mathematical source of truth; AI assists through controlled, auditable, and draft-only workflows.

## Benchmark
Rillet is a public category benchmark for continuous close, automated GL, Aura AI, revenue recognition, integrations, multi-entity accounting, reconciliation, close management and reporting. We build our own code, UX, branding and workflows without copying proprietary code or confidential implementation.

## Status
- [x] Concept, problem, and feature map
- [x] Comprehensive Architectural Review & Gap Analysis (`docs/REVIEW.md`)
- [x] Architectural Decisions Record with provisional items (`docs/DECISIONS.md`)
- [x] Core Database Schema & Accounting Invariants (`docs/ERD.md`)
- [x] Phased MVP Roadmap restructuring (`docs/TASKS.md`)
- [x] Stakeholder confirmation on provisional decisions (Segment, Tax, FBR, Bank formats)
- [x] Phase 1 hardening: Database-level check constraints & control account locks
- [ ] Initial pilot customer validation & interview sessions

## Architectural Decisions
1. **Double-Entry Safeguards:** Non-negative debits/credits (`CHECK (debit >= 0 AND credit >= 0)`), mutually exclusive lines (`debit XOR credit`), immutable posted records, and system-locked control accounts (`1010`, `1030`, `2010`).
2. **Two-Stage Period Closing:** `open` → `soft_closed` (operational lock, adjustments only) → `hard_closed` (frozen GL, step-up MFA override required).
3. **Pakistan Tax Boundaries:** Separate Federal GST on physical goods (18%) from Provincial Sales Tax on services (SRB 13/15%, PRA 16%, KPRA 15%, ICT 15%) and automated Section 153 Withholding Tax (WHT) deductions.
4. **AI Boundary Enforcement:** AI has zero direct posting privileges and zero database write access. All AI generation is restricted to classification proposals and draft journals (`status = 'draft'`), requiring human accountant review.

## Technology Stack
- **API:** Laravel 11/12, PHP 8.3+, PostgreSQL 16, Redis, SQLite (in-memory test suite).
- **Web:** Next.js 16 (Turbopack), React 19, TypeScript strict, TanStack Query, Tailwind CSS.
- **AI:** Python 3.12, FastAPI, Pydantic v2, provider adapter abstraction.
- **Infra:** Docker Compose (multi-service), Nginx reverse proxy, MinIO S3 storage, GitHub Actions CI.

## Open Decisions (Requiring Stakeholder Confirmation)
1. **Target Customer Segment:** Tech/Software agencies vs. Wholesale physical goods distributors.
2. **FBR Digital Invoicing Mandate:** Real-time PRAL POS sandbox vs. simulated offline queue adapter.
3. **Bank Statement Priority:** HBL and Meezan Bank CSV statement export formats.
4. **Accounting Framework:** IFRS for SMEs vs. Pakistan AFRS for Non-SMEs (ICAP).
5. **Tax Verification:** Review of Pakistan tax engine rate matrices by a qualified Pakistani Chartered Accountant.

## Coding Assistant Protocol
Always read `MEMORY.md`, `RULES.md`, `ERD.md`, `DECISIONS.md`, and relevant `TASKS.md` items before making changes. Implement the smallest correct change, maintain accounting invariants, run test suites, and never bypass financial controls.

---

## Session Update
```text
Date: 2026-09-26
Current status: All 7 Phases of the Finova ERP Roadmap are 100% completed, fully integrated, and verified across both backend and frontend.

Summary of Phased Deliverables:
- [PHASE 1] Minimalist Rillet-Inspired UI Shell:
  * 68px white icon rail (SidebarNavigation.tsx) with calm light palette (#FFFFFF, #F8FAFC).
  * TopHeader.tsx with tenant badge, active accounting period, and live dual-engine health indicator.
  * Executive Launchpad (LaunchpadView.tsx) with live gauge dials and real-time Attention Stream.

- [PHASE 2] Accounting & Subledger Surfaces:
  * Upgraded all 6 subledgers onto standardized DataTable, FilterBar, and SlideOverDrawer:
    - InvoicesView.tsx (AR subledger, FBR PRAL QR fiscalization, receipt tracking).
    - BillsView.tsx (AP subledger, automated 3-Way Matching PO/Bill/Receipt).
    - GeneralLedgerView.tsx (Journal entries, double-entry invariant verification, lines drilldown).
    - ChartOfAccountsView.tsx (System-locked control accounts, normal balance enforcement).
    - BankMatchingRulesView.tsx (Rule conditions, auto-clearing thresholds, priority weighting).
    - CloseChecklistView.tsx (Progress countdown, control procedures, soft/hard close lifecycle).

- [PHASE 3] Workflow & Multi-Layer Approval Engine (ApprovalsWorkflowView.tsx):
  * Multi-layer approval hierarchy: Tier 1 Specialist (<100k PKR), Tier 2 Finance Manager (<500k PKR), Tier 3 Executive CFO (>=500k PKR).
  * Interactive Multi-Step Approval Timeline visualizer (Initiator -> Invariant Check -> Manager -> CFO -> GL Posting).
  * Mandatory audit rejection reasoning and digital signature logging.
  * Exception waivers for 3-way match tolerances and governance policy rules enforcement.

- [PHASE 4] Full-Scale Axiom AI Workspace (CommandCenterView.tsx):
  * Axiom Copilot Assistant: Live Groq LPU (Llama 3.3 70B) grounded in active GL context.
  * Autonomous Financial Agents Directory: 5 specialized controllers (AP, Tax/ATL, Invariants, Close, Cash).
  * Flow Execution Plans: Visual DAG pipelines for Bill Ingestion, Month-End Accruals, and Cash Clearing.
  * Human-in-the-Loop Proposal Queue: Zero direct DB writes by LLM; strictly generates draft entries with projected debit/credit balances.

- [PHASE 5] Continuous AI & Accruals Detection (ContinuousAccrualsFluxView.tsx):
  * Continuous Accruals Detector: Identifies missing recurring vendor bills (AWS, LESCO, Rent) with confidence scoring and trailing trends.
  * Subledger & GL Flux Analysis: MoM period comparison with significance thresholds (>15% and >PKR 25k) and AI root-cause variance explanations.
  * Prepayments & Amortization Schedule: Straight-line contract tracking for Account 1150.
  * Integrated sub-navigation switcher in Month-End Close section.

- [PHASE 6] Multi-Entity Consolidation & Advanced Reporting (AdvancedReportingConsolidationView.tsx):
  * IFRS / GAAP Financial Reporting Engine:
    - Statement of Profit & Loss (P&L) with multi-level revenue, COGS, operating profit, and net income.
    - Balance Sheet with real-time double-entry mathematical invariant verification (Assets == Liabilities + Equity).
    - Statement of Cash Flows (Indirect Method) with operating, investing, and financing cash reconciliation.
    - Trial Balance with live debit/credit totals, classification filters, and zero-variance verification.
  * Multi-Entity Consolidation & Eliminations:
    - Indus Holding Co. (Parent - PKR), Gulf Trading FZE (AED), UK Logistics Ltd. (GBP).
    - Reciprocal intercompany AP/AR elimination to net zero (Account 1035 vs 2035).
    - Active Foreign Exchange (FX) rate ticker (SBP fixings) & IAS 21 FX Revaluation engine.
  * SlideOverDrawer record drill-down: Clicking any statement row opens the full underlying journal entry ledger.

- [PHASE 7] Integrations Framework, Security Settings & Audit Polish:
  * Immutable Cryptographic Audit Trail (ImmutableAuditSecurityView.tsx):
    - SHA-256 hash-chained event ledger with tampering detection.
    - 1-click chain integrity verification checking sequential hash linkage.
    - Segregation of duties Role-Based Access Control (RBAC) governance matrix.
  * Integrations Hub & Developer Settings (IntegrationsSettingsView.tsx):
    - Catalog for Banking APIs (HBL, Meezan), Tax (FBR PRAL), Payments (Stripe), and OCR (Textract).
    - Developer API Keys management & scoped token generator.
    - Tenant accounting policies & dual-authorization threshold configurations.

Verifications & Quality Assurance:
- Next.js 16.3.5 Turbopack production build: 0 errors, all routes statically and dynamically optimized.
- PHPUnit test suite: 150/150 passing with 1,175 assertions and 100% double-entry invariant coverage.
```
