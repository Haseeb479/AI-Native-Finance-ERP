"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { erpApi } from "@/lib/api";

const input = "rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-sm";
const primary = "rounded-lg bg-[#4F46E5] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50";

export function BankAccountPanel({ orgId }: { orgId: string }) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ bank_name: "", account_title: "", account_number: "", iban: "", account_id: "", opening_balance: "0" });

  const accounts = useQuery({ queryKey: ["accounts", orgId], queryFn: () => erpApi.getAccounts(orgId) });
  const assetAccounts = (accounts.data ?? []).filter((account: any) => /^1/.test(String(account.code || "")));

  const create = useMutation({
    mutationFn: () =>
      erpApi.createBankAccount(orgId, {
        account_id: form.account_id,
        bank_name: form.bank_name.trim(),
        account_title: form.account_title.trim(),
        account_number: form.account_number.trim(),
        ...(form.iban.trim() ? { iban: form.iban.trim() } : {}),
        currency: "PKR",
        opening_balance: Number(form.opening_balance) || 0,
      }),
    onSuccess: () => {
      setForm({ bank_name: "", account_title: "", account_number: "", iban: "", account_id: "", opening_balance: "0" });
      setOpen(false);
      queryClient.invalidateQueries({ queryKey: ["bank-accounts"] });
    },
  });

  const ready = form.bank_name.trim() && form.account_title.trim() && form.account_number.trim() && form.account_id;

  return (
    <div className="mx-auto w-full max-w-[1320px] space-y-3 px-6 pt-4 sm:px-10">
      <button type="button" className="rounded-lg border border-[#E2E8F0] bg-white px-3 py-2 text-xs font-semibold text-[#334155]" onClick={() => setOpen((value) => !value)}>
        + Add bank account
      </button>
      {open && (
        <div className="grid gap-2 rounded-xl border border-[#E2E8F0] bg-[#F8FAFC] p-4 sm:grid-cols-3">
          <input aria-label="Bank name" placeholder="Bank name *" maxLength={100} value={form.bank_name} onChange={(e) => setForm({ ...form, bank_name: e.target.value })} className={input} />
          <input aria-label="Account title" placeholder="Account title *" maxLength={150} value={form.account_title} onChange={(e) => setForm({ ...form, account_title: e.target.value })} className={input} />
          <input aria-label="Account number" placeholder="Account number *" maxLength={50} value={form.account_number} onChange={(e) => setForm({ ...form, account_number: e.target.value })} className={input} />
          <input aria-label="IBAN" placeholder="IBAN (optional)" maxLength={34} value={form.iban} onChange={(e) => setForm({ ...form, iban: e.target.value })} className={input} />
          <select aria-label="Ledger account" value={form.account_id} onChange={(e) => setForm({ ...form, account_id: e.target.value })} className={input}>
            <option value="">Ledger account (asset) *</option>
            {assetAccounts.map((account: any) => (
              <option key={account.id} value={account.id}>{account.code} — {account.name}</option>
            ))}
          </select>
          <input aria-label="Opening balance" type="number" min="0" placeholder="Opening balance" value={form.opening_balance} onChange={(e) => setForm({ ...form, opening_balance: e.target.value })} className={input} />
          <button type="button" className={primary} disabled={!ready || create.isPending} onClick={() => create.mutate()}>{create.isPending ? "Saving…" : "Save bank account"}</button>
          {create.error && <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700 sm:col-span-3">{(create.error as Error).message || "Request failed."}</div>}
        </div>
      )}
    </div>
  );
}
