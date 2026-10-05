"use client";

import Link from "next/link";
import { FormEvent, Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ShieldCheck } from "lucide-react";

function InvitationAcceptanceForm() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const [token] = useState(() => searchParams.get("token") || "");
  const [email, setEmail] = useState(() => searchParams.get("email") || "");
  const [name, setName] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState("");
  const [complete, setComplete] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (searchParams.has("token")) router.replace("/ops/invite");
  }, [router, searchParams]);

  async function accept(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    if (!token || !email) {
      setError("This invitation link is invalid or incomplete. Ask your Operations administrator for a new invite.");
      return;
    }
    setBusy(true);
    try {
      const response = await fetch("/api/ops/invitations/accept", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ token, email, name, password, password_confirmation: passwordConfirmation }),
      });
      const body = await response.json().catch(() => null);
      if (!response.ok) {
        throw new Error(body?.errors?.[0]?.message || body?.errors?.[0] || body?.error || "The invitation could not be accepted.");
      }
      setComplete(true);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The invitation could not be accepted.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-[#F5F7F5] p-5">
      <section className="w-full max-w-lg rounded-2xl border border-[#E1E9E2] bg-white p-7 shadow-[0_18px_60px_rgba(16,37,28,0.07)] sm:p-9">
        <div className="grid h-11 w-11 place-items-center rounded-xl bg-[#EAF3EC] text-[#174C38]"><ShieldCheck size={21} /></div>
        <p className="mt-5 text-xs font-bold uppercase tracking-[0.16em] text-emerald-800">Finova Operations</p>
        <h1 className="mt-2 text-2xl font-semibold tracking-tight">{complete ? "Invitation accepted" : "Create your staff account"}</h1>
        {complete ? (
          <>
            <p role="status" className="mt-3 text-sm leading-6 text-slate-600">Your account is verified and invited to Operations. Sign in with your new password, then complete mandatory MFA setup before accessing team workflows.</p>
            <Link href="/ops/login" className="mt-6 block rounded-xl bg-[#174C38] px-4 py-3 text-center text-sm font-semibold text-white">Continue to staff sign in</Link>
          </>
        ) : (
          <>
            <p className="mt-2 text-sm leading-6 text-slate-500">Choose a password for the invited staff account. This link expires after 48 hours and can only be used once.</p>
            {error && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}
            {!token && <p role="alert" className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">The invitation token is missing. Use the full link from your invitation email.</p>}
            <form onSubmit={accept} className="mt-6 space-y-4">
              <label className="block text-sm font-medium text-slate-700">Your name
                <input required maxLength={120} autoComplete="name" value={name} onChange={(event) => setName(event.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-200 px-3.5 py-3 outline-none focus:border-emerald-700" />
              </label>
              <label className="block text-sm font-medium text-slate-700">Work email
                <input required type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-200 px-3.5 py-3 outline-none focus:border-emerald-700" />
              </label>
              <label className="block text-sm font-medium text-slate-700">Create password
                <input required minLength={12} maxLength={200} type="password" autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-200 px-3.5 py-3 outline-none focus:border-emerald-700" />
              </label>
              <label className="block text-sm font-medium text-slate-700">Confirm password
                <input required minLength={12} maxLength={200} type="password" autoComplete="new-password" value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-200 px-3.5 py-3 outline-none focus:border-emerald-700" />
              </label>
              <button disabled={busy || !token} className="w-full rounded-xl bg-[#174C38] px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">{busy ? "Accepting invitation…" : "Create account and accept invite"}</button>
            </form>
            <p className="mt-5 text-center text-xs text-slate-500">Already have an account? <Link href="/ops/login" className="font-semibold text-emerald-800 hover:underline">Go to staff sign in</Link></p>
          </>
        )}
      </section>
    </main>
  );
}

export default function OperationsInvitationPage() {
  return <Suspense fallback={<main className="grid min-h-screen place-items-center bg-[#F5F7F5] text-sm text-slate-500">Loading staff invitation…</main>}><InvitationAcceptanceForm /></Suspense>;
}
