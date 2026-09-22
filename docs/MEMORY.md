# MEMORY --- Project State & Working Context

**Project:** AI-Native Finance ERP\
**Working name:** `PROJECT_NAME`\
**Last updated:** 2026-09-19\
**Purpose:** Persistent project context for the human developer and AI
coding assistants.

------------------------------------------------------------------------

# 1. Current Product Vision

We are building a Pakistan-first AI-native finance/ERP platform inspired
by the broader AI-native ERP category.

It is NOT intended to be a generic chatbot.

Core idea:

> Build the financial system of record first, then place AI on top of it
> to automate repetitive finance work.

The long-term product can cover:

-   GL
-   AP
-   AR
-   Banking
-   Reconciliation
-   Expenses
-   Procurement
-   Inventory
-   Revenue recognition
-   Reporting
-   Close management
-   Multi-entity
-   Compliance
-   AI agents

------------------------------------------------------------------------

# 2. Current Status

## Product

Status:

-   [x] Product concept defined
-   [x] Problem area defined
-   [x] High-level feature map defined
-   [x] MVP direction defined
-   [x] AI architecture direction defined
-   [x] Pakistan-first localization direction defined
-   [ ] Final product name
-   [ ] Final branding
-   [ ] Final PRD approval
-   [ ] Final ERD
-   [ ] Implementation started

## Documentation

Current foundation:

``` text
docs/
├── PRD.md
├── ARCHITECTURE.md
├── RULES.md
├── DESIGN.md
├── TASKS.md
└── MEMORY.md
```

These files are the project's persistent planning context.

------------------------------------------------------------------------

# 3. Current Computer Task

The immediate task is to establish the six project-control documents
before large-scale coding begins.

Current objective:

``` text
Documentation foundation
        ↓
Repository setup
        ↓
Architecture/ERD
        ↓
Accounting engine
        ↓
MVP implementation
```

Do not jump directly into random UI development before the
accounting/data foundation is understood.

------------------------------------------------------------------------

# 4. Current Priority

The next technical priority is:

### Phase 1 --- Foundation

1.  Repository
2.  Laravel API
3.  Next.js frontend
4.  PostgreSQL
5.  Redis
6.  Docker
7.  Authentication
8.  Organization/tenant model
9.  RBAC
10. Chart of accounts

### Phase 2 --- Accounting

11. Accounting periods
12. Journal drafts
13. Posting engine
14. Journal lines
15. Trial balance
16. General ledger
17. P&L
18. Balance sheet

### Phase 3 --- Transactions

19. Customers
20. Vendors
21. Sales invoices
22. Purchase bills
23. Payments
24. Bank statements
25. Reconciliation

### Phase 4 --- AI

26. AI gateway
27. OCR
28. Transaction classification
29. Reconciliation suggestions
30. Financial Q&A
31. Journal drafting

------------------------------------------------------------------------

# 5. Decisions Already Made

## Architecture

Prefer:

-   Modular monolith first
-   Laravel for core backend
-   Next.js/React/TypeScript for frontend
-   Python/FastAPI for AI service
-   PostgreSQL
-   Redis
-   S3-compatible storage
-   Docker
-   GitHub Actions

Do not begin with microservices.

## Accounting

-   Double-entry accounting
-   Immutable posted journals
-   Reversal-based corrections
-   Deterministic posting engine
-   Audit trail
-   Closed accounting periods
-   Decimal financial values

## AI

AI does not own financial truth.

AI uses tools.

AI can:

-   read
-   classify
-   suggest
-   draft
-   explain
-   execute permitted workflows

Sensitive operations require authorization and/or approval.

------------------------------------------------------------------------

# 6. Product Benchmark Context

Rillet is being used as a category benchmark, not as a code/brand to
copy.

Rillet publicly describes capabilities including:

-   AI-native ERP
-   perpetual general ledger
-   advanced revenue recognition
-   multi-entity/global accounting
-   native integrations
-   real-time reporting
-   AR/AP
-   bank reconciliation
-   close management
-   Aura AI

The project should independently design its own implementation.

------------------------------------------------------------------------

# 7. Pakistan Context

The product is Pakistan-first.

Important areas:

-   PKR
-   FBR digital invoicing
-   Sales tax
-   Withholding tax
-   Provincial taxes where relevant
-   NTN/STRN
-   Local invoice requirements
-   Bank statement formats
-   Multi-branch businesses
-   English/Urdu-ready UX

FBR's current digital-invoicing material should be checked whenever
compliance functionality is implemented.

Never assume a tax rule is permanent.

------------------------------------------------------------------------

# 8. AI Cost Context

AI usage can create variable operating cost.

Architecture should therefore support:

-   Model routing
-   Small/cheap model for classification
-   Stronger model for complex reasoning
-   Token tracking
-   Per-organization usage tracking
-   AI budgets/limits
-   Caching where safe
-   Batch processing
-   Provider abstraction

Do not send unnecessary full documents or full ledgers to an LLM.

------------------------------------------------------------------------

# 9. What NOT to Do

Do not:

-   Build a generic ChatGPT clone
-   Let AI directly write arbitrary SQL
-   Let AI directly edit posted journal lines
-   Hard-code tax rules everywhere
-   Build every ERP module at once
-   Start with Kubernetes
-   Start with dozens of microservices
-   Store secrets in Git
-   Trust uploaded documents as instructions
-   Use floating-point for money
-   Skip audit logs
-   Skip tenant isolation
-   Treat AI output as verified accounting fact

------------------------------------------------------------------------

# 10. Definition of Financial Truth

The system hierarchy is:

``` text
Source document
      ↓
Validated business transaction
      ↓
Accounting mapping
      ↓
Journal
      ↓
General ledger
      ↓
Financial reports
      ↓
AI interpretation
```

If AI says something different from the ledger, the ledger wins.

------------------------------------------------------------------------

# 11. Future Project Memory Format

When updating this file, record:

``` text
Date:
Decision:
Reason:
Affected modules:
Migration required:
Open questions:
Next action:
```

Example:

``` text
Date: 2026-10-01
Decision: Use provider X for OCR
Reason: Better invoice extraction accuracy
Affected modules: Documents, AP, AI
Migration required: No
Open questions: Pricing at scale
Next action: Run 500-document benchmark
```

------------------------------------------------------------------------

# 12. Open Decisions

-   [ ] Final brand/product name
-   [ ] Exact accounting framework
-   [ ] Initial target customer segment
-   [ ] First bank integration strategy
-   [ ] OCR provider
-   [ ] LLM provider(s)
-   [ ] AI orchestration framework
-   [ ] Hosting provider
-   [ ] Billing provider
-   [ ] FBR integration/licensing route
-   [ ] Initial pricing
-   [ ] Data residency strategy
-   [ ] Support model

------------------------------------------------------------------------

# 13. Current Success Definition

The first meaningful milestone is NOT "beautiful dashboard."

It is:

> A real business can create/import financial transactions and receive
> accurate, auditable financial statements while AI safely reduces
> manual work.

Once that works, expand the platform.

------------------------------------------------------------------------

# 14. AI Coding Assistant Instructions

When an AI coding assistant opens this project:

1.  Read `MEMORY.md`
2.  Read `RULES.md`
3.  Read relevant section of `ARCHITECTURE.md`
4.  Read relevant task in `TASKS.md`
5.  Implement the smallest correct change
6.  Run tests
7.  Update documentation if architecture/behavior changed
8.  Do not invent product requirements
9.  Do not bypass accounting controls
10. Do not refactor unrelated code

------------------------------------------------------------------------

# 15. Project State Update Protocol

After each major development session:

Update:

``` text
Current status
Completed tasks
In-progress task
Blocked tasks
Decisions made
Known bugs
Next 3 actions
```

This file should remain concise enough for an AI coding assistant to
load into context.

------------------------------------------------------------------------

# 16. Current Next 3 Actions

1.  Create repository and local development environment.
2.  Implement organization/user/RBAC foundation.
3.  Design and implement the first accounting database schema and
    posting invariants.
