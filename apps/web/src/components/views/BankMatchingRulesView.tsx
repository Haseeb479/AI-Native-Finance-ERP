"use client";

import React, { useState } from "react";
import {
  Landmark,
  Plus,
  RefreshCw,
  Zap,
  CheckCircle2,
  SlidersHorizontal,
  Code,
  ArrowRight,
  ShieldCheck,
  Check,
  Play,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { DataTable, Column } from "../ui/DataTable";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge } from "../ui/Badge";

export interface MatchingRule {
  id: string;
  name: string;
  pattern: string;
  field: "description" | "reference" | "amount";
  targetAccount: string;
  targetAccountName: string;
  confidence: number;
  autoReconcile: boolean;
  status: "active" | "paused";
  matchedCount?: number;
}

interface BankMatchingRulesViewProps {
  onRefresh?: () => void;
  className?: string;
}

export function BankMatchingRulesView({
  onRefresh,
  className,
}: BankMatchingRulesViewProps) {
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [selectedRule, setSelectedRule] = useState<MatchingRule | null>(null);

  const [rules, setRules] = useState<MatchingRule[]>([
    {
      id: "RULE-001",
      name: "Stripe Payment Processor Settlement",
      pattern: "STRIPE*PAYMENT|STRIPE PAYOUT",
      field: "description",
      targetAccount: "1030",
      targetAccountName: "Trade Debtors (AR Control)",
      confidence: 99,
      autoReconcile: true,
      status: "active",
      matchedCount: 84,
    },
    {
      id: "RULE-002",
      name: "AWS Cloud Infrastructure Direct Debit",
      pattern: "AMAZON WEB SERVICES|AWS CLOUD",
      field: "description",
      targetAccount: "5120",
      targetAccountName: "Cloud Hosting & SaaS OpEx",
      confidence: 96,
      autoReconcile: true,
      status: "active",
      matchedCount: 22,
    },
    {
      id: "RULE-003",
      name: "Meezan Bank Monthly Maintenance & FED",
      pattern: "FED ON CASH WITHDRAWAL|BANK CHARGES",
      field: "description",
      targetAccount: "6050",
      targetAccountName: "Bank Charges & Federal Excise Duty",
      confidence: 95,
      autoReconcile: true,
      status: "active",
      matchedCount: 48,
    },
    {
      id: "RULE-004",
      name: "Pakistan State Bank Clearing Cheque",
      pattern: "CLEARING CHEQUE #*",
      field: "description",
      targetAccount: "1040",
      targetAccountName: "Undeposited Funds / Cheques",
      confidence: 92,
      autoReconcile: false,
      status: "active",
      matchedCount: 15,
    },
    {
      id: "RULE-005",
      name: "Karachi Corporate Tax Withholding (FBR)",
      pattern: "FBR WHT TAX COLLECTION*",
      field: "description",
      targetAccount: "2050",
      targetAccountName: "Withholding Tax Payable",
      confidence: 98,
      autoReconcile: false,
      status: "paused",
      matchedCount: 6,
    },
  ]);

  const filteredRules = rules.filter((r) => {
    const matchesSearch =
      !searchQuery ||
      r.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      r.pattern.toLowerCase().includes(searchQuery.toLowerCase()) ||
      r.targetAccountName.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesStatus =
      statusFilter === "all" || r.status.toLowerCase() === statusFilter.toLowerCase();

    return matchesSearch && matchesStatus;
  });

  const columns: Column<MatchingRule>[] = [
    {
      key: "name",
      header: "Rule Name",
      render: (r) => (
        <div className="space-y-0.5 max-w-[240px]">
          <span className="font-semibold text-xs text-[#0F172A] block truncate">{r.name}</span>
          <span className="text-[10px] font-mono text-[#94A3B8] block">{r.id}</span>
        </div>
      ),
    },
    {
      key: "pattern",
      header: "Matching Pattern / Criteria",
      render: (r) => (
        <code className="text-[11px] font-mono text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-100 block max-w-[220px] truncate">
          {r.pattern}
        </code>
      ),
    },
    {
      key: "targetAccount",
      header: "Destination GL Account",
      render: (r) => (
        <div>
          <span className="font-mono font-bold text-xs text-[#0F172A]">{r.targetAccount}</span>
          <span className="text-[11px] text-[#64748B] block truncate max-w-[200px]">
            {r.targetAccountName}
          </span>
        </div>
      ),
    },
    {
      key: "confidence",
      header: "Confidence",
      align: "center",
      render: (r) => (
        <span className="text-xs font-bold font-mono text-[#0F172A]">
          {r.confidence}%
        </span>
      ),
    },
    {
      key: "autoReconcile",
      header: "Auto-Reconcile",
      align: "center",
      render: (r) => (
        <Badge variant={r.autoReconcile ? "success" : "neutral"}>
          {r.autoReconcile ? "Automated" : "Requires Review"}
        </Badge>
      ),
    },
    {
      key: "status",
      header: "Status",
      align: "center",
      render: (r) => (
        <span
          className={cn(
            "text-[10px] font-bold px-2 py-0.5 rounded-full capitalize",
            r.status === "active"
              ? "bg-emerald-50 text-emerald-700 border border-emerald-200"
              : "bg-slate-100 text-slate-600"
          )}
        >
          {r.status}
        </span>
      ),
    },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      render: (r) => (
        <div className="flex items-center justify-end space-x-2" onClick={(e) => e.stopPropagation()}>
          <button
            onClick={() => setSelectedRule(r)}
            className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50 transition-colors cursor-pointer"
          >
            Configure
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-7 space-y-6 animate-in fade-in duration-200", className)}>
      {/* 1. Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
        <div>
          <span className="text-[11px] font-semibold text-[#6366F1] uppercase tracking-wider block">
            Banking Intelligence
          </span>
          <h1 className="text-2xl font-bold tracking-tight text-[#0F172A]">
            Bank Statement Auto-Matching Rules
          </h1>
          <p className="text-xs text-[#64748B] mt-1">
            Deterministic regex and keyword heuristics for automated ledger reconciliation and clearing.
          </p>
        </div>

        <div className="flex items-center space-x-3">
          <button
            onClick={() => alert("Creating a new deterministic matching rule.")}
            className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-2 shadow-sm transition-colors cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            <span>New Matching Rule</span>
          </button>
        </div>
      </div>

      {/* 2. Filter Bar */}
      <FilterBar
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        searchPlaceholder="Search matching rules by name, pattern, or account..."
        statusFilter={statusFilter}
        onStatusChange={setStatusFilter}
        statusOptions={[
          { label: "All Statuses", value: "all" },
          { label: "Active", value: "active" },
          { label: "Paused", value: "paused" },
        ]}
        count={filteredRules.length}
        countLabel="matching rules"
        onRefresh={onRefresh}
      />

      {/* 3. Data Table */}
      <DataTable
        columns={columns}
        data={filteredRules}
        onRowClick={(r) => setSelectedRule(r)}
        rowKey={(r) => r.id}
        emptyMessage="No matching rules found"
        emptySubtext="Create a new automated rule to streamline transaction clearance."
      />

      {/* 4. Slide-Over Detail Drawer */}
      <SlideOverDrawer
        isOpen={Boolean(selectedRule)}
        onClose={() => setSelectedRule(null)}
        title={selectedRule?.name || "Matching Rule"}
        subtitle={`Rule ID: ${selectedRule?.id} • Match Field: ${selectedRule?.field}`}
        badge={
          <Badge variant={selectedRule?.status === "active" ? "success" : "neutral"}>
            {selectedRule?.status === "active" ? "Active" : "Paused"}
          </Badge>
        }
        footer={
          selectedRule && (
            <div className="flex items-center justify-between w-full">
              <button
                onClick={() => setSelectedRule(null)}
                className="px-4 py-2 border border-[#E2E8F0] hover:bg-slate-50 text-xs font-semibold rounded-xl text-[#64748B] transition-colors cursor-pointer"
              >
                Close
              </button>

              <div className="flex items-center space-x-2">
                <button
                  onClick={() => alert(`Simulated rule ${selectedRule.id} on latest bank statement. 18 matches identified.`)}
                  className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-[#0F172A] text-xs font-semibold rounded-xl transition-colors cursor-pointer flex items-center space-x-1.5"
                >
                  <Play className="w-3.5 h-3.5" />
                  <span>Test on Statement</span>
                </button>
                <button
                  onClick={() => {
                    alert(`Rule ${selectedRule.id} saved.`);
                    setSelectedRule(null);
                  }}
                  className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl transition-colors cursor-pointer shadow-xs"
                >
                  Save Changes
                </button>
              </div>
            </div>
          )
        }
      >
        {selectedRule && (
          <div className="space-y-6 text-xs text-[#0F172A]">
            {/* Criteria Box */}
            <div className="p-4 bg-[#F8FAFC] rounded-xl border border-[#E2E8F0] space-y-2">
              <span className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider block">
                Rule Definition & Criteria
              </span>
              <div>
                <span className="text-[11px] text-[#64748B] block">Search Pattern:</span>
                <code className="text-xs font-mono font-bold text-indigo-700 bg-white p-2 rounded border border-indigo-100 block mt-1">
                  {selectedRule.pattern}
                </code>
              </div>
              <div className="grid grid-cols-2 gap-3 pt-2 text-[11px]">
                <div>
                  <span className="text-[#94A3B8] block">Match Target:</span>
                  <span className="font-semibold text-[#0F172A] capitalize">Statement {selectedRule.field}</span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block">Confidence Level:</span>
                  <span className="font-semibold text-emerald-600">{selectedRule.confidence}% High Assurance</span>
                </div>
              </div>
            </div>

            {/* Target General Ledger Account */}
            <div className="p-4 bg-white border border-[#E2E8F0] rounded-xl space-y-2">
              <span className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider block">
                Destination Accounting Distribution
              </span>
              <div className="flex items-center space-x-3">
                <div className="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs font-mono">
                  {selectedRule.targetAccount}
                </div>
                <div>
                  <span className="font-bold text-xs text-[#0F172A] block">
                    {selectedRule.targetAccountName}
                  </span>
                  <span className="text-[10px] text-[#94A3B8]">
                    Account #{selectedRule.targetAccount}
                  </span>
                </div>
              </div>
            </div>

            {/* Statistics */}
            <div className="p-4 bg-emerald-50/60 border border-emerald-200/80 rounded-xl space-y-1.5">
              <div className="flex items-center space-x-2 text-emerald-800 font-bold">
                <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                <span>Execution Performance</span>
              </div>
              <p className="text-[11px] text-emerald-900">
                Successfully matched <strong>{selectedRule.matchedCount || 12}</strong> statement transactions with zero human reconciliation adjustments.
              </p>
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
