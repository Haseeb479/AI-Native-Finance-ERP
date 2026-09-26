"use client";

import React, { useState } from "react";
import {
  TrendingUp,
  AlertTriangle,
  Clock,
  Sparkles,
  Calendar,
  CheckCircle2,
  RefreshCw,
  Plus,
  ArrowRight,
  ShieldCheck,
  Building,
  FilePenLine,
  SlidersHorizontal,
  ChevronRight,
  Zap,
  Layers,
  ArrowUpRight,
  ArrowDownRight,
  Scale,
  Receipt,
  FileSpreadsheet,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";
import { DataTable, Column } from "../ui/DataTable";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge } from "../ui/Badge";

export interface AccrualProposal {
  id: string;
  vendorName: string;
  vendorNtn?: string;
  category: "Utilities" | "Cloud Hosting" | "Rent & Facility" | "Professional Services" | "Software Subscriptions";
  historicalAverage: number;
  expectedDayOfMonth: number;
  status: "missing_bill" | "bill_received" | "accrued" | "waived";
  confidenceScore: number;
  lastBillDate: string;
  suggestedDebitAccount: string;
  suggestedDebitAccountName: string;
  suggestedCreditAccount: string;
  suggestedCreditAccountName: string;
  estimatedAmount: number;
  trailingTrend: { month: string; amount: number }[];
  rationale: string;
}

export interface FluxItem {
  accountCode: string;
  accountName: string;
  classification: "Asset" | "Liability" | "Equity" | "Revenue" | "Expense";
  priorBalance: number;
  currentBalance: number;
  dollarChange: number;
  percentChange: number;
  isSignificant: boolean;
  aiExplanation?: string;
  reviewerSignOff?: boolean;
}

export interface PrepaymentSchedule {
  id: string;
  assetName: string;
  accountCode: string;
  contractTotal: number;
  monthlyAmortization: number;
  startDate: string;
  endDate: string;
  periodsRemaining: number;
  status: "active" | "completed";
}

interface ContinuousAccrualsFluxViewProps {
  currentPeriodName?: string;
  priorPeriodName?: string;
  onRefresh?: () => void;
  onCreateAccrualDraft?: (proposal: AccrualProposal) => void;
  className?: string;
}

export function ContinuousAccrualsFluxView({
  currentPeriodName = "August 2025",
  priorPeriodName = "July 2025",
  onRefresh,
  onCreateAccrualDraft,
  className,
}: ContinuousAccrualsFluxViewProps) {
  const [activeTab, setActiveTab] = useState<"accruals" | "flux" | "prepayments">("accruals");

  // Accruals state
  const [accrualSearch, setAccrualSearch] = useState("");
  const [accrualCategoryFilter, setAccrualCategoryFilter] = useState("all");
  const [accrualStatusFilter, setAccrualStatusFilter] = useState("missing_bill");
  const [selectedAccrual, setSelectedAccrual] = useState<AccrualProposal | null>(null);

  // Flux state
  const [fluxSearch, setFluxSearch] = useState("");
  const [fluxFilter, setFluxFilter] = useState("significant");
  const [selectedFluxItem, setSelectedFluxItem] = useState<FluxItem | null>(null);

  // 1. Continuous Accruals Dataset (Missing recurring vendor bills)
  const [accrualsList, setAccrualsList] = useState<AccrualProposal[]>([
    {
      id: "ACC-001",
      vendorName: "Amazon Web Services (AWS)",
      vendorNtn: "9021481-2",
      category: "Cloud Hosting",
      historicalAverage: 350000,
      expectedDayOfMonth: 18,
      status: "missing_bill",
      confidenceScore: 98.6,
      lastBillDate: "2025-07-18",
      suggestedDebitAccount: "6030",
      suggestedDebitAccountName: "Software & Cloud Hosting",
      suggestedCreditAccount: "2050",
      suggestedCreditAccountName: "Accrued Expenses Control",
      estimatedAmount: 362000,
      trailingTrend: [
        { month: "May", amount: 340000 },
        { month: "Jun", amount: 348000 },
        { month: "Jul", amount: 350000 },
      ],
      rationale: "Vendor bills regularly on the 18th of each calendar month. Day 24 reached with 0 bills received. Recommend booking an estimated accrual to prevent August expense understatement.",
    },
    {
      id: "ACC-002",
      vendorName: "Lahore Electric Supply Company (LESCO)",
      vendorNtn: "0710892-7",
      category: "Utilities",
      historicalAverage: 185000,
      expectedDayOfMonth: 20,
      status: "missing_bill",
      confidenceScore: 99.2,
      lastBillDate: "2025-07-20",
      suggestedDebitAccount: "6040",
      suggestedDebitAccountName: "Utilities Expense - Electricity",
      suggestedCreditAccount: "2050",
      suggestedCreditAccountName: "Accrued Expenses Control",
      estimatedAmount: 215000,
      trailingTrend: [
        { month: "May", amount: 165000 },
        { month: "Jun", amount: 180000 },
        { month: "Jul", amount: 185000 },
      ],
      rationale: "Utility tariff surge observed in summer season. AI estimates PKR 215,000 accrual based on regional peak unit consumption.",
    },
    {
      id: "ACC-003",
      vendorName: "Habib Bank Plaza Office Lease (HBL Real Estate)",
      vendorNtn: "1092837-1",
      category: "Rent & Facility",
      historicalAverage: 450000,
      expectedDayOfMonth: 5,
      status: "accrued",
      confidenceScore: 100.0,
      lastBillDate: "2025-07-05",
      suggestedDebitAccount: "6010",
      suggestedDebitAccountName: "Rent & Facilities Expense",
      suggestedCreditAccount: "2050",
      suggestedCreditAccountName: "Accrued Expenses Control",
      estimatedAmount: 450000,
      trailingTrend: [
        { month: "May", amount: 450000 },
        { month: "Jun", amount: 450000 },
        { month: "Jul", amount: 450000 },
      ],
      rationale: "Fixed contractual lease payment. Accrual draft journal posted and confirmed for August period.",
    },
    {
      id: "ACC-004",
      vendorName: "PTCL Corporate Fiber Internet",
      vendorNtn: "0801234-9",
      category: "Utilities",
      historicalAverage: 42000,
      expectedDayOfMonth: 15,
      status: "bill_received",
      confidenceScore: 97.4,
      lastBillDate: "2025-08-16",
      suggestedDebitAccount: "6050",
      suggestedDebitAccountName: "Internet & Telecommunications",
      suggestedCreditAccount: "2010",
      suggestedCreditAccountName: "Trade Creditors (AP)",
      estimatedAmount: 42000,
      trailingTrend: [
        { month: "May", amount: 42000 },
        { month: "Jun", amount: 42000 },
        { month: "Jul", amount: 42000 },
      ],
      rationale: "Actual vendor bill received on Aug 16 for PKR 42,000. 100% matched against historical baseline.",
    },
  ]);

  // 2. Flux Analysis Dataset (MoM Period Comparison)
  const [fluxItems, setFluxItems] = useState<FluxItem[]>([
    {
      accountCode: "4010",
      accountName: "Sales Revenue - Local Commercial",
      classification: "Revenue",
      priorBalance: 3100000,
      currentBalance: 3750000,
      dollarChange: 650000,
      percentChange: 20.97,
      isSignificant: true,
      aiExplanation: "Revenue increased by 21.0% primarily driven by 2 new enterprise software onboarding contracts with Textile Mills Ltd.",
      reviewerSignOff: true,
    },
    {
      accountCode: "5010",
      accountName: "Cost of Goods Sold - Purchases",
      classification: "Expense",
      priorBalance: 1250000,
      currentBalance: 1490000,
      dollarChange: 240000,
      percentChange: 19.2,
      isSignificant: true,
      aiExplanation: "Direct raw materials procurement scaled proportionally with August commercial delivery volume.",
      reviewerSignOff: true,
    },
    {
      accountCode: "6040",
      accountName: "Utilities Expense - Electricity",
      classification: "Expense",
      priorBalance: 185000,
      currentBalance: 215000,
      dollarChange: 30000,
      percentChange: 16.22,
      isSignificant: true,
      aiExplanation: "LESCO peak summer tariff rate increase (+16.2%) applied to cooling and datacenter machinery.",
      reviewerSignOff: false,
    },
    {
      accountCode: "6020",
      accountName: "Legal & Regulatory Compliance Fees",
      classification: "Expense",
      priorBalance: 250000,
      currentBalance: 35000,
      dollarChange: -215000,
      percentChange: -86.0,
      isSignificant: true,
      aiExplanation: "Material drop of 86.0% following conclusion of corporate restructuring legal retainer in July.",
      reviewerSignOff: true,
    },
    {
      accountCode: "6010",
      accountName: "Rent & Facilities Expense",
      classification: "Expense",
      priorBalance: 450000,
      currentBalance: 450000,
      dollarChange: 0,
      percentChange: 0.0,
      isSignificant: false,
      aiExplanation: "Fixed office premises lease with 0% variance.",
      reviewerSignOff: true,
    },
    {
      accountCode: "1010",
      accountName: "Operating Cash & Bank Account (Meezan)",
      classification: "Asset",
      priorBalance: 4200000,
      currentBalance: 5120000,
      dollarChange: 920000,
      percentChange: 21.9,
      isSignificant: true,
      aiExplanation: "Operating cash improved by PKR 920,000 following accelerated collections on Q2 aged receivables.",
      reviewerSignOff: true,
    },
  ]);

  // 3. Prepayments & Amortization Dataset
  const [prepaymentsList] = useState<PrepaymentSchedule[]>([
    {
      id: "PRE-001",
      assetName: "Annual Commercial Property Insurance (EFU General)",
      accountCode: "1150",
      contractTotal: 240000,
      monthlyAmortization: 20000,
      startDate: "2025-01-01",
      endDate: "2025-12-31",
      periodsRemaining: 4,
      status: "active",
    },
    {
      id: "PRE-002",
      assetName: "Enterprise Cloud ERP License (Annual Advance)",
      accountCode: "1150",
      contractTotal: 540000,
      monthlyAmortization: 45000,
      startDate: "2025-03-01",
      endDate: "2026-02-28",
      periodsRemaining: 6,
      status: "active",
    },
    {
      id: "PRE-003",
      assetName: "Annual Statutory Audit Retainer Retained Advance",
      accountCode: "1150",
      contractTotal: 180000,
      monthlyAmortization: 15000,
      startDate: "2025-01-01",
      endDate: "2025-12-31",
      periodsRemaining: 4,
      status: "active",
    },
  ]);

  // Filtered accruals
  const filteredAccruals = accrualsList.filter((item) => {
    const matchesSearch =
      !accrualSearch ||
      item.vendorName.toLowerCase().includes(accrualSearch.toLowerCase()) ||
      item.category.toLowerCase().includes(accrualSearch.toLowerCase());
    const matchesCategory =
      accrualCategoryFilter === "all" || item.category === accrualCategoryFilter;
    const matchesStatus =
      accrualStatusFilter === "all" || item.status === accrualStatusFilter;
    return matchesSearch && matchesCategory && matchesStatus;
  });

  // Filtered flux items
  const filteredFluxItems = fluxItems.filter((item) => {
    const matchesSearch =
      !fluxSearch ||
      item.accountCode.toLowerCase().includes(fluxSearch.toLowerCase()) ||
      item.accountName.toLowerCase().includes(fluxSearch.toLowerCase());
    const matchesSignificance =
      fluxFilter === "all" || (fluxFilter === "significant" ? item.isSignificant : !item.isSignificant);
    return matchesSearch && matchesSignificance;
  });

  // Columns for Accruals
  const accrualColumns: Column<AccrualProposal>[] = [
    {
      key: "vendor",
      header: "Recurring Vendor",
      render: (item) => (
        <div>
          <span className="font-bold text-xs text-[#0F172A] block hover:text-[#6366F1] transition-colors">
            {item.vendorName}
          </span>
          <span className="text-[10px] text-[#64748B]">
            {item.category} • Expected: {item.expectedDayOfMonth}th of month
          </span>
        </div>
      ),
    },
    {
      key: "history",
      header: "Historical Average",
      align: "right",
      render: (item) => (
        <span className="font-mono text-xs font-semibold text-[#0F172A]">
          PKR {item.historicalAverage.toLocaleString()}
        </span>
      ),
    },
    {
      key: "estimatedAmount",
      header: "Proposed Accrual",
      align: "right",
      render: (item) => (
        <div>
          <span className="font-mono text-xs font-bold text-indigo-700 block">
            PKR {item.estimatedAmount.toLocaleString()}
          </span>
          <span className="text-[9px] text-[#64748B]">Confidence: {item.confidenceScore}%</span>
        </div>
      ),
    },
    {
      key: "glDistribution",
      header: "GL Posting Distribution",
      render: (item) => (
        <div className="text-[10px] space-y-0.5">
          <span className="text-emerald-700 font-mono block">DR {item.suggestedDebitAccount} ({item.suggestedDebitAccountName})</span>
          <span className="text-indigo-700 font-mono block">CR {item.suggestedCreditAccount} ({item.suggestedCreditAccountName})</span>
        </div>
      ),
    },
    {
      key: "status",
      header: "Detection Status",
      align: "center",
      render: (item) => (
        <Badge
          variant={
            item.status === "missing_bill"
              ? "warning"
              : item.status === "accrued"
              ? "approved"
              : item.status === "bill_received"
              ? "active"
              : "neutral"
          }
        >
          {item.status === "missing_bill"
            ? "Missing Bill - Accrual Proposed"
            : item.status === "accrued"
            ? "Accrued ✓"
            : item.status === "bill_received"
            ? "Bill Received"
            : "Waived"}
        </Badge>
      ),
    },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      render: (item) => (
        <div className="flex items-center justify-end space-x-2" onClick={(e) => e.stopPropagation()}>
          {item.status === "missing_bill" && (
            <button
              onClick={() => {
                if (onCreateAccrualDraft) onCreateAccrualDraft(item);
                else alert(`Draft Accrual Journal generated for ${item.vendorName}: PKR ${item.estimatedAmount.toLocaleString()}`);
              }}
              className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50 transition-colors cursor-pointer"
            >
              Draft Journal
            </button>
          )}
          <button
            onClick={() => setSelectedAccrual(item)}
            className="text-[11px] font-medium text-[#64748B] hover:text-[#0F172A] px-1.5 py-1 rounded"
          >
            Inspect
          </button>
        </div>
      ),
    },
  ];

  // Columns for Flux
  const fluxColumns: Column<FluxItem>[] = [
    {
      key: "account",
      header: "Account",
      render: (item) => (
        <div>
          <span className="font-mono font-bold text-xs text-[#0F172A] mr-1.5">{item.accountCode}</span>
          <span className="text-xs text-[#334155] font-medium">{item.accountName}</span>
          <span className="text-[10px] text-[#64748B] block">{item.classification}</span>
        </div>
      ),
    },
    {
      key: "prior",
      header: `${priorPeriodName} (Prior)`,
      align: "right",
      render: (item) => (
        <span className="font-mono text-xs text-[#64748B]">
          PKR {item.priorBalance.toLocaleString()}
        </span>
      ),
    },
    {
      key: "current",
      header: `${currentPeriodName} (Current)`,
      align: "right",
      render: (item) => (
        <span className="font-mono text-xs font-bold text-[#0F172A]">
          PKR {item.currentBalance.toLocaleString()}
        </span>
      ),
    },
    {
      key: "dollarChange",
      header: "Variance ($)",
      align: "right",
      render: (item) => {
        const isPos = item.dollarChange > 0;
        return (
          <span
            className={cn(
              "font-mono text-xs font-bold",
              isPos ? "text-emerald-700" : item.dollarChange < 0 ? "text-rose-700" : "text-[#64748B]"
            )}
          >
            {isPos ? `+${item.dollarChange.toLocaleString()}` : item.dollarChange.toLocaleString()}
          </span>
        );
      },
    },
    {
      key: "percentChange",
      header: "Variance (%)",
      align: "right",
      render: (item) => {
        const isSurge = Math.abs(item.percentChange) >= 15.0;
        return (
          <div className="flex items-center justify-end space-x-1">
            {item.percentChange > 0 ? (
              <ArrowUpRight className="w-3.5 h-3.5 text-emerald-600" />
            ) : item.percentChange < 0 ? (
              <ArrowDownRight className="w-3.5 h-3.5 text-rose-600" />
            ) : null}
            <span
              className={cn(
                "font-mono text-xs font-bold",
                isSurge ? "text-indigo-700" : "text-[#64748B]"
              )}
            >
              {item.percentChange > 0 ? `+${item.percentChange}%` : `${item.percentChange}%`}
            </span>
          </div>
        );
      },
    },
    {
      key: "significance",
      header: "Flux Status",
      align: "center",
      render: (item) => (
        <Badge variant={item.isSignificant ? "warning" : "neutral"}>
          {item.isSignificant ? "Significant Flux ⚡" : "Normal Invariant"}
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
            onClick={() => setSelectedFluxItem(item)}
            className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50 transition-colors cursor-pointer"
          >
            Review Reason
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-7 space-y-6 animate-in fade-in duration-200", className)}>
      {/* ─────────────────────────────────────────────────────────────
          1. HEADER & SUB-TABS (Accruals, Flux Analysis, Prepayments)
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
        <div>
          <div className="flex items-center space-x-3">
            <h1 className="text-2xl font-bold tracking-tight text-[#0F172A]">
              Continuous AI Accruals & Flux Analysis
            </h1>
            <span className="px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
              {currentPeriodName}
            </span>
          </div>
          <p className="text-xs text-[#64748B] mt-1">
            Automated recurring vendor invoice detection, month-over-month flux analysis, and straight-line amortization.
          </p>
        </div>

        {/* Sub-Tabs Switcher */}
        <div className="flex items-center space-x-3">
          <div className="inline-flex p-1 bg-slate-100 rounded-xl border border-slate-200/60">
            <button
              onClick={() => setActiveTab("accruals")}
              className={cn(
                "px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer",
                activeTab === "accruals"
                  ? "bg-white text-[#0F172A] shadow-xs"
                  : "text-[#64748B] hover:text-[#0F172A]"
              )}
            >
              Missing Bill Accruals ({accrualsList.filter((a) => a.status === "missing_bill").length})
            </button>
            <button
              onClick={() => setActiveTab("flux")}
              className={cn(
                "px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer",
                activeTab === "flux"
                  ? "bg-white text-[#0F172A] shadow-xs"
                  : "text-[#64748B] hover:text-[#0F172A]"
              )}
            >
              GL Flux Analysis
            </button>
            <button
              onClick={() => setActiveTab("prepayments")}
              className={cn(
                "px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer",
                activeTab === "prepayments"
                  ? "bg-white text-[#0F172A] shadow-xs"
                  : "text-[#64748B] hover:text-[#0F172A]"
              )}
            >
              Prepayments & Amortization
            </button>
          </div>

          <button
            onClick={onRefresh}
            className="p-2 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] hover:bg-slate-50 transition-colors cursor-pointer"
            title="Refresh Analysis"
          >
            <RefreshCw className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          2. SUMMARY KPI CARDS
      ─────────────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Missing Bills */}
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-4.5 shadow-xs">
          <div className="flex items-center justify-between text-[#64748B] mb-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider">Missing Vendor Invoices</span>
            <AlertTriangle className="w-4 h-4 text-amber-500" />
          </div>
          <div className="flex items-baseline space-x-2">
            <span className="text-2xl font-bold tracking-tight text-amber-600">
              {accrualsList.filter((a) => a.status === "missing_bill").length}
            </span>
            <span className="text-xs text-[#64748B]">bills overdue</span>
          </div>
          <p className="text-[11px] text-[#94A3B8] mt-2">
            Estimated PKR 577,000 unbooked liabilities detected.
          </p>
        </div>

        {/* Significant Flux */}
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-4.5 shadow-xs">
          <div className="flex items-center justify-between text-indigo-700 mb-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider">Significant Flux Accounts</span>
            <TrendingUp className="w-4 h-4 text-indigo-600" />
          </div>
          <div className="flex items-baseline space-x-2">
            <span className="text-2xl font-bold tracking-tight text-[#0F172A]">
              {fluxItems.filter((f) => f.isSignificant).length}
            </span>
            <span className="text-xs text-[#64748B]">variance &gt; 15%</span>
          </div>
          <p className="text-[11px] text-[#94A3B8] mt-2">
            Month-over-month material shifts investigated by AI.
          </p>
        </div>

        {/* Active Prepayments */}
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-4.5 shadow-xs">
          <div className="flex items-center justify-between text-[#64748B] mb-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider">Active Prepayments (1150)</span>
            <Receipt className="w-4 h-4 text-indigo-500" />
          </div>
          <div className="flex items-baseline space-x-2">
            <span className="text-2xl font-bold tracking-tight text-[#0F172A]">
              PKR 80,000
            </span>
            <span className="text-xs text-[#64748B]">monthly amortization</span>
          </div>
          <p className="text-[11px] text-[#94A3B8] mt-2">
            3 scheduled straight-line contracts active.
          </p>
        </div>

        {/* Invariant Check */}
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-4.5 shadow-xs">
          <div className="flex items-center justify-between text-emerald-700 mb-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider">Accrual Invariant Check</span>
            <ShieldCheck className="w-4 h-4 text-emerald-600" />
          </div>
          <div className="flex items-baseline space-x-2">
            <span className="text-2xl font-bold tracking-tight text-emerald-600">Balanced</span>
          </div>
          <p className="text-[11px] text-[#94A3B8] mt-2">
            All proposed journals enforce Debits == Credits.
          </p>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          SURFACE 1: CONTINUOUS ACCRUALS DETECTOR
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "accruals" && (
        <div className="space-y-4 animate-in fade-in">
          <FilterBar
            searchQuery={accrualSearch}
            onSearchChange={setAccrualSearch}
            searchPlaceholder="Search recurring vendors, categories..."
            statusFilter={accrualStatusFilter}
            onStatusChange={setAccrualStatusFilter}
            statusOptions={[
              { label: "Missing Bill - Accrual Proposed", value: "missing_bill" },
              { label: "Accrued ✓", value: "accrued" },
              { label: "Bill Received", value: "bill_received" },
              { label: "All Statuses", value: "all" },
            ]}
            count={filteredAccruals.length}
            countLabel="recurring vendors"
          >
            <select
              value={accrualCategoryFilter}
              onChange={(e) => setAccrualCategoryFilter(e.target.value)}
              className="text-xs font-semibold bg-white border border-[#E2E8F0] rounded-xl px-3 py-2 text-[#0F172A] outline-none cursor-pointer hover:border-indigo-300"
            >
              <option value="all">All Categories</option>
              <option value="Cloud Hosting">Cloud Hosting</option>
              <option value="Utilities">Utilities</option>
              <option value="Rent & Facility">Rent & Facility</option>
            </select>
          </FilterBar>

          <DataTable
            columns={accrualColumns}
            data={filteredAccruals}
            onRowClick={(item) => setSelectedAccrual(item)}
            rowKey={(item) => item.id}
            emptyMessage="No recurring vendor discrepancies found"
            emptySubtext="All expected operational vendor invoices for this period have been received."
          />
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          SURFACE 2: SUBLEDGER & GL FLUX ANALYSIS
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "flux" && (
        <div className="space-y-4 animate-in fade-in">
          <FilterBar
            searchQuery={fluxSearch}
            onSearchChange={setFluxSearch}
            searchPlaceholder="Search accounts by code or name..."
            statusFilter={fluxFilter}
            onStatusChange={setFluxFilter}
            statusOptions={[
              { label: "Significant Flux (> 15%)", value: "significant" },
              { label: "Normal Invariants (<= 15%)", value: "normal" },
              { label: "All Accounts", value: "all" },
            ]}
            count={filteredFluxItems.length}
            countLabel="accounts"
          />

          <DataTable
            columns={fluxColumns}
            data={filteredFluxItems}
            onRowClick={(item) => setSelectedFluxItem(item)}
            rowKey={(item) => item.accountCode}
            emptyMessage="No account flux variances match criteria"
          />
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          SURFACE 3: PREPAYMENTS & AMORTIZATION SCHEDULE
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "prepayments" && (
        <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs space-y-6 animate-in fade-in">
          <div className="flex items-center justify-between border-b border-[#F1F5F9] pb-4">
            <div>
              <h2 className="text-base font-bold text-[#0F172A]">Prepaid Expenses Amortization Schedule (Account 1150)</h2>
              <p className="text-xs text-[#64748B] mt-0.5">
                Straight-line amortization calculated daily and proposed automatically at month-end closing.
              </p>
            </div>
            <button
              onClick={() => alert("Batch amortization journals created for all 3 active prepaid assets.")}
              className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 shadow-xs transition-colors cursor-pointer"
            >
              <Zap className="w-3.5 h-3.5" />
              <span>Run Month-End Amortization</span>
            </button>
          </div>

          <div className="divide-y divide-[#F1F5F9]">
            {prepaymentsList.map((pre) => (
              <div key={pre.id} className="py-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div className="space-y-1">
                  <div className="flex items-center space-x-2">
                    <span className="text-[10px] font-bold font-mono px-2 py-0.5 rounded bg-indigo-50 text-indigo-700">
                      {pre.id}
                    </span>
                    <h3 className="text-xs font-bold text-[#0F172A]">{pre.assetName}</h3>
                  </div>
                  <p className="text-[11px] text-[#64748B]">
                    Contract: PKR {pre.contractTotal.toLocaleString()} • Schedule: {pre.startDate} to {pre.endDate} ({pre.periodsRemaining} months remaining)
                  </p>
                </div>

                <div className="flex items-center space-x-6 text-right">
                  <div>
                    <span className="text-[10px] text-[#94A3B8] block">Monthly Amortization:</span>
                    <span className="text-xs font-bold font-mono text-[#0F172A]">
                      PKR {pre.monthlyAmortization.toLocaleString()} / mo
                    </span>
                  </div>
                  <button
                    onClick={() => alert(`Amortization journal drafted for ${pre.assetName}: DR Expense / CR 1150 (PKR ${pre.monthlyAmortization.toLocaleString()})`)}
                    className="px-3 py-1.5 text-xs font-semibold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors cursor-pointer"
                  >
                    Draft Entry
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          SLIDEOVER DRAWER: ACCRUAL INSPECTION & PROPOSAL
      ─────────────────────────────────────────────────────────────── */}
      <SlideOverDrawer
        isOpen={Boolean(selectedAccrual)}
        onClose={() => setSelectedAccrual(null)}
        title={`Accrual Proposal: ${selectedAccrual?.vendorName || ""}`}
        subtitle={`${selectedAccrual?.category || ""} • Estimated PKR ${selectedAccrual?.estimatedAmount.toLocaleString() || "0"}`}
        badge={
          <Badge variant={selectedAccrual?.status === "missing_bill" ? "warning" : "approved"}>
            {selectedAccrual?.status === "missing_bill" ? "Missing Bill - Accrual Proposed" : "Accrued"}
          </Badge>
        }
        footer={
          selectedAccrual && (
            <div className="flex items-center justify-between w-full">
              <span className="text-xs text-[#64748B]">
                Confidence: <strong className="text-[#0F172A] font-mono">{selectedAccrual.confidenceScore}%</strong>
              </span>
              <div className="flex items-center space-x-2">
                <button
                  onClick={() => setSelectedAccrual(null)}
                  className="px-3 py-1.5 text-xs text-[#64748B] hover:text-[#0F172A]"
                >
                  Close
                </button>
                <button
                  onClick={() => {
                    if (onCreateAccrualDraft) onCreateAccrualDraft(selectedAccrual);
                    else alert(`Draft Accrual Journal created for ${selectedAccrual.vendorName}`);
                    setSelectedAccrual(null);
                  }}
                  className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 shadow-sm transition-colors cursor-pointer"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Post Draft Accrual</span>
                </button>
              </div>
            </div>
          )
        }
      >
        {selectedAccrual && (
          <div className="space-y-6 text-xs text-[#334155]">
            {/* AI Rationale Box */}
            <div className="p-4 rounded-xl bg-amber-50/60 border border-amber-200 text-amber-900 space-y-1.5">
              <div className="flex items-center space-x-1.5 font-bold">
                <Sparkles className="w-4 h-4 text-amber-600" />
                <span>AI Detection Rationale</span>
              </div>
              <p className="text-xs leading-relaxed">{selectedAccrual.rationale}</p>
            </div>

            {/* Trailing History */}
            <div className="space-y-2">
              <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">
                Trailing 3-Month Billing History
              </span>
              <div className="grid grid-cols-3 gap-2">
                {selectedAccrual.trailingTrend.map((t, idx) => (
                  <div key={idx} className="p-3 bg-[#F8FAFC] rounded-xl border border-[#E2E8F0] text-center">
                    <span className="text-[10px] text-[#64748B] block">{t.month} 2025</span>
                    <span className="text-xs font-bold font-mono text-[#0F172A]">PKR {t.amount.toLocaleString()}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* Double-Entry Distribution */}
            <div className="space-y-2">
              <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">
                Balanced Double-Entry Proposal
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
                    <tr>
                      <td className="py-2 px-3">
                        <span className="font-mono font-bold mr-1">{selectedAccrual.suggestedDebitAccount}</span>
                        <span>{selectedAccrual.suggestedDebitAccountName}</span>
                      </td>
                      <td className="py-2 px-3 text-right font-mono font-bold text-[#0F172A]">
                        PKR {selectedAccrual.estimatedAmount.toLocaleString()}
                      </td>
                      <td className="py-2 px-3 text-right font-mono text-[#94A3B8]">—</td>
                    </tr>
                    <tr>
                      <td className="py-2 px-3">
                        <span className="font-mono font-bold mr-1">{selectedAccrual.suggestedCreditAccount}</span>
                        <span>{selectedAccrual.suggestedCreditAccountName}</span>
                      </td>
                      <td className="py-2 px-3 text-right font-mono text-[#94A3B8]">—</td>
                      <td className="py-2 px-3 text-right font-mono font-bold text-[#0F172A]">
                        PKR {selectedAccrual.estimatedAmount.toLocaleString()}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}
      </SlideOverDrawer>

      {/* ─────────────────────────────────────────────────────────────
          SLIDEOVER DRAWER: FLUX VARIANCE COMMENTARY
      ─────────────────────────────────────────────────────────────── */}
      <SlideOverDrawer
        isOpen={Boolean(selectedFluxItem)}
        onClose={() => setSelectedFluxItem(null)}
        title={`Flux Analysis: Account ${selectedFluxItem?.accountCode || ""}`}
        subtitle={`${selectedFluxItem?.accountName || ""} • Variance: ${(selectedFluxItem?.percentChange ?? 0) > 0 ? `+${selectedFluxItem?.percentChange}%` : `${selectedFluxItem?.percentChange ?? 0}%`}`}
        badge={
          <Badge variant={selectedFluxItem?.isSignificant ? "warning" : "neutral"}>
            {selectedFluxItem?.isSignificant ? "Significant Flux" : "Normal Invariant"}
          </Badge>
        }
      >
        {selectedFluxItem && (
          <div className="space-y-6 text-xs text-[#334155]">
            <div className="p-4 rounded-xl bg-indigo-50/60 border border-indigo-200 text-indigo-950 space-y-1.5">
              <div className="flex items-center space-x-1.5 font-bold">
                <Sparkles className="w-4 h-4 text-indigo-600" />
                <span>AI Root-Cause Explanation</span>
              </div>
              <p className="text-xs leading-relaxed">{selectedFluxItem.aiExplanation}</p>
            </div>

            <div className="bg-[#F8FAFC] p-4.5 rounded-2xl border border-[#E2E8F0] space-y-2">
              <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">Period Comparison</span>
              <div className="grid grid-cols-2 gap-4 text-xs pt-1">
                <div>
                  <span className="text-[#94A3B8] block text-[10px]">{priorPeriodName} Balance:</span>
                  <span className="font-bold text-[#0F172A] font-mono">PKR {selectedFluxItem.priorBalance.toLocaleString()}</span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block text-[10px]">{currentPeriodName} Balance:</span>
                  <span className="font-bold text-[#0F172A] font-mono">PKR {selectedFluxItem.currentBalance.toLocaleString()}</span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block text-[10px]">Net Dollar Shift:</span>
                  <span className="font-bold font-mono text-indigo-700">
                    {selectedFluxItem.dollarChange > 0 ? `+PKR ${selectedFluxItem.dollarChange.toLocaleString()}` : `PKR ${selectedFluxItem.dollarChange.toLocaleString()}`}
                  </span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block text-[10px]">Percentage Shift:</span>
                  <span className="font-bold font-mono text-indigo-700">{selectedFluxItem.percentChange}%</span>
                </div>
              </div>
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
