"use client";

import React, { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { clearStoredSession, erpApi, setStoredSession } from "@/lib/api";
import { askAxiomAI } from "@/lib/axiom";
import {
  ArrowRight,
  Play,
  CheckCircle2,
  Sparkles,
  ShieldCheck,
  Bot,
  Layers,
  Repeat,
  Globe,
  FileText,
  Calendar,
  Clock,
  Building,
  Mail,
  User,
  X,
  ChevronRight,
  TrendingUp,
  BarChart3,
  Check,
  Lock,
  Landmark,
  FileCheck,
  Compass,
  Star,
} from "lucide-react";
import { cn } from "@/lib/utils";

export default function FinovaLandingPage() {
  const router = useRouter();

  // Modal states
  const [isDemoModalOpen, setIsDemoModalOpen] = useState(false);
  const [isLoginModalOpen, setIsLoginModalOpen] = useState(false);
  const [isVideoModalOpen, setIsVideoModalOpen] = useState(false);
  const [demoSubmitted, setDemoSubmitted] = useState(false);

  // Demo form state
  const [demoForm, setDemoForm] = useState({
    name: "",
    email: "",
    company: "",
    teamSize: "11-50",
    erpSystem: "Spreadsheets / QuickBooks",
    preferredDate: "2026-09-28",
    preferredTime: "11:00 AM PST",
    interest: "Zero-Day Close & Axiom AI",
  });

  // Login form state
  const [loginEmail, setLoginEmail] = useState("");
  const [loginPassword, setLoginPassword] = useState("");
  const [loginLoading, setLoginLoading] = useState(false);
  const [loginError, setLoginError] = useState("");

  // Axiom AI sub-nav tab
  const [activeAxiomTab, setActiveAxiomTab] = useState<"assistant" | "agents" | "continuous" | "mcp">("agents");

  // Interactive AI Prompt Preview state
  const [selectedPrompt, setSelectedPrompt] = useState(0);
  const [customSimulatorQuery, setCustomSimulatorQuery] = useState("");
  const [simulatorLiveLoading, setSimulatorLiveLoading] = useState(false);
  const [simulatorLiveResponse, setSimulatorLiveResponse] = useState<{
    answer: string;
    badge?: string;
    model?: string;
  } | null>(null);

  const handleRunSimulator = async (qText?: string) => {
    const q = qText || customSimulatorQuery || samplePrompts[selectedPrompt].q;
    if (!q.trim()) return;
    setSimulatorLiveLoading(true);
    try {
      const res = await askAxiomAI(q, {
        organization: "Apex Trading Pvt Ltd",
        currency: "PKR",
        sample_context: "Double-entry GL with FBR Section 153 WHT",
      });
      if (res && res.answer) {
        setSimulatorLiveResponse({
          answer: res.answer,
          badge: res.configured ? "Live Groq Llama 3.3 70B" : "Axiom AI Engine Ready",
          model: "Groq LPU",
        });
      }
    } catch {
      // fallback
    } finally {
      setSimulatorLiveLoading(false);
    }
  };
  const samplePrompts = [
    {
      q: "What's driving the change in net burn this month?",
      a: "Net burn increased by PKR 420,000 primarily due to semi-annual software renewals (AWS & Snowflake +PKR 350,000) and marketing agency retainers (+PKR 70,000). Gross margins remained stable at 97.4%.",
      badge: "Analyzed 1,420 Journal Entries across 3 Subsidiaries",
    },
    {
      q: "Verify Pakistan FBR sales tax withholding for vendor bills",
      a: "Section 153 WHT deductions totaling PKR 145,200 were automatically mapped to Account 2040 (Withholding Tax Payable) across 18 approved vendor disbursements. Zero tax discrepancies found.",
      badge: "Verified against FBR Active Taxpayer List (ATL)",
    },
    {
      q: "Calculate intercompany elimination for Dubai FZE and Parent PK",
      a: "Identified PKR 80,000 in shared compliance management fees. Reciprocal Debit AP (2010) and Credit AR (1030) balanced elimination entry was generated and verified in the consolidated trial balance.",
      badge: "Reciprocal Ledgers Balanced 100%",
    },
  ];

  const handleDemoSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setDemoSubmitted(true);
    setTimeout(() => {
      // Keep state open to show confirmation
    }, 400);
  };

  return (
    <div className="min-h-screen bg-white text-[#0F172A] font-sans antialiased selection:bg-[#6366F1]/20 selection:text-[#4338CA]">
      <style jsx>{`
        @keyframes finova-window-drift {
          0%, 100% { transform: translateY(0); }
          50% { transform: translateY(-5px); }
        }

        @keyframes finova-panel-reveal {
          from { opacity: 0; transform: translateY(18px) scale(0.985); }
          to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes finova-metric-breathe {
          0%, 100% { transform: translateY(0); box-shadow: 0 0 0 rgba(99, 102, 241, 0); }
          50% { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(99, 102, 241, 0.08); }
        }

        @keyframes finova-ledger-pulse {
          0%, 100% { opacity: 0.72; transform: translateX(0); }
          50% { opacity: 1; transform: translateX(3px); }
        }

        @keyframes finova-status-glow {
          0%, 100% { box-shadow: 0 0 0 rgba(16, 185, 129, 0); }
          50% { box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12); }
        }

        .finova-window-drift { animation: finova-window-drift 8s ease-in-out infinite; }
        .finova-panel-reveal { animation: finova-panel-reveal 900ms cubic-bezier(0.22, 1, 0.36, 1) both; }
        .finova-content-rise { animation: finova-panel-reveal 700ms cubic-bezier(0.22, 1, 0.36, 1) both; }
        .finova-content-rise:nth-child(2) { animation-delay: 180ms; }
        .finova-content-rise:nth-child(3) { animation-delay: 360ms; }
        .finova-metric-card { animation: finova-metric-breathe 6s ease-in-out infinite; }
        .finova-metric-card:nth-child(2) { animation-delay: 0.8s; }
        .finova-metric-card:nth-child(3) { animation-delay: 1.6s; }
        .finova-metric-card:nth-child(4) { animation-delay: 2.4s; }
        .finova-status-glow { animation: finova-status-glow 3s ease-in-out infinite; }
        .finova-ledger-row { animation: finova-ledger-pulse 4s ease-in-out infinite; }
        .finova-ledger-row:nth-child(2) { animation-delay: 0.7s; }
        .finova-ledger-row:nth-child(3) { animation-delay: 1.4s; }

        @media (prefers-reduced-motion: reduce) {
          .finova-window-drift,
          .finova-panel-reveal,
          .finova-content-rise,
          .finova-metric-card,
          .finova-status-glow,
          .finova-ledger-row { animation: none; }
        }
      `}</style>
      {/* ─────────────────────────────────────────────────────────────
          1. TOP ANNOUNCEMENT BAR
      ─────────────────────────────────────────────────────────────── */}
      <div className="bg-[#4F46E5] text-white text-[11px] sm:text-xs font-semibold py-2 px-4 text-center tracking-tight flex items-center justify-center space-x-2 relative z-30">
        <span>Join us for Finova Reconcile: Zero Day Close in San Francisco on 10.23.24</span>
        <ArrowRight className="w-3 h-3 inline-block" />
      </div>

      {/* ─────────────────────────────────────────────────────────────
          2. STICKY GLOBAL NAVIGATION
      ─────────────────────────────────────────────────────────────── */}
      <header className="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-[#F1F5F9] transition-all">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          {/* Brand Logo */}
          <div className="flex items-center space-x-8">
            <Link href="/website" className="flex items-center space-x-2">
              <span className="font-extrabold text-2xl tracking-tighter text-[#0F172A] font-serif">
                Finova
              </span>
              <span className="w-2 h-2 rounded-full bg-[#4F46E5] inline-block mb-1"></span>
            </Link>

            {/* Nav Menu */}
            <nav className="hidden md:flex items-center space-x-7 text-xs font-semibold text-[#475569]">
              <a href="#platform" className="hover:text-[#0F172A] transition-colors flex items-center space-x-1">
                <span>Platform</span>
                <span className="text-[9px] text-[#94A3B8]">▾</span>
              </a>
              <a href="#axiom-ai" className="hover:text-[#0F172A] transition-colors flex items-center space-x-1">
                <span>Axiom AI</span>
                <span className="text-[9px] text-[#94A3B8]">▾</span>
              </a>
              <a href="#testimonials" className="hover:text-[#0F172A] transition-colors">
                Customers
              </a>
              <a href="#integrations" className="hover:text-[#0F172A] transition-colors">
                Partners
              </a>
              <a href="#footer" className="hover:text-[#0F172A] transition-colors flex items-center space-x-1">
                <span>Resources</span>
                <span className="text-[9px] text-[#94A3B8]">▾</span>
              </a>
            </nav>
          </div>

          {/* Right Action Buttons */}
          <div className="flex items-center space-x-3">
            <button
              onClick={() => setIsLoginModalOpen(true)}
              className="text-xs font-semibold text-[#475569] hover:text-[#0F172A] px-3 py-1.5 transition-colors cursor-pointer"
            >
              Login
            </button>
            <button
              onClick={() => setIsDemoModalOpen(true)}
              className="bg-black hover:bg-neutral-800 text-white text-xs font-semibold px-4 py-2 rounded-full shadow-sm hover:shadow transition-all cursor-pointer"
            >
              Request a demo
            </button>
          </div>
        </div>
      </header>

      {/* ─────────────────────────────────────────────────────────────
          3. HERO SECTION (Zero-Day Close starts here)
      ─────────────────────────────────────────────────────────────── */}
      <section className="relative pt-12 pb-20 md:pt-16 md:pb-28 overflow-hidden bg-gradient-to-b from-[#FAFAFA] via-white to-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Top Pill & Headline Grid */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start mb-12">
            <div className="lg:col-span-8 space-y-4">
              {/* G2 Rating Badge */}
              <div className="inline-flex items-center space-x-2 bg-white border border-[#E2E8F0] shadow-xs px-3 py-1 rounded-full text-[11px] font-semibold text-[#334155]">
                <span className="w-4 h-4 rounded-full bg-[#EA4335] text-white text-[9px] font-bold flex items-center justify-center">
                  G2
                </span>
                <span className="text-[#F59E0B] tracking-widest text-[10px]">★★★★★</span>
                <span>5.0</span>
                <span className="text-[#94A3B8]">•</span>
                <span className="text-[#64748B]">Only ERP with 5 stars on G2</span>
              </div>

              {/* Huge Display Headline */}
              <h1 className="text-4xl sm:text-6xl lg:text-7xl font-extrabold tracking-tight text-[#0F172A] leading-[1.08]">
                Zero-Day Close <br />
                <span className="text-[#0F172A]">starts here</span>
              </h1>
            </div>

            <div className="lg:col-span-4 lg:pt-8 space-y-5">
              <p className="text-sm sm:text-base text-[#64748B] leading-relaxed">
                Today&apos;s top finance teams trust Finova to close faster, report in real time and move their business forward.
              </p>
              <div className="flex flex-wrap items-center gap-3">
                <button
                  onClick={() => setIsDemoModalOpen(true)}
                  className="bg-black hover:bg-neutral-800 text-white text-xs font-semibold px-5 py-3 rounded-full shadow-md hover:shadow-lg transition-all cursor-pointer flex items-center space-x-2"
                >
                  <span>Request a demo</span>
                  <ArrowRight className="w-3.5 h-3.5" />
                </button>
                <button
                  onClick={() => setIsVideoModalOpen(true)}
                  className="bg-white border border-[#E2E8F0] hover:bg-[#F8FAFC] text-[#0F172A] text-xs font-semibold px-4 py-3 rounded-full shadow-xs transition-all cursor-pointer flex items-center space-x-1.5"
                >
                  <Play className="w-3 h-3 fill-current text-[#4F46E5]" />
                  <span>See it in action</span>
                </button>
              </div>
            </div>
          </div>

          {/* Floating Product UI Mockup with Skyline Glow */}
          <div className="relative mt-4 group finova-panel-reveal">
            {/* Dusk Skyline Background Frame */}
            <div className="relative rounded-3xl overflow-hidden border border-[#E2E8F0] shadow-2xl bg-gradient-to-tr from-[#F59E0B]/30 via-[#EC4899]/20 to-[#6366F1]/30 p-2 sm:p-6 lg:p-10">
              <div
                className="absolute inset-0 opacity-40 bg-cover bg-center mix-blend-multiply"
                style={{
                  backgroundImage:
                    "url('https://images.unsplash.com/photo-1506973035872-a4ec16b8e8d9?q=80&w=2000&auto=format&fit=crop')",
                }}
              />

              {/* Centered Floating ERP Window */}
              <div className="relative z-10 bg-white/95 backdrop-blur-xl rounded-2xl border border-white/80 shadow-2xl overflow-hidden finova-window-drift">
                {/* Window Chrome Header */}
                <div className="bg-[#F8FAFC]/90 border-b border-[#E2E8F0] px-4 py-3 flex items-center justify-between">
                  <div className="flex items-center space-x-2">
                    <span className="w-2.5 h-2.5 rounded-full bg-rose-400"></span>
                    <span className="w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                    <span className="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                    <span className="text-[11px] font-mono text-[#94A3B8] ml-2">app.finova.io/financial-metrics</span>
                  </div>
                  <div className="flex items-center space-x-3">
                    <span className="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold finova-status-glow">
                      Zero-Day Close: Ready ✓
                    </span>
                  </div>
                </div>

                {/* ERP Dashboard Viewport */}
                <div className="p-6 lg:p-8 space-y-6 finova-dashboard-content">
                  {/* Top Header */}
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4 finova-content-rise">
                    <div>
                      <div className="flex items-center space-x-2">
                        <div className="w-6 h-6 rounded-lg bg-[#4F46E5] text-white flex items-center justify-center font-bold text-xs">
                          Fi
                        </div>
                        <h3 className="font-bold text-base text-[#0F172A]">Financial Metrics & Continuous Close</h3>
                      </div>
                      <p className="text-xs text-[#64748B] mt-0.5">
                        Indus Holdings Group Consolidated (PKR Base • AED & USD Multi-Entity)
                      </p>
                    </div>
                    <div className="flex items-center space-x-2">
                      <span className="px-3 py-1 bg-[#F8FAFC] border border-[#E2E8F0] rounded-xl text-xs font-mono font-medium text-[#475569]">
                        Fiscal Period: July 2025
                      </span>
                    </div>
                  </div>

                  {/* 4 Key Metric Cards */}
                  <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 finova-content-rise">
                    <div className="p-4 rounded-xl bg-[#F8FAFC] border border-[#E2E8F0] finova-metric-card">
                      <span className="text-[11px] text-[#64748B] font-medium block">Cash & Equivalents</span>
                      <span className="text-xl lg:text-2xl font-bold font-tabular text-[#0F172A] mt-1 block">
                        $215M
                      </span>
                      <span className="text-[10px] text-emerald-600 font-semibold mt-0.5 block">
                        PKR 59.8B • Reconciled
                      </span>
                    </div>
                    <div className="p-4 rounded-xl bg-[#F8FAFC] border border-[#E2E8F0] finova-metric-card">
                      <span className="text-[11px] text-[#64748B] font-medium block">Net Burn (Monthly)</span>
                      <span className="text-xl lg:text-2xl font-bold font-tabular text-[#0F172A] mt-1 block">
                        $4.3M
                      </span>
                      <span className="text-[10px] text-emerald-600 font-semibold mt-0.5 block">
                        ↓ 6.2% vs budget
                      </span>
                    </div>
                    <div className="p-4 rounded-xl bg-[#F8FAFC] border border-[#E2E8F0] finova-metric-card">
                      <span className="text-[11px] text-[#64748B] font-medium block">Gross Margin</span>
                      <span className="text-xl lg:text-2xl font-bold font-tabular text-[#0F172A] mt-1 block">
                        97.4%
                      </span>
                      <span className="text-[10px] text-indigo-600 font-semibold mt-0.5 block">
                        Software & Services
                      </span>
                    </div>
                    <div className="p-4 rounded-xl bg-[#F8FAFC] border border-[#E2E8F0] finova-metric-card">
                      <span className="text-[11px] text-[#64748B] font-medium block">Cash Runway</span>
                      <span className="text-xl lg:text-2xl font-bold font-tabular text-[#0F172A] mt-1 block">
                        17 mos
                      </span>
                      <span className="text-[10px] text-slate-500 font-medium mt-0.5 block">
                        Fully funded model
                      </span>
                    </div>
                  </div>

                  {/* Checklist & Ledger Snippet */}
                  <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 pt-2 finova-content-rise">
                    <div className="border border-[#E2E8F0] rounded-xl p-4 bg-white space-y-2.5">
                      <div className="flex items-center justify-between pb-2 border-b border-[#F1F5F9]">
                        <span className="text-xs font-bold text-[#0F172A]">Zero-Day Close Checklist</span>
                        <span className="text-[10px] font-semibold text-emerald-600">4 of 4 Verified</span>
                      </div>
                      <div className="space-y-2 text-xs">
                        <div className="flex items-center justify-between text-[#334155]">
                          <span className="flex items-center space-x-2">
                            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />
                            <span>Monthly fixed asset depreciation (straight-line)</span>
                          </span>
                          <span className="text-[10px] font-mono text-[#94A3B8]">JE-2025-0014</span>
                        </div>
                        <div className="flex items-center justify-between text-[#334155]">
                          <span className="flex items-center space-x-2">
                            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />
                            <span>Intercompany eliminations (Dubai FZE / PK Parent)</span>
                          </span>
                          <span className="text-[10px] font-mono text-[#94A3B8]">PKR 80,000</span>
                        </div>
                        <div className="flex items-center justify-between text-[#334155]">
                          <span className="flex items-center space-x-2">
                            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />
                            <span>Bank statement matching (Meezan & HBL SHA-256)</span>
                          </span>
                          <span className="text-[10px] font-mono text-emerald-600">100% Matched</span>
                        </div>
                        <div className="flex items-center justify-between text-[#334155]">
                          <span className="flex items-center space-x-2">
                            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />
                            <span>Two-stage soft lock period & flux analysis report</span>
                          </span>
                          <span className="text-[10px] font-mono text-indigo-600">Closed</span>
                        </div>
                      </div>
                    </div>

                    <div className="border border-[#E2E8F0] rounded-xl p-4 bg-white space-y-2.5">
                      <div className="flex items-center justify-between pb-2 border-b border-[#F1F5F9]">
                        <span className="text-xs font-bold text-[#0F172A]">Real-time Ledger Posting Stream</span>
                        <span className="text-[10px] font-mono text-indigo-600">Double-Entry Invariant: OK</span>
                      </div>
                      <div className="space-y-2 text-xs font-mono">
                        <div className="p-2 bg-[#F8FAFC] rounded-lg flex items-center justify-between finova-ledger-row">
                          <span>INV-2025-0012 Customer Receipt</span>
                          <span className="text-emerald-600 font-bold">+PKR 150,000</span>
                        </div>
                        <div className="p-2 bg-[#F8FAFC] rounded-lg flex items-center justify-between finova-ledger-row">
                          <span>ASC 606 RevRec Monthly Amortization</span>
                          <span className="text-[#4F46E5] font-bold">PKR 100,000</span>
                        </div>
                        <div className="p-2 bg-[#F8FAFC] rounded-lg flex items-center justify-between finova-ledger-row">
                          <span>FBR Digital Invoicing Pos Fiscalization</span>
                          <span className="text-slate-700">QR Validated ✓</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ─────────────────────────────────────────────────────────────
          4. TRUSTED BY INDUSTRY LEADERS LOGO BAR
      ─────────────────────────────────────────────────────────────── */}
      <section className="border-y border-[#F1F5F9] bg-[#FAFAFA]/60 py-10">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
          <p className="text-[11px] font-bold uppercase tracking-wider text-[#94A3B8] mb-6">
            Trusted by industry leaders
          </p>
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 border border-[#E2E8F0] rounded-2xl bg-white shadow-xs overflow-hidden">
            {[
              { name: "Function", symbol: "● Function" },
              { name: "MERCOR", symbol: "M MERCOR" },
              { name: "Temporal", symbol: "✦ Temporal" },
              { name: "Juicebox", symbol: "🍹 Juicebox" },
              { name: "Monarch", symbol: "👑 Monarch" },
              { name: "Scribe", symbol: "Scribe ✍" },
              { name: "Sotheby's", symbol: "Sotheby's" },
              { name: "KICKSTARTER", symbol: "KICKSTARTER" },
              { name: "FOURSQUARE", symbol: "FOURSQUARE" },
              { name: "omni", symbol: "omni" },
              { name: "GAMMA", symbol: "GAMMA" },
              { name: "LangChain", symbol: "🦜 LangChain" },
            ].map((logo, idx) => (
              <div
                key={idx}
                className="py-5 px-4 flex items-center justify-center border-b md:border-b-0 border-r border-[#F1F5F9] hover:bg-[#F8FAFC] transition-colors"
              >
                <span className="font-extrabold text-xs tracking-tight text-[#334155]">{logo.symbol}</span>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ─────────────────────────────────────────────────────────────
          5. SECTION 01: PLATFORM (8-CARD BENTO GRID)
      ─────────────────────────────────────────────────────────────── */}
      <section id="platform" className="py-24 bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Section Kicker & Title */}
          <div className="text-center max-w-3xl mx-auto mb-16 space-y-3">
            <span className="text-[11px] font-mono font-bold tracking-widest text-[#4F46E5] uppercase">
              01 PLATFORM
            </span>
            <h2 className="text-3xl sm:text-5xl font-extrabold tracking-tight text-[#0F172A]">
              Built by accountants who&apos;ve sat through the close
            </h2>
            <p className="text-sm sm:text-base text-[#64748B]">
              Run a continuous close with every entry traceable to the source. Get back to the work you trained for.
            </p>
          </div>

          {/* 8-Card Bento Grid */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* Card 1: Finova Intelligence */}
            <div className="p-6 rounded-2xl border border-[#E2E8F0] bg-gradient-to-b from-[#FAF5FF] to-white hover:border-[#C084FC] transition-all flex flex-col justify-between group min-h-[300px]">
              <div>
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-sm text-[#0F172A]">Finova Intelligence</h3>
                  <span className="text-[#94A3B8] group-hover:text-[#4F46E5] transition-colors">↗</span>
                </div>
                <p className="text-xs text-[#64748B] mt-1">Put Axiom AI to work for you</p>
              </div>
              <div className="py-8 flex items-center justify-center">
                <div className="w-24 h-24 rounded-full bg-gradient-to-tr from-[#6366F1] via-[#A855F7] to-[#EC4899] p-1 flex items-center justify-center shadow-xl animate-pulse">
                  <div className="w-full h-full rounded-full bg-[#1E1035] flex items-center justify-center">
                    <Sparkles className="w-8 h-8 text-white" />
                  </div>
                </div>
              </div>
            </div>

            {/* Card 2: Perpetual general ledger */}
            <div className="p-6 rounded-2xl border border-[#E2E8F0] bg-[#FAFAFA] hover:border-[#CBD5E1] transition-all flex flex-col justify-between group min-h-[300px]">
              <div>
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-sm text-[#0F172A]">Perpetual general ledger</h3>
                  <span className="text-[#94A3B8] group-hover:text-[#4F46E5] transition-colors">↗</span>
                </div>
                <p className="text-xs text-[#64748B] mt-1">Meet the intelligent system of record</p>
              </div>
              <div className="bg-white p-3 rounded-xl border border-[#E2E8F0] shadow-xs space-y-2 mt-4 text-[10px]">
                <div className="flex justify-between font-mono font-semibold">
                  <span>JE-2025-0012</span>
                  <span className="text-emerald-600">Balanced ✓</span>
                </div>
                <div className="flex justify-between text-[#64748B]">
                  <span>Debit: Cash & Bank</span>
                  <span>PKR 150,000</span>
                </div>
                <div className="flex justify-between text-[#64748B]">
                  <span>Credit: Accounts Receivable</span>
                  <span>PKR 150,000</span>
                </div>
              </div>
            </div>

            {/* Card 3: Advanced revenue recognition */}
            <div className="p-6 rounded-2xl border border-[#E2E8F0] bg-[#FAFAFA] hover:border-[#CBD5E1] transition-all flex flex-col justify-between group min-h-[300px]">
              <div>
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-sm text-[#0F172A]">Advanced revenue recognition</h3>
                  <span className="text-[#94A3B8] group-hover:text-[#4F46E5] transition-colors">↗</span>
                </div>
                <p className="text-xs text-[#64748B] mt-1">Automate GL, P&L, and usage-based models</p>
              </div>
              <div className="bg-white p-3 rounded-xl border border-[#E2E8F0] shadow-xs space-y-1.5 mt-4 text-[10px]">
                <div className="flex justify-between font-semibold text-[#334155]">
                  <span>Contract Waterfall</span>
                  <span className="text-[#4F46E5]">ASC 606</span>
                </div>
                <div className="w-full bg-slate-100 rounded-full h-2 overflow-hidden flex">
                  <div className="bg-emerald-500 h-full w-[40%]"></div>
                  <div className="bg-indigo-400 h-full w-[60%]"></div>
                </div>
                <div className="flex justify-between text-[9px] text-[#64748B] pt-1">
                  <span>Earned: PKR 400K</span>
                  <span>Deferred: PKR 600K</span>
                </div>
              </div>
            </div>

            {/* Card 4: Native Integrations */}
            <div className="p-6 rounded-2xl border border-[#E2E8F0] bg-[#FAFAFA] hover:border-[#CBD5E1] transition-all flex flex-col justify-between group min-h-[300px]">
              <div>
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-sm text-[#0F172A]">Native Integrations</h3>
                  <span className="text-[#94A3B8] group-hover:text-[#4F46E5] transition-colors">↗</span>
                </div>
                <p className="text-xs text-[#64748B] mt-1">Connections to your stack in minutes</p>
              </div>
              <div className="py-4 flex items-center justify-center">
                <div className="relative w-28 h-28 flex items-center justify-center">
                  <div className="absolute inset-0 rounded-full border border-dashed border-[#CBD5E1]"></div>
                  <div className="w-10 h-10 rounded-xl bg-[#4F46E5] text-white flex items-center justify-center font-bold text-xs shadow-md">
                    Fi
                  </div>
                  <span className="absolute top-0 px-1.5 py-0.5 bg-emerald-100 text-emerald-800 text-[8px] font-bold rounded">
                    Meezan
                  </span>
                  <span className="absolute bottom-0 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-[8px] font-bold rounded">
                    HBL
                  </span>
                  <span className="absolute left-0 px-1.5 py-0.5 bg-purple-100 text-purple-800 text-[8px] font-bold rounded">
                    Stripe
                  </span>
                  <span className="absolute right-0 px-1.5 py-0.5 bg-amber-100 text-amber-800 text-[8px] font-bold rounded">
                    FBR
                  </span>
                </div>
              </div>
            </div>

            {/* Card 5: Multi-entity & global-ready */}
            <div className="p-6 rounded-2xl border border-[#E2E8F0] bg-[#FAFAFA] hover:border-[#CBD5E1] transition-all flex flex-col justify-between group min-h-[300px]">
              <div>
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-sm text-[#0F172A]">Multi-entity & global-ready</h3>
                  <span className="text-[#94A3B8] group-hover:text-[#4F46E5] transition-colors">↗</span>
                </div>
                <p className="text-xs text-[#64748B] mt-1">Every entity — all in one place</p>
              </div>
              <div className="space-y-1.5 mt-4 text-[10px]">
                <div className="p-2 bg-white rounded-lg border border-[#E2E8F0] flex items-center justify-between">
                  <span>🇵🇰 Indus Holding Parent</span>
                  <span className="font-mono font-bold">PKR</span>
                </div>
                <div className="p-2 bg-white rounded-lg border border-[#E2E8F0] flex items-center justify-between">
                  <span>🇦🇪 Dubai Gulf Tech FZE</span>
                  <span className="font-mono font-bold">AED</span>
                </div>
              </div>
            </div>

            {/* Card 6: Close management */}
            <div className="p-6 rounded-2xl border border-[#E2E8F0] bg-[#FAFAFA] hover:border-[#CBD5E1] transition-all flex flex-col justify-between group min-h-[300px]">
              <div>
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-sm text-[#0F172A]">Close management</h3>
                  <span className="text-[#94A3B8] group-hover:text-[#4F46E5] transition-colors">↗</span>
                </div>
                <p className="text-xs text-[#64748B] mt-1">Zero-Day Close, today</p>
              </div>
              <div className="bg-white p-3 rounded-xl border border-[#E2E8F0] shadow-xs space-y-2 mt-4 text-xs">
                <div className="flex justify-between items-center">
                  <span className="font-semibold text-[11px]">Period Close Progress</span>
                  <span className="text-emerald-600 font-bold text-[10px]">100% Complete</span>
                </div>
                <div className="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                  <div className="bg-emerald-500 h-full w-full"></div>
                </div>
              </div>
            </div>

            {/* Card 7: Real-time reporting */}
            <div className="p-6 rounded-2xl border border-[#E2E8F0] bg-[#FAFAFA] hover:border-[#CBD5E1] transition-all flex flex-col justify-between group min-h-[300px]">
              <div>
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-sm text-[#0F172A]">Real-time reporting</h3>
                  <span className="text-[#94A3B8] group-hover:text-[#4F46E5] transition-colors">↗</span>
                </div>
                <p className="text-xs text-[#64748B] mt-1">Unified GAAP and Operator metrics</p>
              </div>
              <div className="bg-white p-3 rounded-xl border border-[#E2E8F0] shadow-xs space-y-1.5 mt-4 text-[10px]">
                <div className="flex justify-between"><span>Revenue</span><span className="font-bold">PKR 12.4M</span></div>
                <div className="flex justify-between"><span>COGS</span><span>PKR 3.2M</span></div>
                <div className="flex justify-between font-bold border-t pt-1 text-emerald-600"><span>EBITDA</span><span>PKR 6.8M</span></div>
              </div>
            </div>

            {/* Card 8: Security and Permissions */}
            <div className="p-6 rounded-2xl border border-[#E2E8F0] bg-[#FAFAFA] hover:border-[#CBD5E1] transition-all flex flex-col justify-between group min-h-[300px]">
              <div>
                <div className="flex items-center justify-between">
                  <h3 className="font-bold text-sm text-[#0F172A]">Security and Permissions</h3>
                  <span className="text-[#94A3B8] group-hover:text-[#4F46E5] transition-colors">↗</span>
                </div>
                <p className="text-xs text-[#64748B] mt-1">Every entry, every change, every reason</p>
              </div>
              <div className="bg-white p-3 rounded-xl border border-[#E2E8F0] shadow-xs space-y-1.5 mt-4 text-[10px]">
                <div className="flex items-center space-x-1.5 text-indigo-700 font-semibold">
                  <ShieldCheck className="w-3.5 h-3.5" />
                  <span>SOC 2 Type II Certified</span>
                </div>
                <p className="text-[#64748B] font-mono text-[9px]">Immutable SHA-256 ledger hash</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ─────────────────────────────────────────────────────────────
          6. INTERACTIVE LIVE PRODUCT DEMO BANNER
      ─────────────────────────────────────────────────────────────── */}
      <section className="bg-white pb-16">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="p-8 sm:p-10 rounded-3xl border border-[#E2E8F0] bg-[#F8FAFC] flex flex-col sm:flex-row items-center justify-between gap-6 shadow-xs">
            <div className="space-y-1.5 text-center sm:text-left">
              <h3 className="text-xl sm:text-2xl font-extrabold text-[#0F172A]">
                See a live product demo of Finova
              </h3>
              <p className="text-xs sm:text-sm text-[#64748B]">
                See why mid-market, enterprise and public companies are moving to Finova, the agentic ERP.
              </p>
            </div>
            <button
              onClick={() => setIsVideoModalOpen(true)}
              className="bg-black hover:bg-neutral-800 text-white text-xs font-semibold px-6 py-3 rounded-full shadow-sm hover:shadow transition-all cursor-pointer flex items-center space-x-2 shrink-0"
            >
              <Play className="w-3.5 h-3.5 fill-current" />
              <span>Watch the demo</span>
            </button>
          </div>
        </div>
      </section>

      {/* ─────────────────────────────────────────────────────────────
          7. SECTION 02: AXIOM AI (DEEP VIOLET / INDIGO WORKSPACE)
      ─────────────────────────────────────────────────────────────── */}
      <section id="axiom-ai" className="py-24 bg-gradient-to-b from-[#13092D] via-[#100626] to-[#0A031A] text-white relative overflow-hidden">
        {/* Subtle grid pattern background */}
        <div
          className="absolute inset-0 opacity-10 bg-[radial-gradient(#A855F7_1px,transparent_1px)] [background-size:24px_24px]"
        />

        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 space-y-16">
          {/* Section Kicker & Main Headline */}
          <div className="max-w-3xl space-y-4">
            <span className="text-[11px] font-mono font-bold tracking-widest text-[#A855F7] uppercase">
              02 AXIOM AI
            </span>
            <h2 className="text-3xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight text-white leading-tight">
              Offload the busy work. <br />
              <span className="text-white">Keep the control.</span>
            </h2>
            <p className="text-sm sm:text-base text-slate-300 leading-relaxed max-w-2xl">
              Not a chatbot. Specialized agents embedded in your workflows, trained on accounting, connected to your GL, ready to work.
            </p>
          </div>

          {/* Interactive Axiom Tabs */}
          <div className="flex flex-wrap items-center gap-2 border-b border-white/10 pb-4">
            {[
              { id: "assistant", label: ".AXIOM ASSISTANT" },
              { id: "agents", label: ".AXIOM AGENTS" },
              { id: "continuous", label: ".CONTINUOUS AI" },
              { id: "mcp", label: ".AXIOM MCP" },
            ].map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveAxiomTab(tab.id as any)}
                className={cn(
                  "px-4 py-2 rounded-full text-xs font-mono font-bold transition-all cursor-pointer",
                  activeAxiomTab === tab.id
                    ? "bg-[#6366F1] text-white shadow-lg shadow-indigo-500/30"
                    : "text-slate-400 hover:text-white hover:bg-white/5"
                )}
              >
                {tab.label}
              </button>
            ))}
          </div>

          {/* Feature 1: Ask Anything */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center bg-white/5 border border-white/10 rounded-3xl p-6 sm:p-10 backdrop-blur-md">
            <div className="lg:col-span-5 space-y-4">
              <span className="text-[10px] font-mono text-indigo-400 uppercase tracking-wider font-bold">
                Natural Language Financial Q&A
              </span>
              <h3 className="text-2xl font-bold text-white">Ask anything</h3>
              <p className="text-xs sm:text-sm text-slate-300 leading-relaxed">
                Ask questions in plain English. Get answers you can trust. Finova applies accounting logic first — then surfaces the result.
              </p>
              <div className="pt-2">
                <button
                  onClick={() => setIsDemoModalOpen(true)}
                  className="bg-white text-black hover:bg-slate-100 text-xs font-semibold px-4 py-2 rounded-full transition-colors cursor-pointer inline-flex items-center space-x-1"
                >
                  <span>Learn more</span>
                  <ChevronRight className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>

            {/* Interactive Prompt Simulation Card */}
            <div className="lg:col-span-7 bg-[#1C113B] border border-white/10 rounded-2xl p-6 shadow-2xl space-y-4">
              <div className="flex flex-wrap gap-2 pb-2">
                {samplePrompts.map((p, idx) => (
                  <button
                    key={idx}
                    onClick={() => setSelectedPrompt(idx)}
                    className={cn(
                      "text-[11px] px-3 py-1.5 rounded-lg border transition-all text-left cursor-pointer",
                      selectedPrompt === idx
                        ? "bg-[#6366F1] text-white border-indigo-400 font-semibold"
                        : "bg-white/5 border-white/10 text-slate-300 hover:bg-white/10"
                    )}
                  >
                    Question {idx + 1}
                  </button>
                ))}
              </div>

              <div className="bg-black/40 rounded-xl p-3 sm:p-4 border border-white/5 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-[10px] text-indigo-400 font-mono uppercase tracking-wider">Ask Axiom AI (Type or Pick Question):</span>
                  <span className="text-[9px] font-mono px-2 py-0.5 rounded bg-purple-950/80 text-purple-300 border border-purple-800/40">Powered by Groq</span>
                </div>
                <div className="flex space-x-2">
                  <input
                    type="text"
                    value={customSimulatorQuery}
                    onChange={(e) => {
                      setCustomSimulatorQuery(e.target.value);
                      setSimulatorLiveResponse(null);
                    }}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") handleRunSimulator();
                    }}
                    placeholder={samplePrompts[selectedPrompt].q}
                    className="flex-1 bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-xs text-white placeholder:text-slate-400 outline-none focus:border-indigo-400 font-mono"
                  />
                  <button
                    onClick={() => handleRunSimulator()}
                    disabled={simulatorLiveLoading}
                    className="bg-[#6366F1] hover:bg-[#4F46E5] text-white px-3.5 py-2 rounded-lg text-xs font-semibold cursor-pointer disabled:opacity-50 flex items-center space-x-1"
                  >
                    {simulatorLiveLoading ? (
                      <span className="w-3.5 h-3.5 rounded-full border-2 border-white border-t-transparent animate-spin inline-block" />
                    ) : (
                      <span>Run</span>
                    )}
                  </button>
                </div>
              </div>

              <div className="bg-indigo-950/60 rounded-xl p-4 border border-indigo-500/20 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-[10px] text-emerald-400 font-mono font-bold flex items-center space-x-1">
                    <Sparkles className="w-3 h-3" />
                    <span>AXIOM AI ACCOUNTING ENGINE RESPONSE</span>
                  </span>
                  <span className="text-[9px] px-2 py-0.5 rounded bg-emerald-950 text-emerald-300 border border-emerald-500/30">
                    {simulatorLiveResponse?.badge || samplePrompts[selectedPrompt].badge}
                  </span>
                </div>
                <p className="text-xs text-slate-200 leading-relaxed font-sans">
                  {simulatorLiveResponse?.answer || samplePrompts[selectedPrompt].a}
                </p>
              </div>
            </div>
          </div>

          {/* Feature 2: Automate accounting tasks end-to-end (6 Agent Cards) */}
          <div className="space-y-6">
            <div>
              <h3 className="text-2xl font-bold text-white">Automate accounting tasks end-to-end</h3>
              <p className="text-xs sm:text-sm text-slate-400 mt-1 max-w-2xl">
                Reliable, repeatable workflow agents. Write the rules in plain English and Axiom handles the schedule, data pulls, and handoffs.
              </p>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {[
                { title: "Reconciliation Rules Agent", desc: "Matches statements to invoices, bills & receipts with SHA-256 fingerprinting.", role: "Banking Matcher" },
                { title: "Accounting Vendor Agent", desc: "Automates 3-way matching across POs, GRNs and Vendor Bills with tolerance controls.", role: "AP Automation" },
                { title: "Customer Agent", desc: "Generates date-versioned sales tax invoices with QR fiscalization and tracking.", role: "AR Subledger" },
                { title: "Invoices Agent", desc: "Calculates statutory provincial withholding tax (Section 153) on every disbursement.", role: "Tax Calculator" },
                { title: "Accrual Invoices Agent", desc: "Builds straight-line amortization schedules and deferred revenue releases (ASC 606).", role: "RevRec Engine" },
                { title: "Consolidation Agent", desc: "Executes reciprocal intercompany eliminations and IAS 21 unrealized FX revaluations.", role: "Multi-Entity" },
              ].map((agent, i) => (
                <div
                  key={i}
                  className="p-5 rounded-2xl bg-white/5 border border-white/10 hover:border-indigo-400/50 hover:bg-white/10 transition-all space-y-3 cursor-pointer group"
                >
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] font-mono font-bold px-2 py-0.5 rounded bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                      {agent.role}
                    </span>
                    <span className="text-xs text-slate-500 group-hover:text-indigo-400 transition-colors">↗</span>
                  </div>
                  <h4 className="font-bold text-sm text-white">{agent.title}</h4>
                  <p className="text-xs text-slate-300 leading-relaxed">{agent.desc}</p>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* ─────────────────────────────────────────────────────────────
          8. SECTION 03: TESTIMONIALS & TRUST BADGES
      ─────────────────────────────────────────────────────────────── */}
      <section id="testimonials" className="py-24 bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-16">
          <div className="text-center max-w-2xl mx-auto space-y-2">
            <span className="text-[11px] font-mono font-bold tracking-widest text-[#4F46E5] uppercase">
              03 TESTIMONIALS
            </span>
            <h2 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-[#0F172A]">
              Hear what our customers have to say about us
            </h2>
          </div>

          {/* 3 Video Cards */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {[
              {
                name: "Brock Boyer",
                title: "Controller, Jump",
                company: "Jump",
                quote: "Finova is the clear leader as the AI-native ERP. And it's built by accountants for accountants. I've never closed this fast.",
                image: "https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=600&auto=format&fit=crop",
              },
              {
                name: "Sarah Jenkins",
                title: "VP Finance, Scribe",
                company: "Scribe",
                quote: "The ability to run continuous reconciliation with strict double-entry mathematical invariants made our external audit seamless.",
                image: "https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?q=80&w=600&auto=format&fit=crop",
              },
              {
                name: "Farhan Malik",
                title: "Chief Financial Officer, Apex Group",
                company: "Apex Holdings",
                quote: "From provincial sales tax withholding to multi-currency consolidation across Dubai and Pakistan, Finova replaced 6 spreadsheets.",
                image: "https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=600&auto=format&fit=crop",
              },
            ].map((card, i) => (
              <div
                key={i}
                className="rounded-2xl border border-[#E2E8F0] bg-[#F8FAFC] overflow-hidden flex flex-col justify-between shadow-xs hover:shadow-md transition-shadow group"
              >
                <div className="relative h-56 bg-slate-200 overflow-hidden">
                  <div
                    className="absolute inset-0 bg-cover bg-center group-hover:scale-105 transition-transform duration-500"
                    style={{ backgroundImage: `url(${card.image})` }}
                  />
                  <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                  <button
                    onClick={() => setIsVideoModalOpen(true)}
                    className="absolute inset-0 m-auto w-12 h-12 rounded-full bg-white/90 text-black flex items-center justify-center shadow-lg hover:scale-110 transition-transform cursor-pointer"
                  >
                    <Play className="w-5 h-5 fill-current text-black ml-0.5" />
                  </button>
                  <div className="absolute bottom-3 left-4 text-white">
                    <span className="font-bold text-xs uppercase tracking-wider">{card.company}</span>
                  </div>
                </div>
                <div className="p-5 space-y-3">
                  <p className="text-xs text-[#334155] italic leading-relaxed">
                    &ldquo;{card.quote}&rdquo;
                  </p>
                  <div className="pt-2 border-t border-[#E2E8F0]">
                    <h5 className="font-bold text-xs text-[#0F172A]">{card.name}</h5>
                    <span className="text-[10px] text-[#64748B]">{card.title}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>

          {/* G2 and Trust Badges Row */}
          <div className="pt-6 border-t border-[#F1F5F9] flex flex-wrap items-center justify-center gap-4 text-center">
            {[
              "Best Support 2024",
              "Easiest Setup 2024",
              "Momentum Leader 2024",
              "High Performer 2024",
              "Users Love Us",
              "AICPA SOC 2 Type II",
              "ISO 27001 Certified",
            ].map((badge, idx) => (
              <span
                key={idx}
                className="px-3 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-[10px] font-bold text-slate-700 shadow-2xs"
              >
                🛡 {badge}
              </span>
            ))}
          </div>
        </div>
      </section>

      {/* ─────────────────────────────────────────────────────────────
          9. FINAL CALL TO ACTION (CTA) BANNER
      ─────────────────────────────────────────────────────────────── */}
      <section className="bg-gradient-to-r from-[#2E1065] via-[#3B0764] to-[#1E1B4B] text-white py-20">
        <div className="max-w-4xl mx-auto px-4 text-center space-y-6">
          <h2 className="text-3xl sm:text-5xl font-extrabold tracking-tight">
            Get one step closer to Zero-Day Close
          </h2>
          <p className="text-sm sm:text-base text-slate-300 max-w-xl mx-auto">
            Faster close, fewer spreadsheets, real-time data. Built specifically for growing modern finance teams.
          </p>
          <div className="flex flex-wrap items-center justify-center gap-3 pt-2">
            <button
              onClick={() => setIsDemoModalOpen(true)}
              className="bg-white text-black hover:bg-slate-100 text-xs font-semibold px-6 py-3 rounded-full shadow-lg hover:shadow-xl transition-all cursor-pointer"
            >
              Request a demo
            </button>
            <button
              onClick={() => setIsLoginModalOpen(true)}
              className="bg-white/10 hover:bg-white/20 border border-white/20 text-white text-xs font-semibold px-6 py-3 rounded-full transition-all flex items-center space-x-1.5 cursor-pointer"
            >
              <span>Sign in to your account</span>
              <ArrowRight className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </section>

      {/* ─────────────────────────────────────────────────────────────
          10. COMPREHENSIVE FOOTER
      ─────────────────────────────────────────────────────────────── */}
      <footer id="footer" className="bg-white border-t border-[#E2E8F0] pt-16 pb-12 text-xs text-[#64748B]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
          {/* Newsletter Subscribe */}
          <div className="p-6 bg-[#F8FAFC] border border-[#E2E8F0] rounded-2xl flex flex-col md:flex-row items-center justify-between gap-4">
            <div>
              <h4 className="font-bold text-sm text-[#0F172A]">Subscribe to our newsletter</h4>
              <p className="text-xs text-[#64748B]">Keep up to date on Finova and continuous close accounting.</p>
            </div>
            <form onSubmit={(e) => { e.preventDefault(); alert("Thank you for subscribing!"); }} className="flex w-full md:w-auto space-x-2">
              <input
                type="email"
                required
                placeholder="Business email"
                className="px-3 py-2 border border-[#CBD5E1] rounded-lg text-xs w-full sm:w-64 bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
              />
              <button
                type="submit"
                className="bg-[#4F46E5] hover:bg-[#4338CA] text-white px-4 py-2 rounded-lg text-xs font-semibold transition-colors cursor-pointer shrink-0"
              >
                Subscribe
              </button>
            </form>
          </div>

          {/* 5 Columns */}
          <div className="grid grid-cols-2 md:grid-cols-5 gap-8">
            <div className="space-y-3">
              <h5 className="font-bold text-xs text-[#0F172A] uppercase tracking-wider">PRODUCT</h5>
              <ul className="space-y-2 text-[11px]">
                <li><a href="#axiom-ai" className="hover:text-[#0F172A]">Axiom AI</a></li>
                <li><a href="#platform" className="hover:text-[#0F172A]">Advanced revenue recognition</a></li>
                <li><a href="#platform" className="hover:text-[#0F172A]">Multi-entity consolidation</a></li>
                <li><a href="#platform" className="hover:text-[#0F172A]">Security and Permissions</a></li>
                <li><a href="#platform" className="hover:text-[#0F172A]">Automated general ledger</a></li>
                <li><a href="#platform" className="hover:text-[#0F172A]">Bank reconciliation</a></li>
              </ul>
            </div>

            <div className="space-y-3">
              <h5 className="font-bold text-xs text-[#0F172A] uppercase tracking-wider">SOLUTIONS</h5>
              <ul className="space-y-2 text-[11px]">
                <li><span className="text-[#94A3B8] font-semibold">BY ROLE</span></li>
                <li><a href="#" className="hover:text-[#0F172A]">CFO & Finance Director</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Controllers & Accountants</a></li>
                <li><span className="text-[#94A3B8] font-semibold block pt-2">BY COMPANY SIZE</span></li>
                <li><a href="#" className="hover:text-[#0F172A]">Startups & Tech Agencies</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Mid-Market Enterprises</a></li>
              </ul>
            </div>

            <div className="space-y-3">
              <h5 className="font-bold text-xs text-[#0F172A] uppercase tracking-wider">RESOURCES</h5>
              <ul className="space-y-2 text-[11px]">
                <li><a href="#" className="hover:text-[#0F172A]">Continuous Close Guides</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Finova Academy</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Accounting Law & Tax Rules</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Help Center & Docs</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Close Club Community</a></li>
              </ul>
            </div>

            <div className="space-y-3">
              <h5 className="font-bold text-xs text-[#0F172A] uppercase tracking-wider">COMPANY</h5>
              <ul className="space-y-2 text-[11px]">
                <li><a href="#" className="hover:text-[#0F172A]">About Us</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Careers</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Customer Success</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Terms of Use</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Privacy Policy</a></li>
              </ul>
            </div>

            <div className="space-y-3">
              <h5 className="font-bold text-xs text-[#0F172A] uppercase tracking-wider">PARTNERS</h5>
              <ul className="space-y-2 text-[11px]">
                <li><a href="#" className="hover:text-[#0F172A]">First Dollar Alliance</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Become a Partner</a></li>
                <li><a href="#" className="hover:text-[#0F172A]">Partner Advisory Board</a></li>
              </ul>
            </div>
          </div>

          {/* Bottom Copyright */}
          <div className="pt-8 border-t border-[#F1F5F9] flex flex-col sm:flex-row items-center justify-between gap-4 text-[11px]">
            <div className="flex items-center space-x-2">
              <span className="font-extrabold text-base text-[#0F172A] font-serif">Finova</span>
              <span>© 2026 Finova. &ldquo;Finova&rdquo; and the Finova logo are registered trademarks.</span>
            </div>
            <div className="flex items-center space-x-4 text-[#94A3B8]">
              <span>G2 5.0 Star Rated</span>
              <span>•</span>
              <span>SOC 2 Type II</span>
              <span>•</span>
              <span>Strict Double-Entry Invariant</span>
            </div>
          </div>
        </div>
      </footer>

      {/* ─────────────────────────────────────────────────────────────
          MODAL: BOOK A LIVE PRODUCT DEMO
      ─────────────────────────────────────────────────────────────── */}
      {isDemoModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-lg rounded-3xl shadow-2xl border border-[#E2E8F0] p-6 sm:p-8 space-y-6">
            <div className="flex items-center justify-between border-b pb-4">
              <div>
                <h3 className="font-bold text-lg text-[#0F172A]">Book a Live Product Demo</h3>
                <p className="text-xs text-[#64748B]">
                  See Zero-Day Close, continuous GL, and Axiom AI personalized for your team.
                </p>
              </div>
              <button
                onClick={() => {
                  setIsDemoModalOpen(false);
                  setDemoSubmitted(false);
                }}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {demoSubmitted ? (
              <div className="py-8 text-center space-y-4">
                <div className="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                  <CheckCircle2 className="w-8 h-8" />
                </div>
                <h4 className="font-bold text-base text-[#0F172A]">Demo Confirmed for {demoForm.name}!</h4>
                <p className="text-xs text-[#64748B] max-w-sm mx-auto leading-relaxed">
                  We have reserved your session for <strong>{demoForm.preferredDate} at {demoForm.preferredTime}</strong>. A calendar invite has been sent to <strong>{demoForm.email}</strong>.
                </p>
                <div className="pt-4 flex justify-center space-x-3">
                  <button
                    onClick={() => {
                      setIsDemoModalOpen(false);
                      setDemoSubmitted(false);
                      setIsLoginModalOpen(true);
                    }}
                    className="bg-[#4F46E5] text-white text-xs font-semibold px-5 py-2.5 rounded-xl hover:bg-[#4338CA] transition-colors cursor-pointer"
                  >
                    Sign in to Dashboard →
                  </button>
                  <button
                    onClick={() => {
                      setIsDemoModalOpen(false);
                      setDemoSubmitted(false);
                    }}
                    className="border border-[#CBD5E1] px-4 py-2.5 rounded-xl text-xs font-semibold text-[#475569]"
                  >
                    Close
                  </button>
                </div>
              </div>
            ) : (
              <form onSubmit={handleDemoSubmit} className="space-y-4 text-xs">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block font-semibold text-[#0F172A] mb-1">Your Full Name</label>
                    <input
                      type="text"
                      required
                      placeholder="e.g. Sarah Jenkins"
                      value={demoForm.name}
                      onChange={(e) => setDemoForm({ ...demoForm, name: e.target.value })}
                      className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    />
                  </div>
                  <div>
                    <label className="block font-semibold text-[#0F172A] mb-1">Work Email</label>
                    <input
                      type="email"
                      required
                      placeholder="sarah@company.com"
                      value={demoForm.email}
                      onChange={(e) => setDemoForm({ ...demoForm, email: e.target.value })}
                      className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block font-semibold text-[#0F172A] mb-1">Company Name</label>
                    <input
                      type="text"
                      required
                      placeholder="e.g. Apex Holdings"
                      value={demoForm.company}
                      onChange={(e) => setDemoForm({ ...demoForm, company: e.target.value })}
                      className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    />
                  </div>
                  <div>
                    <label className="block font-semibold text-[#0F172A] mb-1">Finance Team Size</label>
                    <select
                      value={demoForm.teamSize}
                      onChange={(e) => setDemoForm({ ...demoForm, teamSize: e.target.value })}
                      className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    >
                      <option value="1-5">1-5 members</option>
                      <option value="6-20">6-20 members</option>
                      <option value="21-50">21-50 members</option>
                      <option value="50+">50+ members</option>
                    </select>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block font-semibold text-[#0F172A] mb-1">Preferred Date</label>
                    <input
                      type="date"
                      required
                      value={demoForm.preferredDate}
                      onChange={(e) => setDemoForm({ ...demoForm, preferredDate: e.target.value })}
                      className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    />
                  </div>
                  <div>
                    <label className="block font-semibold text-[#0F172A] mb-1">Preferred Time</label>
                    <select
                      value={demoForm.preferredTime}
                      onChange={(e) => setDemoForm({ ...demoForm, preferredTime: e.target.value })}
                      className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    >
                      <option value="09:00 AM PST">09:00 AM PST</option>
                      <option value="11:00 AM PST">11:00 AM PST</option>
                      <option value="02:00 PM PST">02:00 PM PST</option>
                      <option value="04:00 PM PST">04:00 PM PST</option>
                    </select>
                  </div>
                </div>

                <div>
                  <label className="block font-semibold text-[#0F172A] mb-1">Primary Area of Interest</label>
                  <select
                    value={demoForm.interest}
                    onChange={(e) => setDemoForm({ ...demoForm, interest: e.target.value })}
                    className="w-full px-3 py-2 border border-[#E2E8F0] rounded-lg text-xs bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                  >
                    <option value="Zero-Day Close & Axiom AI">Zero-Day Close & Axiom AI Agents</option>
                    <option value="Multi-Entity & FX Consolidation">Multi-Entity & FX Currency Consolidation</option>
                    <option value="Pakistan Sales Tax & FBR">Pakistan Sales Tax & FBR Invoicing</option>
                    <option value="Bank Reconciliation & 3-Way Match">Bank Statement Reconcile & AP 3-Way Match</option>
                  </select>
                </div>

                <div className="flex justify-end space-x-2 pt-3 border-t">
                  <button
                    type="button"
                    onClick={() => setIsDemoModalOpen(false)}
                    className="px-4 py-2 border border-[#E2E8F0] rounded-lg text-xs font-semibold text-[#475569] hover:bg-[#F8FAFC] transition-colors cursor-pointer"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-5 py-2 bg-black hover:bg-neutral-800 text-white rounded-lg text-xs font-semibold shadow-xs transition-colors cursor-pointer"
                  >
                    Confirm Demo Booking
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: LOGIN
      ─────────────────────────────────────────────────────────────── */}
      {isLoginModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-sm rounded-3xl shadow-2xl border border-[#E2E8F0] p-6 space-y-5">
            <div className="flex items-center justify-between border-b pb-3">
              <div className="flex items-center space-x-2">
                <div className="w-7 h-7 rounded-lg bg-[#4F46E5] text-white flex items-center justify-center font-bold text-xs">
                  Fi
                </div>
                <h3 className="font-bold text-sm text-[#0F172A]">Sign in to Finova</h3>
              </div>
              <button
                onClick={() => {
                  setIsLoginModalOpen(false);
                  setLoginError("");
                }}
                className="text-[#94A3B8] hover:text-[#0F172A] p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form
              onSubmit={async (e) => {
                e.preventDefault();
                setLoginError("");
                setLoginLoading(true);
                try {
                  clearStoredSession();
                  const data = await erpApi.login(loginEmail, loginPassword);
                  setStoredSession(data.token, data.user);
                  const orgs = await erpApi.getOrganizations();
                  if (!orgs[0]) {
                    throw new Error("This account has no organization with live demo data.");
                  }
                  setStoredSession(data.token, data.user, orgs[0]);
                  // Navigate to the ERP dashboard as an authenticated user
                  router.push("/app");
                } catch (err: any) {
                  setLoginError(err.message || "Invalid credentials. Please try again.");
                  setLoginLoading(false);
                }
              }}
              className="space-y-4 text-xs"
            >
              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Email</label>
                <input
                  type="email"
                  required
                  placeholder="you@company.com"
                  value={loginEmail}
                  onChange={(e) => setLoginEmail(e.target.value)}
                  className="w-full px-3 py-2.5 border border-[#E2E8F0] rounded-lg text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 focus:outline-none transition-all"
                />
              </div>

              <div>
                <label className="block font-semibold text-[#0F172A] mb-1">Password</label>
                <input
                  type="password"
                  required
                  placeholder="••••••••"
                  value={loginPassword}
                  onChange={(e) => setLoginPassword(e.target.value)}
                  className="w-full px-3 py-2.5 border border-[#E2E8F0] rounded-lg text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 focus:outline-none transition-all"
                />
              </div>

              {loginError && (
                <div className="p-2.5 bg-red-50 border border-red-200 rounded-xl text-[11px] text-red-700 font-medium">
                  {loginError}
                </div>
              )}

              <div className="p-3 bg-indigo-50/60 border border-indigo-100 rounded-xl text-[10px] text-indigo-900 leading-relaxed">
                <strong>Demo credentials pre-filled.</strong> Click &quot;Sign In&quot; to access the dashboard.
              </div>

              <button
                type="submit"
                disabled={loginLoading}
                className={cn(
                  "w-full font-semibold py-2.5 rounded-xl shadow-xs transition-all cursor-pointer text-xs flex items-center justify-center space-x-2",
                  loginLoading
                    ? "bg-[#6366F1]/70 text-white/80 cursor-wait"
                    : "bg-[#4F46E5] hover:bg-[#4338CA] text-white"
                )}
              >
                {loginLoading ? (
                  <>
                    <span className="w-4 h-4 rounded-full border-2 border-white border-t-transparent animate-spin inline-block" />
                    <span>Signing in…</span>
                  </>
                ) : (
                  <>
                    <Lock className="w-3.5 h-3.5" />
                    <span>Sign In</span>
                  </>
                )}
              </button>

              <p className="text-center text-[10px] text-[#94A3B8]">
                By signing in you agree to Finova&apos;s Terms of Service and Privacy Policy.
              </p>
            </form>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          MODAL: WATCH LIVE DEMO WALKTHROUGH
      ─────────────────────────────────────────────────────────────── */}
      {isVideoModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-black w-full max-w-3xl rounded-3xl overflow-hidden shadow-2xl border border-white/20 space-y-0">
            <div className="bg-[#111] px-4 py-3 flex items-center justify-between text-white border-b border-white/10">
              <span className="text-xs font-bold font-mono">Finova Product Walkthrough • Zero-Day Close</span>
              <button
                onClick={() => setIsVideoModalOpen(false)}
                className="text-slate-400 hover:text-white p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="aspect-video bg-gradient-to-tr from-[#1E0E4E] via-[#0D0422] to-black flex flex-col items-center justify-center p-8 text-center text-white space-y-4">
              <div className="w-16 h-16 rounded-full bg-[#4F46E5] flex items-center justify-center shadow-2xl">
                <Play className="w-6 h-6 fill-current text-white ml-1" />
              </div>
              <h4 className="text-lg font-bold">Watch Finova in Action</h4>
              <p className="text-xs text-slate-400 max-w-md">
                Experience automated 3-way matching, instant bank statement reconciliation, multi-entity elimination, and Axiom AI in real time.
              </p>
              <div className="pt-2 flex space-x-3">
                <button
                  onClick={() => {
                    setIsVideoModalOpen(false);
                    setIsLoginModalOpen(true);
                  }}
                  className="bg-white text-black text-xs font-semibold px-4 py-2 rounded-full hover:bg-slate-200 transition-colors cursor-pointer"
                >
                  Sign In & Open Dashboard
                </button>
                <button
                  onClick={() => setIsVideoModalOpen(false)}
                  className="border border-white/30 text-white text-xs font-semibold px-4 py-2 rounded-full hover:bg-white/10"
                >
                  Close Preview
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
