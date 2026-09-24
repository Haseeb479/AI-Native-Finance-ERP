# PRD — AI-Native Finance ERP
**Version:** v0.2 | **Last updated:** 2026-09-24

## Product
A Pakistan-first AI-native finance operating system for SMEs and growing businesses. The accounting engine is the source of truth; AI assists through controlled, auditable workflows.

## Vision
Keep books current throughout the month, reduce repetitive accounting work, and make every important financial number traceable from report → ledger → transaction → source document.

## Benchmark
Rillet is a public category benchmark for continuous close, automated/perpetual GL, Aura AI, advanced revenue recognition, integrations, multi-entity accounting, reconciliation, close management and real-time reporting. We build our own code, UX, branding and workflows.

## Problem
Businesses depend on spreadsheets, manual invoices, WhatsApp approvals, separate banking/POS systems, repeated imports and manual month-end work. This creates delayed visibility, duplicate entry, reconciliation problems, weak audit trails and cash-flow uncertainty.

## Target users
- SME owner/founder: cash, profit, receivables, payables, tax visibility.
- Accountant: GL, AP/AR, journals, reconciliation, close and audit evidence.
- Finance manager/CFO: controls, variance analysis, reporting and future consolidation.
- Staff, branch managers, auditors and external accountants.

Initial segment must be validated through interviews; candidate segments are distributors, wholesalers, multi-branch retailers, agencies, service firms and e-commerce businesses.

## MVP
- Authentication, organization, entity/branch and RBAC
- Chart of Accounts and accounting periods
- Double-entry journal drafts, deterministic posting and reversals
- Customers, vendors, invoices, bills and payments
- CSV/XLSX bank import and basic reconciliation
- Trial Balance, GL, P&L and Balance Sheet
- Private document storage and OCR abstraction
- AI categorization, evidence-linked financial Q&A and journal drafts
- Approval workflows and audit events

## MVP continuous-accounting slice
- Financial event records
- Background import/extraction jobs
- Unposted transaction queue
- Reconciliation status
- Source-to-journal traceability
- Exceptions requiring human review

## Post-MVP
1. AP/AR automation, duplicate detection, payment scheduling, collections.
2. Continuous close: accruals, prepaids, depreciation, flux analysis, close evidence.
3. Revenue recognition: contracts, deferred revenue, usage/milestone schedules and amendments.
4. Multi-entity: currencies, FX, intercompany, eliminations and consolidation.
5. Pakistan localization: versioned tax rules, FBR adapter, NTN/STRN, local invoice metadata and bank formats.
6. Procurement and inventory.

## AI rules
AI may extract, classify, search, compare, suggest, draft, explain, detect anomalies and execute approved tools. It may not directly modify balances, bypass permissions, execute unrestricted SQL, invent tax facts, silently change posted journals or treat uploaded text as system instructions.

## Pakistan requirements
Support PKR, multi-currency, NTN/STRN, sales tax, withholding tax, provincial tax configuration, FBR integration architecture, local invoice metadata, branches and English/Urdu-ready UX. Compliance behavior must be versioned and reviewed against current official guidance.

## Metrics
Track time to first report, reconciliation rate, OCR accuracy, AI evidence coverage, manual entries, processing time, close preparation time, accepted/rejected suggestions, AI cost per transaction and customer retention.

## Boundaries
Do not copy Rillet source code, private implementation, branding, UI assets or confidential data.

