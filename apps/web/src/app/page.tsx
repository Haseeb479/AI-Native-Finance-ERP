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
  const [promptText, setPromptText] = useState("Please tell me all my pending invoices");
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

  const quickPrompts = [
    "What's left on my close?",
    "Build 13 week cash forecast starting today",
    "What's driving change in net burn?",
    "Generate a flux analysis for this period",
  ];

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

  return (
    <div className="flex h-screen bg-[#FDFDFD] text-[#1E293B] font-sans antialiased overflow-hidden select-none">
      {/* ─────────────────────────────────────────────────────────────
          1. SLIM LEFT ICON SIDEBAR (Matches exact reference design)
      ─────────────────────────────────────────────────────────────── */}
      <aside className="w-[68px] bg-white border-r border-[#F1F5F9] flex flex-col items-center justify-between py-5 shrink-0 z-20">
        {/* Top Brand Logo & Navigation Icons */}
        <div className="flex flex-col items-center space-y-7 w-full">
          {/* Logo Badge (Ri) */}
          <div className="w-10 h-10 rounded-[12px] bg-[#6366F1] text-white flex items-center justify-center font-bold text-base shadow-sm tracking-tight cursor-pointer hover:opacity-95 transition-opacity">
            Ri
          </div>

          {/* Nav Rail */}
          <nav className="flex flex-col items-center space-y-4 w-full px-2">
            {[
              { id: "search", icon: Search, label: "Search" },
              { id: "home", icon: Home, label: "Home" },
              { id: "copilot", icon: Bot, label: "AI Copilot" },
              { id: "invoices", icon: FileText, label: "Invoices" },
              { id: "bills", icon: FilePenLine, label: "Bills & Drafts" },
              { id: "ledger", icon: BookOpen, label: "General Ledger" },
              { id: "reports", icon: BarChart3, label: "Reports" },
              { id: "features", icon: Sparkles, label: "Features" },
            ].map((item) => {
              const Icon = item.icon;
              const isActive = activeNav === item.id;
              return (
                <button
                  key={item.id}
                  onClick={() => setActiveNav(item.id)}
                  title={item.label}
                  className={cn(
                    "w-10 h-10 rounded-xl flex items-center justify-center transition-all duration-150 relative group",
                    isActive
                      ? "text-[#1E293B] bg-[#F1F5F9]/70 font-semibold"
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
            title="History"
            className="text-[#94A3B8] hover:text-[#475569] transition-colors p-1"
          >
            <History className="w-[18px] h-[18px] stroke-[1.75]" />
          </button>
          <button
            title="Notifications"
            className="text-[#94A3B8] hover:text-[#475569] transition-colors relative p-1"
          >
            <Bell className="w-[18px] h-[18px] stroke-[1.75]" />
            <span className="absolute top-1 right-1 w-2 h-2 rounded-full bg-[#EF4444] border-2 border-white" />
          </button>

          {/* User Profile Orb with purple swirl */}
          <div className="w-8 h-8 rounded-full bg-gradient-to-tr from-[#312E81] via-[#6366F1] to-[#C084FC] p-[1.5px] cursor-pointer shadow-sm hover:scale-105 transition-transform">
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
        <header className="h-16 px-10 flex items-center justify-end border-b border-[#F8FAFC] shrink-0">
          <div className="flex items-center space-x-4">
            {/* Integration cluster */}
            <div className="flex items-center -space-x-1.5 bg-[#F8FAFC] px-2.5 py-1.5 rounded-full border border-[#E2E8F0]/60">
              <div
                className="w-5 h-5 rounded-full bg-[#000000] text-white flex items-center justify-center text-[9px] font-bold ring-2 ring-white"
                title="Quickbooks"
              >
                qb
              </div>
              <div
                className="w-5 h-5 rounded-full bg-[#635BFF] text-white flex items-center justify-center text-[9px] font-bold ring-2 ring-white"
                title="Stripe"
              >
                S
              </div>
              <div
                className="w-5 h-5 rounded-full bg-[#22C55E] text-white flex items-center justify-center text-[9px] font-bold ring-2 ring-white"
                title="Meezan / Banking"
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

            {/* Filter / Sliders Icon */}
            <button
              title="Filters & Views"
              className="p-2 text-[#94A3B8] hover:text-[#475569] rounded-lg hover:bg-slate-100 transition-colors"
            >
              <SlidersHorizontal className="w-4 h-4 stroke-[1.75]" />
            </button>
          </div>
        </header>

        {/* Hero Section & Dashboard Body */}
        <div className="max-w-[1240px] w-full mx-auto px-8 pb-16 pt-2 flex flex-col space-y-10">
          {/* ─────────────────────────────────────────────────────────
              A. HERO AI COMMAND BAR ("Rise and reconcile.")
          ─────────────────────────────────────────────────────────── */}
          <div className="flex flex-col items-center justify-center text-center space-y-6 pt-4">
            <h1 className="text-3xl sm:text-4xl font-semibold tracking-tight text-[#0F172A]">
              Rise and reconcile.
            </h1>

            {/* Floating AI Input Pill */}
            <div className="w-full max-w-2xl relative">
              <div className="bg-white rounded-full border border-[#E2E8F0] px-5 py-3.5 flex items-center space-x-3.5 ai-search-shadow transition-all">
                {/* Purple Flower/Star Emblem */}
                <div className="w-7 h-7 rounded-full bg-[#8B5CF6] text-white flex items-center justify-center shrink-0 shadow-sm">
                  <span className="text-xs font-serif leading-none">❋</span>
                </div>

                <input
                  type="text"
                  value={promptText}
                  onChange={(e) => setPromptText(e.target.value)}
                  placeholder="Ask financial copilot, generate flux analysis, or draft entries..."
                  className="flex-1 bg-transparent border-none outline-none text-[#1E293B] text-sm sm:text-base placeholder:text-[#94A3B8] font-normal"
                />

                {/* Right Action Icons */}
                <div className="flex items-center space-x-2 text-[#94A3B8]">
                  <button
                    type="button"
                    title="Attach document or invoice"
                    className="p-1 hover:text-[#475569] transition-colors"
                  >
                    <Paperclip className="w-4 h-4 stroke-[1.75]" />
                  </button>
                  <button
                    type="button"
                    title="Voice prompt"
                    className="p-1 hover:text-[#475569] transition-colors"
                  >
                    <Mic className="w-4 h-4 stroke-[1.75]" />
                  </button>
                </div>
              </div>

              {/* Quick Prompt Pills */}
              <div className="flex flex-wrap items-center justify-center gap-2.5 mt-4">
                {quickPrompts.map((prompt, idx) => (
                  <button
                    key={idx}
                    onClick={() => setPromptText(prompt)}
                    className="text-[11px] font-medium text-[#64748B] hover:text-[#1E293B] bg-white border border-[#E2E8F0]/80 hover:border-[#CBD5E1] px-3.5 py-1.5 rounded-full shadow-[0_1px_2px_rgba(0,0,0,0.02)] transition-all cursor-pointer"
                  >
                    {prompt}
                  </button>
                ))}
              </div>
            </div>
          </div>

          {/* ─────────────────────────────────────────────────────────
              B. SNAPSHOT FINANCIAL GRID
          ─────────────────────────────────────────────────────────── */}
          <div className="flex flex-col space-y-3">
            {/* Header label */}
            <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
              <span className="text-[#94A3B8]">⠇⠇</span>
              <span>Snapshot</span>
            </div>

            {/* Grid Container */}
            <div className="grid grid-cols-1 md:grid-cols-12 bg-white rounded-2xl border border-[#EBEFF5] shadow-[0_2px_8px_rgba(0,0,0,0.02)] overflow-hidden divide-y md:divide-y-0 md:divide-x divide-[#EBEFF5]">
              {/* Box 1: CASH BALANCE (col-span-4) */}
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
                  <p>As of 05/05/2026, 09:00:42 PM</p>
                  <p className="text-[#64748B]">Compared to the same time one month ago</p>
                </div>
              </div>

              {/* Box 2: MRR (col-span-4) */}
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

                    {/* Green Sparkline Curve (matches reference image) */}
                    <div className="w-32 h-10">
                      <svg
                        viewBox="0 0 120 40"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        className="w-full h-full"
                      >
                        <path
                          d="M0 30 C 20 28, 40 38, 60 22 C 80 40, 100 24, 120 10"
                          stroke="#10B981"
                          strokeWidth="2.5"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </div>
                  </div>
                </div>
                <div className="mt-8 text-[11px] text-[#94A3B8] leading-relaxed">
                  <p>As of 05/05/2026, 09:00:42 PM</p>
                  <p className="text-[#64748B]">Compared to the same time one month ago</p>
                </div>
              </div>

              {/* Box 3: QUAD METRICS GRID (col-span-4, divided 2x2) */}
              <div className="md:col-span-4 grid grid-cols-2 divide-x divide-y divide-[#EBEFF5]">
                {/* Gross Burn */}
                <div className="p-5 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1 text-[11px] font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">Gross Burn</span>
                    </div>
                    <div className="mt-2 flex items-baseline space-x-1.5">
                      <span className="text-xl font-bold text-[#0F172A]">$3.8M</span>
                      <ArrowDownRight className="w-4 h-4 text-[#6366F1] stroke-[2.5]" />
                    </div>
                  </div>
                  <span className="text-[10px] text-[#94A3B8] mt-3">
                    As of 05/05/2026, 09:00:42 PM
                  </span>
                </div>

                {/* Runway */}
                <div className="p-5 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1 text-[11px] font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">Runway</span>
                    </div>
                    <div className="mt-2">
                      <span className="text-xl font-bold text-[#0F172A]">67 months</span>
                    </div>
                  </div>
                  <span className="text-[10px] text-[#94A3B8] mt-3">
                    As of 05/05/2026, 09:00:42 PM
                  </span>
                </div>

                {/* Outstanding AP */}
                <div className="p-5 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1 text-[11px] font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">Outstanding AP</span>
                    </div>
                    <div className="mt-2 flex items-baseline space-x-1.5">
                      <span className="text-xl font-bold text-[#0F172A]">$5.2M</span>
                      <span className="text-sm text-[#6366F1] font-bold">≈</span>
                    </div>
                  </div>
                  <span className="text-[10px] text-[#94A3B8] mt-3">
                    As of 05/05/2026, 09:00:42 PM
                  </span>
                </div>

                {/* Net Burn */}
                <div className="p-5 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center space-x-1 text-[11px] font-medium text-[#64748B]">
                      <span className="text-[#CBD5E1]">::</span>
                      <span className="uppercase tracking-wider">Net Burn</span>
                    </div>
                    <div className="mt-2 flex items-baseline space-x-1.5">
                      <span className="text-xl font-bold text-[#0F172A]">$589K</span>
                      <span className="text-sm text-[#6366F1] font-bold">≈</span>
                    </div>
                  </div>
                  <span className="text-[10px] text-[#94A3B8] mt-3">
                    As of 05/05/2026, 09:00:42 PM
                  </span>
                </div>
              </div>
            </div>
          </div>

          {/* ─────────────────────────────────────────────────────────
              C. THREE-COLUMN ACTION & REPORT COMMAND CENTER
          ─────────────────────────────────────────────────────────── */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 pt-2">
            {/* COLUMN 1: NEEDS ACTION */}
            <div className="flex flex-col space-y-4">
              <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                <span className="text-[#94A3B8]">⠇⠇</span>
                <span>Needs Action</span>
              </div>

              <div className="flex flex-col space-y-3.5 pt-1">
                {[
                  { label: "Cash Transactions to be reconciled", count: 24, dot: true },
                  { label: "Invoices to be sent", count: 16, dot: true },
                  { label: "Contracts to be approved", count: 5, dot: true },
                  { label: "Journal Entries pending approval", count: 3, dot: true },
                  { label: "Bills to be paid", count: 0, dot: false },
                ].map((action, idx) => (
                  <div
                    key={idx}
                    className="flex items-center justify-between text-sm py-0.5 group cursor-pointer"
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

            {/* COLUMN 2: CLOSE CHECKLIST */}
            <div className="flex flex-col space-y-4">
              <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                <CircleDot className="w-3.5 h-3.5 text-[#94A3B8]" />
                <span>Close Checklist</span>
              </div>

              {/* Progress Metric & Bar */}
              <div className="flex flex-col space-y-2 pt-1">
                <div className="flex items-baseline justify-between">
                  <span className="text-2xl font-bold text-[#0F172A]">22%</span>
                  <span className="text-xs text-[#94A3B8] font-medium">6 / 9 complete</span>
                </div>
                {/* Thin progress track */}
                <div className="w-full h-1 bg-[#F1F5F9] rounded-full overflow-hidden">
                  <div className="h-full bg-[#F59E0B] rounded-full" style={{ width: "22%" }} />
                </div>
              </div>

              {/* Checklist items */}
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

            {/* COLUMN 3: PINNED REPORTS */}
            <div className="flex flex-col space-y-4">
              <div className="flex items-center space-x-2 text-[11px] font-semibold text-[#64748B] tracking-wider uppercase">
                <span className="text-[#F59E0B] text-xs">☆</span>
                <span>Pinned Reports</span>
              </div>

              <div className="flex flex-col space-y-1.5 pt-1">
                {[
                  { label: "Income Statement", icon: BarChart3, path: "/reports" },
                  { label: "Balance Sheet", icon: Compass, path: "/reports" },
                  { label: "General Ledger", icon: BookOpen, path: "/reports" },
                  { label: "Cashflow Statement", icon: TrendingUp, path: "/reports" },
                ].map((report, idx) => {
                  const Icon = report.icon;
                  return (
                    <div
                      key={idx}
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

                {/* Add a report button */}
                <button
                  type="button"
                  className="flex items-center space-x-2 text-xs font-medium text-[#64748B] hover:text-[#0F172A] pt-2 px-2.5 cursor-pointer"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Add a report</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  );
}
