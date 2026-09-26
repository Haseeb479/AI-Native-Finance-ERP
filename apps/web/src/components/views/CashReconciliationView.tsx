"use client";

import React, { useState } from "react";
import {
  Landmark,
  Check,
  CircleDot,
  Upload,
  ArrowUpDown,
  Filter,
  Search,
  RefreshCw,
  Plus,
  ArrowRight,
  CreditCard,
  Building,
  CheckCircle2,
} from "lucide-react";
import { cn, formatPKR } from "@/lib/utils";

interface CashReconciliationViewProps {
  bankAccounts: any[];
  activeAccount: any;
  onSelectAccount: (id: string) => void;
  bankTransactions: any[];
  onImportStatement: () => void;
  onReconcile: (txId: string, journalId: string) => void;
  onUnreconcile: (txId: string) => void;
  className?: string;
}

export function CashReconciliationView({
  bankAccounts,
  activeAccount,
  onSelectAccount,
  bankTransactions,
  onImportStatement,
  onReconcile,
  onUnreconcile,
  className,
}: CashReconciliationViewProps) {
  const [activeTab, setActiveTab] = useState<"unmatched" | "matched">("unmatched");
  const [selectedBankTxId, setSelectedBankTxId] = useState<string | null>(null);
  const [selectedGlEntryId, setSelectedGlEntryId] = useState<string | null>(null);

  // Filter transactions
  const unmatchedTxns = bankTransactions.filter(
    (t: any) => t.reconciliation_status !== "reconciled"
  );
  const matchedTxns = bankTransactions.filter(
    (t: any) => t.reconciliation_status === "reconciled"
  );

  const currentList = activeTab === "unmatched" ? unmatchedTxns : matchedTxns;

  // Selected amounts calculation
  const selectedBankTx = bankTransactions.find((t: any) => t.id === selectedBankTxId) || currentList[0];
  const bankAmount = selectedBankTx ? (selectedBankTx.type === "debit" ? -parseFloat(selectedBankTx.amount) : parseFloat(selectedBankTx.amount)) : 0;
  const glAmount = selectedGlEntryId ? (selectedBankTx?.suggestion ? parseFloat(selectedBankTx.amount) : 0) : 0;
  const diff = bankAmount - glAmount;

  return (
    <div className={cn("max-w-[1320px] w-full mx-auto px-6 sm:px-10 py-7 space-y-6 animate-in fade-in duration-200", className)}>
      {/* ─────────────────────────────────────────────────────────────
          1. HEADER & ACCOUNT SWITCHER (PDF Pages 13 & 14)
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#F1F5F9] pb-4">
        <div>
          <div className="flex items-center space-x-3">
            <h1 className="text-2xl font-bold tracking-tight text-[#0F172A]">
              Cash Reconciliation
            </h1>

            {/* Account Selector Dropdown */}
            <select
              value={activeAccount?.id || ""}
              onChange={(e) => onSelectAccount(e.target.value)}
              className="text-xs font-semibold bg-white border border-[#E2E8F0] rounded-xl px-3 py-1.5 text-[#0F172A] outline-none cursor-pointer hover:border-indigo-300"
            >
              {bankAccounts.map((acc: any) => (
                <option key={acc.id} value={acc.id}>
                  {acc.bank_name} ({acc.account_number})
                </option>
              ))}
            </select>
          </div>
          <p className="text-xs text-[#64748B] mt-1">
            Dual-sided bank statement clearing and ledger reconciliation.
          </p>
        </div>

        {/* Right Action Buttons */}
        <div className="flex items-center space-x-3">
          <button
            onClick={onImportStatement}
            className="px-4 py-2 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl flex items-center space-x-2 transition-colors cursor-pointer shadow-xs"
          >
            <Upload className="w-3.5 h-3.5" />
            <span>Import Statement (CSV)</span>
          </button>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          2. UNMATCHED / MATCHED TABS (PDF Page 13/14)
      ─────────────────────────────────────────────────────────────── */}
      <div className="flex items-center space-x-2">
        <button
          onClick={() => setActiveTab("unmatched")}
          className={cn(
            "px-4 py-1.5 rounded-xl text-xs font-semibold transition-all cursor-pointer",
            activeTab === "unmatched"
              ? "bg-[#0F172A] text-white shadow-xs"
              : "bg-white text-[#64748B] hover:text-[#0F172A] border border-[#E2E8F0]"
          )}
        >
          Unmatched ({unmatchedTxns.length})
        </button>
        <button
          onClick={() => setActiveTab("matched")}
          className={cn(
            "px-4 py-1.5 rounded-xl text-xs font-semibold transition-all cursor-pointer",
            activeTab === "matched"
              ? "bg-[#0F172A] text-white shadow-xs"
              : "bg-white text-[#64748B] hover:text-[#0F172A] border border-[#E2E8F0]"
          )}
        >
          Matched ({matchedTxns.length})
        </button>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          3. DUAL-COLUMN RECONCILIATION SPLIT SCREEN (PDF Page 13 & 14)
      ─────────────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Left Pane: Bank Transactions */}
        <div className="bg-white border border-[#E2E8F0] rounded-2xl p-5 shadow-xs flex flex-col justify-between space-y-4">
          <div>
            <div className="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
              <div>
                <h3 className="text-sm font-bold text-[#0F172A]">Bank Transactions</h3>
                <span className="text-[10px] text-[#94A3B8]">
                  Statement live balance
                </span>
              </div>
              <div className="text-right">
                <span className="text-base font-extrabold text-[#0F172A]">
                  PKR {parseFloat(activeAccount?.current_balance || "5000000").toLocaleString()}
                </span>
                <span className="text-[10px] text-[#64748B] block">Bank Balance</span>
              </div>
            </div>

            {/* List */}
            <div className="divide-y divide-[#F1F5F9] max-h-[440px] overflow-y-auto pt-1">
              {currentList.length === 0 ? (
                <div className="py-12 text-center text-xs text-[#94A3B8]">
                  No {activeTab} transactions found.
                </div>
              ) : (
                currentList.map((tx: any) => {
                  const isDebit = tx.type === "debit";
                  const isSelected = selectedBankTxId === tx.id || (!selectedBankTxId && tx === currentList[0]);

                  return (
                    <div
                      key={tx.id}
                      onClick={() => setSelectedBankTxId(tx.id)}
                      className={cn(
                        "py-3 px-3 rounded-xl flex items-center justify-between transition-colors cursor-pointer",
                        isSelected
                          ? "bg-indigo-50/70 border border-indigo-200/80"
                          : "hover:bg-[#F8FAFC]"
                      )}
                    >
                      <div className="flex items-center space-x-3 min-w-0">
                        <div className="w-8 h-8 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-xs shrink-0">
                          {tx.description?.charAt(0) || "B"}
                        </div>
                        <div className="truncate">
                          <h4 className="text-xs font-semibold text-[#0F172A] truncate">
                            {tx.description}
                          </h4>
                          <span className="text-[10px] text-[#94A3B8] font-mono">
                            {tx.transaction_date} • {tx.reference || "No Ref"}
                          </span>
                        </div>
                      </div>

                      <div className="text-right shrink-0 ml-3">
                        <span
                          className={cn(
                            "text-xs font-bold font-mono block",
                            isDebit ? "text-rose-600" : "text-emerald-600"
                          )}
                        >
                          {isDebit ? "-" : "+"}PKR {parseFloat(tx.amount || 0).toLocaleString()}
                        </span>
                        <span className="text-[9px] px-1.5 py-0.2 rounded bg-slate-100 text-[#64748B]">
                          Bank Feed
                        </span>
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          </div>
        </div>

        {/* Right Pane: Finova Entries */}
        <div className="bg-white border border-[#E2E8F0] rounded-2xl p-5 shadow-xs flex flex-col justify-between space-y-4">
          <div>
            <div className="flex items-center justify-between pb-3 border-b border-[#F1F5F9]">
              <div>
                <h3 className="text-sm font-bold text-[#0F172A]">Finova ERP Entries</h3>
                <span className="text-[10px] text-[#94A3B8]">
                  General ledger candidates & receipts
                </span>
              </div>
              <div className="flex items-center space-x-2">
                <span className="text-[10px] px-2 py-1 rounded bg-[#F1F5F9] text-[#64748B] font-medium">
                  Auto-Match 98%
                </span>
              </div>
            </div>

            {/* List */}
            <div className="divide-y divide-[#F1F5F9] max-h-[440px] overflow-y-auto pt-1">
              {selectedBankTx?.suggestion ? (
                <div
                  onClick={() => setSelectedGlEntryId(selectedBankTx.suggestion.journal_id)}
                  className="py-3.5 px-3 rounded-xl bg-emerald-50/60 border border-emerald-200/80 flex items-center justify-between cursor-pointer"
                >
                  <div className="space-y-1">
                    <div className="flex items-center space-x-2">
                      <span className="text-xs font-bold text-[#0F172A]">
                        {selectedBankTx.suggestion.entry_number}
                      </span>
                      <span className="text-[9px] font-bold px-1.5 py-0.2 rounded bg-emerald-100 text-emerald-800">
                        {Math.round(selectedBankTx.suggestion.confidence * 100)}% match
                      </span>
                    </div>
                    <p className="text-[11px] text-[#64748B]">
                      {selectedBankTx.suggestion.reason}
                    </p>
                    <span className="text-[10px] font-mono text-emerald-700 block">
                      Target Account: #1010 Operating Cash
                    </span>
                  </div>

                  <div className="text-right shrink-0">
                    <span className="text-xs font-bold font-mono text-emerald-700 block">
                      PKR {parseFloat(selectedBankTx.amount || 0).toLocaleString()}
                    </span>
                    <span className="text-[9px] px-1.5 py-0.2 rounded bg-emerald-100 text-emerald-800 font-semibold">
                      Journal Entry
                    </span>
                  </div>
                </div>
              ) : (
                <div className="py-12 text-center text-xs text-[#94A3B8] space-y-2">
                  <p>No automated ledger suggestion for this transaction.</p>
                  <button
                    onClick={() => alert("Creating custom clearing entry...")}
                    className="text-xs font-semibold text-[#6366F1] hover:underline cursor-pointer"
                  >
                    + Create Manual Matching Entry
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* ─────────────────────────────────────────────────────────────
          4. BOTTOM MATCHING DOCK (PDF Page 13 & 14)
      ─────────────────────────────────────────────────────────────── */}
      <div className="bg-[#0F172A] text-white rounded-2xl p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xl">
        <div className="flex flex-wrap items-center gap-6 sm:gap-10 text-xs">
          <div>
            <span className="text-[10px] text-slate-400 block uppercase">Bank Amount</span>
            <span className="text-sm font-bold font-mono text-white">
              PKR {Math.abs(bankAmount).toLocaleString()}
            </span>
          </div>

          <div>
            <span className="text-[10px] text-slate-400 block uppercase">Selected GL</span>
            <span className="text-sm font-bold font-mono text-white">
              PKR {selectedBankTx?.suggestion ? Math.abs(bankAmount).toLocaleString() : "0"}
            </span>
          </div>

          <div>
            <span className="text-[10px] text-slate-400 block uppercase">Difference</span>
            <span
              className={cn(
                "text-sm font-bold font-mono",
                selectedBankTx?.suggestion ? "text-emerald-400" : "text-amber-400"
              )}
            >
              PKR {selectedBankTx?.suggestion ? "0.00" : Math.abs(bankAmount).toLocaleString()}
            </span>
          </div>
        </div>

        {/* Action Button */}
        <div>
          {selectedBankTx?.reconciliation_status === "reconciled" ? (
            <button
              onClick={() => onUnreconcile(selectedBankTx.id)}
              className="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-xl transition-colors cursor-pointer"
            >
              Unmatch Transaction
            </button>
          ) : (
            <button
              onClick={() => {
                if (selectedBankTx?.suggestion) {
                  onReconcile(selectedBankTx.id, selectedBankTx.suggestion.journal_id);
                } else {
                  alert("Please select a matching journal entry or create a manual one.");
                }
              }}
              className="px-6 py-2.5 bg-[#6366F1] hover:bg-[#4F46E5] text-white text-xs font-semibold rounded-xl transition-colors cursor-pointer flex items-center space-x-2 shadow-sm"
            >
              <Check className="w-4 h-4" />
              <span>Confirm Match & Clear</span>
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
