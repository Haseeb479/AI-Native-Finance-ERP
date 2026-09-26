"use client";

import React, { useState } from "react";
import {
  FileText,
  Plus,
  RefreshCw,
  QrCode,
  CheckCircle2,
  Clock,
  Send,
  Building,
  ShieldCheck,
  ExternalLink,
  Printer,
  X,
  CreditCard,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";
import { DataTable, Column } from "../ui/DataTable";
import { FilterBar } from "../ui/FilterBar";
import { SlideOverDrawer } from "../ui/SlideOverDrawer";
import { Badge } from "../ui/Badge";

export interface InvoiceRecord {
  id: string;
  rawId: string;
  customer: string;
  customerNtn?: string;
  date: string;
  dueDate?: string;
  subtotal: string;
  tax: string;
  total: string;
  fbr: string;
  status: "paid" | "sent" | "draft" | string;
  items?: { description: string; qty: number; unitPrice: number; total: number }[];
  fbrInvoiceNo?: string;
  glPosted?: boolean;
}

interface InvoicesViewProps {
  invoices: InvoiceRecord[];
  isLoading?: boolean;
  onRefresh?: () => void;
  onPostInvoice?: (rawId: string) => void;
  onOpenCreateModal?: () => void;
  orgName?: string;
  className?: string;
}

export function InvoicesView({
  invoices,
  isLoading = false,
  onRefresh,
  onPostInvoice,
  onOpenCreateModal,
  orgName = "Apex Trading",
  className,
}: InvoicesViewProps) {
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [fbrFilter, setFbrFilter] = useState("all");
  const [selectedInvoice, setSelectedInvoice] = useState<InvoiceRecord | null>(null);

  // Filter logic
  const filteredInvoices = invoices.filter((inv) => {
    const matchesSearch =
      !searchQuery ||
      inv.id.toLowerCase().includes(searchQuery.toLowerCase()) ||
      inv.customer.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesStatus = statusFilter === "all" || inv.status.toLowerCase() === statusFilter.toLowerCase();

    const isFiscalized = inv.fbr.includes("Fiscalized");
    const matchesFbr =
      fbrFilter === "all" ||
      (fbrFilter === "fiscalized" && isFiscalized) ||
      (fbrFilter === "pending" && !isFiscalized);

    return matchesSearch && matchesStatus && matchesFbr;
  });

  const columns: Column<InvoiceRecord>[] = [
    {
      key: "id",
      header: "Invoice #",
      render: (inv) => (
        <span className="font-mono font-bold text-xs text-[#0F172A] hover:text-[#6366F1] transition-colors">
          {inv.id}
        </span>
      ),
    },
    {
      key: "customer",
      header: "Customer",
      render: (inv) => (
        <div className="max-w-[200px]">
          <span className="font-semibold text-xs text-[#0F172A] block truncate">{inv.customer}</span>
          <span className="text-[10px] text-[#94A3B8] font-mono">
            {inv.customerNtn || "NTN: 4892019-2"}
          </span>
        </div>
      ),
    },
    {
      key: "date",
      header: "Issue Date",
      render: (inv) => <span className="text-xs text-[#64748B] font-mono">{inv.date}</span>,
    },
    {
      key: "subtotal",
      header: "Subtotal",
      align: "right",
      render: (inv) => (
        <span className="text-xs font-mono text-[#475569]">PKR {inv.subtotal}</span>
      ),
    },
    {
      key: "tax",
      header: "GST (18%)",
      align: "right",
      render: (inv) => (
        <span className="text-xs font-mono text-[#64748B]">PKR {inv.tax}</span>
      ),
    },
    {
      key: "total",
      header: "Total Amount",
      align: "right",
      render: (inv) => (
        <span className="text-xs font-bold font-mono text-[#0F172A]">PKR {inv.total}</span>
      ),
    },
    {
      key: "fbr",
      header: "FBR POS Status",
      align: "center",
      render: (inv) => {
        const isFiscalized = inv.fbr.includes("Fiscalized");
        return (
          <Badge variant={isFiscalized ? "success" : "warning"}>
            {isFiscalized ? "Fiscalized (FBR POS)" : "Pending QR"}
          </Badge>
        );
      },
    },
    {
      key: "status",
      header: "Status",
      align: "center",
      render: (inv) => {
        const s = inv.status.toLowerCase();
        return (
          <Badge
            variant={
              s === "paid" ? "success" : s === "sent" ? "neutral" : "outline"
            }
          >
            <span className="capitalize">{inv.status}</span>
          </Badge>
        );
      },
    },
    {
      key: "actions",
      header: "Actions",
      align: "right",
      render: (inv) => (
        <div className="flex items-center justify-end space-x-2" onClick={(e) => e.stopPropagation()}>
          {inv.status === "draft" && onPostInvoice && (
            <button
              onClick={() => onPostInvoice(inv.rawId)}
              className="text-[11px] font-semibold text-[#6366F1] hover:text-[#4338CA] px-2 py-1 rounded hover:bg-indigo-50 transition-colors cursor-pointer"
            >
              Post GL
            </button>
          )}
          <button
            onClick={() => setSelectedInvoice(inv)}
            className="text-[11px] font-semibold text-[#64748B] hover:text-[#0F172A] px-2 py-1 rounded hover:bg-slate-100 transition-colors cursor-pointer"
          >
            Inspect
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
            Accounts Receivable
          </span>
          <h1 className="text-2xl font-bold tracking-tight text-[#0F172A]">
            Sales Invoices & Commercial Billings
          </h1>
          <p className="text-xs text-[#64748B] mt-1">
            Manage customer invoicing, digital FBR POS fiscalization, and subledger collections.
          </p>
        </div>

        <div className="flex items-center space-x-3">
          <button
            onClick={onOpenCreateModal}
            className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-2 shadow-sm transition-colors cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            <span>New Sales Invoice</span>
          </button>
        </div>
      </div>

      {/* 2. Filter Bar */}
      <FilterBar
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        searchPlaceholder="Search invoices by customer, number, or tax ID..."
        statusFilter={statusFilter}
        onStatusChange={setStatusFilter}
        statusOptions={[
          { label: "All Statuses", value: "all" },
          { label: "Paid", value: "paid" },
          { label: "Sent", value: "sent" },
          { label: "Draft", value: "draft" },
        ]}
        entityFilter={fbrFilter}
        onEntityChange={setFbrFilter}
        entityOptions={[
          { label: "All FBR States", value: "all" },
          { label: "Fiscalized (FBR POS)", value: "fiscalized" },
          { label: "Pending QR Fiscalization", value: "pending" },
        ]}
        count={filteredInvoices.length}
        countLabel="invoices"
        onRefresh={onRefresh}
        isRefreshing={isLoading}
      />

      {/* 3. Data Table */}
      <DataTable
        columns={columns}
        data={filteredInvoices}
        isLoading={isLoading}
        onRowClick={(inv) => setSelectedInvoice(inv)}
        rowKey={(inv) => inv.id}
        emptyMessage="No sales invoices found"
        emptySubtext="Create a new commercial invoice or adjust your filter query."
      />

      {/* 4. Slide-Over Detail Drawer */}
      <SlideOverDrawer
        isOpen={Boolean(selectedInvoice)}
        onClose={() => setSelectedInvoice(null)}
        title={`Invoice ${selectedInvoice?.id || ""}`}
        subtitle={`Issued to ${selectedInvoice?.customer || ""} on ${selectedInvoice?.date || ""}`}
        badge={
          selectedInvoice && (
            <Badge
              variant={
                selectedInvoice.status === "paid"
                  ? "success"
                  : selectedInvoice.status === "sent"
                  ? "neutral"
                  : "outline"
              }
            >
              <span className="capitalize">{selectedInvoice.status}</span>
            </Badge>
          )
        }
        footer={
          selectedInvoice && (
            <div className="flex items-center justify-between w-full">
              <button
                onClick={() => setSelectedInvoice(null)}
                className="px-4 py-2 border border-[#E2E8F0] hover:bg-slate-50 text-xs font-semibold rounded-xl text-[#64748B] transition-colors cursor-pointer"
              >
                Close
              </button>

              <div className="flex items-center space-x-2">
                {selectedInvoice.status === "draft" && onPostInvoice && (
                  <button
                    onClick={() => {
                      onPostInvoice(selectedInvoice.rawId);
                      setSelectedInvoice(null);
                    }}
                    className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl transition-colors cursor-pointer shadow-xs"
                  >
                    Post to General Ledger
                  </button>
                )}
                <button
                  onClick={() => alert(`Printing tax invoice for ${selectedInvoice.id}`)}
                  className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-[#0F172A] text-xs font-semibold rounded-xl transition-colors cursor-pointer flex items-center space-x-1.5"
                >
                  <Printer className="w-3.5 h-3.5" />
                  <span>Print Tax Invoice</span>
                </button>
              </div>
            </div>
          )
        }
      >
        {selectedInvoice && (
          <div className="space-y-6 text-xs text-[#0F172A]">
            {/* Customer Details Box */}
            <div className="p-4 bg-[#F8FAFC] rounded-xl border border-[#E2E8F0] space-y-2">
              <span className="text-[10px] font-bold text-[#64748B] uppercase tracking-wider block">
                Customer & Tax Registration
              </span>
              <div className="flex items-center justify-between">
                <span className="text-sm font-bold text-[#0F172A]">{selectedInvoice.customer}</span>
                <span className="text-[11px] font-mono text-[#64748B]">NTN: 4892019-2</span>
              </div>
              <div className="grid grid-cols-2 gap-2 pt-2 border-t border-[#E2E8F0]/60 text-[11px]">
                <div>
                  <span className="text-[#94A3B8] block">Payment Terms:</span>
                  <span className="font-semibold text-[#0F172A]">Net 30 Days</span>
                </div>
                <div>
                  <span className="text-[#94A3B8] block">Currency:</span>
                  <span className="font-semibold text-[#0F172A]">PKR (Pakistani Rupee)</span>
                </div>
              </div>
            </div>

            {/* Line Items Table */}
            <div className="space-y-2">
              <span className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider block">
                Billed Items & Services
              </span>
              <div className="border border-[#E2E8F0] rounded-xl overflow-hidden">
                <table className="w-full text-left text-xs">
                  <thead className="bg-[#F8FAFC] border-b border-[#E2E8F0] text-[10px] text-[#64748B] uppercase">
                    <tr>
                      <th className="py-2.5 px-3">Item / Description</th>
                      <th className="py-2.5 px-3 text-right">Qty</th>
                      <th className="py-2.5 px-3 text-right">Unit Price</th>
                      <th className="py-2.5 px-3 text-right">Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#F1F5F9]">
                    <tr>
                      <td className="py-2.5 px-3 font-medium text-[#0F172A]">
                        Enterprise Financial Software License
                      </td>
                      <td className="py-2.5 px-3 text-right font-mono">1</td>
                      <td className="py-2.5 px-3 text-right font-mono">PKR {selectedInvoice.subtotal}</td>
                      <td className="py-2.5 px-3 text-right font-mono font-bold">PKR {selectedInvoice.subtotal}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            {/* Financial Summary */}
            <div className="space-y-1.5 p-4 bg-white border border-[#E2E8F0] rounded-xl">
              <div className="flex justify-between text-xs text-[#64748B]">
                <span>Subtotal (Excl. Sales Tax):</span>
                <span className="font-mono">PKR {selectedInvoice.subtotal}</span>
              </div>
              <div className="flex justify-between text-xs text-[#64748B]">
                <span>Sales Tax (18% FBR GST):</span>
                <span className="font-mono">PKR {selectedInvoice.tax}</span>
              </div>
              <div className="flex justify-between text-sm font-bold text-[#0F172A] pt-2 border-t border-[#F1F5F9]">
                <span>Total Amount Due:</span>
                <span className="font-mono text-indigo-700">PKR {selectedInvoice.total}</span>
              </div>
            </div>

            {/* FBR Digital POS Fiscalization Details */}
            <div className="p-4 bg-emerald-50/60 border border-emerald-200/80 rounded-xl space-y-2.5">
              <div className="flex items-center space-x-2 text-emerald-800">
                <ShieldCheck className="w-4 h-4 text-emerald-600" />
                <span className="text-xs font-bold uppercase tracking-wider">
                  FBR Digital POS Fiscalization
                </span>
              </div>
              <p className="text-[11px] text-emerald-900 leading-relaxed">
                Fiscalized via cryptographic integration. Encrypted QR code verified against Federal Board of Revenue central invoice repository.
              </p>
              <div className="p-2.5 bg-white rounded-lg border border-emerald-200 flex items-center justify-between text-[11px] font-mono">
                <div>
                  <span className="text-[#94A3B8] block text-[9px]">FBR INVOICE NO:</span>
                  <span className="font-bold text-[#0F172A]">
                    {selectedInvoice.fbrInvoiceNo || `FBR-${selectedInvoice.id}-882194`}
                  </span>
                </div>
                <QrCode className="w-8 h-8 text-[#0F172A]" />
              </div>
            </div>

            {/* Double-Entry GL Distribution Preview */}
            <div className="space-y-2">
              <span className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider block">
                General Ledger Posting Impact
              </span>
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-200 space-y-1 font-mono text-[11px]">
                <div className="flex justify-between text-indigo-900">
                  <span>Dr 1030 Trade Debtors</span>
                  <span>PKR {selectedInvoice.total}</span>
                </div>
                <div className="flex justify-between text-[#64748B] pl-4">
                  <span>Cr 4010 Sales Revenue</span>
                  <span>PKR {selectedInvoice.subtotal}</span>
                </div>
                <div className="flex justify-between text-[#64748B] pl-4">
                  <span>Cr 2040 Sales Tax Payable</span>
                  <span>PKR {selectedInvoice.tax}</span>
                </div>
              </div>
            </div>
          </div>
        )}
      </SlideOverDrawer>
    </div>
  );
}
