"use client";

import React, { useState } from "react";
import {
  BookOpen,
  Plus,
  RefreshCw,
  Shield,
  ShieldCheck,
  CheckCircle2,
  FolderTree,
  DollarSign,
  ArrowRight,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { DataTable, Column } from "../ui/DataTable";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge } from "../ui/Badge";

export interface AccountRecord {
  code: string;
  name: string;
  type: string;
  normal: string;
  isControl?: boolean;
  controlType?: string;
  balance?: string;
  currency?: string;
  description?: string;
}

interface ChartOfAccountsViewProps {
  accounts: AccountRecord[];
  isLoading?: boolean;
  onRefresh?: () => void;
  onOpenCreateAccount?: () => void;
  className?: string;
}

export function ChartOfAccountsView({
  accounts,
  isLoading = false,
  onRefresh,
  onOpenCreateAccount,
  className,
}: ChartOfAccountsViewProps) {
  const [searchQuery, setSearchQuery] = useState("");
  const [typeFilter, setTypeFilter] = useState("all");
  const [controlFilter, setControlFilter] = useState("all");
  const [selectedAccount, setSelectedAccount] = useState<AccountRecord | null>(null);

  const filteredAccounts = accounts.filter((acc) => {
    const matchesSearch =
      !searchQuery ||
      acc.code.toLowerCase().includes(searchQuery.toLowerCase()) ||
      acc.name.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesType =
      typeFilter === "all" || acc.type.toLowerCase() === typeFilter.toLowerCase();

    const matchesControl =
      controlFilter === "all" ||
      (controlFilter === "control" && acc.isControl) ||
      (controlFilter === "standard" && !acc.isControl);

    return matchesSearch && matchesType && matchesControl;
  });

  const columns: Column<AccountRecord>[] = [
    {
      key: "code",
      header: "Account Code",
      render: (acc) => (
        <span className="font-mono font-bold text-xs text-[#0F172A] hover:text-[#6366F1] transition-colors">
          {acc.code}
        </span>
      ),
    },
    {
      key: "name",
      header: "Account Name",
      render: (acc) => (
        <div className="flex items-center space-x-2">
          <span className="font-semibold text-xs text-[#0F172A]">{acc.name}</span>
          {acc.isControl && (
            <span
              className="inline-flex items-center gap-1 font-semibold text-amber-700 bg-amber-50 border border-amber-200/60 px-1.5 py-0.2 rounded text-[9px]"
              title="Protected Control Account: Direct manual journals are blocked by posting engine invariant."
            >
              <Shield className="w-2.5 h-2.5 text-amber-600" />
              Control
            </span>
          )}
        </div>
      ),
    },
    {
      key: "type",
      header: "Classification",
      render: (acc) => (
        <span className="text-xs text-[#475569] font-medium">{acc.type}</span>
      ),
    },
    {
      key: "normal",
      header: "Normal Balance",
      render: (acc) => (
        <span
          className={cn(
            "text-[10px] font-bold px-2 py-0.5 rounded font-mono uppercase",
            acc.normal === "Debit"
              ? "bg-blue-50 text-blue-700"
              : "bg-purple-50 text-purple-700"
          )}
        >
          {acc.normal}
        </span>
      ),
    },
    {
      key: "safeguard",
      header: "Safeguard Status",
      align: "center",
      render: (acc) => (
        <Badge variant={acc.isControl ? "warning" : "neutral"}>
          {acc.isControl ? "Control Protected" : "Standard Posting"}
        </Badge>
      ),
    },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      render: (acc) => (
        <div className="flex items-center justify-end space-x-2" onClick={(e) => e.stopPropagation()}>
          <button
            onClick={() => setSelectedAccount(acc)}
            className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50 transition-colors cursor-pointer"
          >
            Inspect Ledger
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
            Chart of Accounts (COA)
          </span>
          <h1 className="text-2xl font-bold tracking-tight text-[#0F172A]">
            Financial Structure & Control Accounts
          </h1>
          <p className="text-xs text-[#64748B] mt-1">
            Pakistan SME Accounting Standard COA with programmatic subledger control account safeguards.
          </p>
        </div>

        <div className="flex items-center space-x-3">
          <button
            onClick={onOpenCreateAccount}
            className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-2 shadow-sm transition-colors cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            <span>New General Ledger Account</span>
          </button>
        </div>
      </div>

      {/* 2. Filter Bar */}
      <FilterBar
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        searchPlaceholder="Search accounts by code or name..."
        statusFilter={typeFilter}
        onStatusChange={setTypeFilter}
        statusOptions={[
          { label: "All Account Types", value: "all" },
          { label: "Asset", value: "asset" },
          { label: "Liability", value: "liability" },
          { label: "Equity", value: "equity" },
          { label: "Revenue", value: "revenue" },
          { label: "Expense", value: "expense" },
        ]}
        entityFilter={controlFilter}
        onEntityChange={setControlFilter}
        entityOptions={[
          { label: "All Safeguards", value: "all" },
          { label: "Protected Control Accounts", value: "control" },
          { label: "Standard Accounts", value: "standard" },
        ]}
        count={filteredAccounts.length}
        countLabel="GL accounts"
        onRefresh={onRefresh}
        isRefreshing={isLoading}
      />

      {/* 3. Data Table */}
      <DataTable
        columns={columns}
        data={filteredAccounts}
        isLoading={isLoading}
        onRowClick={(acc) => setSelectedAccount(acc)}
        rowKey={(acc) => acc.code}
        emptyMessage="No accounts match filter"
        emptySubtext="Adjust your classification or search criteria."
      />

      {/* 4. Slide-Over Detail Drawer */}
      <SlideOverDrawer
        isOpen={Boolean(selectedAccount)}
        onClose={() => setSelectedAccount(null)}
        title={`Account #${selectedAccount?.code || ""} - ${selectedAccount?.name || ""}`}
        subtitle={`Classification: ${selectedAccount?.type || ""} • Normal: ${selectedAccount?.normal || ""}`}
        badge={
          <Badge variant={selectedAccount?.isControl ? "warning" : "success"}>
            {selectedAccount?.isControl ? "Control Account" : "Active GL"}
          </Badge>
        }
        footer={
          selectedAccount && (
            <div className="flex items-center justify-between w-full">
              <button
                onClick={() => setSelectedAccount(null)}
                className="px-4 py-2 border border-[#E2E8F0] hover:bg-slate-50 text-xs font-semibold rounded-xl text-[#64748B] transition-colors cursor-pointer"
              >
                Close
              </button>

              <button
                onClick={() => alert(`View full general ledger statement for Account ${selectedAccount.code}`)}
                className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl shadow-xs transition-colors cursor-pointer"
              >
                View Account Ledger
              </button>
            </div>
          )
        }
      >
        {selectedAccount && (
          <div className="space-y-6 text-xs text-[#0F172A]">
            {/* Account Details Box */}
            <div className="p-4 bg-[#F8FAFC] rounded-xl border border-[#E2E8F0] space-y-2">
              <span className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider block">
                Accounting Properties
              </span>
              <div className="grid grid-cols-2 gap-3 text-xs">
                <div>
                  <span className="text-[#94A3B8] block">Account Code:</span>
                  <span className="font-mono font-bold text-[#0F172A]">{selectedAccount.code}</span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block">Standard Balance:</span>
                  <span className="font-bold text-[#0F172A]">{selectedAccount.normal}</span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block">Classification:</span>
                  <span className="font-bold text-[#0F172A]">{selectedAccount.type}</span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block">Reporting Currency:</span>
                  <span className="font-bold text-[#0F172A]">PKR (Base)</span>
                </div>
              </div>
            </div>

            {/* Invariant Safeguard Protection Rule */}
            {selectedAccount.isControl ? (
              <div className="p-4 bg-amber-50/70 border border-amber-200/80 rounded-xl space-y-2">
                <div className="flex items-center space-x-2 text-amber-900 font-bold">
                  <Shield className="w-4 h-4 text-amber-600" />
                  <span>Control Account Safeguard Enforced</span>
                </div>
                <p className="text-[11px] text-amber-900 leading-relaxed">
                  This is a designated subledger control account (<code>{selectedAccount.controlType || "subledger_control"}</code>). Direct manual journal entries are strictly blocked by the Laravel posting engine invariant to prevent subledger drift.
                </p>
                <div className="p-2 bg-white rounded-lg border border-amber-200 text-[10px] font-mono text-amber-800">
                  RULE: Subledger entries must originate from validated Invoices or Vendor Bills.
                </div>
              </div>
            ) : (
              <div className="p-4 bg-emerald-50/60 border border-emerald-200/80 rounded-xl space-y-1.5">
                <div className="flex items-center space-x-2 text-emerald-800 font-bold">
                  <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                  <span>Standard Operational Account</span>
                </div>
                <p className="text-[11px] text-emerald-900">
                  Accepts balanced manual journal entries and automated workflow allocations.
                </p>
              </div>
            )}

            {/* Turnover Preview */}
            <div className="p-4 bg-white border border-[#E2E8F0] rounded-xl space-y-2">
              <span className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider block">
                Current Fiscal Year Turnover
              </span>
              <div className="flex justify-between items-baseline pt-1">
                <span className="text-xs text-[#64748B]">Active Book Balance:</span>
                <span className="text-base font-bold font-mono text-[#0F172A]">
                  PKR {selectedAccount.balance || "1,450,000.00"}
                </span>
              </div>
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
