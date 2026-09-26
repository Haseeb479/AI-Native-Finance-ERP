# Architecture & Product Decisions (ADR) — AI-Native Finance ERP
**Version:** v0.3  
**Status:** PROVISIONAL / PENDING STAKEHOLDER CONFIRMATION  
**Last Updated:** 2026-09-24  
**Author:** Lead Software Architect  

This document formalizes critical architectural, accounting, compliance, and scope decisions for the AI-Native Finance ERP project. All items marked `PENDING_CONFIRMATION` represent provisional engineering choices that require explicit validation by the business owner, qualified accounting advisor, or pilot customer before hard architectural commitment.

---

## 1. Initial Target Customer Segment
* **Status:** `PENDING_CONFIRMATION`
* **Context:** A finance system cannot optimize for inventory warehousing, complex manufacturing bills of materials, and service recurring billing simultaneously in an MVP.
* **Provisional Decision:** Focus the initial MVP on **Pakistani B2B Service Firms & Software/Tech Agencies** (5–50 employees) with a secondary path for **Single-Warehouse Wholesale Distributors**.
* **Rationale:**
  * B2B service firms deal primarily with standard invoicing, recurring retainer contracts, bank reconciliation, and provincial sales tax withholding, avoiding the heavy physical inventory tracking, multi-warehouse FIFO batching, and freight charges required by complex distributors.
* **Consequences:**
  * Complex multi-warehouse inventory valuation, batch tracking, and BOM (Bill of Materials) are deferred to Post-MVP.
* **Open Questions for Confirmation:**
  * Is the first pilot customer a software agency, consulting firm, or physical goods distributor?

---

## 2. MVP Scope and Explicit Exclusions
* **Status:** `PROVISIONAL`
* **Context:** The implementation roadmap accumulated features from Phase 0 to Phase 15. The core financial MVP must be tight, stable, and mathematically airtight.
* **Confirmed MVP Scope:**
  1. Multi-tenant Organization context with strict server-side boundary enforcement.
  2. Role-Based Access Control (Owner, Admin, Accountant, Finance Manager, Staff, Auditor).
  3. Pakistan SME Chart of Accounts and Fiscal Year / Period management.
  4. Double-Entry General Ledger engine with immutable posted journals and reversals.
  5. Accounts Receivable (Customers, Sales Invoices with Sales Tax).
  6. Accounts Payable (Vendors, Bills, Payment Disbursements).
  7. Banking import (CSV/XLSX) and rule-assisted transaction reconciliation.
  8. Core Financial Statements: Trial Balance, General Ledger, Profit & Loss (P&L), Balance Sheet.
  9. Controlled AI assistance: Transaction classification, draft journal proposals, and evidence-grounded financial Q&A (read-only execution).
  10. Full audit logging for every financial mutation.
* **Explicit Exclusions (Deferred to Post-MVP):**
  1. *Multi-Entity Consolidation & Intercompany Elimination:* Single organization per tenant is enforced for MVP.
  2. *Perpetual Multi-Warehouse FIFO Layer Depletion & Automated COGS Engine:* Simplified inventory or periodic accounting used initially.
  3. *Variable Consideration & Complex Multi-Element ASC 606 RevRec:* Straight-line monthly amortization retained; usage/milestone schedules deferred.
  4. *Outbound Webhook Fan-Out & Third-Party Integration Marketplace:* Standard CSV/Excel export covers early needs.
  5. *Direct External Code Execution by AI:* Prohibited by system design.

---

## 3. FBR Digital Invoicing Assumptions
* **Status:** `PENDING_CONFIRMATION`
* **Context:** The Federal Board of Revenue (FBR) in Pakistan has issued digital invoicing mandates (e.g., SRO 28(I)/2024, Sales Tax Rules 2006 Chapter VIIA) for Tier-1 retailers and notified sectors.
* **Provisional Decision:**
  * The MVP will implement an **Adapter Pattern** with simulated digital invoice formatting, QR code payload generation, and an offline queue.
  * Real-time production PRAL (Pakistan Revenue Automation Limited) API credentials and transmission will **not** block invoice posting in the initial MVP.
* **Rationale:** Direct integration with PRAL requires licensed integrator status, corporate digital certificates, and continuous uptime of government endpoints. Failing network calls must not prevent business invoicing.
* **Open Questions for Confirmation:**
  * Does the pilot customer fall under mandatory Tier-1 POS integration rules, or standard registered sales tax invoicing?
  * Will the pilot entity utilize a licensed integrator intermediary or direct FBR software registration?

---

## 4. Initial Bank Statement Ingestion Strategy
* **Status:** `PENDING_CONFIRMATION`
* **Context:** Pakistani banks lack unified Open Banking APIs. Corporate clients export statement statements via online portals in varied CSV/XLSX formats.
* **Provisional Decision:**
  * Prioritize standardized CSV/Excel statement parsers for **Habib Bank Limited (HBL)** and **Meezan Bank Limited**, followed by a generic configurable CSV column-mapping tool.
  * Ingestion uses SHA-256 transaction fingerprinting (`date + amount + description + reference`) to achieve strict import idempotency and eliminate duplicate line items.
* **Open Questions for Confirmation:**
  * Which specific banks does the pilot enterprise operate accounts with?
  * What exact statement CSV/Excel export formats can the pilot accountant provide for test fixtures?

---

## 5. Accounting Framework
* **Status:** `PENDING_CONFIRMATION`
* **Context:** Financial statement presentation differs between global IFRS, IFRS for SMEs, and local Pakistani standards.
* **Provisional Decision:**
  * Adopt **IFRS for SMEs** as the primary accounting framework, harmonized with the **Fifth Schedule of the Companies Act 2017** and **AFRS for Non-SMEs (ICAP)**.
* **Consequences:**
  * Requires explicit classification of Current vs. Non-Current Assets/Liabilities on the Balance Sheet.
  * Operating Expenses separated by Nature or Function.
* **Open Questions for Confirmation:**
  * Has the pilot entity's external auditor approved this Chart of Accounts structure?

---

## 6. Pakistan Tax-Engine Boundaries
* **Status:** `PENDING_CONFIRMATION`
* **Context:** Pakistani tax laws separate federal indirect taxes on goods from provincial indirect taxes on services, alongside statutory withholding obligations.
* **Provisional Decision:**
  * The tax engine will separate:
    1. **Federal GST on Goods (FBR):** Standard 18% rate (Sales Tax Act 1990).
    2. **Provincial Sales Tax on Services:** Sindh (SRB, 13%/15%), Punjab (PRA, 16%), Islamabad (ICT, 15%), KP (KPRA, 15%), Balochistan (BRA, 15%).
    3. **Income Tax Withholding at Source (Section 153):** Configurable withholding deduction on vendor disbursements (e.g. 4.5%–9% on goods, 10%–15% on services) mapped to account `2040 (Withholding Tax Payable)`.
  * Tax tables must be date-versioned; no tax calculation rules hardcoded without an effective date range.
* **Open Questions for Confirmation:**
  * Will the tax engine configuration be reviewed and approved by a qualified Pakistani Chartered Accountant (FCA/ACA)?

---

## 7. Authentication & MFA Strategy
* **Status:** `PROVISIONAL`
* **Context:** Financial applications handling enterprise general ledgers require enterprise authentication safeguards.
* **Provisional Decision:**
  * **Tokens:** Short-lived API access tokens (15 to 60 minutes lifetime) issued via Laravel Sanctum / OAuth, paired with secure HTTP-only refresh tokens.
  * **MFA (Multi-Factor Authentication):** Time-based One-Time Password (TOTP / RFC 6238) mandatory for Owner and Finance Manager roles, and required for sensitive high-impact operations.
  * **Step-Up Authentication Triggers:**
    1. Reopening a closed accounting period.
    2. Modifying corporate bank account details.
    3. Manual journal reversals exceeding defined organizational materiality thresholds.
    4. Generating API tokens or changing webhook secret keys.

---

## 8. Soft-Close and Hard-Close Period Behavior
* **Status:** `PROVISIONAL`
* **Context:** Single-state period locking causes workflow bottlenecks during month-end reconciliations.
* **Provisional Decision:**
  * Implement a **Two-Stage Period Closing Lifecycle**:
    1. `Open`: Standard operational state; all authorized users can post transactions.
    2. `Soft-Closed` (Operational Lock):
       * Operational posting blocked: No new sales invoices, vendor bills, or customer payments allowed in this period.
       * Adjustment posting permitted: Accountants and Finance Managers can post adjusting journals, accruals, prepayments, and depreciation.
    3. `Hard-Closed` (Audit Lock):
       * Complete freeze: No journal entries, adjustments, or mutations permitted by any user.
       * Reopening requires Owner/CFO credentials with step-up MFA and mandatory audit justification logging.
