"use client";

import React, { useState } from "react";
import {
  Sparkles,
  CheckCircle2,
  Clock,
  AlertCircle,
  Filter,
  Check,
  RotateCw,
  FolderClosed,
  User,
  ShieldCheck,
  Calendar,
  FileText,
  Workflow,
  ExternalLink,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { DataTable, Column } from "../ui/DataTable";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge } from "../ui/Badge";

export interface CloseTask {
  id: string;
  folder: string;
  title: string;
  entity: string;
  dueDate: string;
  overdueText?: string;
  preparer: { name: string; submittedAt?: string; avatarColor?: string };
  reviewer: { name: string; reviewed?: boolean; autoFlowStatus?: string; avatarColor?: string };
  status: "done" | "in_review" | "open";
  description?: string;
  glAccount?: string;
  notes?: string;
}

interface CloseChecklistViewProps {
  periodName?: string;
  onRefresh?: () => void;
  className?: string;
}

export function CloseChecklistView({
  periodName = "August 2025",
  onRefresh,
  className,
}: CloseChecklistViewProps) {
  const [searchQuery, setSearchQuery] = useState("");
  const [folderFilter, setFolderFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("all");
  const [selectedTask, setSelectedTask] = useState<CloseTask | null>(null);

  const [tasks, setTasks] = useState<CloseTask[]>([
    {
      id: "TSK-01",
      folder: "Cash and cash equivalents",
      title: "Reconcile HBL Operating Cash Account (#1010)",
      entity: "Apex Trading PK",
      dueDate: "Aug 24, 2025",
      overdueText: "12 days",
      preparer: { name: "Travis Holden", submittedAt: "Aug 26, 2025", avatarColor: "bg-blue-600" },
      reviewer: { name: "Asaf Kotzer", reviewed: true, avatarColor: "bg-purple-600" },
      status: "done",
      description: "Verify that general ledger cash account #1010 perfectly matches cleared HBL bank statements with zero unclassified variance.",
      glAccount: "1010 - HBL Operating Cash",
      notes: "Auto-matched 140 transactions. Difference is PKR 0.00.",
    },
    {
      id: "TSK-02",
      folder: "Cash and cash equivalents",
      title: "Reconcile Meezan Treasury & In-Flight Clearing",
      entity: "Apex Trading PK",
      dueDate: "Aug 29, 2025",
      preparer: { name: "Travis Holden", avatarColor: "bg-blue-600" },
      reviewer: { name: "Alex Turner", autoFlowStatus: "Auto-flow running | 42%", avatarColor: "bg-emerald-600" },
      status: "in_review",
      description: "Inspect unsettled electronic receipts and outbound clearing wires against treasury statement ledger.",
      glAccount: "1020 - Meezan Treasury Account",
      notes: "Axiom Reconciliation Agent currently executing batch match on 18 remaining lines.",
    },
    {
      id: "TSK-03",
      folder: "Cash and cash equivalents",
      title: "Verify Cheque In-Transit & Bank Deposit Slips",
      entity: "Apex Trading PK",
      dueDate: "Aug 29, 2025",
      preparer: { name: "Travis Holden", submittedAt: "Aug 26, 2025", avatarColor: "bg-blue-600" },
      reviewer: { name: "Alex Turner", reviewed: true, autoFlowStatus: "Auto-flow complete", avatarColor: "bg-emerald-600" },
      status: "done",
      description: "Audit physical deposit slips against banking credit advices for clearing turnaround validation.",
      glAccount: "1040 - Undeposited Funds",
      notes: "All cleared through clearing house with verified voucher hashes.",
    },
    {
      id: "TSK-04",
      folder: "Expenses & Accruals",
      title: "Monthly Fixed Asset Depreciation (#1510 / #6100)",
      entity: "Apex Trading PK",
      dueDate: "Aug 29, 2025",
      preparer: { name: "Travis Holden", avatarColor: "bg-blue-600" },
      reviewer: { name: "Asaf Kotzer", avatarColor: "bg-purple-600" },
      status: "open",
      description: "Calculate straight-line depreciation across IT equipment, warehouse machinery, and corporate vehicles.",
      glAccount: "6100 - Depreciation Expense / 1510 - Accumulated Depreciation",
      notes: "Awaiting final capital expenditure sign-off for server hardware additions.",
    },
    {
      id: "TSK-05",
      folder: "Expenses & Accruals",
      title: "Accrue Cloud Infrastructure (AWS / Vercel OpEx)",
      entity: "Apex Trading PK",
      dueDate: "Aug 30, 2025",
      preparer: { name: "Travis Holden", submittedAt: "Aug 28, 2025", avatarColor: "bg-blue-600" },
      reviewer: { name: "Alex Turner", reviewed: true, avatarColor: "bg-emerald-600" },
      status: "done",
      description: "Generate monthly cloud hosting accruals based on 6-month historical spend trajectory.",
      glAccount: "5120 - Cloud Hosting & SaaS / 2020 - Accrued Expenses",
      notes: "Accrued PKR 350,000 for AWS production services.",
    },
    {
      id: "TSK-06",
      folder: "Expenses & Accruals",
      title: "Section 153 WHT Withholding Tax Schedule",
      entity: "Apex Trading PK",
      dueDate: "Aug 31, 2025",
      preparer: { name: "Travis Holden", avatarColor: "bg-blue-600" },
      reviewer: { name: "Alex Turner", autoFlowStatus: "Auto-flow running | 68%", avatarColor: "bg-emerald-600" },
      status: "in_review",
      description: "Verify tax deduction certificates against FBR Annex-C portal prior to monthly withholding return.",
      glAccount: "2050 - Withholding Tax Payable (FBR)",
      notes: "Calculated across active vendor bill payments with ATL verification.",
    },
    {
      id: "TSK-07",
      folder: "Intercompany & Equity",
      title: "Reciprocal UAE FZE Elimination Journal",
      entity: "Consolidated Group",
      dueDate: "Aug 31, 2025",
      preparer: { name: "Travis Holden", submittedAt: "Aug 29, 2025", avatarColor: "bg-blue-600" },
      reviewer: { name: "Alex Turner", reviewed: true, avatarColor: "bg-emerald-600" },
      status: "done",
      description: "Eliminate reciprocal management service charges between parent holding and Dubai entity.",
      glAccount: "2100 - Intercompany Payable / 1200 - Intercompany Receivable",
      notes: "Zero net variance across consolidated currency translation.",
    },
  ]);

  // Filter tasks
  const filteredTasks = tasks.filter((task) => {
    const matchesSearch =
      !searchQuery ||
      task.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
      task.folder.toLowerCase().includes(searchQuery.toLowerCase()) ||
      task.preparer.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      task.reviewer.name.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesFolder = folderFilter === "all" || task.folder === folderFilter;
    const matchesStatus = statusFilter === "all" || task.status === statusFilter;

    return matchesSearch && matchesFolder && matchesStatus;
  });

  const handleMarkReviewed = (taskId: string) => {
    setTasks((prev) =>
      prev.map((t) =>
        t.id === taskId
          ? {
              ...t,
              status: "done",
              reviewer: { ...t.reviewer, reviewed: true, autoFlowStatus: "Auto-flow complete" },
            }
          : t
      )
    );
    if (selectedTask && selectedTask.id === taskId) {
      setSelectedTask((prev) =>
        prev
          ? {
              ...prev,
              status: "done",
              reviewer: { ...prev.reviewer, reviewed: true, autoFlowStatus: "Auto-flow complete" },
            }
          : null
      );
    }
  };

  const columns: Column<CloseTask>[] = [
    {
      key: "task",
      header: "Task Details",
      render: (task) => (
        <div className="space-y-0.5 max-w-[280px]">
          <div className="flex items-center space-x-1.5">
            <span className="text-[10px] font-mono px-1.5 py-0.2 rounded bg-slate-100 text-[#475569]">
              {task.folder}
            </span>
          </div>
          <span className="font-semibold text-xs text-[#0F172A] block leading-tight hover:text-indigo-600 transition-colors">
            {task.title}
          </span>
          <span className="text-[10px] font-mono text-[#94A3B8] block">{task.id}</span>
        </div>
      ),
    },
    {
      key: "entity",
      header: "Entity",
      render: (task) => (
        <span className="text-xs text-[#475569] font-medium">{task.entity}</span>
      ),
    },
    {
      key: "dueDate",
      header: "Due Date",
      render: (task) => (
        <div>
          <span className="text-xs font-medium text-[#0F172A] block">{task.dueDate}</span>
          {task.overdueText && (
            <span className="text-[10px] font-semibold text-rose-600 block">
              {task.overdueText} overdue
            </span>
          )}
        </div>
      ),
    },
    {
      key: "preparer",
      header: "Preparer",
      render: (task) => (
        <div className="flex items-center space-x-2">
          <div
            className={cn(
              "w-6 h-6 rounded-full text-white text-[10px] font-bold flex items-center justify-center shrink-0 shadow-2xs",
              task.preparer.avatarColor || "bg-blue-600"
            )}
          >
            {task.preparer.name.charAt(0)}
          </div>
          <div>
            <span className="font-medium text-[#0F172A] block text-[11px]">
              {task.preparer.name}
            </span>
            {task.preparer.submittedAt && (
              <span className="text-[9px] text-emerald-600 block">
                Submitted {task.preparer.submittedAt}
              </span>
            )}
          </div>
        </div>
      ),
    },
    {
      key: "reviewer",
      header: "Reviewer & Auto-Flow",
      render: (task) => (
        <div className="flex items-center space-x-2">
          <div
            className={cn(
              "w-6 h-6 rounded-full text-white text-[10px] font-bold flex items-center justify-center shrink-0 shadow-2xs",
              task.reviewer.avatarColor || "bg-purple-600"
            )}
          >
            {task.reviewer.name.charAt(0)}
          </div>
          <div className="space-y-0.5">
            <span className="font-medium text-[#0F172A] block text-[11px]">
              {task.reviewer.name}
            </span>
            {task.reviewer.autoFlowStatus ? (
              <span className="text-[9px] font-bold px-1.5 py-0.2 rounded bg-indigo-50 text-indigo-700 block">
                {task.reviewer.autoFlowStatus}
              </span>
            ) : task.reviewer.reviewed ? (
              <span className="text-[9px] text-emerald-600 font-semibold block">
                Reviewed ✓
              </span>
            ) : (
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  handleMarkReviewed(task.id);
                }}
                className="text-[10px] font-semibold text-[#6366F1] hover:underline cursor-pointer"
              >
                Mark as Reviewed
              </button>
            )}
          </div>
        </div>
      ),
    },
    {
      key: "status",
      header: "Status",
      align: "center",
      render: (task) => (
        <Badge
          variant={
            task.status === "done"
              ? "success"
              : task.status === "in_review"
              ? "warning"
              : "neutral"
          }
        >
          {task.status === "done"
            ? "Done"
            : task.status === "in_review"
            ? "In review"
            : "Open"}
        </Badge>
      ),
    },
    {
      key: "actions",
      header: "Action",
      align: "right",
      render: (task) => (
        <button
          onClick={(e) => {
            e.stopPropagation();
            setSelectedTask(task);
          }}
          className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition-colors cursor-pointer px-2 py-1 rounded hover:bg-indigo-50"
        >
          Inspect
        </button>
      ),
    },
  ];

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-7 space-y-6 animate-in fade-in duration-200", className)}>
      {/* ─────────────────────────────────────────────────────────────
          1. TOP BREADCRUMB & COUNTDOWN PROGRESS BAR (PDF Page 1/2)
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
        <div>
          <div className="flex items-center space-x-2 text-xs text-[#64748B]">
            <span className="font-semibold text-[#0F172A]">{periodName}</span>
            <span>&gt;</span>
            <span className="text-[#6366F1] font-medium">Checklist</span>
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-[#0F172A] mt-1">
            Continuous Close Checklist
          </h1>
        </div>

        {/* Countdown & Dual Progress Bar */}
        <div className="flex flex-col sm:items-end space-y-1.5">
          <div className="flex items-center space-x-2 text-xs font-semibold text-[#0F172A]">
            <Clock className="w-3.5 h-3.5 text-amber-500" />
            <span>4 days to month-end</span>
          </div>

          <div className="w-48 h-2 bg-slate-100 rounded-full overflow-hidden flex">
            <div className="w-[72%] h-full bg-emerald-500" title="Completed: 72%" />
            <div className="w-[28%] h-full bg-rose-500" title="Remaining: 28%" />
          </div>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          2. 4 AGENT RUNNING CARDS WITH CIRCULAR GAUGES (PDF Page 1/2)
      ─────────────────────────────────────────────────────────────── */}
      <div className="space-y-3">
        <div className="flex items-center space-x-2 text-xs font-bold text-[#6366F1]">
          <Sparkles className="w-4 h-4" />
          <span>6 agents running</span>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Gauge Card 1 */}
          <div className="bg-white border border-[#E2E8F0] rounded-2xl p-4 flex items-center justify-between shadow-2xs hover:border-indigo-200 transition-colors">
            <div className="pr-2 space-y-1">
              <span className="text-xs font-semibold text-[#0F172A] block leading-tight">
                Matching customer payments 1042 - US Ops
              </span>
              <span className="text-[10px] text-[#94A3B8]">In progress</span>
            </div>
            <div className="relative w-11 h-11 shrink-0 flex items-center justify-center">
              <svg className="w-11 h-11 transform -rotate-90" viewBox="0 0 36 36">
                <circle cx="18" cy="18" r="15" fill="none" stroke="#F1F5F9" strokeWidth="3" />
                <circle cx="18" cy="18" r="15" fill="none" stroke="#6366F1" strokeWidth="3" strokeDasharray="94.2" strokeDashoffset="82.9" strokeLinecap="round" />
              </svg>
              <span className="absolute text-[10px] font-bold text-[#0F172A]">12%</span>
            </div>
          </div>

          {/* Gauge Card 2 */}
          <div className="bg-white border border-[#E2E8F0] rounded-2xl p-4 flex items-center justify-between shadow-2xs hover:border-indigo-200 transition-colors">
            <div className="pr-2 space-y-1">
              <span className="text-xs font-semibold text-[#0F172A] block leading-tight">
                Reconciling 1005 - Chase Checking
              </span>
              <span className="text-[10px] text-[#94A3B8]">Subledger aligned</span>
            </div>
            <div className="relative w-11 h-11 shrink-0 flex items-center justify-center">
              <svg className="w-11 h-11 transform -rotate-90" viewBox="0 0 36 36">
                <circle cx="18" cy="18" r="15" fill="none" stroke="#F1F5F9" strokeWidth="3" />
                <circle cx="18" cy="18" r="15" fill="none" stroke="#6366F1" strokeWidth="3" strokeDasharray="94.2" strokeDashoffset="22.6" strokeLinecap="round" />
              </svg>
              <span className="absolute text-[10px] font-bold text-[#0F172A]">76%</span>
            </div>
          </div>

          {/* Gauge Card 3 */}
          <div className="bg-white border border-[#E2E8F0] rounded-2xl p-4 flex items-center justify-between shadow-2xs hover:border-amber-300 transition-colors">
            <div className="pr-2 space-y-1.5">
              <span className="text-xs font-semibold text-[#0F172A] block leading-tight">
                Recording funds-in-flight Stripe
              </span>
              <span className="text-[9px] font-bold px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 inline-block">
                Requires your attention
              </span>
            </div>
            <div className="relative w-11 h-11 shrink-0 flex items-center justify-center">
              <svg className="w-11 h-11 transform -rotate-90" viewBox="0 0 36 36">
                <circle cx="18" cy="18" r="15" fill="none" stroke="#F1F5F9" strokeWidth="3" />
                <circle cx="18" cy="18" r="15" fill="none" stroke="#F59E0B" strokeWidth="3" strokeDasharray="94.2" strokeDashoffset="54.6" strokeLinecap="round" />
              </svg>
              <span className="absolute text-[10px] font-bold text-[#0F172A]">42%</span>
            </div>
          </div>

          {/* Gauge Card 4 */}
          <div className="bg-white border border-[#E2E8F0] rounded-2xl p-4 flex items-center justify-between shadow-2xs hover:border-indigo-200 transition-colors">
            <div className="pr-2 space-y-1">
              <span className="text-xs font-semibold text-[#0F172A] block leading-tight">
                Generating accruals working paper for 5020
              </span>
              <span className="text-[10px] text-[#94A3B8]">Legal expenses</span>
            </div>
            <div className="relative w-11 h-11 shrink-0 flex items-center justify-center">
              <svg className="w-11 h-11 transform -rotate-90" viewBox="0 0 36 36">
                <circle cx="18" cy="18" r="15" fill="none" stroke="#F1F5F9" strokeWidth="3" />
                <circle cx="18" cy="18" r="15" fill="none" stroke="#6366F1" strokeWidth="3" strokeDasharray="94.2" strokeDashoffset="22.6" strokeLinecap="round" />
              </svg>
              <span className="absolute text-[10px] font-bold text-[#0F172A]">76%</span>
            </div>
          </div>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          3. FILTER BAR WITH SEARCH & FOLDER/STATUS SELECTORS
      ─────────────────────────────────────────────────────────────── */}
      <FilterBar
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        searchPlaceholder="Filter close checklist by title, preparer, or folder..."
        statusFilter={statusFilter}
        onStatusChange={setStatusFilter}
        statusOptions={[
          { label: "All Statuses", value: "all" },
          { label: "Done", value: "done" },
          { label: "In Review", value: "in_review" },
          { label: "Open", value: "open" },
        ]}
        entityFilter={folderFilter}
        onEntityChange={setFolderFilter}
        entityOptions={[
          { label: "All Folders", value: "all" },
          { label: "Cash and cash equivalents", value: "Cash and cash equivalents" },
          { label: "Expenses & Accruals", value: "Expenses & Accruals" },
          { label: "Intercompany & Equity", value: "Intercompany & Equity" },
        ]}
        count={filteredTasks.length}
        countLabel="close tasks"
        onRefresh={onRefresh}
      />

      {/* ─────────────────────────────────────────────────────────────
          4. STANDARDIZED DATA TABLE
      ─────────────────────────────────────────────────────────────── */}
      <DataTable
        columns={columns}
        data={filteredTasks}
        onRowClick={(task) => setSelectedTask(task)}
        rowKey={(task) => task.id}
        emptyMessage="No close tasks match your filter criteria"
        emptySubtext="Try adjusting the folder or status filters above."
      />

      {/* ─────────────────────────────────────────────────────────────
          5. SLIDE-OVER DRAWER FOR TASK DETAILS & SIGN-OFF
      ─────────────────────────────────────────────────────────────── */}
      <SlideOverDrawer
        isOpen={Boolean(selectedTask)}
        onClose={() => setSelectedTask(null)}
        title={selectedTask?.title || "Close Task Details"}
        subtitle={`Task ID: ${selectedTask?.id} • Due: ${selectedTask?.dueDate}`}
        badge={
          selectedTask && (
            <Badge
              variant={
                selectedTask.status === "done"
                  ? "success"
                  : selectedTask.status === "in_review"
                  ? "warning"
                  : "neutral"
              }
            >
              {selectedTask.status === "done"
                ? "Done"
                : selectedTask.status === "in_review"
                ? "In review"
                : "Open"}
            </Badge>
          )
        }
        footer={
          selectedTask && (
            <div className="flex items-center justify-between w-full">
              <button
                onClick={() => setSelectedTask(null)}
                className="px-4 py-2 border border-[#E2E8F0] hover:bg-slate-50 text-xs font-semibold rounded-xl text-[#64748B] transition-colors cursor-pointer"
              >
                Close Drawer
              </button>
              <div className="flex items-center space-x-2">
                {selectedTask.status !== "done" && (
                  <button
                    onClick={() => handleMarkReviewed(selectedTask.id)}
                    className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl shadow-xs transition-colors cursor-pointer flex items-center space-x-1.5"
                  >
                    <Check className="w-3.5 h-3.5" />
                    <span>Mark as Reviewed</span>
                  </button>
                )}
              </div>
            </div>
          )
        }
      >
        {selectedTask && (
          <div className="space-y-6 text-xs text-[#0F172A]">
            {/* Folder & Description */}
            <div className="p-4 bg-[#F8FAFC] rounded-xl border border-[#E2E8F0]/80 space-y-2">
              <span className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider block">
                Folder & Target Account
              </span>
              <div className="flex items-center space-x-2 font-semibold text-xs text-[#0F172A]">
                <FolderClosed className="w-4 h-4 text-[#6366F1]" />
                <span>{selectedTask.folder}</span>
              </div>
              <p className="text-xs text-[#334155] leading-relaxed pt-1">
                {selectedTask.description}
              </p>
              {selectedTask.glAccount && (
                <div className="pt-2 border-t border-[#E2E8F0]/60">
                  <span className="text-[10px] text-[#94A3B8] block">GL Account:</span>
                  <span className="font-mono font-bold text-indigo-700 text-xs">
                    {selectedTask.glAccount}
                  </span>
                </div>
              )}
            </div>

            {/* Preparer Section */}
            <div className="space-y-2">
              <span className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider block">
                Preparer Sign-off
              </span>
              <div className="p-3 bg-white border border-[#E2E8F0] rounded-xl flex items-center justify-between">
                <div className="flex items-center space-x-3">
                  <div className="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs">
                    {selectedTask.preparer.name.charAt(0)}
                  </div>
                  <div>
                    <span className="font-bold text-xs text-[#0F172A] block">
                      {selectedTask.preparer.name}
                    </span>
                    <span className="text-[10px] text-[#94A3B8]">
                      {selectedTask.preparer.submittedAt
                        ? `Submitted on ${selectedTask.preparer.submittedAt}`
                        : "Sign-off pending"}
                    </span>
                  </div>
                </div>
                <Badge variant={selectedTask.preparer.submittedAt ? "success" : "neutral"}>
                  {selectedTask.preparer.submittedAt ? "Signed Off" : "Pending"}
                </Badge>
              </div>
            </div>

            {/* Reviewer & Auto-Flow Section */}
            <div className="space-y-2">
              <span className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider block">
                Reviewer Approval & Autonomous Agent Status
              </span>
              <div className="p-3 bg-white border border-[#E2E8F0] rounded-xl flex items-center justify-between">
                <div className="flex items-center space-x-3">
                  <div className="w-8 h-8 rounded-full bg-purple-600 text-white flex items-center justify-center font-bold text-xs">
                    {selectedTask.reviewer.name.charAt(0)}
                  </div>
                  <div>
                    <span className="font-bold text-xs text-[#0F172A] block">
                      {selectedTask.reviewer.name}
                    </span>
                    <span className="text-[10px] text-[#94A3B8]">
                      {selectedTask.reviewer.autoFlowStatus || "Manual Controller Review"}
                    </span>
                  </div>
                </div>
                <Badge variant={selectedTask.reviewer.reviewed ? "success" : "warning"}>
                  {selectedTask.reviewer.reviewed ? "Approved" : "In Review"}
                </Badge>
              </div>
            </div>

            {/* Operational Notes */}
            <div className="space-y-1.5">
              <span className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider block">
                Verification Notes
              </span>
              <div className="p-3 bg-[#F8FAFC] rounded-xl border border-[#E2E8F0] text-xs text-[#475569]">
                {selectedTask.notes || "No special audit notes recorded."}
              </div>
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
