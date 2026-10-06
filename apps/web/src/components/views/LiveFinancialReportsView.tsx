"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { erpApi } from "@/lib/api";
import { LiveConsolidationView } from "./LiveConsolidationView";

type Tab = "pnl" | "balance_sheet" | "cash_flow" | "trial_balance" | "multi_entity";

interface Props {
  orgId: string;
  orgName?: string;
}

const money = (value: unknown) =>
  `PKR ${Number(value || 0).toLocaleString("en-PK", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function Section({ title, accounts, total, field }: { title: string; accounts: any[]; total: number; field: string }) {
  return (
    <div className="rounded-xl border border-[#E2E8F0] bg-white">
      <div className="flex justify-between border-b border-[#F1F5F9] px-4 py-3 text-sm font-bold text-[#0F172A]">
        <span>{title}</span>
        <span>{money(total)}</span>
      </div>
      {accounts.length === 0 ? (
        <p className="px-4 py-3 text-xs text-[#94A3B8]">No posted activity.</p>
      ) : (
        accounts.map((account) => (
          <div key={account.account_id} className="flex justify-between px-4 py-2 text-sm text-[#475569]">
            <span>{account.code} — {account.name}</span>
            <span>{money(account[field])}</span>
          </div>
        ))
      )}
    </div>
  );
}

export function LiveFinancialReportsView({ orgId, orgName }: Props) {
  const [tab, setTab] = useState<Tab>("pnl");
  const year = new Date().getFullYear();
  const [from, setFrom] = useState(`${year}-01-01`);
  const [to, setTo] = useState(`${year}-12-31`);
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));

  const pnl = useQuery({
    queryKey: ["report-pnl", orgId, from, to],
    queryFn: () => erpApi.getProfitAndLoss(orgId, from, to),
    enabled: tab === "pnl" && !!from && !!to && from <= to,
  });
  const bs = useQuery({
    queryKey: ["report-bs", orgId, asOf],
    queryFn: () => erpApi.getBalanceSheet(orgId, asOf),
    enabled: tab === "balance_sheet" && !!asOf,
  });
  const cf = useQuery({
    queryKey: ["report-cf", orgId, from, to],
    queryFn: () => erpApi.getCashFlow(orgId, from, to),
    enabled: tab === "cash_flow" && !!from && !!to && from <= to,
  });
  const tb = useQuery({
    queryKey: ["report-tb", orgId, asOf],
    queryFn: () => erpApi.getTrialBalance(orgId, asOf),
    enabled: tab === "trial_balance" && !!asOf,
  });

  const active = tab === "pnl" ? pnl : tab === "balance_sheet" ? bs : tab === "cash_flow" ? cf : tb;
  const data: any = tab === "multi_entity" ? null : active.data;
  const tabs: [Tab, string][] = [["pnl", "Profit & Loss"], ["balance_sheet", "Balance Sheet"], ["cash_flow", "Cash Flow"], ["trial_balance", "Trial Balance"], ["multi_entity", "Multi-entity"]];
  const inputClass = "rounded-lg border border-[#E2E8F0] bg-white px-2.5 py-1.5 text-sm";

  return (
    <div className="mx-auto w-full max-w-[1100px] space-y-6 px-6 py-7 sm:px-10">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-[#6366F1]">Financial Reports</p>
        <h1 className="text-2xl font-bold text-[#0F172A]">Financial statements</h1>
        <p className="mt-1 text-sm text-[#64748B]">
          Live figures from posted journal entries{orgName ? ` for ${orgName}` : ""}. Nothing here is sample data.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {tabs.map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => setTab(key)}
            className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${tab === key ? "bg-[#4F46E5] text-white" : "bg-white text-[#475569] border border-[#E2E8F0]"}`}
          >
            {label}
          </button>
        ))}
        {tab !== "multi_entity" && <div className="ml-auto flex items-center gap-2 text-xs text-[#64748B]">
          {tab === "pnl" || tab === "cash_flow" ? (
            <>
              <input aria-label="From date" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} className={inputClass} />
              <span>to</span>
              <input aria-label="To date" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} className={inputClass} />
            </>
          ) : (
            <>
              <span>As of</span>
              <input aria-label="As of date" type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} className={inputClass} />
            </>
          )}
        </div>}
      </div>

      {tab === "multi_entity" && <LiveConsolidationView orgId={orgId} orgName={orgName} />}
      {tab !== "multi_entity" && active.isLoading && <p className="text-sm text-[#64748B]">Loading report…</p>}
      {tab !== "multi_entity" && active.error && (
        <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
          {(active.error as Error).message || "Unable to load this report."}
        </div>
      )}

      {data && tab === "pnl" && (
        <div className="space-y-3">
          <Section title="Revenue" accounts={data.revenue?.accounts ?? []} total={data.revenue?.total ?? 0} field="amount" />
          <Section title="Cost of goods sold" accounts={data.cost_of_goods_sold?.accounts ?? []} total={data.cost_of_goods_sold?.total ?? 0} field="amount" />
          <div className="flex justify-between rounded-xl bg-[#F8FAFC] px-4 py-3 text-sm font-bold text-[#0F172A]"><span>Gross profit</span><span>{money(data.gross_profit)}</span></div>
          <Section title="Operating expenses" accounts={data.operating_expenses?.accounts ?? []} total={data.operating_expenses?.total ?? 0} field="amount" />
          <div className="flex justify-between rounded-xl bg-[#EEF2FF] px-4 py-3 text-sm font-bold text-[#312E81]"><span>Net profit</span><span>{money(data.net_profit)}</span></div>
        </div>
      )}

      {data && tab === "cash_flow" && (
        <div className="space-y-3">
          {([
            ["Operating activities", [["Net income", data.operating_activities?.net_income], ["Depreciation & amortization", data.operating_activities?.depreciation_amortization], ["Change in receivables", data.operating_activities?.working_capital_changes?.accounts_receivable], ["Change in payables", data.operating_activities?.working_capital_changes?.accounts_payable]], data.operating_activities?.net_cash_from_operations],
            ["Investing activities", [["Capital expenditures", data.investing_activities?.capital_expenditures]], data.investing_activities?.net_cash_from_investing],
            ["Financing activities", [["Debt financing", data.financing_activities?.debt_financing], ["Equity financing", data.financing_activities?.equity_financing]], data.financing_activities?.net_cash_from_financing],
          ] as [string, [string, unknown][], unknown][]).map(([title, rows, total]) => (
            <div key={title} className="rounded-xl border border-[#E2E8F0] bg-white">
              <div className="flex justify-between border-b border-[#F1F5F9] px-4 py-3 text-sm font-bold text-[#0F172A]"><span>{title}</span><span>{money(total)}</span></div>
              {rows.map(([label, value]) => (
                <div key={label} className="flex justify-between px-4 py-2 text-sm text-[#475569]"><span>{label}</span><span>{money(value)}</span></div>
              ))}
            </div>
          ))}
          <div className="grid gap-2 rounded-xl bg-[#EEF2FF] px-4 py-3 text-sm text-[#312E81] sm:grid-cols-3">
            <span>Opening cash <b>{money(data.summary?.cash_at_beginning)}</b></span>
            <span>Net change <b>{money(data.summary?.net_cash_increase_decrease)}</b></span>
            <span>Closing cash <b>{money(data.summary?.cash_at_end)}</b></span>
          </div>
        </div>
      )}

      {data && tab === "balance_sheet" && (
        <div className="space-y-3">
          <Section title="Assets" accounts={data.assets?.accounts ?? []} total={data.assets?.total ?? 0} field="balance" />
          <Section title="Liabilities" accounts={data.liabilities?.accounts ?? []} total={data.liabilities?.total ?? 0} field="balance" />
          <Section title="Equity" accounts={data.equity?.accounts ?? []} total={data.equity?.total ?? 0} field="balance" />
          <div className="flex justify-between rounded-xl bg-[#F8FAFC] px-4 py-2 text-sm text-[#475569]"><span>Retained earnings (current)</span><span>{money(data.equity?.retained_earnings)}</span></div>
          <div className={`rounded-xl px-4 py-3 text-sm font-bold ${data.is_balanced ? "bg-emerald-50 text-emerald-800" : "bg-rose-50 text-rose-700"}`}>
            Liabilities + Equity {money(data.total_liabilities_and_equity)} — {data.is_balanced ? "Balanced" : "NOT balanced"}
          </div>
        </div>
      )}

      {data && tab === "trial_balance" && (
        <div className="overflow-x-auto rounded-xl border border-[#E2E8F0] bg-white">
          <table className="w-full text-sm">
            <thead className="bg-[#F8FAFC] text-left text-xs uppercase text-[#64748B]">
              <tr><th className="px-4 py-2">Account</th><th className="px-4 py-2">Type</th><th className="px-4 py-2 text-right">Debit</th><th className="px-4 py-2 text-right">Credit</th></tr>
            </thead>
            <tbody>
              {(data.accounts ?? []).length === 0 && <tr><td colSpan={4} className="px-4 py-3 text-xs text-[#94A3B8]">No posted activity.</td></tr>}
              {(data.accounts ?? []).map((account: any) => (
                <tr key={account.account_id} className="border-t border-[#F1F5F9]">
                  <td className="px-4 py-2">{account.code} — {account.name}</td>
                  <td className="px-4 py-2 capitalize">{account.classification}</td>
                  <td className="px-4 py-2 text-right">{money(account.total_debit)}</td>
                  <td className="px-4 py-2 text-right">{money(account.total_credit)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-[#E2E8F0] font-bold">
                <td className="px-4 py-2" colSpan={2}>Totals {data.is_balanced ? "(balanced)" : "(NOT balanced)"}</td>
                <td className="px-4 py-2 text-right">{money(data.totals?.total_debit)}</td>
                <td className="px-4 py-2 text-right">{money(data.totals?.total_credit)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  );
}
