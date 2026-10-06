"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { erpApi } from "@/lib/api";

const money = (value: unknown) =>
  `PKR ${Number(value || 0).toLocaleString("en-PK", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const input = "rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-sm";
const primary = "rounded-lg bg-[#4F46E5] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50";

function ErrorLine({ error }: { error: unknown }) {
  if (!error) return null;
  return <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">{(error as Error).message || "Request failed."}</div>;
}

export function VendorBillsPanel({ orgId }: { orgId: string }) {
  const queryClient = useQueryClient();
  const today = new Date().toISOString().slice(0, 10);
  const refresh = () => {
    ["bills", "journals", "vendors", "bank-accounts"].forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }));
  };

  const vendors = useQuery({ queryKey: ["vendors", orgId], queryFn: () => erpApi.getVendors(orgId) });
  const bills = useQuery({ queryKey: ["bills", orgId, "panel"], queryFn: () => erpApi.getBills(orgId) });
  const accounts = useQuery({ queryKey: ["accounts", orgId], queryFn: () => erpApi.getAccounts(orgId) });
  const banks = useQuery({ queryKey: ["bank-accounts", orgId, "panel"], queryFn: () => erpApi.getBankAccounts(orgId) });

  const [showVendor, setShowVendor] = useState(false);
  const [showBill, setShowBill] = useState(false);
  const [vendorForm, setVendorForm] = useState({ name: "", email: "", ntn: "" });
  const [billForm, setBillForm] = useState({ vendor_id: "", vendor_invoice_ref: "", bill_date: today, due_date: "", expense_account_id: "", description: "", quantity: "1", unit_price: "", wht_rate: "0", auto_post: false });
  const [payingId, setPayingId] = useState<string | null>(null);
  const [payForm, setPayForm] = useState({ bank_account_id: "", amount: "" });

  const addVendor = useMutation({
    mutationFn: () => {
      const payload: { name: string; email?: string; ntn?: string } = { name: vendorForm.name.trim() };
      if (vendorForm.email.trim()) payload.email = vendorForm.email.trim();
      if (vendorForm.ntn.trim()) payload.ntn = vendorForm.ntn.trim();
      return erpApi.createVendor(orgId, payload);
    },
    onSuccess: (data: any) => {
      const created = data?.vendor ?? data;
      if (created?.id) setBillForm((previous) => ({ ...previous, vendor_id: String(created.id) }));
      setVendorForm({ name: "", email: "", ntn: "" });
      setShowVendor(false);
      refresh();
    },
  });

  const addBill = useMutation({
    mutationFn: () =>
      erpApi.createBill(orgId, {
        vendor_id: billForm.vendor_id,
        ...(billForm.vendor_invoice_ref.trim() ? { vendor_invoice_ref: billForm.vendor_invoice_ref.trim() } : {}),
        bill_date: billForm.bill_date,
        ...(billForm.due_date ? { due_date: billForm.due_date } : {}),
        wht_rate: Number(billForm.wht_rate) || 0,
        auto_post: billForm.auto_post,
        lines: [{ expense_account_id: billForm.expense_account_id, description: billForm.description.trim(), quantity: Number(billForm.quantity), unit_price: Number(billForm.unit_price) }],
      }),
    onSuccess: () => {
      setBillForm({ ...billForm, vendor_invoice_ref: "", description: "", unit_price: "", quantity: "1", auto_post: false });
      setShowBill(false);
      refresh();
    },
  });

  const action = useMutation({
    mutationFn: ({ kind, billId }: { kind: "submit" | "approve" | "post"; billId: string }) =>
      kind === "submit" ? erpApi.submitBillForApproval(orgId, billId) : kind === "approve" ? erpApi.approveBill(orgId, billId) : erpApi.postBill(orgId, billId),
    onSuccess: refresh,
  });

  const pay = useMutation({
    mutationFn: (billId: string) => erpApi.recordBillPayment(orgId, billId, { amount: Number(payForm.amount), bank_account_id: payForm.bank_account_id, payment_date: today }),
    onSuccess: () => { setPayingId(null); refresh(); },
  });

  const expenseAccounts = (accounts.data ?? []).filter((account: any) => /^[5-9]/.test(String(account.code || "")));
  const billList: any[] = bills.data ?? [];
  const canSaveBill = !!billForm.vendor_id && !!billForm.expense_account_id && !!billForm.description.trim() && Number(billForm.quantity) > 0 && Number(billForm.unit_price) >= 0 && billForm.unit_price !== "";

  return (
    <div className="mx-auto w-full max-w-[1240px] space-y-4 px-8 pt-8">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-[#0F172A]">Vendor bills</h2>
          <p className="text-xs text-[#64748B]">Create vendors and bills, move them through approval, post to the ledger and record payments.</p>
        </div>
        <div className="flex gap-2">
          <button type="button" className="rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-xs font-semibold text-[#334155]" onClick={() => setShowVendor((value) => !value)}>+ Add vendor</button>
          <button type="button" className={primary} onClick={() => setShowBill((value) => !value)}>+ New vendor bill</button>
        </div>
      </div>

      {showVendor && (
        <div className="grid gap-2 rounded-xl border border-[#E2E8F0] bg-[#F8FAFC] p-4 sm:grid-cols-4">
          <input aria-label="Vendor name" placeholder="Vendor name *" maxLength={150} value={vendorForm.name} onChange={(e) => setVendorForm({ ...vendorForm, name: e.target.value })} className={input} />
          <input aria-label="Vendor email" type="email" placeholder="Email (optional)" value={vendorForm.email} onChange={(e) => setVendorForm({ ...vendorForm, email: e.target.value })} className={input} />
          <input aria-label="Vendor NTN" placeholder="NTN (optional)" maxLength={30} value={vendorForm.ntn} onChange={(e) => setVendorForm({ ...vendorForm, ntn: e.target.value })} className={input} />
          <button type="button" className={primary} disabled={!vendorForm.name.trim() || addVendor.isPending} onClick={() => addVendor.mutate()}>{addVendor.isPending ? "Saving…" : "Save vendor"}</button>
          <div className="sm:col-span-4"><ErrorLine error={addVendor.error} /></div>
        </div>
      )}

      {showBill && (
        <div className="grid gap-3 rounded-xl border border-[#E2E8F0] bg-[#F8FAFC] p-4 sm:grid-cols-3">
          <select aria-label="Bill vendor" value={billForm.vendor_id} onChange={(e) => setBillForm({ ...billForm, vendor_id: e.target.value })} className={input}>
            <option value="">{(vendors.data ?? []).length === 0 ? "Add a vendor first" : "Select vendor *"}</option>
            {(vendors.data ?? []).map((vendor: any) => <option key={vendor.id} value={vendor.id}>{vendor.name}</option>)}
          </select>
          <input aria-label="Vendor invoice reference" placeholder="Vendor invoice ref" maxLength={100} value={billForm.vendor_invoice_ref} onChange={(e) => setBillForm({ ...billForm, vendor_invoice_ref: e.target.value })} className={input} />
          <select aria-label="Expense account" value={billForm.expense_account_id} onChange={(e) => setBillForm({ ...billForm, expense_account_id: e.target.value })} className={input}>
            <option value="">Expense account *</option>
            {expenseAccounts.map((account: any) => <option key={account.id} value={account.id}>{account.code} — {account.name}</option>)}
          </select>
          <label className="text-xs text-[#64748B]">Bill date<input aria-label="Bill date" type="date" value={billForm.bill_date} onChange={(e) => setBillForm({ ...billForm, bill_date: e.target.value })} className={`${input} mt-1 w-full`} /></label>
          <label className="text-xs text-[#64748B]">Due date<input aria-label="Bill due date" type="date" min={billForm.bill_date} value={billForm.due_date} onChange={(e) => setBillForm({ ...billForm, due_date: e.target.value })} className={`${input} mt-1 w-full`} /></label>
          <label className="text-xs text-[#64748B]">Withholding tax %<input aria-label="WHT rate" type="number" min="0" max="100" step="any" value={billForm.wht_rate} onChange={(e) => setBillForm({ ...billForm, wht_rate: e.target.value })} className={`${input} mt-1 w-full`} /></label>
          <input aria-label="Bill description" placeholder="Description *" maxLength={500} value={billForm.description} onChange={(e) => setBillForm({ ...billForm, description: e.target.value })} className={`${input} sm:col-span-1`} />
          <input aria-label="Bill quantity" type="number" min="0" step="any" placeholder="Qty" value={billForm.quantity} onChange={(e) => setBillForm({ ...billForm, quantity: e.target.value })} className={input} />
          <input aria-label="Bill unit price" type="number" min="0" step="any" placeholder="Unit price (PKR) *" value={billForm.unit_price} onChange={(e) => setBillForm({ ...billForm, unit_price: e.target.value })} className={input} />
          <label className="flex items-center gap-2 text-xs text-[#334155] sm:col-span-2"><input aria-label="Post immediately" type="checkbox" checked={billForm.auto_post} onChange={(e) => setBillForm({ ...billForm, auto_post: e.target.checked })} />Post to ledger immediately</label>
          <button type="button" className={primary} disabled={!canSaveBill || addBill.isPending} onClick={() => addBill.mutate()}>{addBill.isPending ? "Saving…" : "Create bill"}</button>
          <div className="sm:col-span-3"><ErrorLine error={addBill.error} /></div>
        </div>
      )}

      <ErrorLine error={action.error || pay.error || bills.error} />

      <div className="overflow-x-auto rounded-xl border border-[#E2E8F0] bg-white">
        <table className="w-full text-sm">
          <thead className="bg-[#F8FAFC] text-left text-xs uppercase text-[#64748B]">
            <tr><th className="px-4 py-2">Bill #</th><th className="px-4 py-2">Vendor</th><th className="px-4 py-2">Date</th><th className="px-4 py-2 text-right">Total</th><th className="px-4 py-2 text-right">Balance</th><th className="px-4 py-2">Status</th><th className="px-4 py-2 text-right">Actions</th></tr>
          </thead>
          <tbody>
            {billList.length === 0 && <tr><td colSpan={7} className="px-4 py-3 text-xs text-[#94A3B8]">No vendor bills yet.</td></tr>}
            {billList.map((bill) => {
              const status = String(bill.status).toLowerCase();
              const balance = Math.max(0, Number(bill.total_amount || 0) - Number(bill.amount_paid || 0));
              const busy = action.isPending && action.variables?.billId === bill.id;
              const link = "text-xs font-semibold text-[#4F46E5] hover:underline disabled:opacity-50";
              return (
                <tr key={bill.id} className="border-t border-[#F1F5F9] align-top">
                  <td className="px-4 py-2 font-mono text-xs">{bill.bill_number}</td>
                  <td className="px-4 py-2">{bill.vendor?.name ?? "—"}</td>
                  <td className="px-4 py-2">{String(bill.bill_date ?? "").slice(0, 10)}</td>
                  <td className="px-4 py-2 text-right">{money(bill.total_amount)}</td>
                  <td className="px-4 py-2 text-right">{money(balance)}</td>
                  <td className="px-4 py-2 capitalize">{status.replace(/_/g, " ")}</td>
                  <td className="px-4 py-2 text-right space-x-3">
                    {(status === "draft" || status === "rejected") && <button type="button" className={link} disabled={busy} onClick={() => action.mutate({ kind: "submit", billId: bill.id })}>Submit</button>}
                    {status === "pending_approval" && <button type="button" className={link} disabled={busy} onClick={() => action.mutate({ kind: "approve", billId: bill.id })}>Approve</button>}
                    {(status === "draft" || status === "approved") && <button type="button" className={link} disabled={busy} onClick={() => action.mutate({ kind: "post", billId: bill.id })}>Post</button>}
                    {["received", "posted", "partially_paid", "partial"].includes(status) && balance > 0 && (
                      payingId === bill.id ? (
                        <span className="inline-flex flex-wrap items-center justify-end gap-2">
                          <select aria-label="Pay from bank account" value={payForm.bank_account_id} onChange={(e) => setPayForm({ ...payForm, bank_account_id: e.target.value })} className={input}>
                            <option value="">Bank account</option>
                            {(banks.data ?? []).map((bank: any) => <option key={bank.id} value={bank.account_id}>{bank.account_title || bank.bank_name}</option>)}
                          </select>
                          <input aria-label="Payment amount" type="number" min="0" step="any" value={payForm.amount} onChange={(e) => setPayForm({ ...payForm, amount: e.target.value })} className={`${input} w-28`} />
                          <button type="button" className={primary} disabled={!payForm.bank_account_id || !(Number(payForm.amount) > 0) || pay.isPending} onClick={() => pay.mutate(bill.id)}>Pay</button>
                          <button type="button" className="text-xs text-[#64748B]" onClick={() => setPayingId(null)}>Cancel</button>
                        </span>
                      ) : (
                        <button type="button" className={link} onClick={() => { setPayingId(bill.id); setPayForm({ bank_account_id: "", amount: String(balance) }); }}>Record payment</button>
                      )
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
