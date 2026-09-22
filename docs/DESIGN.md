# DESIGN --- UI/UX System

**Product:** AI-Native Finance ERP\
**Status:** Design foundation / v0.1\
**Last updated:** 2026-09-19

------------------------------------------------------------------------

# 1. Design Direction

The product should feel:

-   Professional
-   Financial
-   Modern
-   Calm
-   Trustworthy
-   Data-dense but readable
-   AI-native without looking like a chatbot
-   Premium without excessive decoration

Avoid:

-   Overly dark interfaces everywhere
-   Neon AI aesthetics
-   Excessive gradients
-   Huge cards with little information
-   Dashboard clutter
-   Fake "AI magic"
-   Excessive animations

------------------------------------------------------------------------

# 2. Visual Concept

### Primary concept

**Modern finance command center**

Think:

``` text
Accounting software
+
Modern SaaS
+
AI copilot
+
Operational dashboard
```

------------------------------------------------------------------------

# 3. Color System

Use semantic tokens rather than hard-coding colors.

Suggested palette:

``` text
Background:
--bg-primary:      #F8FAFC
--bg-secondary:    #FFFFFF
--bg-muted:        #F1F5F9

Text:
--text-primary:    #0F172A
--text-secondary:  #475569
--text-muted:      #64748B

Brand:
--brand-primary:   #14532D
--brand-hover:     #166534

Accent:
--accent-gold:     #C9A227

Status:
--success:         #15803D
--warning:         #B45309
--danger:          #B91C1C
--info:             #2563EB

Borders:
--border:          #E2E8F0
```

These are starting tokens, not final branding.

------------------------------------------------------------------------

# 4. Typography

Recommended:

### Primary UI

Inter or Geist.

### Financial numbers

Use a highly legible tabular-number font feature.

### Headings

Use a clean modern sans-serif.

Typography hierarchy:

``` text
Page title:       28–36px
Section title:    20–24px
Card title:       15–18px
Body:             14–15px
Secondary:        12–13px
Table numbers:    13–14px
```

Avoid using multiple font families unnecessarily.

------------------------------------------------------------------------

# 5. Layout

Desktop:

``` text
┌─────────────────────────────────────────────┐
│ Top Bar                                     │
├───────────┬─────────────────────────────────┤
│ Sidebar   │ Main Content                    │
│           │                                 │
│ Dashboard │ Header                          │
│ Sales     │ KPI / controls                  │
│ Purchases│                                 │
│ Banking   │ Main workspace                  │
│ Reports   │                                 │
│ AI        │                                 │
└───────────┴─────────────────────────────────┘
```

Sidebar:

-   Dashboard
-   Sales
-   Purchases
-   Banking
-   Expenses
-   Accounting
-   Inventory
-   Reports
-   Close
-   AI
-   Settings

------------------------------------------------------------------------

# 6. Dashboard

Dashboard should answer immediately:

1.  How much cash do we have?
2.  How much did we earn?
3.  What do customers owe?
4.  What do we owe suppliers?
5.  What needs attention?
6.  What changed?

Suggested sections:

``` text
Cash
Revenue
Expenses
Net Profit
AR
AP

Cash movement
Revenue trend
Expense trend

Tasks requiring attention
AI recommendations
Recent activity
```

------------------------------------------------------------------------

# 7. AI UX

AI should be embedded into workflows.

### Global AI command bar

Example:

``` text
Ask Finance AI...
"Show unpaid invoices over 30 days"
```

### Contextual AI

Inside bank reconciliation:

``` text
AI suggestion:
"This PKR 125,000 transaction likely matches
ABC Traders invoice #INV-204."

[Review] [Accept]
```

Inside reports:

``` text
AI Insight
Marketing expenses increased 18% compared
with the previous month.

[View transactions]
```

AI should always provide evidence for financial claims.

------------------------------------------------------------------------

# 8. Tables

Financial applications need strong tables.

Required:

-   Sorting
-   Filtering
-   Search
-   Pagination
-   Column selection
-   Export
-   Sticky headers
-   Status indicators
-   Row actions
-   Bulk actions
-   Keyboard-friendly navigation

Amounts should align consistently.

Negative values should be visually distinct but not rely only on color.

------------------------------------------------------------------------

# 9. Forms

Forms should:

-   Group related information
-   Use clear labels
-   Show validation immediately where useful
-   Preserve entered data after errors
-   Explain accounting consequences where necessary
-   Show totals before submission

Example invoice form:

``` text
Customer
Invoice date
Due date

Line items
Qty
Rate
Tax
Discount

Subtotal
Tax
Total

Accounting preview
Attachments

[Save Draft] [Send] [Post]
```

------------------------------------------------------------------------

# 10. Approval UI

Approval should be explicit.

``` text
Bill #B-1024

Amount: PKR 240,000
Vendor: ABC Supplies
Due: 30 days

AI checks:
✓ Duplicate check
✓ PO match
✓ Amount validated
⚠ Tax classification requires review

[Approve] [Reject] [Request changes]
```

------------------------------------------------------------------------

# 11. Status System

Use consistent states.

Example invoice:

``` text
Draft
Pending Approval
Approved
Posted
Partially Paid
Paid
Overdue
Void
```

Do not use arbitrary statuses per page.

------------------------------------------------------------------------

# 12. Empty States

Every empty state should tell the user:

-   What is missing
-   Why it matters
-   What to do next

Bad:

> No data.

Good:

> No bank accounts connected yet. Import a statement or connect a
> supported bank feed to start reconciliation.

------------------------------------------------------------------------

# 13. Notifications

Use:

-   Toasts for immediate actions
-   Inbox/activity for persistent events
-   Email for important workflow events
-   Optional WhatsApp/SMS for customer-facing events

Do not overload users with notifications.

------------------------------------------------------------------------

# 14. Responsive Design

Desktop is primary for accounting teams.

Mobile must support:

-   Dashboard
-   Approvals
-   Notifications
-   Invoice review
-   Expense upload
-   AI questions
-   Quick actions

Do not attempt to put every complex accounting table into mobile.

------------------------------------------------------------------------

# 15. Accessibility

Target WCAG 2.2 AA where practical.

Minimum:

-   Keyboard navigation
-   Visible focus states
-   Semantic labels
-   Contrast
-   Screen-reader friendly forms
-   Non-color status indicators
-   Error messages associated with fields

------------------------------------------------------------------------

# 16. Motion

Use subtle motion only:

-   Drawer transitions
-   Modal transitions
-   Loading states
-   Success feedback

Avoid animated dashboards that distract from financial information.

------------------------------------------------------------------------

# 17. Design Components

Build reusable components:

``` text
Button
Input
Select
Combobox
DatePicker
MoneyInput
CurrencyInput
Table
DataTable
StatusBadge
KPI
Card
Drawer
Modal
Tabs
CommandBar
AIInsight
ApprovalPanel
Timeline
ActivityFeed
Chart
EmptyState
ErrorState
FileUploader
DocumentPreview
```

------------------------------------------------------------------------

# 18. Design Tokens

Keep all visual decisions in one place.

Example:

``` text
tokens/
├── colors
├── typography
├── spacing
├── radius
├── shadows
├── breakpoints
└── motion
```

Never create random page-specific colors.

------------------------------------------------------------------------

# 19. Brand Principle

The UI should communicate:

> "These are your real financial numbers, and the system knows where
> they came from."

Not:

> "This is an AI toy."
