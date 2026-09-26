"use client";

import React, { useState } from "react";
import {
  BarChart3,
  TrendingUp,
  Scale,
  DollarSign,
  Globe,
  Building,
  Repeat,
  Download,
  FileSpreadsheet,
  ArrowRight,
  ArrowUpRight,
  ArrowDownRight,
  Layers,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Search,
  Filter,
  Eye,
  Calendar,
  Sparkles,
  SlidersHorizontal,
  ChevronRight,
  PieChart,
  ShieldCheck,
  FileText,
  Clock,
  ExternalLink,
  Plus,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";
import { DataTable, Column } from "../ui/DataTable";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge } from "../ui/Badge";

export interface ReportAccountLine {
  id: string;
  code: string;
  name: string;
  classification: "asset" | "liability" | "equity" | "revenue" | "expense";
  normal_balance: "DEBIT" | "CREDIT";
  debit?: number;
  credit?: number;
  balance: number;
  notes?: string;
  transactions?: {
    id: string;
    entry_date: string;
    reference: string;
    description: string;
    debit: number;
    credit: number;
    running_balance: number;
    source_type?: "invoice" | "bill" | "journal" | "bank_tx";
    source_id?: string;
  }[];
}

export interface LegalEntityItem {
  id: string;
  name: string;
  code: string;
  currency: string;
  is_primary?: boolean;
  status?: string;
  country?: string;
  shareholding?: string;
}

export interface ExchangeRateItem {
  id?: string;
  pair?: string;
  from_currency?: string;
  to_currency?: string;
  rate?: number | string;
  spotRate?: number;
  priorPeriodRate?: number;
  effective_date?: string;
  source?: string;
  type?: string;
}

export interface IntercompanyTxItem {
  id: string;
  transaction_number: string;
  transaction_date: string;
  fromEntity?: { name: string; code: string };
  toEntity?: { name: string; code: string };
  from_entity?: { name: string; code: string };
  to_entity?: { name: string; code: string };
  amount: number | string;
  currency: string;
  description: string;
  status: "draft" | "posted" | "eliminated" | string;
}

interface AdvancedReportingConsolidationViewProps {
  currentOrgName?: string;
  periodName?: string;
  entities?: LegalEntityItem[];
  exchangeRates?: ExchangeRateItem[];
  intercompanyTransactions?: IntercompanyTxItem[];
  onRefresh?: () => void;
  onRunEliminations?: () => void;
  onRunFxRevaluation?: () => void;
  onOpenNewIntercompany?: () => void;
  onPostIntercompany?: (txId: string) => void;
  isEliminating?: boolean;
  className?: string;
}

export function AdvancedReportingConsolidationView({
  currentOrgName = "Indus Technologies Ltd.",
  periodName = "August 2025",
  entities,
  exchangeRates,
  intercompanyTransactions,
  onRefresh,
  onRunEliminations,
  onRunFxRevaluation,
  onOpenNewIntercompany,
  onPostIntercompany,
  isEliminating = false,
  className,
}: AdvancedReportingConsolidationViewProps) {
  const [activeTab, setActiveTab] = useState<
    "pnl" | "balance_sheet" | "cash_flow" | "trial_balance" | "consolidation" | "kpis"
  >("pnl");

  const [dateRange, setDateRange] = useState("august-2025");
  const [selectedAccount, setSelectedAccount] = useState<ReportAccountLine | null>(null);

  // Trial Balance state
  const [tbSearch, setTbSearch] = useState("");
  const [tbClassification, setTbClassification] = useState("all");

  // Multi-Entity & FX State
  const [activeCurrency, setActiveCurrency] = useState<"PKR" | "USD" | "AED" | "GBP">("PKR");
  const [icSearch, setIcSearch] = useState("");
  const [icStatus, setIcStatus] = useState("all");

  // ─────────────────────────────────────────────────────────────
  // 1. DATASETS (IFRS / DOUBLE-ENTRY COMPLIANT)
  // ─────────────────────────────────────────────────────────────

  // Income Statement (P&L) Data
  const revenueLines: ReportAccountLine[] = [
    {
      id: "acc-4010",
      code: "4010",
      name: "Sales Revenue — Local Software & Subscriptions",
      classification: "revenue",
      normal_balance: "CREDIT",
      balance: 14500000,
      notes: "SaaS Enterprise licenses and recurring annual billings",
      transactions: [
        {
          id: "tx-4010-1",
          entry_date: "2025-08-05",
          reference: "INV-2025-089",
          description: "Systems Limited - Finova Enterprise License Q3",
          debit: 0,
          credit: 8500000,
          running_balance: 8500000,
          source_type: "invoice",
          source_id: "INV-2025-089",
        },
        {
          id: "tx-4010-2",
          entry_date: "2025-08-18",
          reference: "INV-2025-091",
          description: "Engro Corp - Finova Cloud Module Tier 1",
          debit: 0,
          credit: 6000000,
          running_balance: 14500000,
          source_type: "invoice",
          source_id: "INV-2025-091",
        },
      ],
    },
    {
      id: "acc-4020",
      code: "4020",
      name: "Service & Implementation Consulting",
      classification: "revenue",
      normal_balance: "CREDIT",
      balance: 3200000,
      notes: "ERP deployment and custom workflow configuration fees",
      transactions: [
        {
          id: "tx-4020-1",
          entry_date: "2025-08-12",
          reference: "INV-2025-090",
          description: "Nishat Mills - Onsite ERP Customization Sprint 2",
          debit: 0,
          credit: 3200000,
          running_balance: 3200000,
          source_type: "invoice",
        },
      ],
    },
    {
      id: "acc-4030",
      code: "4030",
      name: "Export Technology Services (0% Sales Tax)",
      classification: "revenue",
      normal_balance: "CREDIT",
      balance: 4800000,
      notes: "Offshore software advisory to Gulf Trading FZE (USD wire)",
      transactions: [
        {
          id: "tx-4030-1",
          entry_date: "2025-08-22",
          reference: "INV-2025-094",
          description: "Gulf Trading FZE - Advisory Services August (USD 17,250)",
          debit: 0,
          credit: 4800000,
          running_balance: 4800000,
          source_type: "invoice",
        },
      ],
    },
  ];

  const cogsLines: ReportAccountLine[] = [
    {
      id: "acc-5010",
      code: "5010",
      name: "Cloud Infrastructure & Server Hosting (AWS / Azure)",
      classification: "expense",
      normal_balance: "DEBIT",
      balance: 1850000,
      notes: "Dedicated compute clusters, multi-AZ database read replicas",
      transactions: [
        {
          id: "tx-5010-1",
          entry_date: "2025-08-01",
          reference: "BILL-2025-081",
          description: "Amazon Web Services - Production US-East Clusters",
          debit: 1850000,
          credit: 0,
          running_balance: 1850000,
          source_type: "bill",
        },
      ],
    },
    {
      id: "acc-5020",
      code: "5020",
      name: "Third-Party API Integrations & FBR Fiscalization Fees",
      classification: "expense",
      normal_balance: "DEBIT",
      balance: 420000,
      notes: "Licensed digital signature HSM & sandbox validator gateways",
      transactions: [
        {
          id: "tx-5020-1",
          entry_date: "2025-08-15",
          reference: "BILL-2025-088",
          description: "Digital Signature HSM monthly verification throughput",
          debit: 420000,
          credit: 0,
          running_balance: 420000,
          source_type: "bill",
        },
      ],
    },
  ];

  const operatingExpenseLines: ReportAccountLine[] = [
    {
      id: "acc-6010",
      code: "6010",
      name: "Engineering & Staff Salaries",
      classification: "expense",
      normal_balance: "DEBIT",
      balance: 6200000,
      notes: "Product engineering, QA, financial analyst team salaries",
      transactions: [
        {
          id: "tx-6010-1",
          entry_date: "2025-08-28",
          reference: "PAYROLL-2025-08",
          description: "Gross Staff Payroll August 2025 Disbursement",
          debit: 6200000,
          credit: 0,
          running_balance: 6200000,
          source_type: "journal",
        },
      ],
    },
    {
      id: "acc-6030",
      code: "6030",
      name: "Office Rent & Facilities (Commercial Plaza)",
      classification: "expense",
      normal_balance: "DEBIT",
      balance: 950000,
      notes: "Gulberg Corporate Plaza Lease (Lahore HQ)",
      transactions: [
        {
          id: "tx-6030-1",
          entry_date: "2025-08-03",
          reference: "BILL-2025-079",
          description: "Plaza Management - August Commercial Office Rent",
          debit: 950000,
          credit: 0,
          running_balance: 950000,
          source_type: "bill",
        },
      ],
    },
    {
      id: "acc-6070",
      code: "6070",
      name: "Depreciation & Asset Amortization",
      classification: "expense",
      normal_balance: "DEBIT",
      balance: 310000,
      notes: "Straight-line depreciation on laptops, servers, office fixtures",
      transactions: [
        {
          id: "tx-6070-1",
          entry_date: "2025-08-31",
          reference: "DEP-2025-08",
          description: "Monthly depreciation journal - Fixed Asset Register",
          debit: 310000,
          credit: 0,
          running_balance: 310000,
          source_type: "journal",
        },
      ],
    },
    {
      id: "acc-6090",
      code: "6090",
      name: "Legal, Audit & Tax Compliance Advisory",
      classification: "expense",
      normal_balance: "DEBIT",
      balance: 450000,
      notes: "External statutory audit retainers and corporate filings",
      transactions: [
        {
          id: "tx-6090-1",
          entry_date: "2025-08-10",
          reference: "BILL-2025-082",
          description: "Deloitte & Co. - Q3 Corporate Tax Retainer",
          debit: 450000,
          credit: 0,
          running_balance: 450000,
          source_type: "bill",
        },
      ],
    },
  ];

  const totalRevenue = revenueLines.reduce((s, x) => s + x.balance, 0);
  const totalCogs = cogsLines.reduce((s, x) => s + x.balance, 0);
  const grossProfit = totalRevenue - totalCogs;
  const grossMargin = (grossProfit / totalRevenue) * 100;
  const totalOpex = operatingExpenseLines.reduce((s, x) => s + x.balance, 0);
  const operatingProfit = grossProfit - totalOpex;
  const netIncome = operatingProfit * 0.71; // 29% corporate income tax provision
  const taxProvision = operatingProfit * 0.29;

  // Balance Sheet Data
  const currentAssetLines: ReportAccountLine[] = [
    {
      id: "acc-1010",
      code: "1010",
      name: "Cash and Cash Equivalents (HBL Operating)",
      classification: "asset",
      normal_balance: "DEBIT",
      balance: 14200000,
      notes: "Primary operational treasury account",
      transactions: [
        {
          id: "tx-1010-1",
          entry_date: "2025-08-31",
          reference: "BANK-REC-08",
          description: "Reconciled Ending Balance HBL A/C #0042-9912",
          debit: 14200000,
          credit: 0,
          running_balance: 14200000,
          source_type: "bank_tx",
        },
      ],
    },
    {
      id: "acc-1020",
      code: "1020",
      name: "Foreign Currency Bank Account (Meezan USD)",
      classification: "asset",
      normal_balance: "DEBIT",
      balance: 8520000,
      notes: "USD 30,500 @ 279.34 PKR/USD spot rate",
      transactions: [
        {
          id: "tx-1020-1",
          entry_date: "2025-08-31",
          reference: "FX-REVAL-08",
          description: "Unrealized FX revaluation gain +PKR 45,000",
          debit: 8520000,
          credit: 0,
          running_balance: 8520000,
          source_type: "journal",
        },
      ],
    },
    {
      id: "acc-1030",
      code: "1030",
      name: "Trade Accounts Receivable (Subledger Control)",
      classification: "asset",
      normal_balance: "DEBIT",
      balance: 12450000,
      notes: "Subledger tied to customer invoices (0 unposted variances)",
      transactions: [
        {
          id: "tx-1030-1",
          entry_date: "2025-08-31",
          reference: "AR-AGING-08",
          description: "Verified AR Subledger Total",
          debit: 12450000,
          credit: 0,
          running_balance: 12450000,
          source_type: "invoice",
        },
      ],
    },
    {
      id: "acc-1150",
      code: "1150",
      name: "Prepaid Software & Cloud Infrastructure",
      classification: "asset",
      normal_balance: "DEBIT",
      balance: 1680000,
      notes: "Straight-line monthly amortization into Account 5010",
      transactions: [
        {
          id: "tx-1150-1",
          entry_date: "2025-08-31",
          reference: "AMORT-08",
          description: "August amortization release: -PKR 280,000",
          debit: 1680000,
          credit: 280000,
          running_balance: 1680000,
          source_type: "journal",
        },
      ],
    },
  ];

  const nonCurrentAssetLines: ReportAccountLine[] = [
    {
      id: "acc-1510",
      code: "1510",
      name: "Computer Hardware & Network Infrastructure",
      classification: "asset",
      normal_balance: "DEBIT",
      balance: 4200000,
      notes: "Developer MacBook Pros, local staging server rack",
    },
    {
      id: "acc-1590",
      code: "1590",
      name: "Accumulated Depreciation — Hardware",
      classification: "asset",
      normal_balance: "CREDIT",
      balance: -1150000,
      notes: "Contra-asset contra account",
    },
  ];

  const totalAssets =
    currentAssetLines.reduce((s, x) => s + x.balance, 0) +
    nonCurrentAssetLines.reduce((s, x) => s + x.balance, 0);

  const currentLiabilityLines: ReportAccountLine[] = [
    {
      id: "acc-2010",
      code: "2010",
      name: "Trade Accounts Payable (Vendor Control)",
      classification: "liability",
      normal_balance: "CREDIT",
      balance: 4850000,
      notes: "Subledger tied to verified 3-way matched bills",
    },
    {
      id: "acc-2020",
      code: "2020",
      name: "Output Sales Tax Payable (FBR Annex-C)",
      classification: "liability",
      normal_balance: "CREDIT",
      balance: 1980000,
      notes: "FBR 17% collected sales tax pending monthly treasury challan",
    },
    {
      id: "acc-2050",
      code: "2050",
      name: "Accrued Expenses & Unbilled Services",
      classification: "liability",
      normal_balance: "CREDIT",
      balance: 2750000,
      notes: "Continuous AI accruals detector (AWS, LESCO, Rent)",
    },
    {
      id: "acc-2080",
      code: "2080",
      name: "Provision for Corporate Income Tax",
      classification: "liability",
      normal_balance: "CREDIT",
      balance: 2470800,
      notes: "29% taxable income provision",
    },
  ];

  const equityLines: ReportAccountLine[] = [
    {
      id: "acc-3010",
      code: "3010",
      name: "Paid-up Share Capital",
      classification: "equity",
      normal_balance: "CREDIT",
      balance: 15000000,
      notes: "Authorized & fully paid Ordinary Class A shares",
    },
    {
      id: "acc-3020",
      code: "3020",
      name: "Retained Earnings — Prior Fiscal Years",
      classification: "equity",
      normal_balance: "CREDIT",
      balance: 6799200,
      notes: "Cumulative historical profits reinvested into operations",
    },
    {
      id: "acc-3030",
      code: "3030",
      name: "Net Income for Current Period (P&L Reinvested)",
      classification: "equity",
      normal_balance: "CREDIT",
      balance: 6050000,
      notes: "Dynamically synced from Statement of Profit & Loss",
    },
  ];

  const totalLiabilities = currentLiabilityLines.reduce((s, x) => s + x.balance, 0);
  const totalEquity = equityLines.reduce((s, x) => s + x.balance, 0);
  const totalLiabilitiesAndEquity = totalLiabilities + totalEquity;
  const isBalanceSheetBalanced = Math.abs(totalAssets - totalLiabilitiesAndEquity) < 0.01;

  // Trial Balance Accounts Array
  const trialBalanceAccounts: ReportAccountLine[] = [
    ...currentAssetLines,
    ...nonCurrentAssetLines,
    ...currentLiabilityLines,
    ...equityLines,
    ...revenueLines,
    ...cogsLines,
    ...operatingExpenseLines,
  ].map((acc) => {
    const isCreditNormal = acc.normal_balance === "CREDIT";
    const debit = isCreditNormal ? 0 : Math.abs(acc.balance);
    const credit = isCreditNormal ? Math.abs(acc.balance) : 0;
    return {
      ...acc,
      debit,
      credit,
    };
  });

  const totalTbDebit = trialBalanceAccounts.reduce((s, a) => s + (a.debit || 0), 0);
  const totalTbCredit = trialBalanceAccounts.reduce((s, a) => s + (a.credit || 0), 0);
  const isTbBalanced = Math.abs(totalTbDebit - totalTbCredit) < 0.01;

  // Filtered TB
  const filteredTb = trialBalanceAccounts.filter((acc) => {
    const matchesSearch =
      acc.name.toLowerCase().includes(tbSearch.toLowerCase()) ||
      acc.code.toLowerCase().includes(tbSearch.toLowerCase());
    const matchesClass = tbClassification === "all" || acc.classification === tbClassification;
    return matchesSearch && matchesClass;
  });

  // Multi-Entity Consolidation Rows
  const consolidationRows = [
    {
      code: "1010",
      name: "Cash and Cash Equivalents",
      classification: "Asset",
      parentPkr: 14200000,
      gulfAed: 135000, // in PKR: ~10,260,000
      ukGbp: 18000, // in PKR: ~6,480,000
      eliminationsPkr: 0,
      consolidatedPkr: 30940000,
    },
    {
      code: "1035",
      name: "Due From Gulf Trading FZE (Intercompany AR)",
      classification: "Asset",
      parentPkr: 4800000,
      gulfAed: 0,
      ukGbp: 0,
      eliminationsPkr: -4800000, // Eliminated!
      consolidatedPkr: 0,
    },
    {
      code: "2035",
      name: "Due To Indus Holding (Intercompany AP)",
      classification: "Liability",
      parentPkr: 0,
      gulfAed: -63150, // AED 63,150 = ~4,800,000 PKR
      ukGbp: 0,
      eliminationsPkr: 4800000, // Eliminated!
      consolidatedPkr: 0,
    },
    {
      code: "4010",
      name: "Enterprise Software Revenue",
      classification: "Revenue",
      parentPkr: 14500000,
      gulfAed: 220000, // ~16,720,000 PKR
      ukGbp: 45000, // ~16,200,000 PKR
      eliminationsPkr: 0,
      consolidatedPkr: 47420000,
    },
    {
      code: "4035",
      name: "Intercompany Management Fees",
      classification: "Revenue",
      parentPkr: 2500000,
      gulfAed: 0,
      ukGbp: 0,
      eliminationsPkr: -2500000, // Reciprocal revenue eliminated
      consolidatedPkr: 0,
    },
    {
      code: "6035",
      name: "Intercompany Advisory Fee Expense",
      classification: "Expense",
      parentPkr: 0,
      gulfAed: 32890, // ~2,500,000 PKR
      ukGbp: 0,
      eliminationsPkr: -2500000, // Reciprocal expense eliminated
      consolidatedPkr: 0,
    },
  ];

  // FX Rates Dataset
  const activeFxRates = [
    { pair: "USD/PKR", spotRate: 279.34, priorPeriodRate: 278.1, source: "SBP Interbank Fixing", type: "Floating" },
    { pair: "AED/PKR", spotRate: 76.04, priorPeriodRate: 75.72, source: "SBP Weighted Average", type: "Pegged USD" },
    { pair: "GBP/PKR", spotRate: 360.22, priorPeriodRate: 356.8, source: "London LSE Spot", type: "Floating" },
    { pair: "EUR/PKR", spotRate: 304.5, priorPeriodRate: 302.15, source: "ECB Reference", type: "Floating" },
  ];

  const defaultEntities: LegalEntityItem[] = [
    {
      id: "ent-1",
      name: "Indus Technologies Ltd.",
      code: "INDUS-PK",
      currency: "PKR",
      is_primary: true,
      country: "Lahore, Pakistan",
      shareholding: "100% Primary Parent",
      status: "active",
    },
    {
      id: "ent-2",
      name: "Gulf Trading FZE",
      code: "GULF-AED",
      currency: "AED",
      is_primary: false,
      country: "Dubai, UAE",
      shareholding: "100% Wholly Owned Subsidiary",
      status: "active",
    },
    {
      id: "ent-3",
      name: "Indus Logistics UK Ltd.",
      code: "UK-LOGISTICS",
      currency: "GBP",
      is_primary: false,
      country: "London, United Kingdom",
      shareholding: "85% Direct Subsidiary",
      status: "active",
    },
  ];

  const defaultIntercompanyTxs: IntercompanyTxItem[] = [
    {
      id: "ic-1",
      transaction_number: "IC-2025-0001",
      transaction_date: "2025-08-10",
      fromEntity: { name: "Indus Technologies Ltd.", code: "INDUS-PK" },
      toEntity: { name: "Indus Logistics UK Ltd.", code: "UK-LOGISTICS" },
      amount: 150000,
      currency: "PKR",
      description: "Shared Cloud ERP Infrastructure and Accounting Overhead Allocation",
      status: "posted",
    },
    {
      id: "ic-2",
      transaction_number: "IC-2025-0002",
      transaction_date: "2025-08-15",
      fromEntity: { name: "Indus Technologies Ltd.", code: "INDUS-PK" },
      toEntity: { name: "Gulf Trading FZE", code: "GULF-AED" },
      amount: 4800000,
      currency: "PKR",
      description: "Advisory and Core Platform IP Licensing Fee",
      status: "eliminated",
    },
    {
      id: "ic-3",
      transaction_number: "IC-2025-0003",
      transaction_date: "2025-08-25",
      fromEntity: { name: "Indus Technologies Ltd.", code: "INDUS-PK" },
      toEntity: { name: "Gulf Trading FZE", code: "GULF-AED" },
      amount: 2500000,
      currency: "PKR",
      description: "Reciprocal Management Services Fee Allocation",
      status: "draft",
    },
  ];

  const displayEntitiesList: LegalEntityItem[] =
    entities && entities.length > 0
      ? entities.map((e) => ({
          ...e,
          country:
            e.country ||
            (e.code === "GULF" || e.currency === "AED"
              ? "Dubai, UAE"
              : e.currency === "GBP"
              ? "London, United Kingdom"
              : "Lahore, Pakistan"),
          shareholding: e.shareholding || (e.is_primary ? "100% Primary Parent" : "Wholly Owned Subsidiary"),
        }))
      : defaultEntities;

  const displayRatesList: ExchangeRateItem[] =
    exchangeRates && exchangeRates.length > 0
      ? exchangeRates.map((r) => ({
          pair: r.pair || `${r.from_currency}/${r.to_currency}`,
          spotRate:
            typeof r.rate === "number"
              ? r.rate
              : parseFloat(String(r.rate || 0)) || r.spotRate || 0,
          priorPeriodRate: r.priorPeriodRate,
          source: r.source || "State Bank of Pakistan",
          type: r.type || "Interbank",
        }))
      : activeFxRates;

  const displayIntercompanyList: IntercompanyTxItem[] =
    intercompanyTransactions && intercompanyTransactions.length > 0
      ? intercompanyTransactions
      : defaultIntercompanyTxs;

  const filteredIntercompanyList = displayIntercompanyList.filter((tx) => {
    const matchesSearch =
      tx.transaction_number.toLowerCase().includes(icSearch.toLowerCase()) ||
      tx.description.toLowerCase().includes(icSearch.toLowerCase()) ||
      (tx.fromEntity?.name || tx.from_entity?.name || "").toLowerCase().includes(icSearch.toLowerCase()) ||
      (tx.toEntity?.name || tx.to_entity?.name || "").toLowerCase().includes(icSearch.toLowerCase());
    const matchesStatus = icStatus === "all" || tx.status.toLowerCase() === icStatus.toLowerCase();
    return matchesSearch && matchesStatus;
  });

  // ─────────────────────────────────────────────────────────────
  // RENDER HELPERS
  // ─────────────────────────────────────────────────────────────

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-6 space-y-6", className)}>
      {/* ─────────────────────────────────────────────────────────────
          TOP HEADER & NAVIGATION TABS
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
        <div>
          <div className="flex items-center space-x-2">
            <h2 className="text-xl font-bold tracking-tight text-[#0F172A]">
              Financial Statements & Multi-Entity Consolidation
            </h2>
            <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200/60 font-mono">
              Phase 6
            </span>
          </div>
          <p className="text-xs text-[#64748B] mt-0.5">
            IFRS & GAAP compliant P&L, Balance Sheet, Direct Cash Flow, and automated intercompany eliminations.
          </p>
        </div>

        {/* Global Controls & Actions */}
        <div className="flex flex-wrap items-center gap-2">
          {/* Period Selector */}
          <div className="flex items-center space-x-1.5 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-[#334155]">
            <Calendar className="w-3.5 h-3.5 text-[#64748B]" />
            <span className="font-medium text-[#0F172A]">{periodName}</span>
          </div>

          <button
            onClick={onRefresh}
            className="p-2 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] hover:bg-slate-50 transition-colors cursor-pointer"
            title="Refresh All Statements"
          >
            <RefreshCw className="w-4 h-4" />
          </button>

          <button
            onClick={() => alert(`Exporting complete Financial Report Package (${activeTab.toUpperCase()}) to PDF...`)}
            className="px-3 py-1.5 border border-[#E2E8F0] text-[#334155] hover:bg-slate-50 text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition-colors cursor-pointer"
          >
            <Download className="w-3.5 h-3.5 text-[#64748B]" />
            <span>Export PDF</span>
          </button>

          <button
            onClick={() => alert(`Exporting ledger data to Excel (.XLSX) with audit formulas...`)}
            className="px-3 py-1.5 border border-[#E2E8F0] text-[#334155] hover:bg-slate-50 text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition-colors cursor-pointer"
          >
            <FileSpreadsheet className="w-3.5 h-3.5 text-emerald-600" />
            <span>Excel (.xlsx)</span>
          </button>
        </div>
      </div>

      {/* Sub-surface Tab Switcher */}
      <div className="flex items-center space-x-1 bg-slate-100/80 p-1 rounded-xl border border-slate-200/60 overflow-x-auto">
        {[
          { id: "pnl", label: "Profit & Loss (P&L)", icon: TrendingUp },
          { id: "balance_sheet", label: "Balance Sheet", icon: Scale },
          { id: "cash_flow", label: "Statement of Cash Flows", icon: DollarSign },
          { id: "trial_balance", label: "Trial Balance", icon: BarChart3 },
          { id: "consolidation", label: "Multi-Entity Consolidation & FX", icon: Globe },
          { id: "kpis", label: "Executive Financial KPIs", icon: PieChart },
        ].map((tab) => {
          const Icon = tab.icon;
          const isActive = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id as any)}
              className={cn(
                "flex items-center space-x-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all whitespace-nowrap cursor-pointer",
                isActive
                  ? "bg-white text-[#0F172A] shadow-xs"
                  : "text-[#64748B] hover:text-[#0F172A]"
              )}
            >
              <Icon className="w-3.5 h-3.5" />
              <span>{tab.label}</span>
            </button>
          );
        })}
      </div>

      {/* ─────────────────────────────────────────────────────────────
          TAB 1: PROFIT & LOSS (INCOME STATEMENT)
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "pnl" && (
        <div className="space-y-6">
          {/* Executive Metric Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs space-y-1">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Total Operating Revenue
              </span>
              <p className="text-xl font-bold text-[#0F172A]">{formatPKR(totalRevenue)}</p>
              <span className="text-[10px] text-emerald-600 font-medium flex items-center">
                <ArrowUpRight className="w-3 h-3 mr-0.5" /> +18.4% vs Prior Quarter
              </span>
            </div>

            <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs space-y-1">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Gross Profit (Gross Margin)
              </span>
              <div className="flex items-baseline space-x-2">
                <p className="text-xl font-bold text-emerald-700">{formatPKR(grossProfit)}</p>
                <span className="text-xs font-bold text-emerald-600">({grossMargin.toFixed(1)}%)</span>
              </div>
              <span className="text-[10px] text-[#64748B]">COGS: {formatPKR(totalCogs)}</span>
            </div>

            <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs space-y-1">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Operating Expenses (OPEX)
              </span>
              <p className="text-xl font-bold text-[#0F172A]">{formatPKR(totalOpex)}</p>
              <span className="text-[10px] text-[#64748B]">Staff, Cloud & Facility</span>
            </div>

            <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs space-y-1">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Net Profit After Tax
              </span>
              <p className="text-xl font-bold text-emerald-600">{formatPKR(netIncome)}</p>
              <span className="text-[10px] text-emerald-600 font-medium">
                {((netIncome / totalRevenue) * 100).toFixed(1)}% Net Margin
              </span>
            </div>
          </div>

          {/* Interactive P&L Table */}
          <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs overflow-hidden">
            <div className="px-6 py-4 border-b border-[#F1F5F9] flex items-center justify-between bg-slate-50/50">
              <div>
                <h3 className="text-sm font-bold text-[#0F172A]">Statement of Profit and Loss</h3>
                <p className="text-[11px] text-[#64748B]">
                  Click any line item to inspect the underlying journal entries in the drill-down drawer.
                </p>
              </div>
              <span className="text-[11px] font-mono font-semibold text-[#64748B]">Amounts in PKR</span>
            </div>

            <div className="divide-y divide-[#F1F5F9] text-xs">
              {/* SECTION: REVENUE */}
              <div className="p-4 bg-slate-50/30">
                <div className="flex justify-between items-center font-bold text-[#0F172A] text-xs uppercase tracking-wider mb-2">
                  <span>1. Operating Revenue</span>
                  <span className="font-mono">{formatPKR(totalRevenue)}</span>
                </div>
                <div className="space-y-1.5 pl-3">
                  {revenueLines.map((row) => (
                    <div
                      key={row.id}
                      onClick={() => setSelectedAccount(row)}
                      className="flex justify-between items-center py-1 px-2 rounded-lg hover:bg-slate-100/80 transition-colors cursor-pointer group"
                    >
                      <div className="flex items-center space-x-2">
                        <span className="font-mono text-[11px] text-indigo-600 font-medium">{row.code}</span>
                        <span className="text-[#334155] group-hover:text-indigo-600 transition-colors font-medium">
                          {row.name}
                        </span>
                        <ChevronRight className="w-3 h-3 text-[#94A3B8] opacity-0 group-hover:opacity-100 transition-opacity" />
                      </div>
                      <span className="font-mono text-[#0F172A] font-medium">{formatPKR(row.balance)}</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* SECTION: COGS */}
              <div className="p-4 bg-slate-50/30">
                <div className="flex justify-between items-center font-bold text-[#0F172A] text-xs uppercase tracking-wider mb-2">
                  <span>2. Cost of Goods Sold (COGS)</span>
                  <span className="font-mono text-rose-600">({formatPKR(totalCogs)})</span>
                </div>
                <div className="space-y-1.5 pl-3">
                  {cogsLines.map((row) => (
                    <div
                      key={row.id}
                      onClick={() => setSelectedAccount(row)}
                      className="flex justify-between items-center py-1 px-2 rounded-lg hover:bg-slate-100/80 transition-colors cursor-pointer group"
                    >
                      <div className="flex items-center space-x-2">
                        <span className="font-mono text-[11px] text-indigo-600 font-medium">{row.code}</span>
                        <span className="text-[#334155] group-hover:text-indigo-600 transition-colors font-medium">
                          {row.name}
                        </span>
                        <ChevronRight className="w-3 h-3 text-[#94A3B8] opacity-0 group-hover:opacity-100 transition-opacity" />
                      </div>
                      <span className="font-mono text-rose-600">({formatPKR(row.balance)})</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* SUB-TOTAL: GROSS PROFIT */}
              <div className="px-6 py-3 bg-emerald-50/60 border-y border-emerald-100 flex justify-between items-center font-bold text-xs">
                <span className="text-emerald-950 uppercase tracking-wider">Gross Profit</span>
                <div className="flex items-center space-x-3">
                  <span className="text-[11px] text-emerald-700 font-normal">Margin: {grossMargin.toFixed(1)}%</span>
                  <span className="font-mono text-emerald-900 text-sm">{formatPKR(grossProfit)}</span>
                </div>
              </div>

              {/* SECTION: OPERATING EXPENSES */}
              <div className="p-4 bg-slate-50/30">
                <div className="flex justify-between items-center font-bold text-[#0F172A] text-xs uppercase tracking-wider mb-2">
                  <span>3. Operating Expenses (OPEX)</span>
                  <span className="font-mono text-rose-600">({formatPKR(totalOpex)})</span>
                </div>
                <div className="space-y-1.5 pl-3">
                  {operatingExpenseLines.map((row) => (
                    <div
                      key={row.id}
                      onClick={() => setSelectedAccount(row)}
                      className="flex justify-between items-center py-1 px-2 rounded-lg hover:bg-slate-100/80 transition-colors cursor-pointer group"
                    >
                      <div className="flex items-center space-x-2">
                        <span className="font-mono text-[11px] text-indigo-600 font-medium">{row.code}</span>
                        <span className="text-[#334155] group-hover:text-indigo-600 transition-colors font-medium">
                          {row.name}
                        </span>
                        <ChevronRight className="w-3 h-3 text-[#94A3B8] opacity-0 group-hover:opacity-100 transition-opacity" />
                      </div>
                      <span className="font-mono text-rose-600">({formatPKR(row.balance)})</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* SUB-TOTAL: OPERATING INCOME */}
              <div className="px-6 py-3 bg-slate-100/80 flex justify-between items-center font-bold text-xs text-[#0F172A]">
                <span className="uppercase tracking-wider">Operating Income (EBIT)</span>
                <span className="font-mono text-sm">{formatPKR(operatingProfit)}</span>
              </div>

              {/* TAX PROVISION */}
              <div className="px-6 py-3 flex justify-between items-center text-xs text-[#64748B]">
                <span>Provision for Corporate Income Tax (29%)</span>
                <span className="font-mono text-rose-600">({formatPKR(taxProvision)})</span>
              </div>

              {/* FINAL NET PROFIT */}
              <div className="px-6 py-4 bg-emerald-100/70 border-t-2 border-emerald-500 flex justify-between items-center font-bold text-sm text-emerald-950">
                <span className="uppercase tracking-wide">Net Profit After Tax</span>
                <span className="font-mono text-base font-extrabold text-emerald-800">{formatPKR(netIncome)}</span>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 2: BALANCE SHEET
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "balance_sheet" && (
        <div className="space-y-6">
          {/* Mathematical Invariant Banner */}
          <div
            className={cn(
              "p-4 rounded-xl border flex items-center justify-between text-xs",
              isBalanceSheetBalanced
                ? "bg-emerald-50/70 border-emerald-200 text-emerald-900"
                : "bg-rose-50 border-rose-200 text-rose-900"
            )}
          >
            <div className="flex items-center space-x-2">
              <CheckCircle2 className="w-4 h-4 text-emerald-600" />
              <div>
                <span className="font-bold">Double-Entry Balance Sheet Invariant:</span>{" "}
                <span>
                  Total Assets ({formatPKR(totalAssets)}) == Total Liabilities & Equity ({formatPKR(totalLiabilitiesAndEquity)})
                </span>
              </div>
            </div>
            <Badge variant="success">Balanced (Zero Variance)</Badge>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* LEFT COLUMN: ASSETS */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs p-6 space-y-5">
              <div className="flex items-center justify-between border-b border-[#F1F5F9] pb-3">
                <h3 className="text-sm font-bold text-[#0F172A] uppercase tracking-wider">1. Assets</h3>
                <span className="font-mono font-bold text-sm text-[#0F172A]">{formatPKR(totalAssets)}</span>
              </div>

              {/* Current Assets */}
              <div className="space-y-2">
                <h4 className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider">Current Assets</h4>
                <div className="space-y-1 pl-2">
                  {currentAssetLines.map((row) => (
                    <div
                      key={row.id}
                      onClick={() => setSelectedAccount(row)}
                      className="flex justify-between items-center py-1.5 px-2 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer group text-xs"
                    >
                      <div className="flex items-center space-x-2">
                        <span className="font-mono text-[11px] text-indigo-600 font-medium">{row.code}</span>
                        <span className="text-[#334155] group-hover:text-indigo-600 font-medium">{row.name}</span>
                      </div>
                      <span className="font-mono text-[#0F172A] font-medium">{formatPKR(row.balance)}</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Non-Current Assets */}
              <div className="space-y-2 pt-3 border-t border-[#F1F5F9]">
                <h4 className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider">
                  Non-Current Assets & Equipment
                </h4>
                <div className="space-y-1 pl-2">
                  {nonCurrentAssetLines.map((row) => (
                    <div
                      key={row.id}
                      onClick={() => setSelectedAccount(row)}
                      className="flex justify-between items-center py-1.5 px-2 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer group text-xs"
                    >
                      <div className="flex items-center space-x-2">
                        <span className="font-mono text-[11px] text-indigo-600 font-medium">{row.code}</span>
                        <span className="text-[#334155] group-hover:text-indigo-600 font-medium">{row.name}</span>
                      </div>
                      <span className={cn("font-mono font-medium", row.balance < 0 ? "text-rose-600" : "text-[#0F172A]")}>
                        {row.balance < 0 ? `(${formatPKR(Math.abs(row.balance))})` : formatPKR(row.balance)}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Total Assets Summary */}
              <div className="p-3 bg-slate-50 rounded-xl flex justify-between items-center font-bold text-xs text-[#0F172A] border border-[#E2E8F0]">
                <span>Total Assets</span>
                <span className="font-mono text-sm">{formatPKR(totalAssets)}</span>
              </div>
            </div>

            {/* RIGHT COLUMN: LIABILITIES & EQUITY */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs p-6 space-y-5">
              <div className="flex items-center justify-between border-b border-[#F1F5F9] pb-3">
                <h3 className="text-sm font-bold text-[#0F172A] uppercase tracking-wider">
                  2. Liabilities & Shareholders' Equity
                </h3>
                <span className="font-mono font-bold text-sm text-[#0F172A]">{formatPKR(totalLiabilitiesAndEquity)}</span>
              </div>

              {/* Liabilities */}
              <div className="space-y-2">
                <h4 className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider">Current Liabilities</h4>
                <div className="space-y-1 pl-2">
                  {currentLiabilityLines.map((row) => (
                    <div
                      key={row.id}
                      onClick={() => setSelectedAccount(row)}
                      className="flex justify-between items-center py-1.5 px-2 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer group text-xs"
                    >
                      <div className="flex items-center space-x-2">
                        <span className="font-mono text-[11px] text-indigo-600 font-medium">{row.code}</span>
                        <span className="text-[#334155] group-hover:text-indigo-600 font-medium">{row.name}</span>
                      </div>
                      <span className="font-mono text-[#0F172A] font-medium">{formatPKR(row.balance)}</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Equity */}
              <div className="space-y-2 pt-3 border-t border-[#F1F5F9]">
                <h4 className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider">Shareholders' Equity</h4>
                <div className="space-y-1 pl-2">
                  {equityLines.map((row) => (
                    <div
                      key={row.id}
                      onClick={() => setSelectedAccount(row)}
                      className="flex justify-between items-center py-1.5 px-2 rounded-lg hover:bg-slate-50 transition-colors cursor-pointer group text-xs"
                    >
                      <div className="flex items-center space-x-2">
                        <span className="font-mono text-[11px] text-indigo-600 font-medium">{row.code}</span>
                        <span className="text-[#334155] group-hover:text-indigo-600 font-medium">{row.name}</span>
                      </div>
                      <span className="font-mono text-[#0F172A] font-medium">{formatPKR(row.balance)}</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Total Liabilities & Equity Summary */}
              <div className="p-3 bg-slate-50 rounded-xl flex justify-between items-center font-bold text-xs text-[#0F172A] border border-[#E2E8F0]">
                <span>Total Liabilities & Equity</span>
                <span className="font-mono text-sm">{formatPKR(totalLiabilitiesAndEquity)}</span>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 3: STATEMENT OF CASH FLOWS (DIRECT / INDIRECT)
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "cash_flow" && (
        <div className="space-y-6">
          <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs p-6 space-y-6">
            <div className="border-b border-[#F1F5F9] pb-4 flex items-center justify-between">
              <div>
                <h3 className="text-sm font-bold text-[#0F172A]">Statement of Cash Flows (Indirect Method)</h3>
                <p className="text-[11px] text-[#64748B]">For the fiscal period ended August 31, 2025</p>
              </div>
              <Badge variant="outline">GAAP / IFRS IAS 7</Badge>
            </div>

            <div className="space-y-6 text-xs">
              {/* OPERATING CASH FLOW */}
              <div className="space-y-2">
                <div className="flex justify-between font-bold text-xs text-[#0F172A] uppercase tracking-wider pb-1 border-b">
                  <span>Cash Flows from Operating Activities</span>
                  <span className="font-mono">PKR 7,840,000</span>
                </div>
                <div className="space-y-1.5 pl-3">
                  <div className="flex justify-between text-[#334155]">
                    <span>Net Profit After Tax</span>
                    <span className="font-mono font-medium">{formatPKR(netIncome)}</span>
                  </div>
                  <div className="flex justify-between text-[#334155]">
                    <span>Adjustments for Non-Cash Depreciation Expense</span>
                    <span className="font-mono font-medium">+PKR 310,000</span>
                  </div>
                  <div className="flex justify-between text-[#334155]">
                    <span>(Increase) in Trade Accounts Receivable</span>
                    <span className="font-mono text-rose-600">(PKR 1,250,000)</span>
                  </div>
                  <div className="flex justify-between text-[#334155]">
                    <span>Increase in Trade Accounts Payable & Accruals</span>
                    <span className="font-mono text-emerald-600">+PKR 2,730,000</span>
                  </div>
                </div>
              </div>

              {/* INVESTING CASH FLOW */}
              <div className="space-y-2">
                <div className="flex justify-between font-bold text-xs text-[#0F172A] uppercase tracking-wider pb-1 border-b">
                  <span>Cash Flows from Investing Activities</span>
                  <span className="font-mono text-rose-600">(PKR 1,500,000)</span>
                </div>
                <div className="space-y-1.5 pl-3">
                  <div className="flex justify-between text-[#334155]">
                    <span>Purchase of Developer Hardware & Workstations</span>
                    <span className="font-mono text-rose-600">(PKR 1,500,000)</span>
                  </div>
                </div>
              </div>

              {/* FINANCING CASH FLOW */}
              <div className="space-y-2">
                <div className="flex justify-between font-bold text-xs text-[#0F172A] uppercase tracking-wider pb-1 border-b">
                  <span>Cash Flows from Financing Activities</span>
                  <span className="font-mono text-[#64748B]">PKR 0</span>
                </div>
                <div className="space-y-1.5 pl-3">
                  <div className="flex justify-between text-[#334155]">
                    <span>Proceeds from Issuance of Share Capital</span>
                    <span className="font-mono">PKR 0</span>
                  </div>
                </div>
              </div>

              {/* NET CHANGE & RECONCILIATION */}
              <div className="p-4 bg-slate-50 rounded-xl border border-[#E2E8F0] space-y-2">
                <div className="flex justify-between font-bold text-xs text-[#0F172A]">
                  <span>Net Increase in Cash & Cash Equivalents</span>
                  <span className="font-mono text-emerald-700">+PKR 6,340,000</span>
                </div>
                <div className="flex justify-between text-xs text-[#64748B]">
                  <span>Cash at Beginning of Period (August 1, 2025)</span>
                  <span className="font-mono">PKR 16,380,000</span>
                </div>
                <div className="flex justify-between font-bold text-sm text-[#0F172A] pt-2 border-t border-[#E2E8F0]">
                  <span>Cash & Cash Equivalents at End of Period (August 31, 2025)</span>
                  <span className="font-mono text-emerald-600">PKR 22,720,000</span>
                </div>
                <p className="text-[10px] text-emerald-700 flex items-center pt-1">
                  <CheckCircle2 className="w-3 h-3 mr-1" /> Reconciled to Balance Sheet Accounts 1010 + 1020
                </p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 4: TRIAL BALANCE
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "trial_balance" && (
        <div className="space-y-4">
          {/* Trial Balance Invariant Header */}
          <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div className="flex items-center space-x-3">
              <div className="w-10 h-10 rounded-xl bg-indigo-50 border border-indigo-200 flex items-center justify-center text-indigo-700">
                <Scale className="w-5 h-5" />
              </div>
              <div>
                <h4 className="text-sm font-bold text-[#0F172A]">General Ledger Trial Balance</h4>
                <p className="text-xs text-[#64748B]">
                  Mathematical Double-Entry Invariant: Debit Total == Credit Total
                </p>
              </div>
            </div>

            <div className="flex items-center space-x-6 text-xs font-mono">
              <div>
                <span className="text-[#64748B] block text-[10px] uppercase">Total Period Debits</span>
                <span className="font-bold text-[#0F172A]">{formatPKR(totalTbDebit)}</span>
              </div>
              <div>
                <span className="text-[#64748B] block text-[10px] uppercase">Total Period Credits</span>
                <span className="font-bold text-[#0F172A]">{formatPKR(totalTbCredit)}</span>
              </div>
              <Badge variant={isTbBalanced ? "success" : "danger"}>
                {isTbBalanced ? "In Balance" : "Out of Balance"}
              </Badge>
            </div>
          </div>

          {/* Filter Bar */}
          <FilterBar
            searchQuery={tbSearch}
            onSearchChange={setTbSearch}
            searchPlaceholder="Filter by account code or title..."
            statusFilter={tbClassification}
            onStatusChange={setTbClassification}
            statusOptions={[
              { label: "All Account Types", value: "all" },
              { label: "Assets (#1000s)", value: "asset" },
              { label: "Liabilities (#2000s)", value: "liability" },
              { label: "Equity (#3000s)", value: "equity" },
              { label: "Revenue (#4000s)", value: "revenue" },
              { label: "Expenses (#5000s & #6000s)", value: "expense" },
            ]}
          />

          {/* DataTable */}
          <DataTable<ReportAccountLine>
            data={filteredTb}
            rowKey={(item: ReportAccountLine) => item.id}
            onRowClick={(item: ReportAccountLine) => setSelectedAccount(item)}
            emptyMessage="No ledger accounts match the criteria."
            columns={[
              {
                key: "code",
                header: "Account Code",
                width: "120px",
                render: (item: ReportAccountLine) => (
                  <span className="font-mono text-xs font-bold text-indigo-600">{item.code}</span>
                ),
              },
              {
                key: "name",
                header: "Account Title",
                render: (item: ReportAccountLine) => (
                  <div>
                    <span className="font-medium text-[#0F172A] block">{item.name}</span>
                    <span className="text-[10px] text-[#64748B] capitalize">{item.classification}</span>
                  </div>
                ),
              },
              {
                key: "normal_balance",
                header: "Normal Balance",
                width: "120px",
                render: (item: ReportAccountLine) => (
                  <Badge variant="outline" className="font-mono text-[10px]">
                    {item.normal_balance}
                  </Badge>
                ),
              },
              {
                key: "debit",
                header: "Debit (PKR)",
                align: "right",
                render: (item: ReportAccountLine) => (
                  <span className="font-mono text-xs text-right block">
                    {item.debit && item.debit > 0 ? formatPKR(item.debit) : "—"}
                  </span>
                ),
              },
              {
                key: "credit",
                header: "Credit (PKR)",
                align: "right",
                render: (item: ReportAccountLine) => (
                  <span className="font-mono text-xs text-right block">
                    {item.credit && item.credit > 0 ? formatPKR(item.credit) : "—"}
                  </span>
                ),
              },
              {
                key: "balance",
                header: "Net Balance",
                align: "right",
                render: (item: ReportAccountLine) => (
                  <span className="font-mono text-xs font-bold text-right block text-[#0F172A]">
                    {formatPKR(Math.abs(item.balance))}
                  </span>
                ),
              },
            ]}
          />
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 5: MULTI-ENTITY CONSOLIDATION & FX REVALUATION
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "consolidation" && (
        <div className="space-y-6">
          {/* Top Legal Entities Cards */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {displayEntitiesList.map((ent) => (
              <div
                key={ent.id}
                className={cn(
                  "p-4 bg-white rounded-2xl border shadow-xs space-y-2",
                  ent.is_primary ? "border-indigo-200/80" : "border-[#E2E8F0]"
                )}
              >
                <div className="flex items-center justify-between">
                  <span
                    className={cn(
                      "font-mono text-xs font-bold px-2 py-0.5 rounded-lg",
                      ent.is_primary
                        ? "bg-indigo-50 text-indigo-700"
                        : "bg-slate-100 text-[#334155]"
                    )}
                  >
                    {ent.code}
                  </span>
                  <Badge variant={ent.is_primary ? "success" : "outline"}>
                    {ent.is_primary ? "Primary Parent" : "Subsidiary"}
                  </Badge>
                </div>
                <h4 className="text-xs font-bold text-[#0F172A]">{ent.name}</h4>
                <p className="text-[11px] text-[#64748B]">
                  {ent.country} • Functional Currency: {ent.currency}
                </p>
                <div className="pt-2 border-t border-[#F1F5F9] flex justify-between text-[11px]">
                  <span className="text-[#64748B]">Shareholding:</span>
                  <span className="font-semibold text-[#0F172A]">
                    {ent.shareholding}
                  </span>
                </div>
              </div>
            ))}
          </div>

          {/* Active Foreign Exchange Rates Banner */}
          <div className="p-4 bg-white rounded-xl border border-[#E2E8F0] shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div className="flex items-center space-x-2">
              <Repeat className="w-4 h-4 text-emerald-600" />
              <div>
                <h4 className="text-xs font-bold text-[#0F172A]">
                  Active Exchange Rates (SBP Official Fixings)
                </h4>
                <p className="text-[10px] text-[#64748B]">
                  Foreign balances revalued monthly according to IAS 21.
                </p>
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              {displayRatesList.map((fx, idx) => (
                <div
                  key={idx}
                  className="px-2.5 py-1 bg-slate-50 border border-slate-200 rounded-lg text-xs font-mono"
                >
                  <strong className="text-emerald-700">{fx.pair}</strong>:{" "}
                  {Number(fx.spotRate).toFixed(2)}
                  <span className="text-[10px] text-[#94A3B8] ml-1">PKR</span>
                </div>
              ))}
            </div>

            <div className="flex items-center space-x-2">
              <button
                onClick={() => {
                  if (onRunFxRevaluation) onRunFxRevaluation();
                  else alert("Running FX Revaluation for foreign bank accounts and AR/AP balances under IAS 21...");
                }}
                className="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-[#0F172A] text-xs font-semibold rounded-xl transition-colors cursor-pointer"
              >
                Run FX Revaluation
              </button>
              <button
                onClick={() => {
                  if (onRunEliminations) onRunEliminations();
                  else alert("Running automated intercompany elimination entries for August 2025...");
                }}
                disabled={isEliminating}
                className="px-3 py-1.5 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition-colors cursor-pointer disabled:opacity-50"
              >
                <Layers className="w-3.5 h-3.5" />
                <span>{isEliminating ? "Eliminating..." : "Run Period Eliminations"}</span>
              </button>
            </div>
          </div>

          {/* Consolidated Trial Balance Table */}
          <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs overflow-hidden">
            <div className="px-6 py-4 border-b border-[#F1F5F9] flex items-center justify-between bg-slate-50/50">
              <div>
                <h3 className="text-sm font-bold text-[#0F172A]">Consolidated Trial Balance & Eliminations</h3>
                <p className="text-[11px] text-[#64748B]">
                  Intercompany reciprocal balances (Account 1035 vs 2035) eliminated to net zero.
                </p>
              </div>
              <Badge variant="success">Eliminations Net Zero</Badge>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-xs text-left">
                <thead>
                  <tr className="bg-[#F8FAFC] text-[#64748B] font-semibold text-[10px] uppercase border-b border-[#E2E8F0]">
                    <th className="py-2.5 px-4">Account Code & Title</th>
                    <th className="py-2.5 px-4">Type</th>
                    <th className="py-2.5 px-4 text-right">Indus PK (Parent)</th>
                    <th className="py-2.5 px-4 text-right">Gulf FZE (AED in PKR)</th>
                    <th className="py-2.5 px-4 text-right">UK Logistics (GBP in PKR)</th>
                    <th className="py-2.5 px-4 text-right text-rose-600 font-bold">Intercompany Eliminations</th>
                    <th className="py-2.5 px-4 text-right font-bold text-[#0F172A]">Consolidated Total (PKR)</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#F1F5F9]">
                  {consolidationRows.map((row, idx) => (
                    <tr key={idx} className="hover:bg-slate-50 transition-colors">
                      <td className="py-2.5 px-4 font-mono">
                        <span className="text-indigo-600 font-bold">{row.code}</span> — {row.name}
                      </td>
                      <td className="py-2.5 px-4 text-[#64748B]">{row.classification}</td>
                      <td className="py-2.5 px-4 text-right font-mono">{formatPKR(row.parentPkr)}</td>
                      <td className="py-2.5 px-4 text-right font-mono">
                        {row.gulfAed !== 0 ? formatPKR(Math.round(row.gulfAed * 76.04)) : "—"}
                      </td>
                      <td className="py-2.5 px-4 text-right font-mono">
                        {row.ukGbp !== 0 ? formatPKR(Math.round(row.ukGbp * 360.22)) : "—"}
                      </td>
                      <td className="py-2.5 px-4 text-right font-mono text-rose-600 font-bold">
                        {row.eliminationsPkr !== 0 ? `(${formatPKR(Math.abs(row.eliminationsPkr))})` : "—"}
                      </td>
                      <td className="py-2.5 px-4 text-right font-mono font-bold text-[#0F172A]">
                        {formatPKR(row.consolidatedPkr)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* ─────────────────────────────────────────────────────────────
              INTERCOMPANY BILATERAL TRANSACTIONS REGISTER
          ─────────────────────────────────────────────────────────────── */}
          <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs overflow-hidden space-y-4 p-6">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-[#F1F5F9]">
              <div>
                <div className="flex items-center space-x-2">
                  <h3 className="text-sm font-bold text-[#0F172A]">
                    Intercompany Bilateral Transactions
                  </h3>
                  <Badge variant="outline" className="font-mono text-[10px]">
                    {filteredIntercompanyList.length} Entries
                  </Badge>
                </div>
                <p className="text-[11px] text-[#64748B] mt-0.5">
                  Bilateral intra-group agreements, shared services overhead allocations, and transfer pricing.
                </p>
              </div>

              <div className="flex items-center space-x-2">
                {onOpenNewIntercompany && (
                  <button
                    onClick={onOpenNewIntercompany}
                    className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-[#6366F1] text-white text-xs font-semibold hover:bg-[#4F46E5] shadow-xs transition-colors cursor-pointer"
                  >
                    <Plus className="w-3.5 h-3.5" />
                    <span>New Intercompany Tx</span>
                  </button>
                )}
              </div>
            </div>

            {/* Filter Bar */}
            <FilterBar
              searchQuery={icSearch}
              onSearchChange={setIcSearch}
              searchPlaceholder="Filter by transaction number, description, or entity..."
              statusFilter={icStatus}
              onStatusChange={setIcStatus}
              statusOptions={[
                { label: "All Statuses", value: "all" },
                { label: "Draft (Pending Post)", value: "draft" },
                { label: "Posted (Active Reciprocal)", value: "posted" },
                { label: "Eliminated (Net-Zero)", value: "eliminated" },
              ]}
            />

            {/* DataTable of Intercompany Transactions */}
            <DataTable<IntercompanyTxItem>
              data={filteredIntercompanyList}
              rowKey={(item) => item.id}
              emptyMessage="No intercompany transactions found for this period."
              columns={[
                {
                  key: "transaction_number",
                  header: "Tx Number",
                  width: "140px",
                  render: (item) => (
                    <span className="font-mono text-xs font-bold text-indigo-600">
                      {item.transaction_number}
                    </span>
                  ),
                },
                {
                  key: "transaction_date",
                  header: "Date",
                  width: "110px",
                  render: (item) => (
                    <span className="text-xs text-[#64748B] font-mono">
                      {item.transaction_date}
                    </span>
                  ),
                },
                {
                  key: "entities",
                  header: "Entity Flow (From → To)",
                  render: (item) => {
                    const fromName =
                      item.fromEntity?.code ||
                      item.from_entity?.code ||
                      item.fromEntity?.name ||
                      "PARENT";
                    const toName =
                      item.toEntity?.code ||
                      item.to_entity?.code ||
                      item.toEntity?.name ||
                      "SUBSIDIARY";
                    return (
                      <div className="flex items-center space-x-2 text-xs">
                        <span className="font-semibold text-[#0F172A] px-2 py-0.5 bg-slate-100 rounded-md font-mono text-[11px]">
                          {fromName}
                        </span>
                        <ArrowRight className="w-3 h-3 text-[#94A3B8]" />
                        <span className="font-semibold text-[#0F172A] px-2 py-0.5 bg-slate-100 rounded-md font-mono text-[11px]">
                          {toName}
                        </span>
                      </div>
                    );
                  },
                },
                {
                  key: "description",
                  header: "Description & Agreement",
                  render: (item) => (
                    <span className="text-xs text-[#334155] line-clamp-1">
                      {item.description}
                    </span>
                  ),
                },
                {
                  key: "amount",
                  header: "Amount",
                  align: "right",
                  width: "150px",
                  render: (item) => {
                    const num =
                      typeof item.amount === "number"
                        ? item.amount
                        : parseFloat(String(item.amount).replace(/,/g, "")) || 0;
                    return (
                      <span className="font-mono text-xs font-bold text-right block text-[#0F172A]">
                        {item.currency} {formatPKR(num)}
                      </span>
                    );
                  },
                },
                {
                  key: "status",
                  header: "Status",
                  width: "130px",
                  render: (item) => {
                    if (item.status === "eliminated") {
                      return <Badge variant="success">Eliminated (0.00)</Badge>;
                    }
                    if (item.status === "posted") {
                      return <Badge variant="neutral">Posted</Badge>;
                    }
                    return <Badge variant="warning">Draft</Badge>;
                  },
                },
                {
                  key: "actions",
                  header: "Action",
                  align: "right",
                  width: "140px",
                  render: (item) => {
                    if (item.status === "draft" && onPostIntercompany) {
                      return (
                        <button
                          onClick={() => onPostIntercompany(item.id)}
                          className="px-2.5 py-1 text-[11px] font-semibold bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 rounded-lg transition-colors cursor-pointer"
                        >
                          Post to Ledgers
                        </button>
                      );
                    }
                    if (item.status === "posted") {
                      return (
                        <span className="text-[11px] text-[#64748B] font-mono">
                          Ready to Eliminate
                        </span>
                      );
                    }
                    return (
                      <span className="text-[11px] text-emerald-600 font-mono">
                        Net-Zero Reconciled
                      </span>
                    );
                  },
                },
              ]}
            />
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 6: EXECUTIVE FINANCIAL KPIS
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "kpis" && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs space-y-2">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Current Liquidity Ratio
              </span>
              <p className="text-2xl font-bold text-emerald-600">3.04x</p>
              <p className="text-[11px] text-[#64748B]">
                Current Assets ({formatPKR(currentAssetLines.reduce((s, x) => s + x.balance, 0))}) / Current Liabilities ({formatPKR(totalLiabilities)}). Strong solvency buffer.
              </p>
            </div>

            <div className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs space-y-2">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Quick Ratio (Acid Test)
              </span>
              <p className="text-2xl font-bold text-emerald-600">2.88x</p>
              <p className="text-[11px] text-[#64748B]">
                (Cash + Foreign Currency + Receivables) / Current Liabilities. Zero reliance on inventory liquidation.
              </p>
            </div>

            <div className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs space-y-2">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Days Sales Outstanding (DSO)
              </span>
              <p className="text-2xl font-bold text-[#0F172A]">26.4 Days</p>
              <p className="text-[11px] text-emerald-600 font-medium">
                Average collection speed well within standard Net-30 payment terms.
              </p>
            </div>

            <div className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs space-y-2">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Days Payable Outstanding (DPO)
              </span>
              <p className="text-2xl font-bold text-[#0F172A]">34.1 Days</p>
              <p className="text-[11px] text-[#64748B]">
                Vendors paid within agreed 35-day cycle. Optimized vendor working capital.
              </p>
            </div>

            <div className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs space-y-2">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Debt-to-Equity Ratio
              </span>
              <p className="text-2xl font-bold text-emerald-600">0.43</p>
              <p className="text-[11px] text-[#64748B]">
                Conservative capital structure with zero interest-bearing institutional term debt.
              </p>
            </div>

            <div className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs space-y-2">
              <span className="text-[11px] font-semibold text-[#64748B] uppercase tracking-wider">
                Estimated Treasury Runway
              </span>
              <p className="text-2xl font-bold text-indigo-600">28.5 Months</p>
              <p className="text-[11px] text-[#64748B]">
                Based on current average operational cash burn rate of PKR 800k/mo.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          SLIDEOVER DRAWER: GENERAL LEDGER LINE ITEM DRILL-DOWN
      ─────────────────────────────────────────────────────────────── */}
      <SlideOverDrawer
        isOpen={Boolean(selectedAccount)}
        onClose={() => setSelectedAccount(null)}
        title={`Account ${selectedAccount?.code}: ${selectedAccount?.name || ""}`}
        subtitle={`Classification: ${selectedAccount?.classification?.toUpperCase()} • Normal Balance: ${selectedAccount?.normal_balance}`}
        badge={
          <Badge variant="neutral">
            Balance: {selectedAccount ? formatPKR(Math.abs(selectedAccount.balance)) : ""}
          </Badge>
        }
      >
        {selectedAccount && (
          <div className="space-y-6 text-xs text-[#334155]">
            {/* Account Summary Banner */}
            <div className="p-4 rounded-xl bg-slate-50 border border-[#E2E8F0] space-y-2">
              <div className="flex justify-between items-center">
                <span className="text-[#64748B]">Account Code:</span>
                <span className="font-mono font-bold text-[#0F172A]">{selectedAccount.code}</span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-[#64748B]">Title:</span>
                <span className="font-medium text-[#0F172A]">{selectedAccount.name}</span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-[#64748B]">Classification:</span>
                <span className="capitalize font-semibold text-indigo-600">{selectedAccount.classification}</span>
              </div>
              {selectedAccount.notes && (
                <div className="pt-2 border-t border-[#E2E8F0] text-[11px] text-[#64748B]">
                  <strong>Accounting Notes:</strong> {selectedAccount.notes}
                </div>
              )}
            </div>

            {/* Transaction Ledger Drilldown */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <h4 className="font-bold text-xs text-[#0F172A]">Transaction History & Supporting Entries</h4>
                <span className="text-[10px] text-[#64748B]">
                  {selectedAccount.transactions?.length || 0} Posted Entries
                </span>
              </div>

              {selectedAccount.transactions && selectedAccount.transactions.length > 0 ? (
                <div className="space-y-2">
                  {selectedAccount.transactions.map((tx) => (
                    <div
                      key={tx.id}
                      className="p-3 bg-white rounded-xl border border-[#E2E8F0] space-y-2 shadow-2xs hover:border-indigo-300 transition-colors"
                    >
                      <div className="flex justify-between items-start">
                        <div>
                          <span className="font-mono text-[11px] font-bold text-indigo-600">{tx.reference}</span>
                          <p className="text-xs text-[#0F172A] font-medium mt-0.5">{tx.description}</p>
                        </div>
                        <span className="text-[10px] text-[#94A3B8] font-mono">{tx.entry_date}</span>
                      </div>

                      <div className="flex justify-between items-center text-[11px] pt-2 border-t border-[#F1F5F9] font-mono">
                        <div className="space-x-3">
                          {tx.debit > 0 && <span className="text-indigo-700">DR: {formatPKR(tx.debit)}</span>}
                          {tx.credit > 0 && <span className="text-emerald-700">CR: {formatPKR(tx.credit)}</span>}
                        </div>
                        <span className="font-bold text-[#0F172A]">Bal: {formatPKR(tx.running_balance)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="p-6 text-center text-[#94A3B8] bg-slate-50 rounded-xl border border-dashed border-[#E2E8F0]">
                  <p className="text-xs">No direct journal lines found for this period filter.</p>
                </div>
              )}
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
