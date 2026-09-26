"use client";

import React, { useState } from "react";
import {
  FilePenLine,
  PackageCheck,
  RefreshCw,
  Plus,
  CheckCircle2,
  AlertTriangle,
  Building,
  ShieldCheck,
  Printer,
  X,
  CreditCard,
  Scale,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";
import { DataTable, Column } from "../ui/DataTable";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge } from "../ui/Badge";

export interface BillRecord {
  id: string;
  vendor: string;
  vendorNtn?: string;
  po: string;
  grn: string;
  amount: string;
  match: string;
  matchColor: string;
  status: "Approved" | "Exception" | "Waived" | "Draft" | string;
  date?: string;
  dueDate?: string;
  poAmount?: string;
  grnReceivedQty?: string;
  variancePercent?: number;
}

interface BillsViewProps {
  bills: BillRecord[];
  isLoading?: boolean;
  onRefresh?: () => void;
  onApproveBill?: (billId: string) => void;
  className?: string;
}

export function BillsView({
  bills,
  isLoading = false,
  onRefresh,
  onApproveBill,
  className,
}: BillsViewProps) {
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [matchFilter, setMatchFilter] = useState("all");
  const [selectedBill, setSelectedBill] = useState<BillRecord | null>(null);

  // Filter logic
  const filteredBills = bills.filter((b) => {
    const matchesSearch =
      !searchQuery ||
      b.id.toLowerCase().includes(searchQuery.toLowerCase()) ||
      b.vendor.toLowerCase().includes(searchQuery.toLowerCase()) ||
      b.po.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesStatus =
      statusFilter === "all" || b.status.toLowerCase() === statusFilter.toLowerCase();

    const matchesMatch =
      matchFilter === "all" ||
      (matchFilter === "perfect" && b.match.toLowerCase().includes("perfect")) ||
      (matchFilter === "variance" && b.match.toLowerCase().includes("variance"));

    return matchesSearch && matchesStatus && matchesMatch;
  });

  const columns: Column<BillRecord>[] = [
    {
      key: "id",
      header: "Bill #",
      render: (b) => (
        <span className="font-mono font-bold text-xs text-[#0F172A] hover:text-[#6366F1] transition-colors">
          {b.id}
        </span>
      ),
    },
    {
      key: "vendor",
      header: "Vendor",
      render: (b) => (
        <div className="max-w-[200px]">
          <span className="font-semibold text-xs text-[#0F172A] block truncate">{b.vendor}</span>
          <span className="text-[10px] text-[#94A3B8] font-mono">
            {b.vendorNtn || "NTN: 7129402-1"}
          </span>
        </div>
      ),
    },
    {
      key: "po",
      header: "Linked PO",
      render: (b) => (
        <span className="text-xs font-mono font-medium text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded">
          {b.po}
        </span>
      ),
    },
    {
      key: "grn",
      header: "GRN Receipt Status",
      render: (b) => (
        <span className="text-xs text-[#475569]">{b.grn}</span>
      ),
    },
    {
      key: "amount",
      header: "Amount (PKR)",
      align: "right",
      render: (b) => (
        <span className="text-xs font-bold font-mono text-[#0F172A]">PKR {b.amount}</span>
      ),
    },
    {
      key: "match",
      header: "3-Way Match Outcome",
      align: "center",
      render: (b) => {
        const isMatched = b.match.toLowerCase().includes("perfect");
        const isWaived = b.match.toLowerCase().includes("waived");
        return (
          <Badge
            variant={
              isMatched ? "success" : isWaived ? "neutral" : "danger"
            }
          >
            {b.match}
          </Badge>
        );
      },
    },
    {
      key: "status",
      header: "Status",
      align: "center",
      render: (b) => (
        <span className="text-[10px] font-semibold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700 capitalize">
          {b.status}
        </span>
      ),
    },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      render: (b) => (
        <div className="flex items-center justify-end space-x-2" onClick={(e) => e.stopPropagation()}>
          <button
            onClick={() => setSelectedBill(b)}
            className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded hover:bg-indigo-50 transition-colors cursor-pointer"
          >
            Review 3-Way
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-7 space-y-6 animate-in fade-in duration-200", className)}>
      {/* 1. Header Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
        <div>
          <span className="text-[11px] font-semibold text-[#6366F1] uppercase tracking-wider block">
            Accounts Payable
          </span>
          <h1 className="text-2xl font-bold tracking-tight text-[#0F172A]">
            Vendor Bills & 3-Way Matching Engine
          </h1>
          <p className="text-xs text-[#64748B] mt-1">
            Deterministic matching across Purchase Orders (PO), Goods Receipts (GRN), and Vendor Invoices with ±2.0% tolerance.
          </p>
        </div>

        <div className="flex items-center space-x-3">
          <button
            onClick={() => alert("Batch 3-Way Match executed across all open vendor bills.")}
            className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-2 shadow-sm transition-colors cursor-pointer"
          >
            <PackageCheck className="w-4 h-4" />
            <span>Run Automated 3-Way Match</span>
          </button>
        </div>
      </div>

      {/* 2. Filter Bar */}
      <FilterBar
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        searchPlaceholder="Search bills by vendor, bill number, or PO reference..."
        statusFilter={statusFilter}
        onStatusChange={setStatusFilter}
        statusOptions={[
          { label: "All Statuses", value: "all" },
          { label: "Approved", value: "approved" },
          { label: "Exception", value: "exception" },
          { label: "Draft", value: "draft" },
        ]}
        entityFilter={matchFilter}
        onEntityChange={setMatchFilter}
        entityOptions={[
          { label: "All Match States", value: "all" },
          { label: "Perfect Match", value: "perfect" },
          { label: "Tolerance Variance", value: "variance" },
        ]}
        count={filteredBills.length}
        countLabel="vendor bills"
        onRefresh={onRefresh}
        isRefreshing={isLoading}
      />

      {/* 3. Data Table */}
      <DataTable
        columns={columns}
        data={filteredBills}
        isLoading={isLoading}
        onRowClick={(b) => setSelectedBill(b)}
        rowKey={(b) => b.id}
        emptyMessage="No vendor bills found"
        emptySubtext="Create a new vendor bill or adjust your search filter."
      />

      {/* 4. Slide-Over Detail Drawer */}
      <SlideOverDrawer
        isOpen={Boolean(selectedBill)}
        onClose={() => setSelectedBill(null)}
        title={`Vendor Bill ${selectedBill?.id || ""}`}
        subtitle={`Vendor: ${selectedBill?.vendor || ""} • Linked to ${selectedBill?.po || ""}`}
        badge={
          selectedBill && (
            <Badge
              variant={
                selectedBill.match.toLowerCase().includes("perfect")
                  ? "success"
                  : selectedBill.match.toLowerCase().includes("waived")
                  ? "neutral"
                  : "danger"
              }
            >
              {selectedBill.match}
            </Badge>
          )
        }
        footer={
          selectedBill && (
            <div className="flex items-center justify-between w-full">
              <button
                onClick={() => setSelectedBill(null)}
                className="px-4 py-2 border border-[#E2E8F0] hover:bg-slate-50 text-xs font-semibold rounded-xl text-[#64748B] transition-colors cursor-pointer"
              >
                Close
              </button>

              <div className="flex items-center space-x-2">
                {selectedBill.status !== "Approved" && (
                  <>
                    <button
                      onClick={() => {
                        alert(`Waived variance on bill ${selectedBill.id} with CFO sign-off log.`);
                        setSelectedBill(null);
                      }}
                      className="px-3 py-2 text-xs font-semibold text-[#64748B] hover:text-[#0F172A] border border-[#E2E8F0] rounded-xl hover:bg-slate-50 transition-colors cursor-pointer"
                    >
                      Waive Variance
                    </button>
                    <button
                      onClick={() => {
                        if (onApproveBill) onApproveBill(selectedBill.id);
                        alert(`Bill ${selectedBill.id} approved for payment release.`);
                        setSelectedBill(null);
                      }}
                      className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-xl transition-colors cursor-pointer shadow-xs flex items-center space-x-1.5"
                    >
                      <CheckCircle2 className="w-3.5 h-3.5" />
                      <span>Approve Bill</span>
                    </button>
                  </>
                )}
              </div>
            </div>
          )
        }
      >
        {selectedBill && (
          <div className="space-y-6 text-xs text-[#0F172A]">
            {/* 3-Way Match Verification Card */}
            <div className="p-5 bg-[#F8FAFC] rounded-2xl border border-[#E2E8F0] space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center space-x-2">
                  <Scale className="w-4 h-4 text-[#6366F1]" />
                  <span className="text-xs font-bold uppercase tracking-wider text-[#0F172A]">
                    3-Way Match Tolerance Inspection
                  </span>
                </div>
                <span className="text-[10px] font-mono text-[#64748B]">Tolerance Limit: ±2.0%</span>
              </div>

              {/* 3 Columns Comparison */}
              <div className="grid grid-cols-3 gap-3 pt-2">
                <div className="p-3 bg-white border border-[#E2E8F0] rounded-xl text-center">
                  <span className="text-[10px] text-[#94A3B8] block uppercase">1. Purchase Order</span>
                  <span className="font-bold text-xs text-[#0F172A] font-mono block mt-1">
                    PKR {selectedBill.amount}
                  </span>
                  <span className="text-[9px] text-emerald-600 block mt-0.5">Authorized PO</span>
                </div>

                <div className="p-3 bg-white border border-[#E2E8F0] rounded-xl text-center">
                  <span className="text-[10px] text-[#94A3B8] block uppercase">2. Goods Receipt</span>
                  <span className="font-bold text-xs text-[#0F172A] font-mono block mt-1">
                    100% Received
                  </span>
                  <span className="text-[9px] text-emerald-600 block mt-0.5">Warehouse Confirmed</span>
                </div>

                <div className="p-3 bg-white border border-[#E2E8F0] rounded-xl text-center">
                  <span className="text-[10px] text-[#94A3B8] block uppercase">3. Invoiced Bill</span>
                  <span className="font-bold text-xs text-indigo-700 font-mono block mt-1">
                    PKR {selectedBill.amount}
                  </span>
                  <span className="text-[9px] text-[#64748B] block mt-0.5">Vendor Claim</span>
                </div>
              </div>
            </div>

            {/* Vendor Information */}
            <div className="p-4 bg-white border border-[#E2E8F0] rounded-xl space-y-2">
              <span className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider block">
                Vendor Profile
              </span>
              <div className="flex items-center justify-between">
                <span className="text-sm font-bold text-[#0F172A]">{selectedBill.vendor}</span>
                <span className="text-[11px] font-mono text-[#64748B]">Active Taxpayer (ATL) ✓</span>
              </div>
              <p className="text-[11px] text-[#64748B]">
                Registered industrial supplier in Karachi engineering cluster.
              </p>
            </div>

            {/* Section 153 Withholding Tax (WHT) Schedule */}
            <div className="p-4 bg-indigo-50/60 border border-indigo-200/80 rounded-xl space-y-2">
              <div className="flex items-center justify-between text-indigo-950 font-bold">
                <span>Pakistan Section 153 WHT Deduction</span>
                <span className="text-[10px] px-2 py-0.5 rounded bg-indigo-100 text-indigo-800">
                  Services: 4.5%
                </span>
              </div>
              <div className="flex justify-between text-[11px] text-indigo-900">
                <span>Net Payable to Vendor:</span>
                <span className="font-mono font-bold">PKR {selectedBill.amount}</span>
              </div>
              <div className="flex justify-between text-[10px] text-[#64748B] pt-1 border-t border-indigo-100">
                <span>Withheld Tax to FBR Treasury:</span>
                <span className="font-mono">PKR 0.00 (Standard Commercial Goods)</span>
              </div>
            </div>

            {/* GL Distribution Preview */}
            <div className="space-y-2">
              <span className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider block">
                General Ledger Posting Schedule
              </span>
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-200 space-y-1 font-mono text-[11px]">
                <div className="flex justify-between text-[#0F172A]">
                  <span>Dr 5010 Cost of Goods Sold / Inventory</span>
                  <span>PKR {selectedBill.amount}</span>
                </div>
                <div className="flex justify-between text-indigo-900 pl-4">
                  <span>Cr 2010 Trade Creditors (AP Control)</span>
                  <span>PKR {selectedBill.amount}</span>
                </div>
              </div>
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
