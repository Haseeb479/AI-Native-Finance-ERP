"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { erpApi } from "@/lib/api";

type Item = { kind: "bill" | "invoice"; id: string; number: string; party: string; amount: number; date: string };

const money = (value: number) => `Rs ${value.toLocaleString("en-PK", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export function LiveApprovalsView({ orgId }: { orgId: string }) {
  const queryClient = useQueryClient();
  const [rejecting, setRejecting] = useState<Item | null>(null);
  const [reason, setReason] = useState("");

  const bills = useQuery({ queryKey: ["bills", orgId], queryFn: () => erpApi.getBills(orgId) });
  const invoices = useQuery({ queryKey: ["invoices", orgId], queryFn: () => erpApi.getInvoices(orgId) });

  const items: Item[] = [
    ...(bills.data ?? [])
      .filter((b: any) => b.status === "pending_approval")
      .map((b: any): Item => ({ kind: "bill", id: b.id, number: b.bill_number, party: b.vendor?.name || "Vendor", amount: Number(b.total_amount || 0), date: String(b.bill_date || "").slice(0, 10) })),
    ...(invoices.data ?? [])
      .filter((i: any) => i.status === "pending_approval")
      .map((i: any): Item => ({ kind: "invoice", id: i.id, number: i.invoice_number, party: i.customer?.name || "Customer", amount: Number(i.total_amount || 0), date: String(i.invoice_date || i.issue_date || "").slice(0, 10) })),
  ];

  const done = () => {
    setRejecting(null);
    setReason("");
    queryClient.invalidateQueries({ queryKey: ["bills"] });
    queryClient.invalidateQueries({ queryKey: ["invoices"] });
  };

  const approve = useMutation({
    mutationFn: (item: Item) => (item.kind === "bill" ? erpApi.approveBill(orgId, item.id) : erpApi.approveInvoice(orgId, item.id)),
    onSuccess: done,
  });
  const reject = useMutation({
    mutationFn: (item: Item) => (item.kind === "bill" ? erpApi.rejectBill(orgId, item.id, reason) : erpApi.rejectInvoice(orgId, item.id, reason)),
    onSuccess: done,
  });

  const error = (approve.error || reject.error || bills.error || invoices.error) as Error | null;

  return (
    <div className="mx-auto w-full max-w-[1100px] space-y-5 px-6 py-8 sm:px-10">
      <div>
        <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">Approvals</h2>
        <p className="mt-1 text-xs text-[#64748B]">Bills and invoices waiting for a decision. Approvers cannot approve their own submissions.</p>
      </div>

      {error && <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">{error.message || "Request failed."}</div>}
      {(bills.isLoading || invoices.isLoading) && <p className="text-xs text-[#64748B]">Loading approvals…</p>}

      <div className="overflow-hidden rounded-xl border border-[#E2E8F0] bg-white">
        <table className="w-full text-left text-xs">
          <thead className="bg-[#F8FAFC] text-[#64748B]">
            <tr><th className="px-4 py-3">Type</th><th className="px-4 py-3">Number</th><th className="px-4 py-3">Party</th><th className="px-4 py-3">Date</th><th className="px-4 py-3 text-right">Amount</th><th className="px-4 py-3 text-right">Decision</th></tr>
          </thead>
          <tbody className="divide-y divide-[#F1F5F9]">
            {items.length === 0 && !bills.isLoading && !invoices.isLoading && (
              <tr><td colSpan={6} className="px-4 py-8 text-center text-[#64748B]">Nothing is waiting for approval.</td></tr>
            )}
            {items.map((item) => (
              <tr key={`${item.kind}-${item.id}`}>
                <td className="px-4 py-3 capitalize">{item.kind}</td>
                <td className="px-4 py-3 font-mono">{item.number}</td>
                <td className="px-4 py-3">{item.party}</td>
                <td className="px-4 py-3">{item.date}</td>
                <td className="px-4 py-3 text-right font-semibold">{money(item.amount)}</td>
                <td className="px-4 py-3 text-right">
                  <button type="button" className="mr-2 rounded-lg bg-[#4F46E5] px-3 py-1.5 font-semibold text-white disabled:opacity-50" disabled={approve.isPending} onClick={() => approve.mutate(item)}>Approve</button>
                  <button type="button" className="rounded-lg border border-[#E2E8F0] px-3 py-1.5 font-semibold text-[#334155]" onClick={() => setRejecting(item)}>Reject</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {rejecting && (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-[#E2E8F0] bg-[#F8FAFC] p-4">
          <input aria-label="Rejection reason" placeholder={`Reason for rejecting ${rejecting.number}`} value={reason} onChange={(e) => setReason(e.target.value)} className="min-w-[260px] flex-1 rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-sm" />
          <button type="button" className="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50" disabled={reason.trim().length < 3 || reject.isPending} onClick={() => reject.mutate(rejecting)}>Confirm reject</button>
          <button type="button" className="text-xs text-[#64748B]" onClick={() => setRejecting(null)}>Cancel</button>
        </div>
      )}
    </div>
  );
}
