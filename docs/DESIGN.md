# DESIGN — UI/UX System
**Version:** v0.2 | **Last updated:** 2026-09-24

## Direction
Professional, calm, financial, modern, trustworthy, data-dense but readable. AI-native without looking like a chatbot. Avoid neon AI styling, excessive gradients, clutter and unsupported AI claims.

## Tokens
```text
--bg-primary: #F8FAFC
--bg-secondary: #FFFFFF
--bg-muted: #F1F5F9
--text-primary: #0F172A
--text-secondary: #475569
--text-muted: #64748B
--brand-primary: #14532D
--brand-hover: #166534
--accent-gold: #C9A227
--success: #15803D
--warning: #B45309
--danger: #B91C1C
--info: #2563EB
--border: #E2E8F0
```

## Typography
Use Inter or Geist. Use tabular numbers for money. Page title 28–36px; section title 20–24px; body 14–15px; secondary 12–13px.

## Navigation
Dashboard, Sales, Purchases, Banking, Expenses, Accounting, Reports, Close, AI and Settings. Show organization/entity context clearly.

## Dashboard
Answer cash position, revenue, expenses, profit, AR, AP, changes and tasks requiring attention. Include reconciliation exceptions, close readiness, recent activity and evidence-linked AI insights.

## Continuous-close workspace
Show period, unposted items, unreconciled transactions, missing documents, accrual/prepaid tasks, owners, exceptions, evidence and period lock status. Never show an unexplained close score.

## AI UX
Embed AI in workflows. Every suggestion shows proposal, reason, confidence/uncertainty, evidence, permission and required approval. Distinguish suggestions from posted facts.

## Tables
Search, filters, sorting, pagination, column selection, export, sticky headers, row/bulk actions, keyboard support and aligned monetary values. Do not rely on color alone.

## Approval
Show document identity, amount/currency, mapping, duplicate check, match result, tax warnings, attachments, audit history and Approve/Reject/Request changes.

## Statuses
Draft, Pending Approval, Approved, Posted, Partially Paid, Paid, Overdue and Void. Use shared enums.

## Traceability
Report → ledger line → journal → source transaction → source document → approval/audit history.

## Components
Button, Input, Select, DatePicker, MoneyInput, DataTable, StatusBadge, KPI, Drawer, Modal, Tabs, CommandBar, AIInsight, EvidencePanel, ApprovalPanel, Timeline, ActivityFeed, Chart, EmptyState, ErrorState, FileUploader, DocumentPreview, CloseChecklist and ReconciliationWorkspace.

## Accessibility
Target WCAG 2.2 AA where practical. Keyboard support, visible focus, semantic labels, contrast, screen-reader support, non-color statuses, field errors and reduced motion.

