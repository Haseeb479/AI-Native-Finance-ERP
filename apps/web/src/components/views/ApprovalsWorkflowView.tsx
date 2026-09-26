"use client";

import React, { useState } from "react";
import {
  ShieldCheck,
  CheckCircle2,
  Clock,
  AlertTriangle,
  FileCheck,
  FilePenLine,
  FileText,
  BookOpen,
  Scale,
  RefreshCw,
  Plus,
  ArrowRight,
  UserCheck,
  XCircle,
  Building,
  SlidersHorizontal,
  ChevronRight,
  Hash,
  Send,
  Lock,
  Layers,
  Sparkles,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";
import { DataTable, Column } from "../ui/DataTable";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge, BadgeVariant } from "../ui/Badge";

export interface ApprovalStep {
  stepNumber: number;
  role: string;
  assignee: string;
  status: "completed" | "in_progress" | "pending" | "rejected";
  timestamp?: string;
  notes?: string;
  signature?: string;
}

export interface ApprovalRecord {
  id: string;
  referenceNumber: string;
  category: "vendor_bill" | "sales_invoice" | "gl_journal" | "match_exception" | "period_override";
  title: string;
  initiator: string;
  department: string;
  amount: number;
  currency: string;
  gateTier: "Tier 1: AP Specialist" | "Tier 2: Finance Manager" | "Tier 3: Executive CFO";
  status: "pending_approval" | "approved" | "rejected";
  submittedAt: string;
  dueDate: string;
  description: string;
  targetRecordId: string;
  steps: ApprovalStep[];
  aiRiskScore?: "Low" | "Medium" | "High";
  aiRiskRationale?: string;
  taxSafeguards?: {
    atlVerified: boolean;
    whtSection?: string;
    whtRate?: string;
    fbrPosLinked?: boolean;
  };
  glImpactAccounts?: {
    code: string;
    name: string;
    debit: number;
    credit: number;
  }[];
}

interface ApprovalsWorkflowViewProps {
  approvals?: ApprovalRecord[];
  isLoading?: boolean;
  onRefresh?: () => void;
  onApprove?: (id: string, notes?: string) => void;
  onReject?: (id: string, reason: string) => void;
  onWaiveMatch?: (matchId: string, reason: string) => void;
  className?: string;
}

export function ApprovalsWorkflowView({
  approvals: externalApprovals,
  isLoading = false,
  onRefresh,
  onApprove,
  onReject,
  onWaiveMatch,
  className,
}: ApprovalsWorkflowViewProps) {
  const [activeTab, setActiveTab] = useState<"inbox" | "rules">("inbox");
  const [searchQuery, setSearchQuery] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("all");
  const [tierFilter, setTierFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("pending_approval");
  const [selectedRecord, setSelectedRecord] = useState<ApprovalRecord | null>(null);

  // Approval action states inside Drawer
  const [approvalNotes, setApprovalNotes] = useState("");
  const [rejectionReason, setRejectionReason] = useState("");
  const [isRejecting, setIsRejecting] = useState(false);

  // Default seed dataset representing multi-tier corporate approval gates
  const defaultApprovals: ApprovalRecord[] = [
    {
      id: "REQ-2025-0101",
      referenceNumber: "BILL-2025-002",
      category: "match_exception",
      title: "3-Way Match Quantity Variance: Heavy Bearings Ltd",
      initiator: "Hamza Tariq (Procurement)",
      department: "Supply Chain",
      amount: 45000,
      currency: "PKR",
      gateTier: "Tier 2: Finance Manager",
      status: "pending_approval",
      submittedAt: "2025-08-20 09:30",
      dueDate: "2025-08-22",
      description: "GRN-2025-0002 indicates 40/100 units received against Bill for 100 units. Requires Manager quantity waiver or credit note requisition.",
      targetRecordId: "bill-2",
      aiRiskScore: "Medium",
      aiRiskRationale: "Physical delivery variance exceeds standard 2.0% tolerance. Vendor notified of partial fulfillment.",
      taxSafeguards: {
        atlVerified: true,
        whtSection: "Sec 153(1)(b) Goods",
        whtRate: "4.5%",
        fbrPosLinked: false,
      },
      steps: [
        {
          stepNumber: 1,
          role: "Initiator Submission",
          assignee: "Hamza Tariq",
          status: "completed",
          timestamp: "2025-08-20 09:30",
          notes: "Received partial shipment from vendor; remaining 60 units delayed in customs.",
          signature: "SIG-7a8f9c1e",
        },
        {
          stepNumber: 2,
          role: "Automated Invariant & 3-Way Match Check",
          assignee: "Axiom Engine",
          status: "completed",
          timestamp: "2025-08-20 09:31",
          notes: "Quantity variance flagged: Bill 100 vs GRN 40. Tolerance ±2.0% exceeded.",
        },
        {
          stepNumber: 3,
          role: "Tier 2: Finance Manager Approval",
          assignee: "Sarah Khan (Finance Manager)",
          status: "in_progress",
        },
        {
          stepNumber: 4,
          role: "GL Posting & Disbursement Gate",
          assignee: "Automated Posting Daemon",
          status: "pending",
        },
      ],
      glImpactAccounts: [
        { code: "1070", name: "Merchandise Inventory", debit: 45000, credit: 0 },
        { code: "2010", name: "Accounts Payable Control", debit: 0, credit: 45000 },
      ],
    },
    {
      id: "REQ-2025-0102",
      referenceNumber: "BILL-2025-001",
      category: "vendor_bill",
      title: "Commercial Vendor Disbursement: Steel Corp Pakistan",
      initiator: "Zaid Farooq (AP Clerk)",
      department: "Accounts Payable",
      amount: 70000,
      currency: "PKR",
      gateTier: "Tier 1: AP Specialist",
      status: "pending_approval",
      submittedAt: "2025-08-20 11:15",
      dueDate: "2025-08-25",
      description: "Standard raw material procurement against PO-2025-0001 with 100% matched GRN.",
      targetRecordId: "bill-1",
      aiRiskScore: "Low",
      aiRiskRationale: "3-Way Match is 100% verified. Unit price matches signed contract rate.",
      taxSafeguards: {
        atlVerified: true,
        whtSection: "Sec 153(1)(a) Raw Materials",
        whtRate: "4.0%",
        fbrPosLinked: true,
      },
      steps: [
        {
          stepNumber: 1,
          role: "Initiator Submission",
          assignee: "Zaid Farooq",
          status: "completed",
          timestamp: "2025-08-20 11:15",
          notes: "Original vendor tax invoice attached with FBR sales tax challan.",
          signature: "SIG-9b3c4d5e",
        },
        {
          stepNumber: 2,
          role: "Automated 3-Way Match Gate",
          assignee: "Axiom Engine",
          status: "completed",
          timestamp: "2025-08-20 11:16",
          notes: "PO, GRN, and Bill aligned with 0.0% variance.",
        },
        {
          stepNumber: 3,
          role: "Tier 1: AP Specialist Sign-Off",
          assignee: "Finance Reviewer",
          status: "in_progress",
        },
      ],
      glImpactAccounts: [
        { code: "5010", name: "Cost of Goods Sold - Purchases", debit: 70000, credit: 0 },
        { code: "2010", name: "Accounts Payable Control", debit: 0, credit: 70000 },
      ],
    },
    {
      id: "REQ-2025-0103",
      referenceNumber: "JE-2025-0004",
      category: "gl_journal",
      title: "Executive Payroll & Staff Salaries — August 2025",
      initiator: "Sarah Khan (Finance Manager)",
      department: "Human Resources / Treasury",
      amount: 850000,
      currency: "PKR",
      gateTier: "Tier 3: Executive CFO",
      status: "pending_approval",
      submittedAt: "2025-08-21 14:00",
      dueDate: "2025-08-23",
      description: "Monthly salary disbursement for 42 permanent employees. Total exceeds PKR 500,000 threshold requiring Executive CFO authorization.",
      targetRecordId: "je-4",
      aiRiskScore: "Low",
      aiRiskRationale: "Debits and Credits are perfectly balanced at PKR 850,000. Bank payroll upload verified.",
      taxSafeguards: {
        atlVerified: true,
        whtSection: "Sec 149 Salary Tax Withholding",
        whtRate: "Variable",
        fbrPosLinked: false,
      },
      steps: [
        {
          stepNumber: 1,
          role: "Initiator Submission",
          assignee: "Sarah Khan",
          status: "completed",
          timestamp: "2025-08-21 14:00",
          notes: "Approved payroll registers attached with individual banking IBAN listings.",
          signature: "SIG-1a2b3c4d",
        },
        {
          stepNumber: 2,
          role: "Double-Entry Invariant Validation",
          assignee: "Posting Engine",
          status: "completed",
          timestamp: "2025-08-21 14:01",
          notes: "Sum(Debits) == Sum(Credits). Hash: ca978112ca1bbdcafac231b39a23dc4d.",
        },
        {
          stepNumber: 3,
          role: "Tier 2: Finance Manager Verification",
          assignee: "Sarah Khan",
          status: "completed",
          timestamp: "2025-08-21 14:05",
          notes: "Tax calculations reconciled against Section 149 slabs.",
        },
        {
          stepNumber: 4,
          role: "Tier 3: Executive CFO Sign-off",
          assignee: "CFO Office (Director of Finance)",
          status: "in_progress",
        },
        {
          stepNumber: 5,
          role: "Meezan Bank Batch Wire Execution",
          assignee: "Treasury Gateway",
          status: "pending",
        },
      ],
      glImpactAccounts: [
        { code: "6010", name: "Salaries & Benefits Expense", debit: 850000, credit: 0 },
        { code: "1010", name: "Operating Cash & Bank Account", debit: 0, credit: 850000 },
      ],
    },
    {
      id: "REQ-2025-0104",
      referenceNumber: "INV-2025-0014",
      category: "sales_invoice",
      title: "Commercial Credit Invoice: Lahore Tech Hub",
      initiator: "Bilal Asif (Commercial Sales)",
      department: "Revenue Operations",
      amount: 94400,
      currency: "PKR",
      gateTier: "Tier 1: AP Specialist",
      status: "pending_approval",
      submittedAt: "2025-08-21 16:45",
      dueDate: "2025-08-24",
      description: "Credit sale terms (Net 30) for enterprise software implementation and cloud hosting services.",
      targetRecordId: "inv-3",
      aiRiskScore: "Low",
      aiRiskRationale: "Customer credit limit is PKR 300,000 with 0 delinquent overdue invoices.",
      taxSafeguards: {
        atlVerified: true,
        whtSection: "Sec 236G/H Advance Tax",
        whtRate: "0.5%",
        fbrPosLinked: true,
      },
      steps: [
        {
          stepNumber: 1,
          role: "Sales Rep Drafting",
          assignee: "Bilal Asif",
          status: "completed",
          timestamp: "2025-08-21 16:45",
          notes: "Customer signed Statement of Work #SOW-9812.",
        },
        {
          stepNumber: 2,
          role: "Customer Credit Check Gate",
          assignee: "Axiom Credit Policy",
          status: "completed",
          timestamp: "2025-08-21 16:46",
          notes: "Customer exposure 31.4% of maximum PKR 300k limit. Risk low.",
        },
        {
          stepNumber: 3,
          role: "Tier 1: Billing Supervisor Approval",
          assignee: "Billing Supervisor",
          status: "in_progress",
        },
      ],
      glImpactAccounts: [
        { code: "1030", name: "Trade Debtors (AR Control)", debit: 94400, credit: 0 },
        { code: "4010", name: "Sales Revenue - Local", debit: 0, credit: 80000 },
        { code: "2030", name: "Sales Tax Output Payable (17%)", debit: 0, credit: 14400 },
      ],
    },
  ];

  const approvals = externalApprovals && externalApprovals.length > 0 ? externalApprovals : defaultApprovals;

  // Filtered dataset
  const filteredApprovals = approvals.filter((item) => {
    const matchesSearch =
      !searchQuery ||
      item.id.toLowerCase().includes(searchQuery.toLowerCase()) ||
      item.referenceNumber.toLowerCase().includes(searchQuery.toLowerCase()) ||
      item.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
      item.initiator.toLowerCase().includes(searchQuery.toLowerCase()) ||
      item.department.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesCategory =
      categoryFilter === "all" || item.category === categoryFilter;

    const matchesTier =
      tierFilter === "all" || item.gateTier.toLowerCase().includes(tierFilter.toLowerCase());

    const matchesStatus =
      statusFilter === "all" || item.status === statusFilter;

    return matchesSearch && matchesCategory && matchesTier && matchesStatus;
  });

  // Category Icon & Badge
  const getCategoryConfig = (category: ApprovalRecord["category"]) => {
    switch (category) {
      case "vendor_bill":
        return { label: "Vendor Bill", icon: FilePenLine, color: "text-amber-700 bg-amber-50 border-amber-200" };
      case "sales_invoice":
        return { label: "Sales Invoice", icon: FileText, color: "text-blue-700 bg-blue-50 border-blue-200" };
      case "gl_journal":
        return { label: "GL Journal", icon: BookOpen, color: "text-purple-700 bg-purple-50 border-purple-200" };
      case "match_exception":
        return { label: "3-Way Match Exception", icon: Scale, color: "text-rose-700 bg-rose-50 border-rose-200" };
      case "period_override":
        return { label: "Period Lock Override", icon: Lock, color: "text-slate-700 bg-slate-100 border-slate-300" };
      default:
        return { label: "General", icon: ShieldCheck, color: "text-slate-700 bg-slate-50 border-slate-200" };
    }
  };

  // DataTable Columns definition
  const columns: Column<ApprovalRecord>[] = [
    {
      key: "id",
      header: "Request #",
      render: (item) => (
        <div>
          <span className="font-mono font-bold text-xs text-[#0F172A] hover:text-[#6366F1] transition-colors block">
            {item.id}
          </span>
          <span className="text-[10px] text-[#64748B] font-mono">{item.referenceNumber}</span>
        </div>
      ),
    },
    {
      key: "category",
      header: "Category",
      render: (item) => {
        const conf = getCategoryConfig(item.category);
        const Icon = conf.icon;
        return (
          <span className={cn("inline-flex items-center space-x-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold border", conf.color)}>
            <Icon className="w-3 h-3 shrink-0" />
            <span>{conf.label}</span>
          </span>
        );
      },
    },
    {
      key: "title",
      header: "Subject & Context",
      render: (item) => (
        <div className="max-w-[340px]">
          <span className="text-xs font-semibold text-[#0F172A] block truncate" title={item.title}>
            {item.title}
          </span>
          <span className="text-[11px] text-[#64748B] block truncate">
            {item.initiator} • {item.department}
          </span>
        </div>
      ),
    },
    {
      key: "amount",
      header: "Amount",
      align: "right",
      render: (item) => (
        <div className="text-right">
          <span className="text-xs font-bold font-mono text-[#0F172A] block">
            PKR {item.amount.toLocaleString()}
          </span>
          {item.aiRiskScore && (
            <span
              className={cn(
                "text-[9px] font-semibold uppercase px-1.5 py-0.2 rounded",
                item.aiRiskScore === "Low"
                  ? "bg-emerald-50 text-emerald-700"
                  : item.aiRiskScore === "Medium"
                  ? "bg-amber-50 text-amber-700"
                  : "bg-rose-50 text-rose-700"
              )}
            >
              Risk: {item.aiRiskScore}
            </span>
          )}
        </div>
      ),
    },
    {
      key: "gateTier",
      header: "Approval Gate",
      render: (item) => {
        const isCfo = item.gateTier.includes("CFO");
        const isManager = item.gateTier.includes("Manager");
        return (
          <span
            className={cn(
              "px-2 py-0.5 rounded text-[10px] font-semibold border inline-flex items-center space-x-1",
              isCfo
                ? "bg-purple-50 text-purple-700 border-purple-200"
                : isManager
                ? "bg-indigo-50 text-indigo-700 border-indigo-200"
                : "bg-slate-50 text-slate-700 border-slate-200"
            )}
          >
            <span>{item.gateTier}</span>
          </span>
        );
      },
    },
    {
      key: "progress",
      header: "Workflow Status",
      render: (item) => {
        const totalSteps = item.steps.length;
        const completedSteps = item.steps.filter((s) => s.status === "completed").length;
        return (
          <div>
            <div className="flex items-center space-x-1 mb-1">
              {item.steps.map((s, idx) => (
                <div
                  key={idx}
                  title={`${s.role}: ${s.status}`}
                  className={cn(
                    "w-2.5 h-1.5 rounded-full transition-all",
                    s.status === "completed"
                      ? "bg-emerald-500"
                      : s.status === "in_progress"
                      ? "bg-indigo-500 animate-pulse"
                      : s.status === "rejected"
                      ? "bg-rose-500"
                      : "bg-slate-200"
                  )}
                />
              ))}
            </div>
            <span className="text-[10px] text-[#64748B]">
              Step {completedSteps + 1} of {totalSteps}
            </span>
          </div>
        );
      },
    },
    {
      key: "status",
      header: "Status",
      align: "center",
      render: (item) => (
        <Badge
          variant={
            item.status === "approved"
              ? "approved"
              : item.status === "rejected"
              ? "rejected"
              : "pending_approval"
          }
        >
          {item.status === "pending_approval"
            ? "Pending Sign-off"
            : item.status === "approved"
            ? "Approved"
            : "Rejected"}
        </Badge>
      ),
    },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      render: (item) => (
        <div className="flex items-center justify-end space-x-2" onClick={(e) => e.stopPropagation()}>
          <button
            onClick={() => {
              setSelectedRecord(item);
              setIsRejecting(false);
              setApprovalNotes("");
              setRejectionReason("");
            }}
            className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50 transition-colors cursor-pointer"
          >
            Review & Sign
          </button>
        </div>
      ),
    },
  ];

  // Action handlers
  const handleApproveAction = () => {
    if (!selectedRecord) return;
    if (onApprove) {
      onApprove(selectedRecord.id, approvalNotes);
    } else {
      alert(`Approval granted for ${selectedRecord.id} with digital signature recorded.`);
    }
    setSelectedRecord(null);
  };

  const handleRejectAction = () => {
    if (!selectedRecord) return;
    if (!rejectionReason.trim()) {
      alert("A valid audit rejection reason is required by organizational compliance policy.");
      return;
    }
    if (onReject) {
      onReject(selectedRecord.id, rejectionReason);
    } else {
      alert(`Request ${selectedRecord.id} rejected. Reason: "${rejectionReason}"`);
    }
    setSelectedRecord(null);
  };

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-7 space-y-6 animate-in fade-in duration-200", className)}>
      {/* ─────────────────────────────────────────────────────────────
          1. HEADER & SUB-TABS (Inbox vs Policy Rules)
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
        <div>
          <div className="flex items-center space-x-3">
            <h1 className="text-2xl font-bold tracking-tight text-[#0F172A]">
              Workflow & Approval Engine
            </h1>
            <span className="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200">
              {approvals.filter((a) => a.status === "pending_approval").length} Pending My Sign-off
            </span>
          </div>
          <p className="text-xs text-[#64748B] mt-1">
            Multi-tier governance gates, out-of-tolerance variance waivers, and cryptographic audit sign-offs.
          </p>
        </div>

        {/* Action Controls & Sub-tab Switcher */}
        <div className="flex items-center space-x-3">
          <div className="inline-flex p-1 bg-slate-100 rounded-xl border border-slate-200/60">
            <button
              onClick={() => setActiveTab("inbox")}
              className={cn(
                "px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer",
                activeTab === "inbox"
                  ? "bg-white text-[#0F172A] shadow-xs"
                  : "text-[#64748B] hover:text-[#0F172A]"
              )}
            >
              Approval Inbox
            </button>
            <button
              onClick={() => setActiveTab("rules")}
              className={cn(
                "px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer",
                activeTab === "rules"
                  ? "bg-white text-[#0F172A] shadow-xs"
                  : "text-[#64748B] hover:text-[#0F172A]"
              )}
            >
              Governance Rules
            </button>
          </div>

          <button
            onClick={onRefresh}
            className="p-2 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] hover:bg-slate-50 transition-colors cursor-pointer"
            title="Refresh Approvals"
          >
            <RefreshCw className={cn("w-4 h-4", isLoading && "animate-spin")} />
          </button>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          2. SUMMARY KPI METRIC CARDS (Multi-Tier Policy Gates)
      ─────────────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Tier 1 Card */}
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-4.5 shadow-xs">
          <div className="flex items-center justify-between text-[#64748B] mb-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider">Tier 1: AP Specialist</span>
            <span className="text-[10px] bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded font-mono">&lt; 100k PKR</span>
          </div>
          <div className="flex items-baseline space-x-2">
            <span className="text-2xl font-bold tracking-tight text-[#0F172A]">
              {approvals.filter((a) => a.gateTier.includes("Specialist") && a.status === "pending_approval").length}
            </span>
            <span className="text-xs text-[#64748B]">pending sign-off</span>
          </div>
          <p className="text-[11px] text-[#94A3B8] mt-2">Single authorization required for operational billings.</p>
        </div>

        {/* Tier 2 Card */}
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-4.5 shadow-xs">
          <div className="flex items-center justify-between text-indigo-700 mb-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider">Tier 2: Finance Manager</span>
            <span className="text-[10px] bg-indigo-50 text-indigo-700 border border-indigo-200 px-1.5 py-0.5 rounded font-mono">&lt; 500k PKR</span>
          </div>
          <div className="flex items-baseline space-x-2">
            <span className="text-2xl font-bold tracking-tight text-[#0F172A]">
              {approvals.filter((a) => a.gateTier.includes("Manager") && a.status === "pending_approval").length}
            </span>
            <span className="text-xs text-[#64748B]">pending sign-off</span>
          </div>
          <p className="text-[11px] text-[#94A3B8] mt-2">Requires managerial sign-off or 3-Way match variance waiver.</p>
        </div>

        {/* Tier 3 Card */}
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-4.5 shadow-xs">
          <div className="flex items-center justify-between text-purple-700 mb-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider">Tier 3: Executive CFO</span>
            <span className="text-[10px] bg-purple-50 text-purple-700 border border-purple-200 px-1.5 py-0.5 rounded font-mono">≥ 500k PKR</span>
          </div>
          <div className="flex items-baseline space-x-2">
            <span className="text-2xl font-bold tracking-tight text-[#0F172A]">
              {approvals.filter((a) => a.gateTier.includes("CFO") && a.status === "pending_approval").length}
            </span>
            <span className="text-xs text-[#64748B]">critical gates</span>
          </div>
          <p className="text-[11px] text-[#94A3B8] mt-2">Mandatory dual executive sign-off for large disbursements.</p>
        </div>

        {/* Compliance Gate Card */}
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-4.5 shadow-xs">
          <div className="flex items-center justify-between text-emerald-700 mb-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider">FBR Tax Safeguards</span>
            <span className="text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-200 px-1.5 py-0.5 rounded font-semibold">100% ATL Active</span>
          </div>
          <div className="flex items-baseline space-x-2">
            <span className="text-2xl font-bold tracking-tight text-emerald-600">Enforced</span>
          </div>
          <p className="text-[11px] text-[#94A3B8] mt-2">Sec 153 WHT schedule checked before payment release.</p>
        </div>
      </div>

      {activeTab === "inbox" ? (
        <>
          {/* ─────────────────────────────────────────────────────────────
              3. FILTER BAR
          ─────────────────────────────────────────────────────────────── */}
          <FilterBar
            searchQuery={searchQuery}
            onSearchChange={setSearchQuery}
            searchPlaceholder="Search by Request #, Reference, Subject, Initiator..."
            statusFilter={statusFilter}
            onStatusChange={setStatusFilter}
            statusOptions={[
              { label: "Pending Sign-off", value: "pending_approval" },
              { label: "Approved & Posted", value: "approved" },
              { label: "Rejected / Returned", value: "rejected" },
              { label: "All Statuses", value: "all" },
            ]}
            count={filteredApprovals.length}
            countLabel="approval requests"
            onRefresh={onRefresh}
            isRefreshing={isLoading}
          >
            {/* Category Filter */}
            <select
              value={categoryFilter}
              onChange={(e) => setCategoryFilter(e.target.value)}
              className="text-xs font-semibold bg-white border border-[#E2E8F0] rounded-xl px-3 py-2 text-[#0F172A] outline-none cursor-pointer hover:border-indigo-300"
            >
              <option value="all">All Categories</option>
              <option value="vendor_bill">Vendor Bills (AP)</option>
              <option value="sales_invoice">Sales Invoices (AR)</option>
              <option value="gl_journal">Manual Journals (GL)</option>
              <option value="match_exception">3-Way Match Exceptions</option>
            </select>

            {/* Tier Filter */}
            <select
              value={tierFilter}
              onChange={(e) => setTierFilter(e.target.value)}
              className="text-xs font-semibold bg-white border border-[#E2E8F0] rounded-xl px-3 py-2 text-[#0F172A] outline-none cursor-pointer hover:border-indigo-300"
            >
              <option value="all">All Tiers</option>
              <option value="specialist">Tier 1 (&lt; 100k)</option>
              <option value="manager">Tier 2 (&lt; 500k)</option>
              <option value="cfo">Tier 3 (≥ 500k)</option>
            </select>
          </FilterBar>

          {/* ─────────────────────────────────────────────────────────────
              4. DATA TABLE
          ─────────────────────────────────────────────────────────────── */}
          <DataTable
            columns={columns}
            data={filteredApprovals}
            isLoading={isLoading}
            onRowClick={(item) => {
              setSelectedRecord(item);
              setIsRejecting(false);
              setApprovalNotes("");
              setRejectionReason("");
            }}
            rowKey={(item) => item.id}
            emptyMessage="No pending approvals match your criteria"
            emptySubtext="All corporate workflow gates and subledger requests are fully cleared."
          />
        </>
      ) : (
        /* ─────────────────────────────────────────────────────────────
            5. GOVERNANCE POLICY RULES SURFACE
        ─────────────────────────────────────────────────────────────── */
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs space-y-6">
          <div className="flex items-center justify-between border-b border-[#F1F5F9] pb-4">
            <div>
              <h2 className="text-base font-bold text-[#0F172A]">Organizational Approval Rules & Limits</h2>
              <p className="text-xs text-[#64748B] mt-0.5">
                Deterministic limits configured for tenant governance, segregation of duties, and statutory compliance.
              </p>
            </div>
            <span className="text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-full">
              4 Active Enforcement Policies
            </span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* Rule 1 */}
            <div className="p-4 rounded-xl border border-[#F1F5F9] bg-[#F8FAFC] space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-[#0F172A]">Tier 1: AP Specialist Delegation</span>
                <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">
                  Enforced
                </span>
              </div>
              <p className="text-xs text-[#64748B]">
                Authorizes direct posting of vendor bills under PKR 100,000 where 3-Way Match is 100% verified against PO and GRN.
              </p>
              <div className="text-[10px] text-[#94A3B8] font-mono pt-1 border-t border-[#E2E8F0]/40">
                Rule ID: POL-AP-001 • Segregation of Duties Level 1
              </div>
            </div>

            {/* Rule 2 */}
            <div className="p-4 rounded-xl border border-[#F1F5F9] bg-[#F8FAFC] space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-[#0F172A]">Tier 2: Finance Manager Authorization</span>
                <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">
                  Enforced
                </span>
              </div>
              <p className="text-xs text-[#64748B]">
                Required for disbursements between PKR 100,000 and PKR 500,000, or any 3-Way match price/quantity variance up to 5.0%.
              </p>
              <div className="text-[10px] text-[#94A3B8] font-mono pt-1 border-t border-[#E2E8F0]/40">
                Rule ID: POL-AP-002 • Segregation of Duties Level 2
              </div>
            </div>

            {/* Rule 3 */}
            <div className="p-4 rounded-xl border border-[#F1F5F9] bg-[#F8FAFC] space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-[#0F172A]">Tier 3: Executive CFO Dual Sign-Off</span>
                <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">
                  Enforced
                </span>
              </div>
              <p className="text-xs text-[#64748B]">
                Mandatory dual-authorization for all transactions ≥ PKR 500,000, monthly payroll execution, and out-of-period ledger postings.
              </p>
              <div className="text-[10px] text-[#94A3B8] font-mono pt-1 border-t border-[#E2E8F0]/40">
                Rule ID: POL-EXE-001 • Board Level Governance
              </div>
            </div>

            {/* Rule 4 */}
            <div className="p-4 rounded-xl border border-[#F1F5F9] bg-[#F8FAFC] space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-[#0F172A]">FBR Active Taxpayer (ATL) Statutory Gate</span>
                <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">
                  Enforced
                </span>
              </div>
              <p className="text-xs text-[#64748B]">
                Automatically halts disbursements if vendor NTN is missing or inactive on the Federal Board of Revenue Active Taxpayer List.
              </p>
              <div className="text-[10px] text-[#94A3B8] font-mono pt-1 border-t border-[#E2E8F0]/40">
                Rule ID: POL-TAX-153 • FBR Compliance Invariant
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          6. SLIDEOVER DRAWER (DEEP WORKFLOW INSPECTION & GATES)
      ─────────────────────────────────────────────────────────────── */}
      <SlideOverDrawer
        isOpen={Boolean(selectedRecord)}
        onClose={() => {
          setSelectedRecord(null);
          setIsRejecting(false);
        }}
        title={`Approval Request ${selectedRecord?.id || ""}`}
        subtitle={`${selectedRecord?.referenceNumber || ""} • ${selectedRecord?.title || ""}`}
        badge={
          <Badge
            variant={
              selectedRecord?.status === "approved"
                ? "approved"
                : selectedRecord?.status === "rejected"
                ? "rejected"
                : "pending_approval"
            }
          >
            {selectedRecord?.status === "pending_approval"
              ? "Pending Sign-off"
              : selectedRecord?.status === "approved"
              ? "Approved"
              : "Rejected"}
          </Badge>
        }
        footer={
          selectedRecord && (
            <div className="flex items-center justify-between w-full">
              <div className="text-xs text-[#64748B]">
                Amount: <span className="font-bold text-[#0F172A] font-mono">PKR {selectedRecord.amount.toLocaleString()}</span>
              </div>
              <div className="flex items-center space-x-2">
                {!isRejecting ? (
                  <>
                    <button
                      onClick={() => setIsRejecting(true)}
                      className="px-3.5 py-2 text-xs font-semibold text-rose-700 bg-rose-50 hover:bg-rose-100 rounded-xl transition-colors cursor-pointer"
                    >
                      Reject Request
                    </button>
                    <button
                      onClick={handleApproveAction}
                      className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 shadow-sm transition-colors cursor-pointer"
                    >
                      <UserCheck className="w-3.5 h-3.5" />
                      <span>Sign & Authorize</span>
                    </button>
                  </>
                ) : (
                  <>
                    <button
                      onClick={() => setIsRejecting(false)}
                      className="px-3 py-1.5 text-xs text-[#64748B] hover:text-[#0F172A]"
                    >
                      Cancel
                    </button>
                    <button
                      onClick={handleRejectAction}
                      className="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 shadow-sm transition-colors cursor-pointer"
                    >
                      <XCircle className="w-3.5 h-3.5" />
                      <span>Confirm Rejection</span>
                    </button>
                  </>
                )}
              </div>
            </div>
          )
        }
      >
        {selectedRecord && (
          <div className="space-y-6 text-xs text-[#334155]">
            {/* Multi-Step Approval Timeline Visualizer */}
            <div className="bg-[#F8FAFC] p-4.5 rounded-2xl border border-[#E2E8F0]">
              <div className="flex items-center justify-between mb-4">
                <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">
                  Multi-Tier Approval Timeline
                </span>
                <span className="text-[10px] font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded">
                  {selectedRecord.gateTier}
                </span>
              </div>

              <div className="space-y-4">
                {selectedRecord.steps.map((step, idx) => (
                  <div key={idx} className="flex items-start space-x-3 relative">
                    {/* Connecting line */}
                    {idx < selectedRecord.steps.length - 1 && (
                      <div
                        className={cn(
                          "absolute left-3.5 top-7 bottom-0 w-0.5 -ml-[1px]",
                          step.status === "completed" ? "bg-emerald-300" : "bg-slate-200"
                        )}
                      />
                    )}

                    {/* Step Icon */}
                    <div
                      className={cn(
                        "w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs shrink-0 z-10",
                        step.status === "completed"
                          ? "bg-emerald-500 text-white shadow-xs"
                          : step.status === "in_progress"
                          ? "bg-indigo-600 text-white ring-4 ring-indigo-100"
                          : step.status === "rejected"
                          ? "bg-rose-500 text-white"
                          : "bg-slate-200 text-slate-500"
                      )}
                    >
                      {step.status === "completed" ? (
                        <CheckCircle2 className="w-4 h-4" />
                      ) : (
                        <span>{step.stepNumber}</span>
                      )}
                    </div>

                    {/* Step Content */}
                    <div className="flex-1 pb-2">
                      <div className="flex items-center justify-between">
                        <span className="font-bold text-[#0F172A]">{step.role}</span>
                        <span className="text-[10px] text-[#94A3B8]">
                          {step.timestamp || (step.status === "in_progress" ? "Awaiting Action" : "Upcoming")}
                        </span>
                      </div>
                      <p className="text-[11px] text-[#64748B] mt-0.5">Assignee: {step.assignee}</p>
                      {step.notes && (
                        <p className="text-[11px] text-[#334155] bg-white p-2 rounded-lg border border-[#E2E8F0] mt-1.5">
                          {step.notes}
                        </p>
                      )}
                      {step.signature && (
                        <span className="text-[9px] font-mono text-[#94A3B8] block mt-1">
                          Hash: {step.signature}
                        </span>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Rejection Justification Input (conditional) */}
            {isRejecting && (
              <div className="p-4 rounded-xl border border-rose-200 bg-rose-50/50 space-y-2 animate-in fade-in">
                <div className="flex items-center space-x-1.5 text-rose-800 font-semibold text-xs">
                  <AlertTriangle className="w-4 h-4" />
                  <span>Mandatory Audit Rejection Reason</span>
                </div>
                <textarea
                  value={rejectionReason}
                  onChange={(e) => setRejectionReason(e.target.value)}
                  placeholder="Detail the compliance, pricing, or discrepancy rationale for returning this request..."
                  className="w-full h-20 p-2 text-xs bg-white border border-rose-200 rounded-lg outline-none focus:ring-1 focus:ring-rose-400"
                />
              </div>
            )}

            {/* Approval Notes Input (optional) */}
            {!isRejecting && (
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-[#0F172A]">Authorization Notes & Memo (Optional)</label>
                <input
                  type="text"
                  value={approvalNotes}
                  onChange={(e) => setApprovalNotes(e.target.value)}
                  placeholder="e.g. Verified against physical warehouse delivery note #4091"
                  className="w-full px-3 py-2 text-xs bg-white border border-[#E2E8F0] rounded-xl outline-none focus:border-indigo-400"
                />
              </div>
            )}

            {/* Context & Description */}
            <div className="space-y-1.5">
              <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">Request Description</span>
              <p className="text-xs text-[#334155] leading-relaxed bg-[#F8FAFC] p-3 rounded-xl border border-[#E2E8F0]">
                {selectedRecord.description}
              </p>
            </div>

            {/* Statutory Tax & FBR Safeguards */}
            {selectedRecord.taxSafeguards && (
              <div className="bg-white p-4 rounded-xl border border-[#E2E8F0] space-y-2">
                <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">
                  Statutory Tax & ATL Invariants
                </span>
                <div className="grid grid-cols-2 gap-3 text-xs pt-1">
                  <div>
                    <span className="text-[#64748B] block text-[10px]">FBR Active Taxpayer (ATL)</span>
                    <span className="font-semibold text-emerald-700 flex items-center space-x-1">
                      <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                      <span>Verified Active</span>
                    </span>
                  </div>
                  <div>
                    <span className="text-[#64748B] block text-[10px]">Withholding Tax Section</span>
                    <span className="font-semibold text-[#0F172A]">
                      {selectedRecord.taxSafeguards.whtSection || "N/A"} ({selectedRecord.taxSafeguards.whtRate})
                    </span>
                  </div>
                </div>
              </div>
            )}

            {/* Projected GL Impact */}
            {selectedRecord.glImpactAccounts && (
              <div className="space-y-2">
                <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">
                  Projected General Ledger Impact
                </span>
                <div className="bg-white rounded-xl border border-[#E2E8F0] overflow-hidden">
                  <table className="w-full text-left text-xs">
                    <thead>
                      <tr className="bg-[#F8FAFC] text-[#64748B] border-b border-[#E2E8F0] text-[10px] uppercase font-semibold">
                        <th className="py-2 px-3">Account</th>
                        <th className="py-2 px-3 text-right">Debit</th>
                        <th className="py-2 px-3 text-right">Credit</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[#F1F5F9]">
                      {selectedRecord.glImpactAccounts.map((acc, i) => (
                        <tr key={i}>
                          <td className="py-2 px-3">
                            <span className="font-mono font-bold text-[#0F172A] mr-1.5">{acc.code}</span>
                            <span className="text-[#475569]">{acc.name}</span>
                          </td>
                          <td className="py-2 px-3 text-right font-mono font-semibold text-[#0F172A]">
                            {acc.debit > 0 ? `PKR ${acc.debit.toLocaleString()}` : "—"}
                          </td>
                          <td className="py-2 px-3 text-right font-mono font-semibold text-[#0F172A]">
                            {acc.credit > 0 ? `PKR ${acc.credit.toLocaleString()}` : "—"}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
