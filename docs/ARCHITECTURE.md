# ARCHITECTURE --- AI-Native Finance ERP

**Status:** Architecture foundation / v0.1\
**Last updated:** 2026-09-19

------------------------------------------------------------------------

# 1. Architecture Goal

Build a secure, auditable, multi-tenant finance platform that can begin
as a modular monolith and later split selected workloads into services.

Do NOT start with microservices.

The initial architecture should optimize for:

-   Correct accounting
-   Development speed
-   Clear boundaries
-   Testability
-   Security
-   Auditability
-   AI tool safety
-   Future scale

------------------------------------------------------------------------

# 2. High-Level Architecture

``` text
                         ┌─────────────────────┐
                         │     Web Client      │
                         │ React / Next.js     │
                         └──────────┬──────────┘
                                    │
                         ┌──────────▼──────────┐
                         │     API Layer       │
                         │ Laravel / REST      │
                         └──────────┬──────────┘
                                    │
        ┌───────────────────────────┼───────────────────────────┐
        │                           │                           │
┌───────▼────────┐        ┌────────▼─────────┐        ┌────────▼────────┐
│ Accounting Core│        │ Workflow Engine  │        │ Integration Hub│
│ GL / AP / AR   │        │ approvals/tasks  │        │ FBR/banks/etc. │
└───────┬────────┘        └────────┬─────────┘        └────────┬────────┘
        │                           │                           │
        └───────────────────────────┼───────────────────────────┘
                                    │
                         ┌──────────▼──────────┐
                         │ PostgreSQL          │
                         │ Source of truth     │
                         └──────────┬──────────┘
                                    │
              ┌─────────────────────┼────────────────────┐
              │                     │                    │
      ┌───────▼──────┐      ┌──────▼───────┐    ┌──────▼───────┐
      │ Redis / Queue│      │ Object Store │    │ Search/Vector │
      │ Jobs         │      │ S3 compatible│    │ optional      │
      └──────────────┘      └───────────────┘    └──────────────┘

                         ┌─────────────────────┐
                         │ AI Service          │
                         │ Python / FastAPI    │
                         │ Agents + Tools      │
                         └──────────┬──────────┘
                                    │
                         ┌──────────▼──────────┐
                         │ LLM Provider(s)     │
                         │ OCR / AI APIs       │
                         └─────────────────────┘
```

------------------------------------------------------------------------

# 3. Recommended Technology

## Frontend

-   Next.js
-   React
-   TypeScript
-   Tailwind CSS
-   Component library with accessible primitives
-   React Query / TanStack Query
-   Zod
-   Charts library

## Backend

Primary:

-   Laravel
-   PHP 8.3+
-   REST API
-   Laravel queues
-   Laravel Policies/Gates
-   Laravel Events
-   Laravel Notifications

Why Laravel:

-   Fast product development
-   Strong authentication ecosystem
-   Good database tooling
-   Queues/jobs
-   Policies
-   Notifications
-   Mature SaaS ecosystem

## AI

-   Python
-   FastAPI
-   Pydantic
-   Provider-agnostic LLM adapter
-   Structured outputs
-   Tool calling
-   LangGraph or a small custom orchestration layer initially

Do not make the entire accounting system dependent on the AI service.

## Database

-   PostgreSQL

Primary financial source of truth.

## Cache / Queue

-   Redis

Use for:

-   Job queues
-   Caching
-   Rate limiting
-   Temporary state

## Files

S3-compatible object storage.

Store:

-   Invoices
-   Receipts
-   Statements
-   Contracts
-   Export files

Never store large documents directly inside PostgreSQL unless
intentionally required.

## Observability

-   Sentry
-   OpenTelemetry
-   Structured application logs
-   Error tracking
-   Audit logs

------------------------------------------------------------------------

# 4. Monorepo Structure

``` text
project/
├── apps/
│   ├── web/
│   ├── api/
│   └── ai/
│
├── packages/
│   ├── ui/
│   ├── types/
│   ├── config/
│   └── accounting-rules/
│
├── docs/
│   ├── PRD.md
│   ├── ARCHITECTURE.md
│   ├── RULES.md
│   ├── DESIGN.md
│   ├── TASKS.md
│   └── MEMORY.md
│
├── infra/
│   ├── docker/
│   ├── nginx/
│   └── deployment/
│
├── tests/
│   ├── integration/
│   ├── e2e/
│   └── ai-evals/
│
└── README.md
```

If keeping Laravel and Next.js in separate repositories, preserve the
same logical boundaries.

------------------------------------------------------------------------

# 5. Backend Domain Structure

``` text
app/
├── Domain/
│   ├── Organization/
│   ├── Identity/
│   ├── Accounting/
│   │   ├── ChartOfAccounts/
│   │   ├── Journal/
│   │   ├── Ledger/
│   │   ├── Period/
│   │   └── Posting/
│   ├── Sales/
│   ├── Purchasing/
│   ├── Banking/
│   ├── Expenses/
│   ├── Inventory/
│   ├── Tax/
│   ├── Reporting/
│   ├── Close/
│   ├── Documents/
│   ├── Workflow/
│   └── Audit/
│
├── Application/
├── Infrastructure/
└── Http/
```

The exact framework structure can differ, but domain boundaries must
remain clear.

------------------------------------------------------------------------

# 6. Core Database Model

Minimum core entities:

``` text
organizations
entities
branches
departments
users
roles
permissions

accounts
account_types
accounting_periods
fiscal_years

journal_entries
journal_lines

customers
vendors

sales_invoices
sales_invoice_lines
customer_payments

purchase_bills
purchase_bill_lines
vendor_payments

bank_accounts
bank_transactions
reconciliation_matches

expenses
expense_items

documents
document_extractions

approval_requests
approval_steps

audit_events
ai_runs
ai_tool_calls
```

Later:

``` text
purchase_orders
goods_receipts
products
warehouses
stock_movements
contracts
revenue_schedules
intercompany_transactions
budgets
forecasts
tax_returns
fbr_documents
integrations
```

------------------------------------------------------------------------

# 7. Accounting Invariants

The most important invariant:

``` text
SUM(debit) == SUM(credit)
```

for every posted journal entry.

Additional invariants:

-   Posted journal cannot be silently edited.
-   Every posted journal has a source.
-   Reversal references the original entry.
-   Period locks prevent unauthorized posting.
-   Currency conversion must be explicit.
-   Entity ownership must be enforced.
-   Tenant data must never cross boundaries.
-   Deletion of financial records must follow retention policy.
-   Every automated mutation must have an audit trail.

------------------------------------------------------------------------

# 8. Posting Architecture

``` text
Business Document
       │
       ▼
Accounting Mapping
       │
       ▼
Journal Draft
       │
       ▼
Validation
       │
       ▼
Approval
       │
       ▼
Posting Engine
       │
       ├── journal_entries
       ├── journal_lines
       └── audit_events
```

The posting engine is deterministic.

AI may create a draft but must not bypass the posting engine.

------------------------------------------------------------------------

# 9. AI Architecture

``` text
User
 │
 ▼
AI Orchestrator
 │
 ├── Intent detection
 ├── Permission check
 ├── Context retrieval
 ├── Tool selection
 ├── Tool execution
 ├── Validation
 └── Response generation
```

### Tool categories

Read:

-   `get_company`
-   `get_account`
-   `search_transactions`
-   `get_invoice`
-   `get_vendor`
-   `get_customer_balance`
-   `get_report`

Draft:

-   `draft_journal`
-   `draft_bill`
-   `draft_invoice`
-   `draft_reconciliation`

Action:

-   `submit_for_approval`
-   `post_journal`
-   `send_invoice`
-   `mark_payment`
-   `close_period`

Action tools must enforce server-side permissions.

------------------------------------------------------------------------

# 10. AI Memory

Separate:

1.  Conversation memory
2.  User preferences
3.  Organization configuration
4.  Financial facts

Financial facts must always come from the database, not model memory.

Never allow the model to "remember" a financial balance.

------------------------------------------------------------------------

# 11. Integration Architecture

Use adapters:

``` text
IntegrationInterface
       │
       ├── FBRAdapter
       ├── BankAdapter
       ├── PaymentAdapter
       ├── EmailAdapter
       ├── WhatsAppAdapter
       └── StorageAdapter
```

Each integration should have:

-   credentials
-   scopes
-   status
-   sync cursor
-   last successful sync
-   error state
-   retry policy
-   audit trail

------------------------------------------------------------------------

# 12. Security Architecture

Minimum:

-   HTTPS
-   Secure session/token handling
-   MFA
-   RBAC
-   Organization-level authorization
-   Object-level authorization
-   Rate limits
-   Encryption at rest where appropriate
-   Secret manager
-   Signed webhooks
-   Audit logs
-   IP/device monitoring where needed
-   Secure file access
-   Virus/malware scanning for uploads
-   Prompt injection defenses
-   AI tool authorization

------------------------------------------------------------------------

# 13. Multi-Tenancy

Every business record must belong to an organization/entity context.

Use:

``` text
organization_id
entity_id
branch_id
```

where appropriate.

Never rely only on frontend filtering.

Authorization must be enforced server-side.

------------------------------------------------------------------------

# 14. Event-Driven Areas

Use events for:

-   invoice.created
-   invoice.approved
-   invoice.posted
-   payment.received
-   bill.created
-   bill.approved
-   bank.transaction.imported
-   reconciliation.completed
-   journal.posted
-   period.closed
-   document.uploaded
-   ai.suggestion.created

Events can trigger background jobs.

------------------------------------------------------------------------

# 15. Deployment

Initial:

``` text
Frontend
   ↓
CDN / HTTPS
   ↓
Laravel API
   ├── PostgreSQL
   ├── Redis
   ├── Object Storage
   └── Queue Workers

AI Service
   └── LLM/OCR providers
```

Do not introduce Kubernetes until actual operational complexity
justifies it.

------------------------------------------------------------------------

# 16. Scalability Path

### Stage 1

Modular monolith.

### Stage 2

Separate AI service and workers.

### Stage 3

Separate high-load integrations/workers.

### Stage 4

Extract services only where:

-   scaling is independent
-   deployments conflict
-   failure isolation is necessary
-   team ownership is clear

------------------------------------------------------------------------

# 17. Testing Architecture

Required:

-   Unit tests
-   Feature/integration tests
-   API tests
-   Database invariant tests
-   E2E tests
-   Security tests
-   Tenant isolation tests
-   AI evaluation tests
-   Load tests
-   Accounting reconciliation tests

Critical automated test:

``` text
Subledger totals == General Ledger totals
```

------------------------------------------------------------------------

# 18. Source-of-Truth Hierarchy

``` text
Accounting database
      ↓
Business rules
      ↓
Reports
      ↓
AI analysis
```

Not:

``` text
LLM → database balance
```
