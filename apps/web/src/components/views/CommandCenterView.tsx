"use client";

import React, { useState } from "react";
import {
  Sparkles,
  Bot,
  Workflow,
  ShieldCheck,
  CheckCircle2,
  Clock,
  AlertCircle,
  FileText,
  FilePenLine,
  Landmark,
  Scale,
  RefreshCw,
  Send,
  Zap,
  Layers,
  ArrowRight,
  TrendingUp,
  Cpu,
  Check,
  X,
  Play,
  RotateCcw,
  SlidersHorizontal,
  ChevronRight,
  ExternalLink,
  Building,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";
import { Badge } from "../ui/Badge";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { askAxiomAI, AxiomResponse } from "@/lib/axiom";

export interface QueueItem {
  id: string;
  domain: "AR" | "AP" | "GL" | "Close" | "Recon";
  title: string;
  description: string;
  source: "AP Match Agent" | "Tax Agent" | "Continuous Close Agent" | "Treasury Agent" | "Workflow Flow";
  owner: string;
  estimatedGlImpact: string;
  status: "pending_approval" | "needs_review" | "running" | "scheduled";
  createdAt: string;
  accountsInvolved?: { debit: string; credit: string; amount: number };
  proposalType?: "draft_journal" | "tax_adjustment" | "match_waiver";
}

export interface FinancialAgent {
  id: string;
  name: string;
  role: string;
  description: string;
  icon: any;
  status: "active" | "idle" | "running";
  model: string;
  lastExecution: string;
  processedCount: number;
  accuracyRate: string;
  tasksQueued: number;
}

export interface FlowStep {
  name: string;
  description: string;
  status: "completed" | "running" | "pending";
  runtimeMs?: number;
  outputSummary?: string;
}

export interface FlowPlan {
  id: string;
  name: string;
  category: "Procurement" | "Month-End Close" | "Cash Clearing";
  trigger: string;
  status: "active" | "running" | "idle";
  lastRun: string;
  avgDuration: string;
  steps: FlowStep[];
}

export interface ActivityItem {
  id: string;
  type: "erp_write" | "journal_entry" | "email" | "report" | "workflow_run" | "chat_run";
  title: string;
  summary: string;
  author: string;
  timestamp: string;
  glCode?: string;
  sha256Hash: string;
}

interface CommandCenterViewProps {
  onApproveItem?: (id: string) => void;
  onRejectItem?: (id: string) => void;
  onReviewItem?: (item: QueueItem) => void;
  orgName?: string;
  className?: string;
}

export function CommandCenterView({
  onApproveItem,
  onRejectItem,
  onReviewItem,
  orgName = "Apex Trading (Pvt) Ltd",
  className,
}: CommandCenterViewProps) {
  const [activeTab, setActiveTab] = useState<"assistant" | "agents" | "flows" | "queue" | "activity">("assistant");

  // 1. Assistant Console State
  const [assistantInput, setAssistantInput] = useState("");
  const [assistantLoading, setAssistantLoading] = useState(false);
  const [lastAxiomResponse, setLastAxiomResponse] = useState<AxiomResponse | null>(null);
  const [assistantMessages, setAssistantMessages] = useState<
    { role: "user" | "assistant"; content: string; time: string; metrics?: Record<string, string>; actions?: string[] }[]
  >([
    {
      role: "assistant",
      content:
        `Welcome to the Axiom AI Workspace for ${orgName}. I am your Autonomous Financial Controller connected to live General Ledger journals, FBR tax rules, and 3-Way matching pipelines. Ask me about your close status, bill variances, or request a draft journal.`,
      time: "Just now",
      metrics: {
        "GL Invariant": "Balanced (0 Diff)",
        "Base Currency": "PKR",
        "Active Period": "August 2025",
      },
      actions: [
        "What's left on my month-end close?",
        "Audit 3-way match variances on AP bills",
        "Check FBR ATL compliance on vendors",
      ],
    },
  ]);

  // 2. Autonomous Agents Dataset
  const [agents, setAgents] = useState<FinancialAgent[]>([
    {
      id: "agent-ap-match",
      name: "AP & 3-Way Match Agent",
      role: "Autonomous Subledger Auditor",
      description: "Continuously validates Purchase Orders vs. Goods Receipts (GRN) vs. Vendor Bills. Flags quantity and price variances exceeding ±2.0% tolerance.",
      icon: Scale,
      status: "active",
      model: "Groq LPU (Llama 3.3 70B)",
      lastExecution: "3 mins ago",
      processedCount: 142,
      accuracyRate: "99.4%",
      tasksQueued: 2,
    },
    {
      id: "agent-tax-fbr",
      name: "FBR Statutory & Tax Agent",
      role: "Tax Compliance Inspector",
      description: "Cross-checks vendor NTNs against the Federal Board of Revenue Active Taxpayer List (ATL). Calculates Section 153 WHT deductions and prepares Annex-C returns.",
      icon: ShieldCheck,
      status: "active",
      model: "Groq LPU (Llama 3.3 70B)",
      lastExecution: "12 mins ago",
      processedCount: 88,
      accuracyRate: "100%",
      tasksQueued: 0,
    },
    {
      id: "agent-invariant-audit",
      name: "GL Invariant & Security Agent",
      role: "Cryptographic Integrity Guardian",
      description: "Enforces non-negotiable double-entry rule (Debits == Credits). Verifies SHA-256 fingerprint chains and blocks manual direct journals to control accounts.",
      icon: Cpu,
      status: "active",
      model: "Axiom Deterministic Invariant Verifier",
      lastExecution: "Just now",
      processedCount: 512,
      accuracyRate: "100%",
      tasksQueued: 0,
    },
    {
      id: "agent-continuous-close",
      name: "Continuous Close Agent",
      role: "Month-End Operations Controller",
      description: "Monitors close countdown, inspects subledger-to-GL account flux (>10% month-over-month), and drafts fixed asset depreciation and prepaid expense amortization.",
      icon: Clock,
      status: "active",
      model: "Groq LPU (Llama 3.3 70B)",
      lastExecution: "25 mins ago",
      processedCount: 64,
      accuracyRate: "98.8%",
      tasksQueued: 3,
    },
    {
      id: "agent-treasury-recon",
      name: "Cash & Treasury Agent",
      role: "Bank Statement Clearing Worker",
      description: "Parses Meezan Bank & HBL CSV statement feeds, computes SHA-256 deduplication fingerprints, and executes regex matching rules with auto-reconcile confidence scoring.",
      icon: Landmark,
      status: "active",
      model: "Groq LPU (Llama 3.3 70B)",
      lastExecution: "1 hour ago",
      processedCount: 230,
      accuracyRate: "99.1%",
      tasksQueued: 1,
    },
  ]);

  // 3. Flow Execution Plans Dataset
  const [flows, setFlows] = useState<FlowPlan[]>([
    {
      id: "FLOW-001",
      name: "Vendor Bill Ingestion & 3-Way Match Pipeline",
      category: "Procurement",
      trigger: "New Vendor Bill Draft or OCR Upload",
      status: "active",
      lastRun: "8 mins ago",
      avgDuration: "3.2s",
      steps: [
        { name: "Document OCR & Extraction", description: "Extract NTN, Invoice Number, line items, and GST amount", status: "completed", runtimeMs: 1100 },
        { name: "FBR Active Taxpayer Check", description: "Verify vendor active status on FBR portal", status: "completed", runtimeMs: 450, outputSummary: "ATL Active ✓ (Withholding rate 4.5%)" },
        { name: "3-Way PO & GRN Match", description: "Enforce ±2.0% tolerance across quantities and prices", status: "completed", runtimeMs: 320, outputSummary: "Tolerance verified: 0.0% variance" },
        { name: "Multi-Tier Approval Routing", description: "Route to Finance Manager gate if amount > PKR 100k", status: "completed", runtimeMs: 180, outputSummary: "Routed to Manager Inbox" },
        { name: "Double-Entry Posting Proposal", description: "Construct balanced draft journal: DR 5010 / CR 2010", status: "completed", runtimeMs: 210, outputSummary: "Draft Journal #JE-2025-0044 created" },
      ],
    },
    {
      id: "FLOW-002",
      name: "Continuous Month-End Accruals & Close Pipeline",
      category: "Month-End Close",
      trigger: "Daily Schedule at 00:00 UTC or Soft-Close Request",
      status: "active",
      lastRun: "Today at 00:00",
      avgDuration: "5.8s",
      steps: [
        { name: "Prepaid Contracts Query", description: "Inspect active prepaid expense schedules in Account 1150", status: "completed", runtimeMs: 640 },
        { name: "Daily Amortization Computation", description: "Calculate straight-line monthly portion for August 2025", status: "completed", runtimeMs: 380, outputSummary: "PKR 45,000 amortization computed" },
        { name: "Fixed Asset Depreciation Run", description: "Compute straight-line depreciation across machinery and computers", status: "completed", runtimeMs: 820, outputSummary: "PKR 10,000 depreciation calculated" },
        { name: "Flux Variance Detection", description: "Flag GL accounts with month-over-month variance > 10%", status: "completed", runtimeMs: 910, outputSummary: "1 account flagged: Electricity (PKR +18%)" },
        { name: "Close Checklist Auto-Sync", description: "Update close task sign-off status and progress gauge", status: "completed", runtimeMs: 400, outputSummary: "Checklist auto-updated to 76%" },
      ],
    },
    {
      id: "FLOW-003",
      name: "Dual-Sided Statement Cash Clearing Pipeline",
      category: "Cash Clearing",
      trigger: "Bank Statement CSV Ingestion",
      status: "active",
      lastRun: "1 hour ago",
      avgDuration: "2.4s",
      steps: [
        { name: "CSV Parse & Hash Deduplication", description: "Compute SHA-256 fingerprint per transaction to prevent double ingestion", status: "completed", runtimeMs: 510 },
        { name: "Pattern Regex Rule Execution", description: "Evaluate target GL accounts with confidence rating >= 95%", status: "completed", runtimeMs: 730, outputSummary: "18 transactions matched automatically" },
        { name: "Unmatched Ledger Proposal", description: "Suggest draft journal entry for bank fees and FED charges", status: "completed", runtimeMs: 420, outputSummary: "PKR 1,160 FED tax entry proposed" },
      ],
    },
  ]);

  const [selectedFlow, setSelectedFlow] = useState<FlowPlan | null>(null);

  // 4. Draft Proposals Queue
  const [queueSearch, setQueueSearch] = useState("");
  const [queueDomainFilter, setQueueDomainFilter] = useState("all");
  const [queueItems, setQueueItems] = useState<QueueItem[]>([
    {
      id: "Q-101",
      domain: "AP",
      title: "Vendor Bill Accrual Proposal: AWS Cloud Hosting",
      description: "Axiom AI proposes monthly cloud infrastructure accrual based on 6-month historical average (PKR 350,000).",
      source: "AP Match Agent",
      owner: "Axiom AP Agent",
      estimatedGlImpact: "DR 6030 (Software & Hosting) / CR 2050 (Accrued Expenses)",
      status: "pending_approval",
      createdAt: "Today at 08:30",
      accountsInvolved: { debit: "6030", credit: "2050", amount: 350000 },
      proposalType: "draft_journal",
    },
    {
      id: "Q-102",
      domain: "Close",
      title: "Straight-Line Prepaid Software Amortization",
      description: "Axiom proposes monthly amortization of annual NetSuite license contract (PKR 45,000).",
      source: "Continuous Close Agent",
      owner: "Close Agent",
      estimatedGlImpact: "DR 6030 (IT Subscriptions) / CR 1150 (Prepaid Expenses)",
      status: "pending_approval",
      createdAt: "Today at 09:15",
      accountsInvolved: { debit: "6030", credit: "1150", amount: 45000 },
      proposalType: "draft_journal",
    },
    {
      id: "Q-103",
      domain: "Recon",
      title: "Bank Service Charges & FED Tax Adjustment",
      description: "Auto-detected debit of PKR 1,160 on Meezan Bank statement with no matching journal.",
      source: "Treasury Agent",
      owner: "Treasury Agent",
      estimatedGlImpact: "DR 6090 (Bank Fees & FED) / CR 1010 (Operating Cash)",
      status: "needs_review",
      createdAt: "Yesterday at 17:00",
      accountsInvolved: { debit: "6090", credit: "1010", amount: 1160 },
      proposalType: "draft_journal",
    },
  ]);

  // 5. Activity Log
  const [activityLog] = useState<ActivityItem[]>([
    {
      id: "ACT-801",
      type: "journal_entry",
      title: "Prepaid Insurance Amortization Draft Posted",
      summary: "Journal #JE-2025-0012 confirmed by Controller. Total: PKR 45,000.",
      author: "Haseeb (Controller)",
      timestamp: "Today at 14:22",
      glCode: "#JE-2025-0012",
      sha256Hash: "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    },
    {
      id: "ACT-802",
      type: "erp_write",
      title: "FBR QR Fiscalization Completed: Invoice #INV-2025-0012",
      summary: "Digitally signed with FBR POS IRIS gateway. QR Code generated.",
      author: "FBR Integration Worker",
      timestamp: "Today at 13:05",
      glCode: "#INV-2025-0012",
      sha256Hash: "8f434346648f6b96df89dda901c5176b10a6d83961dd3c1ac88b59b2dc327aa4",
    },
    {
      id: "ACT-803",
      type: "workflow_run",
      title: "3-Way Match Verification Pipeline Executed",
      summary: "Validated Bill #BILL-2025-001 against PO-2025-0001 and GRN-2025-0001. 0.0% variance.",
      author: "AP Match Agent",
      timestamp: "Today at 11:30",
      sha256Hash: "ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48bb",
    },
  ]);

  // Assistant Query Handler
  const handleSendMessage = async (customQuery?: string) => {
    const query = customQuery || assistantInput.trim();
    if (!query || assistantLoading) return;
    setAssistantInput("");

    setAssistantMessages((prev) => [
      ...prev,
      {
        role: "user",
        content: query,
        time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
      },
    ]);

    setAssistantLoading(true);
    try {
      const res = await askAxiomAI(query, {
        organization: orgName,
        currency: "PKR",
        period: "August 2025",
        gl_invariant: "balanced",
      });
      setLastAxiomResponse(res);
      setAssistantMessages((prev) => [
        ...prev,
        {
          role: "assistant",
          content: res.answer,
          time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
          metrics: res.metrics,
          actions: res.suggested_actions,
        },
      ]);
    } catch {
      setAssistantMessages((prev) => [
        ...prev,
        {
          role: "assistant",
          content: "Axiom AI encountered a temporary issue connecting to Groq LPU. Please verify your Groq API key in the top settings modal.",
          time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
        },
      ]);
    } finally {
      setAssistantLoading(false);
    }
  };

  const filteredQueue = queueItems.filter((item) => {
    const matchesDomain = queueDomainFilter === "all" || item.domain === queueDomainFilter;
    const matchesSearch =
      !queueSearch ||
      item.title.toLowerCase().includes(queueSearch.toLowerCase()) ||
      item.description.toLowerCase().includes(queueSearch.toLowerCase()) ||
      item.owner.toLowerCase().includes(queueSearch.toLowerCase());
    return matchesDomain && matchesSearch;
  });

  const handleApprove = (id: string) => {
    setQueueItems((prev) => prev.filter((it) => it.id !== id));
    if (onApproveItem) onApproveItem(id);
    else alert(`Proposal ${id} approved and draft journal routed to General Ledger.`);
  };

  const handleReject = (id: string) => {
    setQueueItems((prev) => prev.filter((it) => it.id !== id));
    if (onRejectItem) onRejectItem(id);
    else alert(`Proposal ${id} dismissed.`);
  };

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-7 space-y-6 animate-in fade-in duration-200", className)}>
      {/* ─────────────────────────────────────────────────────────────
          1. HEADER & 5 SUB-TABS (Assistant, Agents, Flows, Proposals, Activity)
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col space-y-4 border-b border-[#F1F5F9] pb-4">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div className="flex items-center space-x-2.5">
              <span className="p-1.5 rounded-lg bg-indigo-50 text-indigo-600">
                <Sparkles className="w-4 h-4 text-[#6366F1]" />
              </span>
              <h1 className="text-2xl font-bold tracking-tight text-[#0F172A]">
                Axiom AI Workspace
              </h1>
              <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200">
                Groq LPU Llama 3.3 70B
              </span>
            </div>
            <p className="text-xs text-[#64748B] mt-1">
              Autonomous financial controllers, 3-Way match pipelines, and human-in-the-loop proposal verification.
            </p>
          </div>

          {/* Engine Health Context Pill */}
          <div className="flex items-center space-x-2 text-xs">
            <span className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-white border border-[#E2E8F0] shadow-xs text-[#0F172A] font-medium">
              <Building className="w-3.5 h-3.5 text-indigo-500" />
              <span>{orgName}</span>
              <span className="text-[10px] text-[#64748B] font-mono">(PKR)</span>
            </span>
          </div>
        </div>

        {/* 5 Main Sub-Tabs */}
        <div className="flex items-center space-x-6 text-sm font-semibold pt-1 border-t border-[#F8FAFC]">
          {[
            { id: "assistant", label: "Axiom Copilot", icon: Bot },
            { id: "agents", label: "Autonomous Agents", icon: Cpu, count: agents.length },
            { id: "flows", label: "Execution Plans", icon: Workflow, count: flows.length },
            { id: "queue", label: "Proposal Queue", icon: FilePenLine, count: queueItems.length },
            { id: "activity", label: "Audit Activity", icon: ShieldCheck },
          ].map((tab) => {
            const Icon = tab.icon;
            const active = activeTab === tab.id;
            return (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id as any)}
                className={cn(
                  "pb-2 border-b-2 transition-all cursor-pointer flex items-center space-x-2 text-xs sm:text-sm",
                  active
                    ? "border-[#6366F1] text-[#0F172A]"
                    : "border-transparent text-[#64748B] hover:text-[#0F172A]"
                )}
              >
                <Icon className={cn("w-3.5 h-3.5", active ? "text-[#6366F1]" : "text-[#94A3B8]")} />
                <span>{tab.label}</span>
                {typeof tab.count === "number" && (
                  <span
                    className={cn(
                      "text-[10px] px-1.5 py-0.2 rounded-full font-bold font-mono",
                      active ? "bg-indigo-100 text-indigo-700" : "bg-slate-100 text-slate-600"
                    )}
                  >
                    {tab.count}
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          TAB 1: AXIOM COPILOT (INTERACTIVE FINANCIAL ASSISTANT)
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "assistant" && (
        <div className="space-y-6 animate-in fade-in">
          {/* Quick Prompt Chips */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs text-[#64748B] font-medium mr-1">Quick Prompts:</span>
            {[
              "What's left on my month-end close?",
              "Audit 3-way match variances on bills",
              "Check FBR ATL compliance on vendor billings",
              "Explain net burn change between Q2 and Q3",
              "Draft recurring insurance amortization journal",
            ].map((prompt, i) => (
              <button
                key={i}
                onClick={() => handleSendMessage(prompt)}
                className="px-3 py-1.5 bg-white hover:bg-indigo-50/60 text-[#334155] hover:text-indigo-700 border border-[#E2E8F0] hover:border-indigo-200 rounded-xl text-xs font-medium transition-all shadow-xs cursor-pointer"
              >
                {prompt}
              </button>
            ))}
          </div>

          {/* Chat Window */}
          <div className="bg-white border border-[#E2E8F0] rounded-2xl shadow-xs overflow-hidden flex flex-col h-[560px]">
            {/* Terminal Header */}
            <div className="px-6 py-3.5 border-b border-[#F1F5F9] bg-[#F8FAFC] flex items-center justify-between">
              <div className="flex items-center space-x-2">
                <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" />
                <span className="text-xs font-bold text-[#0F172A]">
                  Axiom Autonomous Controller • Llama 3.3 70B (Groq LPU)
                </span>
              </div>
              <span className="text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded font-mono">
                Double-Entry Invariant Verified ✓
              </span>
            </div>

            {/* Messages Body */}
            <div className="flex-1 overflow-y-auto p-6 space-y-4 text-xs">
              {assistantMessages.map((msg, idx) => (
                <div
                  key={idx}
                  className={cn(
                    "flex flex-col p-4 rounded-2xl max-w-[85%] space-y-2",
                    msg.role === "user"
                      ? "ml-auto bg-[#6366F1] text-white shadow-xs"
                      : "mr-auto bg-[#F8FAFC] text-[#1E293B] border border-[#E2E8F0]"
                  )}
                >
                  <div className="flex items-center justify-between gap-4">
                    <span
                      className={cn(
                        "font-bold text-[10px] uppercase tracking-wider",
                        msg.role === "user" ? "text-indigo-200" : "text-indigo-600"
                      )}
                    >
                      {msg.role === "user" ? "You" : "Axiom AI"}
                    </span>
                    <span className={cn("text-[10px]", msg.role === "user" ? "text-indigo-200" : "text-[#94A3B8]")}>
                      {msg.time}
                    </span>
                  </div>

                  <p className="leading-relaxed whitespace-pre-wrap text-xs">{msg.content}</p>

                  {/* Metrics Table / Key-values if present */}
                  {msg.metrics && Object.keys(msg.metrics).length > 0 && (
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-2 border-t border-[#E2E8F0]/60 mt-1">
                      {Object.entries(msg.metrics).map(([k, v]) => (
                        <div key={k} className="p-2 bg-white rounded-lg border border-[#E2E8F0]">
                          <span className="text-[10px] text-[#64748B] block truncate">{k}</span>
                          <span className="font-bold text-xs text-[#0F172A] font-mono">{v}</span>
                        </div>
                      ))}
                    </div>
                  )}

                  {/* Suggested Action Chips */}
                  {msg.actions && msg.actions.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 pt-2 border-t border-[#E2E8F0]/40 mt-1">
                      {msg.actions.map((act, aIdx) => (
                        <button
                          key={aIdx}
                          onClick={() => handleSendMessage(act)}
                          className="text-[10px] px-2 py-1 bg-white hover:bg-indigo-50 border border-indigo-200 rounded-lg text-indigo-700 font-semibold cursor-pointer transition-colors"
                        >
                          → {act}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              ))}

              {assistantLoading && (
                <div className="flex items-center space-x-2 p-3.5 rounded-2xl bg-[#F8FAFC] border border-[#E2E8F0] max-w-[240px]">
                  <span className="w-3.5 h-3.5 rounded-full border-2 border-indigo-600 border-t-transparent animate-spin" />
                  <span className="text-xs text-[#64748B]">Axiom reasoning with Groq LPU…</span>
                </div>
              )}
            </div>

            {/* Input Footer */}
            <div className="p-4 border-t border-[#F1F5F9] bg-white flex items-center space-x-2">
              <input
                type="text"
                value={assistantInput}
                onChange={(e) => setAssistantInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") handleSendMessage();
                }}
                placeholder="Ask Axiom AI: query ledger invariants, check Section 153 WHT, explain net burn, draft journal..."
                className="flex-1 text-xs border border-[#E2E8F0] rounded-xl px-4 py-2.5 outline-none focus:border-indigo-500 text-[#0F172A] placeholder:text-[#94A3B8]"
              />
              <button
                onClick={() => handleSendMessage()}
                disabled={assistantLoading || !assistantInput.trim()}
                className="px-4 py-2.5 bg-[#6366F1] hover:bg-[#4F46E5] text-white rounded-xl text-xs font-semibold flex items-center space-x-1.5 transition-colors cursor-pointer disabled:opacity-50 shadow-xs"
              >
                <Send className="w-3.5 h-3.5" />
                <span>Send</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 2: AUTONOMOUS FINANCIAL AGENTS DIRECTORY
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "agents" && (
        <div className="space-y-6 animate-in fade-in">
          <div className="flex items-center justify-between border-b border-[#F1F5F9] pb-3">
            <div>
              <h2 className="text-base font-bold text-[#0F172A]">Specialized Financial Agents</h2>
              <p className="text-xs text-[#64748B] mt-0.5">
                Domain-specific agents continuously monitoring subledgers, FBR compliance, and mathematical invariants.
              </p>
            </div>
            <span className="text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 px-2.5 py-1 rounded-full">
              5 of 5 Agents Active
            </span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            {agents.map((agent) => {
              const Icon = agent.icon;
              return (
                <div
                  key={agent.id}
                  className="bg-white border border-[#E2E8F0] rounded-2xl p-5 shadow-xs hover:border-indigo-200 transition-all flex flex-col justify-between space-y-4"
                >
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <div className="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                        <Icon className="w-5 h-5" />
                      </div>
                      <span className="inline-flex items-center space-x-1 text-[10px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                        <span className="capitalize">{agent.status}</span>
                      </span>
                    </div>

                    <div>
                      <h3 className="text-sm font-bold text-[#0F172A]">{agent.name}</h3>
                      <span className="text-[11px] font-medium text-indigo-600 block">{agent.role}</span>
                    </div>

                    <p className="text-xs text-[#64748B] leading-relaxed line-clamp-3">
                      {agent.description}
                    </p>
                  </div>

                  <div className="space-y-3 pt-3 border-t border-[#F1F5F9]">
                    <div className="grid grid-cols-2 gap-2 text-[10px]">
                      <div>
                        <span className="text-[#94A3B8] block">Last Execution:</span>
                        <span className="font-semibold text-[#0F172A]">{agent.lastExecution}</span>
                      </div>
                      <div>
                        <span className="text-[#94A3B8] block">Accuracy:</span>
                        <span className="font-bold text-emerald-600 font-mono">{agent.accuracyRate}</span>
                      </div>
                      <div>
                        <span className="text-[#94A3B8] block">Processed:</span>
                        <span className="font-semibold text-[#0F172A] font-mono">{agent.processedCount} items</span>
                      </div>
                      <div>
                        <span className="text-[#94A3B8] block">Pending Tasks:</span>
                        <span className="font-semibold text-amber-600 font-mono">{agent.tasksQueued} queued</span>
                      </div>
                    </div>

                    <button
                      onClick={() => alert(`Triggering autonomous run for ${agent.name}...`)}
                      className="w-full py-2 bg-[#F8FAFC] hover:bg-indigo-50 text-[#0F172A] hover:text-indigo-700 border border-[#E2E8F0] hover:border-indigo-200 rounded-xl text-xs font-semibold flex items-center justify-center space-x-1.5 transition-colors cursor-pointer"
                    >
                      <Play className="w-3.5 h-3.5" />
                      <span>Trigger Agent Run</span>
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 3: FLOW EXECUTION PLANS (AUTONOMOUS PIPELINES)
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "flows" && (
        <div className="space-y-6 animate-in fade-in">
          <div className="flex items-center justify-between border-b border-[#F1F5F9] pb-3">
            <div>
              <h2 className="text-base font-bold text-[#0F172A]">Flow Execution Plans</h2>
              <p className="text-xs text-[#64748B] mt-0.5">
                Deterministic DAG pipelines orchestrated by Axiom AI across billing, 3-Way matching, and month-end closing.
              </p>
            </div>
            <button
              onClick={() => alert("Creating a new custom autonomous flow plan.")}
              className="px-3.5 py-1.5 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition-colors cursor-pointer shadow-xs"
            >
              <span>+ New Execution Flow</span>
            </button>
          </div>

          <div className="space-y-4">
            {flows.map((flow) => (
              <div
                key={flow.id}
                className="bg-white border border-[#E2E8F0] rounded-2xl p-6 shadow-xs space-y-4 hover:border-indigo-200 transition-colors"
              >
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-[#F1F5F9] pb-3">
                  <div>
                    <div className="flex items-center space-x-2">
                      <span className="text-[10px] font-bold font-mono px-2 py-0.5 rounded bg-indigo-50 text-indigo-700">
                        {flow.id}
                      </span>
                      <h3 className="text-sm font-bold text-[#0F172A]">{flow.name}</h3>
                      <span className="text-xs text-[#64748B]">• {flow.category}</span>
                    </div>
                    <span className="text-[11px] text-[#64748B] block mt-0.5">Trigger: {flow.trigger}</span>
                  </div>

                  <div className="flex items-center space-x-3 text-xs">
                    <span className="text-[11px] text-[#64748B]">Last Run: <strong className="text-[#0F172A]">{flow.lastRun}</strong></span>
                    <button
                      onClick={() => setSelectedFlow(flow)}
                      className="px-3 py-1 bg-[#F8FAFC] hover:bg-slate-100 text-[#0F172A] border border-[#E2E8F0] rounded-lg text-xs font-medium cursor-pointer"
                    >
                      Inspect Flow Traces
                    </button>
                  </div>
                </div>

                {/* Pipeline DAG Steps Visualizer */}
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                  {flow.steps.map((st, idx) => (
                    <div
                      key={idx}
                      className="p-3 rounded-xl bg-[#F8FAFC] border border-[#E2E8F0] space-y-1 relative"
                    >
                      <div className="flex items-center justify-between">
                        <span className="text-[10px] font-bold text-[#64748B]">Step {idx + 1}</span>
                        <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                      </div>
                      <span className="text-xs font-bold text-[#0F172A] block truncate">{st.name}</span>
                      <p className="text-[10px] text-[#64748B] line-clamp-2">{st.description}</p>
                      {st.outputSummary && (
                        <span className="text-[9px] font-semibold text-indigo-700 bg-indigo-50 px-1.5 py-0.2 rounded block truncate mt-1">
                          {st.outputSummary}
                        </span>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 4: DRAFT PROPOSALS & HUMAN REVIEW QUEUE (SAFETY INVARIANT)
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "queue" && (
        <div className="space-y-4 animate-in fade-in">
          <FilterBar
            searchQuery={queueSearch}
            onSearchChange={setQueueSearch}
            searchPlaceholder="Search AI proposal queue by title, owner, or GL code..."
            statusFilter={queueDomainFilter}
            onStatusChange={setQueueDomainFilter}
            statusOptions={[
              { label: "All Domains", value: "all" },
              { label: "AR (Receivables)", value: "AR" },
              { label: "AP (Payables)", value: "AP" },
              { label: "GL (General Ledger)", value: "GL" },
              { label: "Close Management", value: "Close" },
              { label: "Reconciliation", value: "Recon" },
            ]}
            count={filteredQueue.length}
            countLabel="pending proposals"
          />

          <div className="space-y-3">
            {filteredQueue.length === 0 ? (
              <div className="p-8 text-center bg-white border border-[#E2E8F0] rounded-2xl text-xs text-[#94A3B8]">
                All AI action proposals processed. Zero pending modifications.
              </div>
            ) : (
              filteredQueue.map((item) => (
                <div
                  key={item.id}
                  className="p-5 bg-white border border-[#E2E8F0] rounded-2xl shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4 hover:border-indigo-200 transition-colors"
                >
                  <div className="space-y-1.5 max-w-2xl">
                    <div className="flex items-center space-x-2">
                      <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 font-bold">
                        {item.domain}
                      </span>
                      <h4 className="text-xs font-bold text-[#0F172A]">{item.title}</h4>
                      <span className="text-[10px] text-[#94A3B8]">• Source: {item.source}</span>
                    </div>
                    <p className="text-xs text-[#64748B]">{item.description}</p>
                    <p className="text-[11px] font-mono text-indigo-900 bg-indigo-50/60 px-2 py-1 rounded inline-block">
                      Impact: {item.estimatedGlImpact}
                    </p>
                  </div>

                  <div className="flex items-center space-x-2 shrink-0">
                    <button
                      onClick={() => handleReject(item.id)}
                      className="px-3 py-1.5 text-xs text-[#64748B] hover:text-rose-600 rounded-xl border border-[#E2E8F0] hover:border-rose-200 transition-colors cursor-pointer"
                    >
                      Dismiss
                    </button>
                    <button
                      onClick={() => handleApprove(item.id)}
                      className="px-3.5 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl shadow-xs transition-colors cursor-pointer"
                    >
                      Approve & Post to GL
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      )}

      {/* ─────────────────────────────────────────────────────────────
          TAB 5: IMMUTABLE AUDIT STREAM
      ─────────────────────────────────────────────────────────────── */}
      {activeTab === "activity" && (
        <div className="bg-white border border-[#E2E8F0] rounded-2xl p-6 shadow-xs space-y-4 animate-in fade-in">
          <div className="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
            <h3 className="text-sm font-bold text-[#0F172A]">
              Immutable SHA-256 Audit Activity Stream
            </h3>
            <span className="text-xs text-emerald-600 font-mono">Cryptographically fingerprinted</span>
          </div>

          <div className="divide-y divide-[#F1F5F9]">
            {activityLog.map((act) => (
              <div key={act.id} className="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="space-y-1">
                  <div className="flex items-center space-x-2">
                    <span className="text-xs font-bold text-[#0F172A]">{act.title}</span>
                    <span className="text-[10px] text-[#94A3B8]">• {act.timestamp}</span>
                  </div>
                  <p className="text-xs text-[#64748B]">{act.summary}</p>
                  <p className="text-[10px] font-mono text-slate-400 truncate max-w-lg">
                    SHA-256: {act.sha256Hash}
                  </p>
                </div>
                <div className="text-right shrink-0">
                  <span className="text-[11px] font-medium text-[#0F172A] block">{act.author}</span>
                  <span className="text-[10px] text-emerald-600 font-mono">Verified Invariant ✓</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Slide-over Drawer for Flow Traces */}
      <SlideOverDrawer
        isOpen={Boolean(selectedFlow)}
        onClose={() => setSelectedFlow(null)}
        title={`Execution Plan: ${selectedFlow?.name || ""}`}
        subtitle={`${selectedFlow?.category || ""} • Trigger: ${selectedFlow?.trigger || ""}`}
        badge={
          <Badge variant="active">
            {selectedFlow?.status || "Active"}
          </Badge>
        }
      >
        {selectedFlow && (
          <div className="space-y-6 text-xs text-[#334155]">
            <div className="bg-[#F8FAFC] p-4.5 rounded-2xl border border-[#E2E8F0] space-y-3">
              <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">Pipeline Summary</span>
              <div className="grid grid-cols-2 gap-3 text-xs">
                <div>
                  <span className="text-[#94A3B8] block text-[10px]">Average Runtime:</span>
                  <span className="font-bold text-[#0F172A] font-mono">{selectedFlow.avgDuration}</span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block text-[10px]">Last Execution:</span>
                  <span className="font-semibold text-[#0F172A]">{selectedFlow.lastRun}</span>
                </div>
              </div>
            </div>

            <div className="space-y-3">
              <span className="text-[11px] font-bold uppercase tracking-wider text-[#64748B]">Step Execution Traces</span>
              <div className="space-y-2.5">
                {selectedFlow.steps.map((st, i) => (
                  <div key={i} className="p-3 bg-white border border-[#E2E8F0] rounded-xl space-y-1">
                    <div className="flex items-center justify-between">
                      <span className="font-bold text-[#0F172A]">Step {i + 1}: {st.name}</span>
                      <span className="text-[10px] font-mono text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded">
                        {st.runtimeMs}ms
                      </span>
                    </div>
                    <p className="text-[#64748B] text-[11px]">{st.description}</p>
                    {st.outputSummary && (
                      <div className="mt-1 p-2 bg-[#F8FAFC] rounded-lg border border-[#E2E8F0]/60 font-mono text-[10px] text-indigo-900">
                        Output: {st.outputSummary}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
