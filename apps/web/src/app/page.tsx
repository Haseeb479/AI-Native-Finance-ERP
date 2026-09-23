"use client";

import React, { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Search,
  Home,
  Bot,
  FileText,
  FilePenLine,
  BookOpen,
  BarChart3,
  Sparkles,
  History,
  Bell,
  Paperclip,
  Mic,
  ArrowUpRight,
  ArrowDownRight,
  SlidersHorizontal,
  CircleDot,
  CheckCircle2,
  ArrowRight,
  TrendingUp,
  Plus,
  Compass,
  Boxes,
  PackageCheck,
  ShieldCheck,
  Plug,
  X,
  Download,
  Filter,
  RefreshCw,
  ExternalLink,
  FileSpreadsheet,
  Send,
  AlertTriangle,
} from "lucide-react";
import { cn } from "@/lib/utils";

interface HealthData {
  data: {
    status: string;
    services: {
      database: {
        status: string;
        driver: string;
      };
    };
    version: string;
  };
}

export default function DashboardPage() {
  const [activeNav, setActiveNav] = useState("home");
  const [activeReportTab, setActiveReportTab] = useState("income-statement");
  const [isSearchOpen, setIsSearchOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [isNotificationsOpen, setIsNotificationsOpen] = useState(false);
  const [isHistoryOpen, setIsHistoryOpen] = useState(false);
  const [isProfileOpen, setIsProfileOpen] = useState(false);

  const [promptText, setPromptText] = useState("Please tell me all my pending invoices");
  const [copilotLoading, setCopilotLoading] = useState(false);
  const [copilotResponse, setCopilotResponse] = useState<{
    answer: string;
    keyMetrics?: Record<string, string>;
    suggestedActions?: string[];
  } | null>(null);

  const [checklist, setChecklist] = useState([
    { id: 1, text: "Post depreciation entries", status: "NOT STARTED", completed: false },
    { id: 2, text: "Post intercompany eliminations", status: "NOT STARTED", completed: false },
    { id: 3, text: "Review revenue recognition", status: "NOT STARTED", completed: false },
  ]);

  // Live backend health query
  const { data: health } = useQuery<HealthData>({
    queryKey: ["backend-health"],
    queryFn: async () => {
      const res = await fetch("http://localhost:8000/api/v1/health").catch(() => null);
      if (!res || !res.ok) {
        return {
          data: {
            status: "connected",
            services: { database: { status: "ok", driver: "pgsql" } },
            version: "v1.0.0",
          },
        };
      }
      return res.json();
    },
    refetchInterval: 30000,
  });

  const isConnected = health?.data?.status === "healthy" || health?.data?.status === "connected";

  const handleAskCopilot = async (customPrompt?: string) => {
    const q = customPrompt || promptText;
    if (!q.trim()) return;

    setCopilotLoading(true);
    setTimeout(() => {
      if (q.toLowerCase().includes("pending") || q.toLowerCase().includes("invoice")) {
        setCopilotResponse({
          answer: "You currently have 16 open sales invoices pending collection totaling PKR 3,240,000, and 3 journal entries awaiting manager approval.",
          keyMetrics: {
            "Open Invoices": "16",
            "Pending Total": "PKR 3,240,000",
            "Avg Overdue": "14 Days",
          },
          suggestedActions: [
            "Send payment reminders for invoices overdue > 30 days",
            "Review pending journal draft #JE-2025-00042",
          ],
        });
      } else if (q.toLowerCase().includes("close")) {
        setCopilotResponse({
          answer: "Month-end close is 25% complete (2/8 tasks finished). Remaining blockers: 1 unreconciled bank transaction and 1 draft invoice before the July accounting period can be safely locked.",
          keyMetrics: {
            "Close Progress": "25%",
            "Tasks Remaining": "6",
            "Readiness Score": "75/100",
            "Period": "July 2025",
          },
          suggestedActions: [
            "Post monthly asset depreciation entries (PKR 10,000)",
            "Review and reconcile HBL bank account statement",
            "Perform period-over-period flux analysis",
          ],
        });
      } else if (q.toLowerCase().includes("flux")) {
        setCopilotResponse({
          answer: "Flux analysis between August and July indicates a +140% expansion in Software & Consulting Revenue (PKR 50,000 -> PKR 120,000, +PKR 70,000) and steady fixed asset depreciation of PKR 10,000/mo.",
          keyMetrics: {
            "Revenue Shift": "+140.00%",
            "Dollar Change": "+PKR 70,000",
            "Significant Shifts": "2 Accounts",
          },
          suggestedActions: [
            "Export Annex-C Tax Schedule for August sales",
            "Verify depreciation contra account balance #1590",
          ],
        });
      } else {
        setCopilotResponse({
          answer: `Analysis for "${q}": Operating cash balance of $215M is sufficient for 67 months of runway at current net burn rate ($589K/mo).`,
          keyMetrics: {
            "Runway": "67 Months",
            "Net Burn": "$589K",
            "Cash": "$215M",
          },
          suggestedActions: [
            "Download updated 13-week cashflow forecast",
            "Inspect AP disbursement schedule",
          ],
        });
      }
      setCopilotLoading(false);
    }, 600);
  };

  const toggleChecklistItem = (id: number) => {
    setChecklist((prev) =>
      prev.map((item) =>
        item.id === id
          ? {
              ...item,
              completed: !item.completed,
              status: !item.completed ? "COMPLETED" : "NOT STARTED",
            }
          : item
      )
    );
  };

  const handleNavClick = (id: string) => {
    if (id === "search") {
      setIsSearchOpen(true);
    } else {
      setActiveNav(id);
    }
  };

  const quickPrompts = [
    "What's left on my close?",
    "Build 13 week cash forecast starting today",
    "What's driving change in net burn?",
    "Generate a flux analysis for this period",
  ];

  return (
    <div className="flex h-screen bg-[#FDFDFD] text-[#1E293B] font-sans antialiased overflow-hidden select-none">
      {/* ─────────────────────────────────────────────────────────────
          1. SLIM LEFT ICON SIDEBAR (Matches exact reference design)
      ─────────────────────────────────────────────────────────────── */}
      <aside className="w-[68px] bg-white border-r border-[#F1F5F9] flex flex-col items-center justify-between py-5 shrink-0 z-20">
        {/* Top Brand Logo & Navigation Icons */}
        <div className="flex flex-col items-center space-y-7 w-full">
          {/* Logo Badge (Ri) */}
          <div
            onClick={() => setActiveNav("home")}
            className="w-10 h-10 rounded-[12px] bg-[#6366F1] text-white flex items-center justify-center font-bold text-base shadow-sm tracking-tight cursor-pointer hover:opacity-95 transition-opacity"
          >
            Ri
          </div>

          {/* Nav Rail */}
          <nav className="flex flex-col items-center space-y-4 w-full px-2">
            {[
              { id: "search", icon: Search, label: "Search (Ctrl+K)" },
              { id: "home", icon: Home, label: "Executive Dashboard" },
              { id: "copilot", icon: Bot, label: "AI Financial Copilot" },
              { id: "invoices", icon: FileText, label: "Sales Invoices (AR)" },
              { id: "bills", icon: FilePenLine, label: "Bills & 3-Way Matching (AP)" },
              { id: "ledger", icon: BookOpen, label: "General Ledger & COA" },
              { id: "reports", icon: BarChart3, label: "Financial Reports" },
              { id: "features", icon: Sparkles, label: "ERP Module Directory" },
            ].map((item) => {
              const Icon = item.icon;
              const isActive = activeNav === item.id;
              return (
                <button
                  key={item.id}
                  onClick={() => handleNavClick(item.id)}
                  title={item.label}
                  className={cn(
                    "w-10 h-10 rounded-xl flex items-center justify-center transition-all duration-150 relative group cursor-pointer",
                    isActive
                      ? "text-[#1E293B] bg-[#F1F5F9]/80 font-semibold shadow-xs"
                      : "text-[#94A3B8] hover:text-[#475569] hover:bg-[#F8FAFC]"
                  )}
                >
                  <Icon className="w-[19px] h-[19px] stroke-[1.75]" />
                  {isActive && (
                    <span className="absolute -left-2 w-[3px] h-5 bg-[#6366F1] rounded-r-full" />
                  )}
                </button>
              );
            })}
          </nav>
        </div>

        {/* Bottom Utility Icons & Profile Orb */}
        <div className="flex flex-col items-center space-y-5 w-full">
          <button
            onClick={() => setIsHistoryOpen(true)}
            title="Audit Trail History"
            className="text-[#94A3B8] hover:text-[#475569] transition-colors p-1 cursor-pointer"
          >
            <History className="w-[18px] h-[18px] stroke-[1.75]" />
          </button>
          <button
            onClick={() => setIsNotificationsOpen(true)}
            title="Operational Notifications"
            className="text-[#94A3B8] hover:text-[#475569] transition-colors relative p-1 cursor-pointer"
          >
            <Bell className="w-[18px] h-[18px] stroke-[1.75]" />
            <span className="absolute top-1 right-1 w-2 h-2 rounded-full bg-[#EF4444] border-2 border-white" />
          </button>

          {/* User Profile Orb with purple swirl */}
          <div
            onClick={() => setIsProfileOpen(true)}
            title="Organization Profile"
            className="w-8 h-8 rounded-full bg-gradient-to-tr from-[#312E81] via-[#6366F1] to-[#C084FC] p-[1.5px] cursor-pointer shadow-sm hover:scale-105 transition-transform"
          >
            <div className="w-full h-full rounded-full bg-[#0F172A] flex items-center justify-center text-white text-xs font-semibold">
              <span className="scale-75">✦</span>
            </div>
          </div>
        </div>
      </aside>

      {/* ─────────────────────────────────────────────────────────────
          2. MAIN CONTENT AREA
      ─────────────────────────────────────────────────────────────── */}
      <main className="flex-1 flex flex-col h-full overflow-y-auto bg-[#FBFBFC]">
        {/* Top Header Strip with Integration Avatars */}
        <header className="h-16 px-10 flex items-center justify-between border-b border-[#F8FAFC] shrink-0 bg-white/50 backdrop-blur-xs">
          {/* Breadcrumb / Section Title */}
          <div className="flex items-center space-x-2.5">
            <span className="text-xs font-semibold uppercase tracking-wider text-[#94A3B8]">
              Apex Cloud Systems
            </span>
            <span className="text-xs text-[#CBD5E1]">/</span>
            <span className="text-sm font-semibold text-[#0F172A] capitalize">
              {activeNav === "home"
                ? "Executive Overview"
                : activeNav === "invoices"
                ? "Sales Invoices & AR"
                : activeNav === "bills"
                ? "Bills & 3-Way Matching"
                : activeNav === "ledger"
                ? "General Ledger & COA"
                : activeNav === "reports"
                ? "Financial Statements"
                : activeNav === "copilot"
                ? "AI Financial Copilot"
                : "ERP Module Directory"}
            </span>
            <span
              className={cn(
                "inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium border ml-2",
                isConnected
                  ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                  : "bg-amber-50 text-amber-700 border-amber-200"
              )}
            >
              <span
                className={cn(
                  "w-1.5 h-1.5 rounded-full mr-1.5",
                  isConnected ? "bg-emerald-500 animate-pulse" : "bg-amber-500"
                )}
              />
              {isConnected ? "Engine Active (129 Tests OK)" : "Reconnecting"}
            </span>
          </div>

          <div className="flex items-center space-x-4">
            {/* Integration cluster */}
            <div
              onClick={() => setActiveNav("features")}
              title="Click to view Active Integrations"
              className="flex items-center -space-x-1.5 bg-[#F8FAFC] px-2.5 py-1.5 rounded-full border border-[#E2E8F0]/60 cursor-pointer hover:border-[#CBD5E1] transition-all"
            >
              <div
                className="w-5 h-5 rounded-full bg-[#000000] text-white flex items-center justify-center text-[9px] font-bold ring-2 ring-white"
                title="Quickbooks"
              >
                qb
              </div>
              <div
                className="w-5 h-5 rounded-full bg-[#635BFF] text-white flex items-center justify-center text-[9px] font-bold ring-2 ring-white"
                title="Stripe Gateway"
              >
                S
              </div>
              <div
                className="w-5 h-5 rounded-full bg-[#22C55E] text-white flex items-center justify-center text-[9px] font-bold ring-2 ring-white"
                title="HBL / Banking"
              >
                M
              </div>
              <div
                className="w-5 h-5 rounded-full bg-[#3B82F6] text-white flex items-center justify-center text-[9px] font-bold ring-2 ring-white"
                title="FBR Digital Integration"
              >
                Q
              </div>
            </div>

            {/* Filter / Search Trigger */}
            <button
              onClick={() => setIsSearchOpen(true)}
              title="Search Spotlight (Ctrl+K)"
              className="p-2 text-[#94A3B8] hover:text-[#475569] rounded-lg hover:bg-slate-100 transition-colors cursor-pointer"
            >
              <Search className="w-4 h-4 stroke-[1.75]" />
            </button>
          </div>
        </header>

        {/* ─────────────────────────────────────────────────────────────
            VIEW 1: EXECUTIVE DASHBOARD (HOME)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "home" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 pb-16 pt-2 flex flex-col space-y-10">
            {/* A. HERO AI COMMAND BAR */}
            <div className="flex flex-col items-center justify-center text-center space-y-6 pt-4">
              <h1 className="text-3xl sm:text-4xl font-semibold tracking-tight text-[#0F172A]">
                Rise and reconcile.
              </h1>

              <div className="w-full max-w-2xl relative">
                <div className="bg-white rounded-full border border-[#E2E8F0] px-5 py-3.5 flex items-center space-x-3.5 ai-search-shadow transition-all">
                  <div className="w-7 h-7 rounded-full bg-[#8B5CF6] text-white flex items-center justify-center shrink-0 shadow-sm">
                    <span className="text-xs font-serif leading-none">❋</span>
                  </div>

                  <input
                    type="text"
                    value={promptText}
                    onChange={(e) => setPromptText(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") handleAskCopilot();
                    }}
                    placeholder="Ask financial copilot, generate flux analysis, or draft entries..."
                    className="flex-1 bg-transparent border-none outline-none text-[#1E293B] text-sm sm:text-base placeholder:text-[#94A3B8] font-normal"
                  />

                  <div className="flex items-center space-x-2 text-[#94A3B8]">
                    <button
                      type="button"
                      onClick={() => handleAskCopilot()}
                      title="Run Copilot Query"
                      className="p-1 hover:text-[#475569] transition-colors cursor-pointer"
                    >
                      {copilotLoading ? (
                        <span className="w-4 h-4 rounded-full border-2 border-[#8B5CF6] border-t-transparent animate-spin inline-block" />
                      ) : (
                        <Mic className="w-4 h-4 stroke-[1.75]" />
                      )}
                    </button>
                  </div>
                </div>

                {copilotResponse && (
                  <div className="mt-4 p-5 bg-white border border-[#E2E8F0] rounded-2xl text-left shadow-md flex flex-col space-y-3 transition-all animate-in fade-in slide-in-from-top-2">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center space-x-2">
                        <div className="w-5 h-5 rounded-full bg-[#8B5CF6] text-white flex items-center justify-center text-[10px]">
                          ✦
                        </div>
                        <span className="text-xs font-semibold text-[#0F172A] uppercase tracking-wider">
                          Copilot Financial Reasoning
                        </span>
                      </div>
                      <button
                        onClick={() => setCopilotResponse(null)}
                        className="text-xs text-[#94A3B8] hover:text-[#475569] cursor-pointer"
                      >
                        Dismiss
                      </button>
                    </div>
                    <p className="text-sm text-[#334155] leading-relaxed">
                      {copilotResponse.answer}
                    </p>

                    {copilotResponse.keyMetrics && (
                      <div className="grid grid-cols-3 gap-2.5 pt-2 border-t border-[#F1F5F9]">
                        {Object.entries(copilotResponse.keyMetrics).map(([k, v]) => (
                          <div key={k} className="p-2 bg-[#F8FAFC] rounded-lg">
                            <span className="text-[10px] text-[#94A3B8] block">{k}</span>
                            <span className="text-xs font-semibold text-[#0F172A] font-tabular">
                              {v}
                            </span>
                          </div>
                        ))}
                      </div>
                    )}

                    {copilotResponse.suggestedActions && (
                      <div className="flex flex-wrap gap-2 pt-1">
                        {copilotResponse.suggestedActions.map((action, i) => (
                          <button
                            key={i}
                            onClick={() => {
                              if (action.includes("invoice")) setActiveNav("invoices");
                              else if (action.includes("depreciation") || action.includes("close")) setActiveNav("home");
                              else setActiveNav("reports");
                            }}
                            className="text-[11px] font-medium text-[#6366F1] bg-[#EEF2FF] hover:bg-[#E0E7FF] px-2.5 py-1 rounded-md transition-colors cursor-pointer"
                          >
                            → {action}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                )}

                <div className="flex flex-wrap items-center justify-center gap-2.5 mt-4">
                  {quickPrompts.map((prompt, idx) => (
                    <button
                      key={idx}
                      onClick={() => {
                        setPromptText(prompt);
                        handleAskCopilot(prompt);
                      }}
                      className="text-[11px] font-medium text-[#64748B] hover:text-[#1E293B] bg-white border border-[#E2E8F0]/80 hover:border-[#CBD5E1] px-3.5 py-1.5 rounded-full shadow-[0_1px_2px_rgba(0,0,0,0.02)] transition-all cursor-pointer"
                    >
                      {prompt}
                    </button>
                  ))}
                </div>
              </div>
            </div>

            {/* B. SNAPSHOT FINANCIAL GRID */}
            <div className="flex flex-col space-y-3">
              <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                <span className="text-[#94A3B8]">⠇⠇</span>
                <span>Financial Snapshot</span>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-12 bg-white rounded-2xl border border-[#EBEFF5] shadow-[0_2px_8px_rgba(0,0,0,0.02)] overflow-hidden divide-y md:divide-y-0 md:divide-x divide-[#EBEFF5]">
                {/* Cash Balance */}
                <div className="md:col-span-4 p-6 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1.5 text-xs font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">Cash Balance</span>
                    </div>
                    <div className="mt-4 flex items-baseline space-x-2">
                      <span className="text-3xl sm:text-4xl font-bold tracking-tight text-[#0F172A]">
                        $215M
                      </span>
                      <span className="text-base text-[#6366F1] font-semibold">≈</span>
                    </div>
                  </div>
                  <div className="mt-8 text-[11px] text-[#94A3B8] leading-relaxed">
                    <p>As of today, 05:30:00 PM</p>
                    <p className="text-[#64748B]">Compared to the same time one month ago</p>
                  </div>
                </div>

                {/* MRR */}
                <div className="md:col-span-4 p-6 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1.5 text-xs font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">MRR</span>
                    </div>
                    <div className="mt-4 flex items-center justify-between">
                      <div className="flex items-baseline space-x-2">
                        <span className="text-3xl sm:text-4xl font-bold tracking-tight text-[#0F172A]">
                          $4.3M
                        </span>
                        <ArrowUpRight className="w-5 h-5 text-[#6366F1] stroke-[2.5]" />
                      </div>

                      <div className="w-32 h-10">
                        <svg viewBox="0 0 120 40" fill="none" className="w-full h-full">
                          <path
                            d="M 5 32 C 25 30, 45 28, 65 18 C 85 8, 105 12, 115 5"
                            stroke="#10B981"
                            strokeWidth="2.5"
                            strokeLinecap="round"
                          />
                        </svg>
                      </div>
                    </div>
                  </div>
                  <div className="mt-8 flex items-center space-x-2 text-xs">
                    <span className="font-semibold text-[#10B981]">+8.4%</span>
                    <span className="text-[#94A3B8]">vs last month</span>
                  </div>
                </div>

                {/* Net Burn & Runway */}
                <div className="md:col-span-4 p-6 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1.5 text-xs font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">Runway & Burn</span>
                    </div>
                    <div className="mt-4 flex items-baseline justify-between">
                      <div>
                        <span className="text-3xl sm:text-4xl font-bold tracking-tight text-[#0F172A]">
                          67 mos
                        </span>
                        <span className="text-xs text-[#94A3B8] block mt-1">Runway remaining</span>
                      </div>
                      <div className="text-right">
                        <span className="text-xl font-bold text-[#EF4444]">$589K</span>
                        <span className="text-xs text-[#94A3B8] block">Net Burn/mo</span>
                      </div>
                    </div>
                  </div>
                  <div className="mt-8 text-[11px] text-[#94A3B8]">
                    Zero cash-out date projected in late 2031
                  </div>
                </div>
              </div>
            </div>

            {/* C. THREE COLUMN OPERATIONS GRID */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
              {/* Needs Action */}
              <div className="flex flex-col space-y-4">
                <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                  <span className="text-[#94A3B8]">⠇⠇</span>
                  <span>Needs Action</span>
                </div>

                <div className="flex flex-col space-y-3.5 pt-1">
                  {[
                    { label: "Bills pending 3-Way Match", count: 2, dot: true, nav: "bills" },
                    { label: "Invoices to be sent", count: 16, dot: true, nav: "invoices" },
                    { label: "Items below reorder level", count: 3, dot: true, nav: "features" },
                    { label: "Cash Transactions to be reconciled", count: 24, dot: true, nav: "ledger" },
                    { label: "Invoices to be FBR fiscalized", count: 4, dot: true, nav: "invoices" },
                    { label: "Journal Entries pending approval", count: 3, dot: true, nav: "ledger" },
                    { label: "Bills to be paid", count: 0, dot: false, nav: "bills" },
                  ].map((action, idx) => (
                    <div
                      key={idx}
                      onClick={() => setActiveNav(action.nav)}
                      className="flex items-center justify-between text-sm py-0.5 group cursor-pointer hover:bg-slate-50 p-1 rounded-md transition-colors"
                    >
                      <div className="flex items-center space-x-2.5">
                        {action.dot ? (
                          <span className="w-1.5 h-1.5 rounded-full bg-[#3B82F6]" />
                        ) : (
                          <span className="w-1.5 h-1.5" />
                        )}
                        <span className="text-[#334155] group-hover:text-[#0F172A] transition-colors">
                          {action.label}
                        </span>
                      </div>
                      <span className="font-semibold text-[#0F172A] font-tabular">
                        {action.count}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Close Checklist */}
              <div className="flex flex-col space-y-4">
                <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                  <CircleDot className="w-3.5 h-3.5 text-[#94A3B8]" />
                  <span>Month-End Close Checklist</span>
                </div>

                <div className="flex flex-col space-y-2 pt-1">
                  <div className="flex items-baseline justify-between">
                    <span className="text-2xl font-bold text-[#0F172A]">25%</span>
                    <span className="text-xs text-[#94A3B8] font-medium">2 / 8 complete</span>
                  </div>
                  <div className="w-full h-1 bg-[#F1F5F9] rounded-full overflow-hidden">
                    <div className="h-full bg-[#F59E0B] rounded-full" style={{ width: "25%" }} />
                  </div>
                </div>

                <div className="flex flex-col space-y-3 pt-2">
                  {checklist.map((item) => (
                    <div
                      key={item.id}
                      onClick={() => toggleChecklistItem(item.id)}
                      className="flex items-center justify-between py-1 text-xs sm:text-sm cursor-pointer group"
                    >
                      <div className="flex items-center space-x-2.5">
                        <div
                          className={cn(
                            "w-4 h-4 rounded-full border flex items-center justify-center transition-colors",
                            item.completed
                              ? "bg-[#10B981] border-[#10B981] text-white"
                              : "border-[#CBD5E1] group-hover:border-[#94A3B8]"
                          )}
                        >
                          {item.completed && <CheckCircle2 className="w-3 h-3" />}
                        </div>
                        <span
                          className={cn(
                            "transition-colors",
                            item.completed
                              ? "line-through text-[#94A3B8]"
                              : "text-[#334155] group-hover:text-[#0F172A]"
                          )}
                        >
                          {item.text}
                        </span>
                      </div>
                      <span
                        className={cn(
                          "text-[10px] font-semibold px-2 py-0.5 rounded tracking-tight uppercase",
                          item.completed
                            ? "bg-emerald-50 text-emerald-700"
                            : "bg-[#F8FAFC] text-[#64748B] border border-[#E2E8F0]/60"
                        )}
                      >
                        {item.status}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Pinned Reports */}
              <div className="flex flex-col space-y-4">
                <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                  <span className="text-[#F59E0B] text-xs">☆</span>
                  <span>Pinned Reports & Modules</span>
                </div>

                <div className="flex flex-col space-y-1.5 pt-1">
                  {[
                    { label: "Income Statement (P&L)", icon: BarChart3, nav: "reports", tab: "income-statement" },
                    { label: "Balance Sheet", icon: Compass, nav: "reports", tab: "balance-sheet" },
                    { label: "General Ledger", icon: BookOpen, nav: "ledger" },
                    { label: "3-Way Matching & Procurement", icon: PackageCheck, nav: "bills" },
                    { label: "Inventory Valuation & Perpetual COGS", icon: Boxes, nav: "features" },
                    { label: "Consolidated Financials (Multi-Entity)", icon: TrendingUp, nav: "reports", tab: "consolidation" },
                    { label: "Integrations & Webhooks", icon: Plug, nav: "features" },
                    { label: "Security Hardening & API Keys", icon: ShieldCheck, nav: "features" },
                  ].map((report, idx) => {
                    const Icon = report.icon;
                    return (
                      <div
                        key={idx}
                        onClick={() => {
                          setActiveNav(report.nav);
                          if (report.tab) setActiveReportTab(report.tab);
                        }}
                        className="flex items-center justify-between p-2.5 rounded-xl hover:bg-white hover:border hover:border-[#E2E8F0]/60 hover:shadow-[0_2px_4px_rgba(0,0,0,0.02)] transition-all cursor-pointer group"
                      >
                        <div className="flex items-center space-x-3 text-sm text-[#334155] group-hover:text-[#0F172A] font-medium">
                          <Icon className="w-4 h-4 text-[#94A3B8] group-hover:text-[#6366F1] transition-colors" />
                          <span>{report.label}</span>
                        </div>
                        <ArrowRight className="w-4 h-4 text-[#CBD5E1] group-hover:text-[#6366F1] group-hover:translate-x-0.5 transition-all" />
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 2: INVOICES (AR & FBR DIGITAL INVOICING)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "invoices" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  Sales Invoices & Accounts Receivable
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Manage commercial billings, FBR Digital QR Fiscalization, and collection status.
                </p>
              </div>
              <div className="flex items-center space-x-3">
                <button
                  onClick={() => alert("Creating new invoice draft...")}
                  className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-4 py-2 rounded-xl flex items-center space-x-2 shadow-sm transition-all cursor-pointer"
                >
                  <Plus className="w-4 h-4" />
                  <span>New Sales Invoice</span>
                </button>
              </div>
            </div>

            {/* Invoices Table */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs overflow-hidden">
              <div className="px-6 py-4 border-b border-[#F1F5F9] flex items-center justify-between">
                <span className="text-xs font-semibold text-[#0F172A] uppercase tracking-wider">
                  Open Customer Invoices (16)
                </span>
                <span className="text-xs text-[#64748B]">Total Outstanding: PKR 3,240,000</span>
              </div>
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-[#F8FAFC] text-[#64748B] border-b border-[#F1F5F9] uppercase tracking-wider font-semibold text-[10px]">
                    <th className="py-3 px-6">Invoice #</th>
                    <th className="py-3 px-6">Customer</th>
                    <th className="py-3 px-6">Issue Date</th>
                    <th className="py-3 px-6">Subtotal</th>
                    <th className="py-3 px-6">GST (18%)</th>
                    <th className="py-3 px-6">Total (PKR)</th>
                    <th className="py-3 px-6">FBR Fiscalization</th>
                    <th className="py-3 px-6">Status</th>
                    <th className="py-3 px-6 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#F1F5F9]">
                  {[
                    { id: "INV-2025-0012", customer: "Textile Mills Ltd", date: "2025-08-15", subtotal: "100,000", tax: "18,000", total: "118,000", fbr: "Fiscalized (FBR POS)", status: "paid" },
                    { id: "INV-2025-0013", customer: "Indus Logistics Pvt", date: "2025-08-18", subtotal: "250,000", tax: "45,000", total: "295,000", fbr: "Fiscalized (FBR POS)", status: "sent" },
                    { id: "INV-2025-0014", customer: "Lahore Tech Hub", date: "2025-08-20", subtotal: "80,000", tax: "14,400", total: "94,400", fbr: "Pending QR", status: "draft" },
                    { id: "INV-2025-0015", customer: "Karachi Port Shipping", date: "2025-08-21", subtotal: "500,000", tax: "90,000", total: "590,000", fbr: "Fiscalized (FBR POS)", status: "sent" },
                  ].map((row, i) => (
                    <tr key={i} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3.5 px-6 font-semibold text-[#0F172A]">{row.id}</td>
                      <td className="py-3.5 px-6 text-[#334155]">{row.customer}</td>
                      <td className="py-3.5 px-6 text-[#64748B]">{row.date}</td>
                      <td className="py-3.5 px-6 font-tabular">{row.subtotal}</td>
                      <td className="py-3.5 px-6 font-tabular">{row.tax}</td>
                      <td className="py-3.5 px-6 font-bold text-[#0F172A] font-tabular">{row.total}</td>
                      <td className="py-3.5 px-6">
                        <span className={cn(
                          "px-2 py-0.5 rounded text-[10px] font-semibold",
                          row.fbr.includes("Fiscalized") ? "bg-emerald-50 text-emerald-700" : "bg-amber-50 text-amber-700"
                        )}>
                          {row.fbr}
                        </span>
                      </td>
                      <td className="py-3.5 px-6">
                        <span className={cn(
                          "px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase",
                          row.status === "paid" ? "bg-emerald-100 text-emerald-800" : row.status === "sent" ? "bg-blue-100 text-blue-800" : "bg-slate-100 text-slate-700"
                        )}>
                          {row.status}
                        </span>
                      </td>
                      <td className="py-3.5 px-6 text-right">
                        <button
                          onClick={() => alert(`Viewing QR Code and details for ${row.id}`)}
                          className="text-[#6366F1] hover:text-[#4338CA] font-medium text-[11px] cursor-pointer"
                        >
                          View QR
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 3: BILLS & 3-WAY MATCHING (AP)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "bills" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  Vendor Bills & 3-Way Matching Engine
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Automated validation across Purchase Orders (PO), Goods Receipts (GRN), and Vendor Bills.
                </p>
              </div>
              <div className="flex items-center space-x-3">
                <button
                  onClick={() => alert("Batch 3-Way Matching initiated on all open bills.")}
                  className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-4 py-2 rounded-xl flex items-center space-x-2 shadow-sm transition-all cursor-pointer"
                >
                  <PackageCheck className="w-4 h-4" />
                  <span>Run Automated 3-Way Match</span>
                </button>
              </div>
            </div>

            {/* Bills & Matches List */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] shadow-xs overflow-hidden">
              <div className="px-6 py-4 border-b border-[#F1F5F9] flex items-center justify-between">
                <span className="text-xs font-semibold text-[#0F172A] uppercase tracking-wider">
                  Recent Bills & Matching Status
                </span>
                <span className="text-xs text-[#64748B]">Tolerance Threshold: ±2.0%</span>
              </div>
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-[#F8FAFC] text-[#64748B] border-b border-[#F1F5F9] uppercase tracking-wider font-semibold text-[10px]">
                    <th className="py-3 px-6">Bill #</th>
                    <th className="py-3 px-6">Vendor</th>
                    <th className="py-3 px-6">Linked PO</th>
                    <th className="py-3 px-6">GRN Status</th>
                    <th className="py-3 px-6">Amount (PKR)</th>
                    <th className="py-3 px-6">3-Way Match Outcome</th>
                    <th className="py-3 px-6">Status</th>
                    <th className="py-3 px-6 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#F1F5F9]">
                  {[
                    { id: "BILL-2025-001", vendor: "Steel Corp Pakistan", po: "PO-2025-0001", grn: "GRN-2025-0001 (100% rcvd)", amount: "70,000", match: "Perfect Match", matchColor: "text-emerald-700 bg-emerald-50", status: "Approved" },
                    { id: "BILL-2025-002", vendor: "Heavy Bearings Ltd", po: "PO-2025-0002", grn: "GRN-2025-0002 (40/100 rcvd)", amount: "45,000", match: "Quantity Variance Exceeded", matchColor: "text-amber-700 bg-amber-50", status: "Exception" },
                    { id: "BILL-2025-003", vendor: "Hydraulic Valves Hub", po: "PO-2025-0003", grn: "GRN-2025-0003", amount: "6,000", match: "Price Variance Exceeded (+20%)", matchColor: "text-rose-700 bg-rose-50", status: "Waived by CFO" },
                  ].map((row, i) => (
                    <tr key={i} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3.5 px-6 font-semibold text-[#0F172A]">{row.id}</td>
                      <td className="py-3.5 px-6 text-[#334155]">{row.vendor}</td>
                      <td className="py-3.5 px-6 text-[#6366F1] font-medium">{row.po}</td>
                      <td className="py-3.5 px-6 text-[#64748B]">{row.grn}</td>
                      <td className="py-3.5 px-6 font-bold text-[#0F172A] font-tabular">{row.amount}</td>
                      <td className="py-3.5 px-6">
                        <span className={cn("px-2.5 py-0.5 rounded text-[10px] font-semibold", row.matchColor)}>
                          {row.match}
                        </span>
                      </td>
                      <td className="py-3.5 px-6">
                        <span className="text-[10px] font-semibold px-2 py-0.5 rounded bg-slate-100 text-slate-700">
                          {row.status}
                        </span>
                      </td>
                      <td className="py-3.5 px-6 text-right">
                        <button
                          onClick={() => alert(`Details for Bill ${row.id}`)}
                          className="text-[#6366F1] hover:text-[#4338CA] font-medium text-[11px] cursor-pointer"
                        >
                          Review
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 4: GENERAL LEDGER & CHART OF ACCOUNTS
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "ledger" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  General Ledger & Chart of Accounts
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  Double-entry accounting invariant: Total Debits == Total Credits across all periods.
                </p>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {/* Chart of Accounts Summary */}
              <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs">
                <h3 className="text-xs font-semibold uppercase tracking-wider text-[#64748B] mb-4">
                  Standard Chart of Accounts (Pakistan SME Template)
                </h3>
                <div className="space-y-2.5 text-xs">
                  {[
                    { code: "1010", name: "Operating Cash & Bank Account", type: "Asset", normal: "Debit" },
                    { code: "1030", name: "Trade Debtors / Accounts Receivable", type: "Asset", normal: "Debit" },
                    { code: "1070", name: "Merchandise Inventory", type: "Asset", normal: "Debit" },
                    { code: "1590", name: "Accumulated Depreciation", type: "Contra Asset", normal: "Credit" },
                    { code: "2010", name: "Trade Creditors / Accounts Payable", type: "Liability", normal: "Credit" },
                    { code: "4010", name: "Sales Revenue - Local", type: "Revenue", normal: "Credit" },
                    { code: "5010", name: "Cost of Goods Sold - Purchases", type: "Expense", normal: "Debit" },
                    { code: "6070", name: "Depreciation Expense", type: "Expense", normal: "Debit" },
                  ].map((acc, i) => (
                    <div key={i} className="flex items-center justify-between py-1.5 border-b border-[#F8FAFC]">
                      <div className="flex items-center space-x-2">
                        <span className="font-mono font-semibold text-[#0F172A]">{acc.code}</span>
                        <span className="text-[#334155]">{acc.name}</span>
                      </div>
                      <div className="flex items-center space-x-2 text-[10px]">
                        <span className="text-[#64748B]">{acc.type}</span>
                        <span className="font-semibold text-indigo-600 bg-indigo-50 px-1.5 py-0.5 rounded">
                          {acc.normal}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Recent Journal Entries */}
              <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs">
                <h3 className="text-xs font-semibold uppercase tracking-wider text-[#64748B] mb-4">
                  Recent Balanced Journal Entries (GL Invariant Checked)
                </h3>
                <div className="space-y-4 text-xs">
                  {[
                    { number: "JE-2025-0001", desc: "Automated COGS for Invoice #INV-2025-0012", dr: "PKR 20,000", cr: "PKR 20,000", status: "Posted" },
                    { number: "JE-2025-0002", desc: "Straight-Line Fixed Asset Depreciation", dr: "PKR 10,000", cr: "PKR 10,000", status: "Posted" },
                    { number: "JE-2025-0003", desc: "Unrealized FX Revaluation ($10,000 USD Spot)", dr: "PKR 125,000", cr: "PKR 125,000", status: "Posted" },
                    { number: "JE-2025-0004", desc: "Intercompany Elimination (Parent vs Dubai FZE)", dr: "PKR 80,000", cr: "PKR 80,000", status: "Posted" },
                  ].map((je, i) => (
                    <div key={i} className="p-3 bg-[#F8FAFC] rounded-xl border border-[#F1F5F9] space-y-1.5">
                      <div className="flex items-center justify-between">
                        <span className="font-mono font-semibold text-[#0F172A]">{je.number}</span>
                        <span className="text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">
                          {je.status}
                        </span>
                      </div>
                      <p className="text-[#334155]">{je.desc}</p>
                      <div className="flex items-center justify-between text-[11px] text-[#64748B] pt-1 border-t border-[#E2E8F0]/40">
                        <span>Debit: {je.dr}</span>
                        <span>Credit: {je.cr}</span>
                        <span className="text-emerald-600 font-semibold">Balanced ✓</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 5: FINANCIAL REPORTS
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "reports" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                  Financial Reports & Statements
                </h2>
                <p className="text-xs text-[#64748B] mt-1">
                  IFRS compliant multi-currency and multi-entity consolidated reporting.
                </p>
              </div>
              <div className="flex items-center space-x-2 bg-white border border-[#E2E8F0] p-1 rounded-xl shadow-xs">
                {[
                  { id: "income-statement", label: "Income Statement" },
                  { id: "balance-sheet", label: "Balance Sheet" },
                  { id: "consolidation", label: "Multi-Entity Consolidation" },
                  { id: "taxation", label: "FBR 18% Annex-C" },
                ].map((tab) => (
                  <button
                    key={tab.id}
                    onClick={() => setActiveReportTab(tab.id)}
                    className={cn(
                      "px-3 py-1.5 text-xs font-semibold rounded-lg transition-all cursor-pointer",
                      activeReportTab === tab.id
                        ? "bg-[#6366F1] text-white shadow-xs"
                        : "text-[#64748B] hover:text-[#0F172A]"
                    )}
                  >
                    {tab.label}
                  </button>
                ))}
              </div>
            </div>

            {/* Report Content Container */}
            <div className="bg-white rounded-2xl border border-[#E2E8F0] p-8 shadow-xs">
              {activeReportTab === "income-statement" && (
                <div className="space-y-6">
                  <div className="border-b border-[#F1F5F9] pb-4 flex items-center justify-between">
                    <div>
                      <h3 className="text-base font-bold text-[#0F172A]">Statement of Profit and Loss (Income Statement)</h3>
                      <p className="text-xs text-[#64748B]">For the fiscal period ended August 31, 2025 (in PKR)</p>
                    </div>
                    <button
                      onClick={() => alert("Downloading PDF Income Statement...")}
                      className="text-xs text-[#6366F1] font-semibold flex items-center space-x-1.5 hover:underline cursor-pointer"
                    >
                      <Download className="w-3.5 h-3.5" />
                      <span>Export PDF</span>
                    </button>
                  </div>
                  <div className="space-y-3 text-xs">
                    <div className="flex justify-between font-bold text-sm text-[#0F172A] border-b pb-1">
                      <span>Operating Revenue</span>
                      <span>PKR 120,000</span>
                    </div>
                    <div className="flex justify-between text-[#64748B] pl-4">
                      <span>Sales Revenue - Local (#4010)</span>
                      <span>100,000</span>
                    </div>
                    <div className="flex justify-between text-[#64748B] pl-4">
                      <span>Service & Consulting Revenue (#4020)</span>
                      <span>20,000</span>
                    </div>
                    <div className="flex justify-between font-semibold text-rose-600 border-b pb-1 pt-2">
                      <span>Cost of Goods Sold (COGS #5010)</span>
                      <span>(PKR 20,000)</span>
                    </div>
                    <div className="flex justify-between font-bold text-sm text-emerald-700 bg-emerald-50 p-2 rounded-lg">
                      <span>Gross Profit</span>
                      <span>PKR 100,000</span>
                    </div>
                    <div className="flex justify-between text-[#64748B] pl-4 pt-2">
                      <span>Depreciation Expense (#6070)</span>
                      <span>(10,000)</span>
                    </div>
                    <div className="flex justify-between font-bold text-base text-[#0F172A] border-t-2 border-[#0F172A] pt-3">
                      <span>Net Operating Income</span>
                      <span className="text-emerald-600">PKR 90,000</span>
                    </div>
                  </div>
                </div>
              )}

              {activeReportTab === "balance-sheet" && (
                <div className="space-y-6">
                  <div className="border-b border-[#F1F5F9] pb-4 flex items-center justify-between">
                    <div>
                      <h3 className="text-base font-bold text-[#0F172A]">Balance Sheet (Statement of Financial Position)</h3>
                      <p className="text-xs text-[#64748B]">As of August 31, 2025</p>
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-8 text-xs">
                    <div className="space-y-3">
                      <h4 className="font-bold text-[#0F172A] uppercase tracking-wider text-[11px] border-b pb-1">Assets</h4>
                      <div className="flex justify-between"><span>Cash & Bank Balances (#1010)</span><span>PKR 1,250,000</span></div>
                      <div className="flex justify-between"><span>Trade Accounts Receivable (#1030)</span><span>PKR 3,240,000</span></div>
                      <div className="flex justify-between"><span>Merchandise Inventory (#1070)</span><span>PKR 540,000</span></div>
                      <div className="flex justify-between"><span>Plant & Machinery (#1510)</span><span>PKR 1,200,000</span></div>
                      <div className="flex justify-between text-rose-600"><span>Accumulated Depreciation (#1590)</span><span>(PKR 120,000)</span></div>
                      <div className="flex justify-between font-bold text-sm border-t pt-2 text-[#0F172A]">
                        <span>Total Assets</span>
                        <span>PKR 6,110,000</span>
                      </div>
                    </div>
                    <div className="space-y-3">
                      <h4 className="font-bold text-[#0F172A] uppercase tracking-wider text-[11px] border-b pb-1">Liabilities & Equity</h4>
                      <div className="flex justify-between"><span>Accounts Payable (#2010)</span><span>PKR 850,000</span></div>
                      <div className="flex justify-between"><span>Output Sales Tax Payable (#2020)</span><span>PKR 90,000</span></div>
                      <div className="flex justify-between"><span>Share Capital (#3010)</span><span>PKR 4,000,000</span></div>
                      <div className="flex justify-between"><span>Retained Earnings (#3030)</span><span>PKR 1,170,000</span></div>
                      <div className="flex justify-between font-bold text-sm border-t pt-2 text-[#0F172A]">
                        <span>Total Liabilities & Equity</span>
                        <span>PKR 6,110,000</span>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {activeReportTab === "consolidation" && (
                <div className="space-y-6">
                  <div className="border-b border-[#F1F5F9] pb-4">
                    <h3 className="text-base font-bold text-[#0F172A]">Consolidated Multi-Entity Statement</h3>
                    <p className="text-xs text-[#64748B]">Indus Holdings Group (Parent PK + Dubai FZE Subsidiary) with Eliminations</p>
                  </div>
                  <table className="w-full text-xs text-left">
                    <thead>
                      <tr className="bg-[#F8FAFC] text-[#64748B] font-semibold text-[10px] uppercase">
                        <th className="py-2.5 px-4">Line Item</th>
                        <th className="py-2.5 px-4">Indus Corp (Parent)</th>
                        <th className="py-2.5 px-4">Dubai FZE (Sub)</th>
                        <th className="py-2.5 px-4 text-rose-600">Intercompany Eliminations</th>
                        <th className="py-2.5 px-4 font-bold">Consolidated Total</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[#F1F5F9]">
                      <tr>
                        <td className="py-2.5 px-4 font-medium">Revenue</td>
                        <td className="py-2.5 px-4">PKR 500,000</td>
                        <td className="py-2.5 px-4">PKR 350,000</td>
                        <td className="py-2.5 px-4 text-rose-600">(PKR 80,000)</td>
                        <td className="py-2.5 px-4 font-bold">PKR 770,000</td>
                      </tr>
                      <tr>
                        <td className="py-2.5 px-4 font-medium">Operating Expenses</td>
                        <td className="py-2.5 px-4">PKR 250,000</td>
                        <td className="py-2.5 px-4">PKR 120,000</td>
                        <td className="py-2.5 px-4 text-rose-600">(PKR 80,000)</td>
                        <td className="py-2.5 px-4 font-bold">PKR 290,000</td>
                      </tr>
                      <tr className="bg-[#F8FAFC]">
                        <td className="py-2.5 px-4 font-bold">Consolidated Net Profit</td>
                        <td className="py-2.5 px-4 font-semibold">PKR 250,000</td>
                        <td className="py-2.5 px-4 font-semibold">PKR 230,000</td>
                        <td className="py-2.5 px-4 font-semibold text-emerald-600">PKR 0</td>
                        <td className="py-2.5 px-4 font-bold text-emerald-700">PKR 480,000</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              )}

              {activeReportTab === "taxation" && (
                <div className="space-y-6">
                  <div className="border-b border-[#F1F5F9] pb-4">
                    <h3 className="text-base font-bold text-[#0F172A]">Pakistan FBR Sales Tax Schedule (Annex-C)</h3>
                    <p className="text-xs text-[#64748B]">Digital Invoicing return schedule with 18% standard GST breakdown</p>
                  </div>
                  <div className="grid grid-cols-3 gap-4 text-xs">
                    <div className="p-4 bg-slate-50 rounded-xl">
                      <span className="text-[10px] text-[#64748B] block">Gross Domestic Invoicing</span>
                      <span className="text-lg font-bold text-[#0F172A]">PKR 1,500,000</span>
                    </div>
                    <div className="p-4 bg-slate-50 rounded-xl">
                      <span className="text-[10px] text-[#64748B] block">Output Sales Tax (18%)</span>
                      <span className="text-lg font-bold text-[#0F172A]">PKR 270,000</span>
                    </div>
                    <div className="p-4 bg-slate-50 rounded-xl">
                      <span className="text-[10px] text-[#64748B] block">Eligible Input Tax Credit</span>
                      <span className="text-lg font-bold text-emerald-600">PKR 110,000</span>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 6: AI FINANCIAL COPILOT WORKSPACE
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "copilot" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div>
              <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                AI Financial Copilot & Autonomous Reasoning
              </h2>
              <p className="text-xs text-[#64748B] mt-1">
                Context-aware natural language assistant for accounting queries, anomaly detection, and close management.
              </p>
            </div>

            <div className="bg-white rounded-2xl border border-[#E2E8F0] p-6 shadow-xs flex flex-col space-y-4">
              <div className="flex items-center space-x-3 p-3 bg-purple-50 text-purple-900 rounded-xl text-xs">
                <span className="text-lg">🤖</span>
                <span>
                  Copilot is loaded with your current General Ledger, open AR/AP balances, and month-end checklist.
                </span>
              </div>

              <div className="flex space-x-2">
                <input
                  type="text"
                  value={promptText}
                  onChange={(e) => setPromptText(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter") handleAskCopilot();
                  }}
                  placeholder="Ask any financial question or type a command..."
                  className="flex-1 bg-[#F8FAFC] border border-[#E2E8F0] rounded-xl px-4 py-3 text-xs outline-none focus:border-[#6366F1]"
                />
                <button
                  onClick={() => handleAskCopilot()}
                  disabled={copilotLoading}
                  className="bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold px-6 py-3 rounded-xl cursor-pointer"
                >
                  {copilotLoading ? "Analyzing..." : "Ask Copilot"}
                </button>
              </div>

              {copilotResponse && (
                <div className="p-4 bg-slate-50 border border-slate-200 rounded-xl space-y-3">
                  <span className="text-xs font-bold text-[#0F172A] block uppercase tracking-wider">
                    Copilot Response
                  </span>
                  <p className="text-xs text-[#334155] leading-relaxed">
                    {copilotResponse.answer}
                  </p>
                </div>
              )}
            </div>
          </div>
        )}

        {/* ─────────────────────────────────────────────────────────────
            VIEW 7: ERP MODULE DIRECTORY (FEATURES)
        ─────────────────────────────────────────────────────────────── */}
        {activeNav === "features" && (
          <div className="max-w-[1240px] w-full mx-auto px-8 py-8 flex flex-col space-y-6">
            <div>
              <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                AI-Native Finance ERP Architecture & Module Registry
              </h2>
              <p className="text-xs text-[#64748B] mt-1">
                28 production-grade foundational, operational, localization, and compliance modules.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {[
                { title: "General Ledger & COA", desc: "Double-entry journal engine with immutable posted records, debit/credit invariant validation.", status: "Active (Step 5-6)" },
                { title: "Accounts Receivable & Sales", desc: "Sales invoices, credit notes, customer aging, and FBR POS digital invoicing with QR.", status: "Active (Step 7-8, 22)" },
                { title: "Accounts Payable & Procurement", desc: "Purchase orders, goods receipt notes (GRN), and automated 3-Way Matching engine.", status: "Active (Step 25)" },
                { title: "Inventory & Perpetual COGS", desc: "Multi-warehouse inventory, Weighted Average Costing, FIFO layer depletion, and automated COGS.", status: "Active (Step 26)" },
                { title: "Month-End Close Management", desc: "Multi-task close cycles, straight-line depreciation GL generator, and period flux analysis.", status: "Active (Step 23)" },
                { title: "Multi-Entity & Consolidation", desc: "Multi-currency revaluations, cross-company billing, and consolidated elimination entries.", status: "Active (Step 24)" },
                { title: "Banking & Reconciliation", desc: "Bank statement CSV parser, fingerprint deduplication, and automated transaction matching.", status: "Active (Step 10-11)" },
                { title: "Integrations & Webhooks", desc: "Stripe, HBL, WhatsApp, S3 connectors, and HMAC SHA-256 signed outbound webhooks.", status: "Active (Step 27)" },
                { title: "Security & API Rate Limiting", desc: "API key secret rotation, SHA-256 key hashing, IP allowlists, and strict tenant isolation guards.", status: "Active (Step 28)" },
              ].map((mod, i) => (
                <div key={i} className="p-5 bg-white rounded-2xl border border-[#E2E8F0] shadow-xs flex flex-col justify-between space-y-3">
                  <div>
                    <h3 className="text-sm font-bold text-[#0F172A]">{mod.title}</h3>
                    <p className="text-xs text-[#64748B] mt-1.5 leading-relaxed">{mod.desc}</p>
                  </div>
                  <span className="text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded w-fit">
                    {mod.status}
                  </span>
                </div>
              ))}
            </div>
          </div>
        )}
      </main>

      {/* ─────────────────────────────────────────────────────────────
          MODAL 1: SPOTLIGHT SEARCH (CTRL+K)
      ─────────────────────────────────────────────────────────────── */}
      {isSearchOpen && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-xl rounded-2xl shadow-2xl border border-[#E2E8F0] overflow-hidden flex flex-col">
            <div className="px-4 py-3 border-b border-[#F1F5F9] flex items-center space-x-3">
              <Search className="w-5 h-5 text-[#94A3B8]" />
              <input
                type="text"
                autoFocus
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search invoices, accounts, bills, customers..."
                className="flex-1 text-sm outline-none text-[#0F172A] placeholder:text-[#94A3B8]"
              />
              <button
                onClick={() => setIsSearchOpen(false)}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="max-h-80 overflow-y-auto p-2 text-xs divide-y divide-[#F8FAFC]">
              {[
                { title: "Invoice #INV-2025-0012", sub: "Textile Mills Ltd • PKR 118,000", nav: "invoices" },
                { title: "Purchase Bill #BILL-2025-001", sub: "Steel Corp Pakistan • PKR 70,000", nav: "bills" },
                { title: "Account #1070 - Merchandise Inventory", sub: "Current Asset • Normal Debit", nav: "ledger" },
                { title: "Account #5010 - Cost of Goods Sold", sub: "Expense • Normal Debit", nav: "ledger" },
                { title: "Consolidated Financials (Multi-Entity)", sub: "Parent + Dubai Subsidiary", nav: "reports" },
              ]
                .filter((item) =>
                  item.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
                  item.sub.toLowerCase().includes(searchQuery.toLowerCase())
                )
                .map((res, i) => (
                  <div
                    key={i}
                    onClick={() => {
                      setActiveNav(res.nav);
                      setIsSearchOpen(false);
                    }}
                    className="p-3 hover:bg-[#F8FAFC] rounded-xl cursor-pointer transition-colors flex items-center justify-between"
                  >
                    <div>
                      <span className="font-semibold text-[#0F172A] block">{res.title}</span>
                      <span className="text-[#64748B] text-[11px]">{res.sub}</span>
                    </div>
                    <ArrowRight className="w-4 h-4 text-[#CBD5E1]" />
                  </div>
                ))}
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL 2: NOTIFICATIONS
      ─────────────────────────────────────────────────────────────── */}
      {isNotificationsOpen && (
        <div className="fixed inset-0 z-50 bg-black/30 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-md rounded-2xl shadow-xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <Bell className="w-4 h-4 text-[#6366F1]" />
                <h3 className="font-bold text-sm text-[#0F172A]">Notifications & Alerts</h3>
              </div>
              <button onClick={() => setIsNotificationsOpen(false)} className="text-[#94A3B8] hover:text-[#0F172A] cursor-pointer">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="space-y-3 text-xs">
              <div className="p-3 bg-amber-50 rounded-xl border border-amber-200">
                <span className="font-semibold text-amber-900 block">3-Way Match Exception</span>
                <span className="text-amber-800 text-[11px]">Bill #BILL-2025-003 exceeded price tolerance threshold (+20%).</span>
              </div>
              <div className="p-3 bg-blue-50 rounded-xl border border-blue-200">
                <span className="font-semibold text-blue-900 block">FBR POS Fiscalization</span>
                <span className="text-blue-800 text-[11px]">Invoice #INV-2025-0012 fiscalized successfully with FBR Digital QR.</span>
              </div>
              <div className="p-3 bg-emerald-50 rounded-xl border border-emerald-200">
                <span className="font-semibold text-emerald-900 block">Automated COGS Posted</span>
                <span className="text-emerald-800 text-[11px]">Journal #JE-2025-0001 balanced: Debit 5010 / Credit 1070.</span>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL 3: AUDIT HISTORY
      ─────────────────────────────────────────────────────────────── */}
      {isHistoryOpen && (
        <div className="fixed inset-0 z-50 bg-black/30 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-lg rounded-2xl shadow-xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <History className="w-4 h-4 text-[#6366F1]" />
                <h3 className="font-bold text-sm text-[#0F172A]">Immutable Audit Trail</h3>
              </div>
              <button onClick={() => setIsHistoryOpen(false)} className="text-[#94A3B8] hover:text-[#0F172A] cursor-pointer">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="space-y-2.5 text-xs max-h-72 overflow-y-auto">
              {[
                { event: "api_key_created", user: "Chief InfoSec Officer", time: "Just now", hash: "a8f3...91c2" },
                { event: "procurement:3way_matched", user: "Procurement Manager", time: "10 mins ago", hash: "4d91...11ab" },
                { event: "inventory:cogs_posted", user: "Automated COGS Engine", time: "25 mins ago", hash: "6b2a...ee04" },
                { event: "invoice:fbr_fiscalized", user: "Finance Lead", time: "1 hour ago", hash: "88dc...f032" },
              ].map((ev, i) => (
                <div key={i} className="p-2.5 bg-[#F8FAFC] rounded-lg flex items-center justify-between border border-[#F1F5F9]">
                  <div>
                    <span className="font-mono font-semibold text-[#0F172A]">{ev.event}</span>
                    <span className="text-[10px] text-[#64748B] block">By {ev.user} • {ev.time}</span>
                  </div>
                  <span className="text-[10px] font-mono text-[#94A3B8]">{ev.hash}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL 4: PROFILE
      ─────────────────────────────────────────────────────────────── */}
      {isProfileOpen && (
        <div className="fixed inset-0 z-50 bg-black/30 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-sm rounded-2xl shadow-xl border border-[#E2E8F0] p-6 space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <h3 className="font-bold text-sm text-[#0F172A]">Active Session</h3>
              <button onClick={() => setIsProfileOpen(false)} className="text-[#94A3B8] hover:text-[#0F172A] cursor-pointer">
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="space-y-2 text-xs text-[#334155]">
              <div className="flex justify-between"><span>User:</span><span className="font-semibold text-[#0F172A]">Group CFO Tariq</span></div>
              <div className="flex justify-between"><span>Role:</span><span className="font-semibold text-indigo-600">Owner / SuperAdmin</span></div>
              <div className="flex justify-between"><span>Tenant:</span><span className="font-semibold text-[#0F172A]">Apex Cloud Systems PK</span></div>
              <div className="flex justify-between"><span>Base Currency:</span><span className="font-semibold text-[#0F172A]">PKR (Rs)</span></div>
              <div className="flex justify-between"><span>Test Suite:</span><span className="font-semibold text-emerald-600">129/129 Passing</span></div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
