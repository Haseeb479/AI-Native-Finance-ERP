"use client";

import React from "react";
import {
  Sparkles,
  ArrowRight,
  TrendingUp,
  Landmark,
  FileText,
  FilePenLine,
  CheckCircle2,
  AlertCircle,
  Clock,
  Zap,
  RefreshCw,
  Plus,
  ArrowUpRight,
  CheckSquare,
  BarChart3,
  BookOpen,
  PieChart,
  Layers,
  Check,
  Building,
  Info,
  Globe,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";
import { AttentionItem } from "../ui/AttentionStream";

interface LaunchpadViewProps {
  attentionItems: AttentionItem[];
  promptText: string;
  setPromptText: (text: string) => void;
  onAskAxiomAI: (prompt?: string) => void;
  copilotLoading: boolean;
  copilotResponse: {
    answer: string;
    keyMetrics?: Record<string, string>;
    suggestedActions?: string[];
  } | null;
  cashTotalPKR: number;
  arTotalPKR: number;
  apTotalPKR: number;
  netBurnPKR: number;
  closeProgressPercent: number;
  closeTasksRemaining: number;
  activePeriodName: string;
  onNavigate: (viewId: string) => void;
  onOpenImportStatement: () => void;
  onOpenCreateInvoice: () => void;
  onOpenCreateBill: () => void;
  onOpenAxiomConfig: () => void;
  userName?: string;
}

export function LaunchpadView({
  attentionItems,
  promptText,
  setPromptText,
  onAskAxiomAI,
  copilotLoading,
  copilotResponse,
  cashTotalPKR,
  arTotalPKR,
  apTotalPKR,
  netBurnPKR,
  closeProgressPercent,
  closeTasksRemaining,
  activePeriodName,
  onNavigate,
  onOpenImportStatement,
  onOpenCreateInvoice,
  onOpenCreateBill,
  onOpenAxiomConfig,
  userName = "Grace",
}: LaunchpadViewProps) {
  const quickPrompts = [
    "What is driving our net burn this month?",
    "Show HBL operating cash variance vs GL #1010",
    "List invoices overdue > 30 days for follow-up",
    "Calculate Section 153 WHT on pending AP bills",
  ];

  return (
    <div className="max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-7 flex flex-col space-y-7 animate-in fade-in duration-200">
      {/* ─────────────────────────────────────────────────────────────
          1. HEADER: GREETING & DATE
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-[28px] font-bold tracking-tight text-[#0F172A]">
            Hi, {userName}
          </h1>
          <p className="text-xs text-[#64748B] mt-0.5">
            Executive financial snapshot & automated operations overview.
          </p>
        </div>

        {/* Live Groq LPU Pill */}
        <button
          onClick={onOpenAxiomConfig}
          className="self-start sm:self-auto inline-flex items-center space-x-2 px-3 py-1.5 rounded-full text-xs font-medium bg-[#F1F5F9] hover:bg-[#E2E8F0] text-[#0F172A] border border-[#E2E8F0] transition-colors cursor-pointer"
        >
          <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
          <span className="font-semibold">Axiom AI</span>
          <span className="text-[#64748B]">• Groq LPU Connected</span>
        </button>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          2. TOP 4 METRIC CARDS (Matches PDF Pages 3 & 11)
      ─────────────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Card 1: ARR */}
        <div className="bg-[#F4F6FB] border border-[#E2E8F0]/80 rounded-2xl p-5 flex flex-col justify-between shadow-[0_1px_3px_rgba(0,0,0,0.02)] min-h-[170px]">
          <div>
            <div className="flex items-center justify-between text-xs font-semibold text-[#64748B]">
              <span className="flex items-center gap-1">
                ARR <Info className="w-3 h-3 text-[#94A3B8]" />
              </span>
            </div>
            <div className="mt-3 flex items-center justify-between">
              <div className="flex items-baseline space-x-1.5">
                <span className="text-3xl font-extrabold tracking-tight text-[#0F172A]">
                  $28.3M
                </span>
                <ArrowUpRight className="w-4 h-4 text-emerald-600 stroke-[2.5]" />
              </div>

              {/* Sparkline curve */}
              <div className="w-24 h-9">
                <svg viewBox="0 0 100 36" fill="none" className="w-full h-full">
                  <path
                    d="M 2 28 C 18 26, 32 18, 48 24 C 64 30, 78 8, 98 4"
                    stroke="#4F46E5"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                  />
                </svg>
              </div>
            </div>
          </div>

          <div className="mt-4 pt-3 border-t border-[#E2E8F0]/60 text-[10px] text-[#94A3B8] flex items-center justify-between">
            <span>As of 23:59, 30 Nov 2025</span>
            <span className="text-[#64748B]">vs previous mo</span>
          </div>
        </div>

        {/* Card 2: Cash Balance */}
        <div className="bg-[#F4F6FB] border border-[#E2E8F0]/80 rounded-2xl p-5 flex flex-col justify-between shadow-[0_1px_3px_rgba(0,0,0,0.02)] min-h-[170px]">
          <div>
            <div className="flex items-center justify-between text-xs font-semibold text-[#64748B]">
              <span className="flex items-center gap-1">
                Cash Balance <Info className="w-3 h-3 text-[#94A3B8]" />
              </span>
            </div>
            <div className="mt-3 flex items-center justify-between">
              <div className="flex items-baseline space-x-1.5">
                <span className="text-3xl font-extrabold tracking-tight text-[#0F172A]">
                  $30.8M
                </span>
                <ArrowUpRight className="w-4 h-4 text-emerald-600 stroke-[2.5]" />
              </div>

              {/* Vertical mini bar chart sparkline matching PDF */}
              <div className="flex items-end space-x-1.5 h-8">
                <div className="w-1.5 h-4 bg-slate-300/80 rounded-full" />
                <div className="w-1.5 h-5 bg-slate-300/80 rounded-full" />
                <div className="w-1.5 h-3 bg-slate-300/80 rounded-full" />
                <div className="w-1.5 h-6 bg-slate-300/80 rounded-full" />
                <div className="w-2 h-8 bg-indigo-600 rounded-full" />
                <div className="w-1.5 h-5 bg-slate-300/80 rounded-full" />
              </div>
            </div>
          </div>

          <div className="mt-4 pt-3 border-t border-[#E2E8F0]/60 text-[10px] text-[#94A3B8] flex items-center justify-between">
            <span>Live HBL + Meezan Accounts</span>
            <span className="text-emerald-700 font-semibold">100% Reconciled</span>
          </div>
        </div>

        {/* Card 3: Dual Metric (Outstanding AR & Net Burn) */}
        <div className="bg-[#F4F6FB] border border-[#E2E8F0]/80 rounded-2xl p-5 flex flex-col justify-between shadow-[0_1px_3px_rgba(0,0,0,0.02)] min-h-[170px]">
          <div>
            <div className="flex items-center justify-between text-xs font-semibold text-[#64748B]">
              <span className="flex items-center gap-1">
                Outstanding AR <Info className="w-3 h-3 text-[#94A3B8]" />
              </span>
              <span className="text-lg font-bold text-[#0F172A] flex items-center gap-1">
                $2.3M <ArrowUpRight className="w-3.5 h-3.5 text-emerald-600" />
              </span>
            </div>
          </div>

          <div className="mt-3 pt-3 border-t border-[#E2E8F0]/80">
            <div className="flex items-center justify-between text-xs font-semibold text-[#64748B]">
              <span className="flex items-center gap-1">
                Net Burn <Info className="w-3 h-3 text-[#94A3B8]" />
              </span>
              <span className="text-lg font-bold text-[#0F172A]">
                $1.2M
              </span>
            </div>
          </div>

          <div className="mt-3 pt-2 border-t border-[#E2E8F0]/60 text-[10px] text-[#94A3B8] flex items-center justify-between">
            <span>DSO: 28 Days</span>
            <span className="text-[#64748B]">Monthly OpEx Invariant</span>
          </div>
        </div>

        {/* Card 4: Dual Metric (Outstanding AP & Runway) */}
        <div className="bg-[#F4F6FB] border border-[#E2E8F0]/80 rounded-2xl p-5 flex flex-col justify-between shadow-[0_1px_3px_rgba(0,0,0,0.02)] min-h-[170px]">
          <div>
            <div className="flex items-center justify-between text-xs font-semibold text-[#64748B]">
              <span className="flex items-center gap-1">
                Outstanding AP <Info className="w-3 h-3 text-[#94A3B8]" />
              </span>
              <span className="text-lg font-bold text-[#0F172A] flex items-center gap-1">
                $3.5M <ArrowUpRight className="w-3.5 h-3.5 text-rose-500" />
              </span>
            </div>
          </div>

          <div className="mt-3 pt-3 border-t border-[#E2E8F0]/80">
            <div className="flex items-center justify-between text-xs font-semibold text-[#64748B]">
              <span className="flex items-center gap-1">
                Runway <Info className="w-3 h-3 text-[#94A3B8]" />
              </span>
              <span className="text-lg font-bold text-[#0F172A]">
                31 months
              </span>
            </div>
          </div>

          <div className="mt-3 pt-2 border-t border-[#E2E8F0]/60 text-[10px] text-[#94A3B8] flex items-center justify-between">
            <span>3-Way Match Active</span>
            <span className="text-emerald-700 font-semibold">Zero Cash Default Risk</span>
          </div>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          3. AI PROMPT SEARCH CAPSULE (Axiom AI Copilot)
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col items-center justify-center space-y-3 pt-1">
        <div className="w-full max-w-3xl relative">
          <div className="bg-white rounded-full border border-[#E2E8F0] px-4 py-2.5 flex items-center space-x-3 shadow-[0_2px_8px_rgba(0,0,0,0.04)] hover:border-indigo-300 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-100 transition-all">
            <div className="w-7 h-7 rounded-full bg-[#8B5CF6] text-white flex items-center justify-center shrink-0 shadow-xs">
              <span className="text-xs font-serif leading-none">❋</span>
            </div>

            <input
              type="text"
              value={promptText}
              onChange={(e) => setPromptText(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") onAskAxiomAI();
              }}
              placeholder="Ask Axiom AI: flux analysis, net burn drivers, pending close tasks, tax calculations..."
              className="flex-1 bg-transparent border-none outline-none text-[#0F172A] text-xs sm:text-sm placeholder:text-[#94A3B8]"
            />

            <button
              onClick={() => onAskAxiomAI()}
              disabled={copilotLoading}
              className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-3.5 py-1.5 rounded-full flex items-center space-x-1.5 transition-colors cursor-pointer disabled:opacity-50 shrink-0 shadow-xs"
            >
              {copilotLoading ? (
                <>
                  <span className="w-3.5 h-3.5 rounded-full border-2 border-white border-t-transparent animate-spin inline-block" />
                  <span>Thinking…</span>
                </>
              ) : (
                <>
                  <Sparkles className="w-3.5 h-3.5" />
                  <span>Ask Axiom</span>
                </>
              )}
            </button>
          </div>

          {/* Copilot Reasoning Output Drawer */}
          {copilotResponse && (
            <div className="mt-3 p-5 bg-white border border-[#E2E8F0] rounded-2xl shadow-lg flex flex-col space-y-3 animate-in fade-in slide-in-from-top-2 text-left">
              <div className="flex items-center justify-between pb-2 border-b border-[#F1F5F9]">
                <div className="flex items-center space-x-2">
                  <div className="w-5 h-5 rounded-full bg-[#8B5CF6] text-white flex items-center justify-center text-[10px]">
                    ✦
                  </div>
                  <span className="text-xs font-bold text-[#0F172A] uppercase tracking-wider">
                    Axiom AI Financial Reasoning (Groq LPU)
                  </span>
                </div>
                <button
                  onClick={() => onAskAxiomAI("")}
                  className="text-xs text-[#94A3B8] hover:text-[#0F172A] cursor-pointer"
                >
                  Dismiss
                </button>
              </div>

              <p className="text-xs sm:text-sm text-[#334155] leading-relaxed">
                {copilotResponse.answer}
              </p>

              {copilotResponse.keyMetrics && (
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-[#F1F5F9]">
                  {Object.entries(copilotResponse.keyMetrics).map(([k, v]) => (
                    <div key={k} className="p-2 bg-[#F8FAFC] rounded-lg">
                      <span className="text-[10px] text-[#94A3B8] block">{k}</span>
                      <span className="text-xs font-bold text-[#0F172A]">{v}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Quick Prompt Pills */}
          <div className="flex flex-wrap items-center justify-center gap-2 mt-2.5">
            {quickPrompts.map((prompt, idx) => (
              <button
                key={idx}
                onClick={() => {
                  setPromptText(prompt);
                  onAskAxiomAI(prompt);
                }}
                className="text-[11px] font-medium text-[#64748B] hover:text-[#0F172A] bg-white border border-[#E2E8F0] hover:border-[#CBD5E1] px-3 py-1 rounded-full shadow-2xs transition-colors cursor-pointer"
              >
                {prompt}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          4. TWO COLUMN SECTION: WORKFLOW SNAPSHOT & REPORTS (PDF Page 3/11)
      ─────────────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 pt-2">
        {/* Left Column (8 cols): Cockpit / Workflow snapshot + Tech Stack */}
        <div className="lg:col-span-8 flex flex-col space-y-6">
          {/* Cockpit Card */}
          <div className="bg-white border border-[#E2E8F0] rounded-2xl p-6 shadow-[0_1px_3px_rgba(0,0,0,0.02)]">
            <div className="flex items-center justify-between pb-4 border-b border-[#F1F5F9]">
              <h2 className="text-sm font-bold text-[#0F172A] tracking-tight">
                Workflow snapshot
              </h2>
              <span className="text-xs text-[#94A3B8]">Auto-sync active</span>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 pt-4">
              {/* Left Sub-column */}
              <div className="space-y-3.5">
                <div
                  onClick={() => onNavigate("banking")}
                  className="flex items-center justify-between text-xs py-1.5 hover:bg-[#F8FAFC] px-2 rounded-lg transition-colors cursor-pointer group"
                >
                  <span className="text-[#334155] group-hover:text-[#0F172A] font-medium">
                    Cash Transactions to be reconciled
                  </span>
                  <span className="font-bold text-[#0F172A] font-mono text-xs">
                    140
                  </span>
                </div>

                <div
                  onClick={() => onNavigate("invoices")}
                  className="flex items-center justify-between text-xs py-1.5 hover:bg-[#F8FAFC] px-2 rounded-lg transition-colors cursor-pointer group"
                >
                  <span className="text-[#334155] group-hover:text-[#0F172A] font-medium">
                    Invoices to be sent
                  </span>
                  <span className="font-bold text-[#0F172A] font-mono text-xs">
                    41
                  </span>
                </div>

                <div
                  onClick={() => onNavigate("revenue")}
                  className="flex items-center justify-between text-xs py-1.5 hover:bg-[#F8FAFC] px-2 rounded-lg transition-colors cursor-pointer group"
                >
                  <span className="text-[#334155] group-hover:text-[#0F172A] font-medium">
                    Contracts to be reviewed
                  </span>
                  <span className="font-bold text-[#0F172A] font-mono text-xs">
                    6
                  </span>
                </div>
              </div>

              {/* Right Sub-column */}
              <div className="space-y-3.5">
                <div
                  onClick={() => onNavigate("invoices")}
                  className="flex items-center justify-between text-xs py-1.5 hover:bg-[#F8FAFC] px-2 rounded-lg transition-colors cursor-pointer group"
                >
                  <span className="text-[#334155] group-hover:text-[#0F172A] font-medium">
                    Invoices reminders to be sent
                  </span>
                  <span className="font-bold text-[#0F172A] font-mono text-xs">
                    5
                  </span>
                </div>

                <div
                  onClick={() => onNavigate("bills")}
                  className="flex items-center justify-between text-xs py-1.5 hover:bg-[#F8FAFC] px-2 rounded-lg transition-colors cursor-pointer group"
                >
                  <span className="text-[#334155] group-hover:text-[#0F172A] font-medium">
                    Bills to be paid
                  </span>
                  <span className="font-bold text-[#0F172A] font-mono text-xs">
                    20
                  </span>
                </div>

                <div
                  onClick={() => onNavigate("close")}
                  className="flex items-center justify-between text-xs py-1.5 hover:bg-[#F8FAFC] px-2 rounded-lg transition-colors cursor-pointer group"
                >
                  <span className="text-[#334155] group-hover:text-[#0F172A] font-medium">
                    Close Tasks to be completed
                  </span>
                  <span className="font-bold text-[#0F172A] font-mono text-xs">
                    4
                  </span>
                </div>

                <div
                  onClick={() => onNavigate("close_flux")}
                  className="flex items-center justify-between text-xs py-1.5 hover:bg-[#F8FAFC] px-2 rounded-lg transition-colors cursor-pointer group"
                >
                  <span className="text-[#334155] group-hover:text-[#0F172A] font-medium flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse" />
                    Missing Accruals to review
                  </span>
                  <span className="font-bold text-amber-600 font-mono text-xs">
                    2
                  </span>
                </div>
              </div>
            </div>
          </div>

          {/* Tech Stack Monitoring Strip */}
          <div className="flex flex-col space-y-3">
            <h3 className="text-xs font-bold text-[#64748B] uppercase tracking-wider">
              Tech stack monitoring
            </h3>

            <div className="flex flex-wrap items-center gap-3">
              {/* Banking */}
              <div className="flex items-center space-x-2 px-3.5 py-2 bg-white border border-[#E2E8F0] rounded-xl shadow-2xs">
                <Landmark className="w-4 h-4 text-[#6366F1]" />
                <span className="text-xs font-semibold text-[#0F172A]">Banking</span>
                <span className="w-4 h-4 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-[10px] font-bold">
                  ✓
                </span>
              </div>

              {/* Ramp */}
              <div className="flex items-center space-x-2 px-3.5 py-2 bg-white border border-[#E2E8F0] rounded-xl shadow-2xs">
                <div className="w-4 h-4 rounded-full bg-slate-900 text-white flex items-center justify-center text-[9px] font-bold">
                  R
                </div>
                <span className="text-xs font-semibold text-[#0F172A]">Ramp</span>
                <span className="w-4 h-4 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-[10px] font-bold">
                  ✓
                </span>
              </div>

              {/* Stripe */}
              <div className="flex items-center space-x-2 px-3.5 py-2 bg-white border border-[#E2E8F0] rounded-xl shadow-2xs">
                <div className="w-4 h-4 rounded bg-[#635BFF] text-white flex items-center justify-center text-[9px] font-bold">
                  S
                </div>
                <span className="text-xs font-semibold text-[#0F172A]">Stripe</span>
                <span className="w-4 h-4 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-[10px] font-bold">
                  ✓
                </span>
              </div>

              {/* Gusto */}
              <div className="flex items-center space-x-2 px-3.5 py-2 bg-white border border-[#E2E8F0] rounded-xl shadow-2xs">
                <div className="w-4 h-4 rounded-full bg-rose-600 text-white flex items-center justify-center text-[9px] font-bold">
                  G
                </div>
                <span className="text-xs font-semibold text-[#0F172A]">Gusto</span>
                <span className="w-4 h-4 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-[10px] font-bold">
                  ✓
                </span>
              </div>

              {/* Salesforce */}
              <div className="flex items-center space-x-2 px-3.5 py-2 bg-white border border-[#E2E8F0] rounded-xl shadow-2xs">
                <div className="w-4 h-4 rounded bg-sky-500 text-white flex items-center justify-center text-[9px] font-bold">
                  SF
                </div>
                <span className="text-xs font-semibold text-[#0F172A]">Salesforce</span>
              </div>

              {/* Manage All Arrow Button */}
              <button
                onClick={() => onNavigate("integrations")}
                title="Manage Integrations"
                className="p-2 bg-white hover:bg-slate-50 border border-[#E2E8F0] rounded-xl text-[#64748B] hover:text-[#0F172A] transition-colors cursor-pointer"
              >
                <ArrowRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        {/* Right Column (4 cols): Reports Card (PDF Page 3/11) */}
        <div className="lg:col-span-4">
          <div className="bg-[#F4F6FB] border border-[#E2E8F0]/80 rounded-2xl p-5 space-y-3">
            <h3 className="text-sm font-bold text-[#0F172A] tracking-tight">
              Reports
            </h3>

            <div className="space-y-2 pt-1">
              {[
                { title: "Income Statement", nav: "reports", icon: FileText },
                { title: "Consolidated Multi-Entity TB", nav: "consolidation", icon: Globe },
                { title: "AR Aging", nav: "ar_aging", icon: Clock },
                { title: "MRR / ARR by Contract Type", nav: "revenue", icon: BarChart3 },
                { title: "Revenue Waterfall", nav: "revenue", icon: TrendingUp },
                { title: "SaaS P&L", nav: "reports", icon: PieChart },
              ].map((report, idx) => {
                const Icon = report.icon;
                return (
                  <button
                    key={idx}
                    onClick={() => onNavigate(report.nav)}
                    className="w-full bg-white hover:bg-[#F8FAFC] border border-[#E2E8F0]/70 p-3 rounded-xl flex items-center justify-between text-left transition-colors cursor-pointer group shadow-2xs"
                  >
                    <div className="flex items-center space-x-3">
                      <div className="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <Icon className="w-3.5 h-3.5" />
                      </div>
                      <span className="text-xs font-semibold text-[#0F172A] group-hover:text-indigo-600 transition-colors">
                        {report.title}
                      </span>
                    </div>
                    <ArrowRight className="w-3.5 h-3.5 text-[#94A3B8] group-hover:text-[#0F172A] transition-transform group-hover:translate-x-0.5" />
                  </button>
                );
              })}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
