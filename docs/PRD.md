# PRD --- AI-Native Finance ERP

**Project:** AI-Native Finance ERP\
**Working name:** `PROJECT_NAME`\
**Market:** Pakistan first, with architecture capable of international
expansion\
**Product category:** AI-native accounting / ERP / finance operating
system\
**Document status:** Product foundation / v0.1\
**Last updated:** 2026-09-19

------------------------------------------------------------------------

## 1. Product Summary

Build a finance operating system for Pakistani SMEs and growing
companies that combines:

-   Double-entry accounting
-   General ledger
-   Accounts payable
-   Accounts receivable
-   Invoicing and bills
-   Bank reconciliation
-   Expenses and receipts
-   Procurement
-   Inventory
-   Tax/e-invoicing integrations
-   Financial reporting
-   Month-end close
-   Multi-entity accounting
-   AI-assisted finance operations

The product should not be positioned as a generic chatbot. AI is the
automation and interaction layer over a controlled accounting system.

The accounting engine remains the source of truth.

### Product principle

> AI can propose, explain, classify, reconcile, draft, and execute
> approved workflows --- but accounting state must always be controlled
> by deterministic business rules, permissions, audit logs, and posting
> rules.

------------------------------------------------------------------------

# 2. Problem

Many SMEs and mid-sized companies operate with fragmented systems:

-   Excel/Google Sheets
-   Manual invoices
-   WhatsApp communication
-   Separate POS systems
-   Bank statements
-   Manual expense records
-   External accountants
-   Legacy accounting software
-   Repeated CSV imports
-   Manual reconciliations
-   Manual month-end closing

This creates:

1.  Delayed financial visibility
2.  Duplicate data entry
3.  Human categorization errors
4.  Difficult bank reconciliation
5.  Weak audit trails
6.  Slow month-end close
7.  Poor cash-flow visibility
8.  Difficulty managing multiple branches/entities
9.  Repetitive accounting work
10. High dependency on individual accountants

------------------------------------------------------------------------

# 3. Product Goal

Create a system where a business can move from:

**Transaction → Classification → Approval → Posting → Reconciliation →
Reporting → Decision**

with as little manual work as possible.

The long-term goal is a continuously updated finance system rather than
a system that becomes useful only at month-end.

------------------------------------------------------------------------

# 4. Target Users

## Primary

### SME owner / founder

Needs:

-   Current cash position
-   Revenue
-   Expenses
-   Profit
-   Outstanding customer payments
-   Supplier obligations
-   Tax visibility
-   Simple business questions answered in natural language

### Accountant / finance executive

Needs:

-   GL
-   Journals
-   AP/AR
-   Reconciliation
-   Close checklist
-   Audit trail
-   Reporting
-   Bulk operations

### CFO / finance manager

Needs:

-   Multi-entity reporting
-   Consolidation
-   Cash forecasting
-   KPI dashboards
-   Variance analysis
-   Close management
-   Controls

## Secondary

-   Procurement officer
-   Sales/admin staff
-   Branch manager
-   Inventory manager
-   Auditor
-   External accountant/bookkeeper

------------------------------------------------------------------------

# 5. Pakistan-First Strategy

Pakistan should be the first localization layer, not an afterthought.

The product should support:

-   PKR
-   Multi-currency
-   Pakistan tax configuration
-   FBR digital invoicing integration architecture
-   Sales tax
-   Withholding tax
-   Provincial tax rules where applicable
-   NTN/STRN/company information
-   Branches
-   Local invoice formats
-   Bank statement imports
-   Urdu/English-ready UI
-   Local date/number formatting
-   Audit-friendly records

FBR currently provides technical documentation for digital invoicing and
states that notified ERP/invoicing systems must integrate through a
licensed integrator. The system must therefore isolate FBR-specific
integration behind a versioned compliance adapter rather than hard-code
tax logic into core accounting.

Reference: FBR Digital Invoicing technical documentation and FAQ.

------------------------------------------------------------------------

# 6. Core Product Modules

## 6.1 Organization & Workspace

-   Organizations
-   Legal entities
-   Branches
-   Departments
-   Users
-   Roles
-   Permissions
-   Fiscal years
-   Accounting periods
-   Currencies
-   Tax profiles
-   Numbering sequences

## 6.2 General Ledger

-   Chart of accounts
-   Journal entries
-   Journal lines
-   Manual journals
-   Recurring journals
-   Reversals
-   Period closing
-   Trial balance
-   Account ledger
-   Source-document traceability

## 6.3 Accounts Payable

Workflow:

`Vendor → PO → Receipt → Bill → Approval → Payment → Reconciliation → GL`

Features:

-   Vendors
-   Vendor contacts
-   Purchase orders
-   Bills
-   Credit notes
-   Attachments
-   OCR
-   Duplicate detection
-   Three-way matching
-   Approval workflows
-   Payment status
-   AP aging

## 6.4 Accounts Receivable

Workflow:

`Customer → Invoice → Delivery/Service → Payment → Matching → GL`

Features:

-   Customers
-   Estimates/quotes
-   Invoices
-   Recurring invoices
-   Credit notes
-   Receipts
-   Payment matching
-   AR aging
-   Collections workflow

## 6.5 Banking & Reconciliation

Workflow:

`Bank feed/import → Normalize → Detect duplicates → Match → Suggest category → Review → Reconcile → Post`

MVP:

-   CSV upload
-   XLSX import
-   Statement parsing
-   Transaction normalization
-   Manual matching
-   Rule-based matching
-   AI suggestions

Later:

-   Direct bank APIs
-   Automated feeds
-   Payment provider integrations

## 6.6 Expenses

-   Receipt upload
-   OCR
-   Merchant extraction
-   Amount/date/tax extraction
-   Category suggestion
-   Employee expense claims
-   Approval
-   Reimbursement
-   Policy checking

## 6.7 Procurement

-   Purchase request
-   Approval
-   Purchase order
-   Goods receipt
-   Vendor bill
-   Payment

## 6.8 Inventory

Later MVP+ module:

-   Products
-   SKUs
-   Warehouses
-   Stock movements
-   Purchase receipts
-   Sales issues
-   Adjustments
-   Transfers
-   COGS
-   Inventory valuation

## 6.9 Revenue Recognition

Post-MVP:

-   Contracts
-   Performance obligations
-   Deferred revenue
-   Recognition schedules
-   Milestone/usage-based recognition
-   Contract modifications
-   Journal generation

## 6.10 Reporting

Core:

-   Profit & Loss
-   Balance Sheet
-   Cash Flow
-   Trial Balance
-   General Ledger
-   AR Aging
-   AP Aging
-   Cash position
-   Tax reports
-   Budget vs actual

Later:

-   Department reporting
-   Entity reporting
-   KPI dashboards
-   Cash runway
-   Forecasting
-   Board reporting

## 6.11 Close Management

-   Close checklist
-   Task ownership
-   Reconciliation status
-   Missing-document detection
-   Unusual transaction detection
-   Accrual reminders
-   Prepaid schedules
-   Flux analysis
-   Period lock
-   Close dashboard

------------------------------------------------------------------------

# 7. AI Layer

The AI layer should operate through controlled tools.

### AI capabilities

-   Invoice extraction
-   Receipt extraction
-   Account categorization
-   Bank transaction matching
-   Duplicate detection
-   Anomaly detection
-   Journal draft generation
-   Explanation of transactions
-   Report analysis
-   Variance explanations
-   Collections assistance
-   Close assistance
-   Natural-language reporting
-   Workflow execution after approval

### Example

User:

> "Why did expenses increase this month?"

System:

1.  Identifies comparison period
2.  Queries structured financial data
3.  Groups expense accounts
4.  Finds material variances
5.  Links evidence to source transactions
6.  Produces explanation
7.  Never invents unsupported causes

------------------------------------------------------------------------

# 8. AI Agent Model

Potential agents:

-   Finance Orchestrator
-   Bookkeeping Agent
-   AP Agent
-   AR Agent
-   Reconciliation Agent
-   Expense Agent
-   Reporting Agent
-   Close Agent
-   Revenue Agent
-   Compliance Agent
-   Anomaly Agent

Agents do not have unrestricted database access.

They call typed tools such as:

-   `get_account`
-   `search_transactions`
-   `search_invoices`
-   `get_customer_balance`
-   `get_vendor_balance`
-   `draft_journal`
-   `create_bill_draft`
-   `suggest_reconciliation`
-   `create_close_task`
-   `generate_report`

Sensitive operations require permissions and/or approval.

------------------------------------------------------------------------

# 9. MVP Definition

The MVP should NOT attempt to reproduce every ERP module.

### MVP includes

1.  Authentication
2.  Organization/workspace
3.  User roles
4.  Entity/branch
5.  Chart of accounts
6.  Double-entry GL
7.  Customers
8.  Vendors
9.  Sales invoices
10. Purchase bills
11. Payments
12. CSV bank import
13. Basic reconciliation
14. P&L
15. Balance Sheet
16. Trial Balance
17. AI transaction categorization
18. Invoice/receipt OCR
19. AI journal drafts
20. Audit log
21. Approval workflow
22. File/document storage

### MVP success condition

A small business can:

-   Set up accounts
-   Enter/import transactions
-   Issue invoices
-   Record bills
-   Record payments
-   Reconcile bank transactions
-   See accurate financial statements
-   Ask AI questions about financial data
-   Review and approve AI-generated drafts

------------------------------------------------------------------------

# 10. Non-MVP

Do not build initially:

-   Full payroll
-   Full HR
-   Full CRM
-   Full POS
-   Complex manufacturing
-   Full international tax engine
-   Every bank integration
-   Mobile apps
-   Microservice architecture
-   Custom foundation model
-   Kubernetes
-   Advanced forecasting
-   Complex consolidation
-   Enterprise procurement suite

These can come later.

------------------------------------------------------------------------

# 11. Differentiation

The product should compete through:

1.  Pakistan-first compliance
2.  AI-native workflow
3.  Simple SME UX
4.  WhatsApp-friendly operations where useful
5.  Local accountant workflows
6.  Fast setup
7.  Real-time financial visibility
8.  Affordable deployment
9.  Strong auditability
10. Modular growth from bookkeeping to ERP

------------------------------------------------------------------------

# 12. Benchmark Against Rillet

Rillet publicly positions itself around AI-native ERP, perpetual GL,
revenue recognition, multi-entity/global accounting, integrations,
reporting, AR/AP, bank reconciliation, close management, and Aura AI.

Those capabilities are useful as category benchmarks, but this project
must implement its own architecture, UX, code, data model, and branding.

Reference: https://www.rillet.com/

------------------------------------------------------------------------

# 13. Product Metrics

Track:

-   Active companies
-   Active users
-   Transactions processed
-   AI suggestions accepted
-   AI suggestions rejected
-   Reconciliation rate
-   OCR accuracy
-   Time to first report
-   Month-end close time
-   Manual entries per month
-   Invoice processing time
-   Monthly recurring revenue
-   Customer retention
-   Cost per active company
-   AI cost per transaction

------------------------------------------------------------------------

# 14. Product Safety & Financial Integrity

Never allow an LLM to directly mutate account balances.

All accounting mutations must pass:

`Request → Permission → Validation → Business Rules → Draft → Approval if required → Posting → Audit Event`

Posted accounting entries should be immutable. Corrections should use
reversals/adjustments rather than silently rewriting history.

------------------------------------------------------------------------

# 15. Future Vision

The end state is:

> "A business can run its financial operations through one continuously
> updated system, while AI handles the repetitive work and humans retain
> control over important decisions."
