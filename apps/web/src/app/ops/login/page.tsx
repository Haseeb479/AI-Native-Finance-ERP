"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { ShieldCheck } from "lucide-react";

export default function OperationsLoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [challengeToken, setChallengeToken] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const response = await fetch(challengeToken ? "/api/ops/auth/mfa" : "/api/ops/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(challengeToken ? { challengeToken, code } : { email, password }),
      });
      const body = await response.json().catch(() => null);
      if (!response.ok) throw new Error(body?.error || "Unable to sign in.");
      if (body.mfaRequired && body.challengeToken) {
        setChallengeToken(body.challengeToken);
        setPassword("");
        return;
      }
      if (body.mfaSetupRequired) {
        router.replace("/ops/mfa-setup");
        return;
      }
      router.replace("/ops");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to sign in.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="grid min-h-screen bg-[#10251C] lg:grid-cols-[1fr_1fr]">
      <section className="relative hidden flex-col justify-between overflow-hidden p-12 text-white lg:flex xl:p-16">
        <div className="pointer-events-none absolute -left-24 top-1/4 h-80 w-80 rounded-full bg-emerald-400/10 blur-3xl" />
        <div className="relative flex items-center gap-3">
          <div className="grid h-11 w-11 place-items-center rounded-xl border border-emerald-300/20 bg-emerald-300/10 text-emerald-200"><ShieldCheck size={22} /></div>
          <div><div className="font-bold tracking-widest">FINOVA</div><div className="text-[10px] uppercase tracking-[0.22em] text-emerald-200/65">Operations</div></div>
        </div>
        <div className="relative max-w-xl">
          <p className="text-xs font-bold uppercase tracking-[0.22em] text-emerald-200">Internal team workspace</p>
          <h1 className="mt-5 text-4xl font-semibold leading-[1.15] tracking-tight xl:text-5xl">Run the company behind the finance platform.</h1>
          <p className="mt-5 max-w-lg text-base leading-7 text-white/60">A dedicated workspace for Finova onboarding, customer operations, demo follow-up and service readiness.</p>
          <div className="mt-10 flex items-center gap-3 text-xs text-white/45"><span className="h-px w-10 bg-emerald-300/50" />Staff access is provisioned by Finova. Customer accounts cannot enter this portal.</div>
        </div>
        <p className="relative text-xs text-white/35">Private staff access · Sessions protected with HttpOnly cookies</p>
      </section>

      <section className="flex items-center justify-center bg-[#F6F8F6] px-5 py-12 sm:px-10">
        <div className="w-full max-w-[440px]">
          <div className="mb-8 flex items-center gap-3 lg:hidden">
            <div className="grid h-10 w-10 place-items-center rounded-xl bg-[#174C38] text-emerald-100"><ShieldCheck size={20} /></div>
            <div className="font-bold tracking-widest text-[#10251C]">FINOVA <span className="text-[10px] font-semibold tracking-[0.18em] text-emerald-800">OPERATIONS</span></div>
          </div>
          <div className="rounded-2xl border border-[#E3EAE4] bg-white p-7 shadow-[0_18px_60px_rgba(16,37,28,0.07)] sm:p-9">
            <div className="grid h-11 w-11 place-items-center rounded-xl bg-[#EAF3EC] text-[#174C38]"><ShieldCheck size={21} /></div>
            <p className="mt-6 text-xs font-bold uppercase tracking-[0.16em] text-emerald-800">Finova team</p>
            <h2 className="mt-2 text-2xl font-semibold tracking-tight text-[#14251C]">{challengeToken ? "Verify it’s you" : "Sign in to Operations"}</h2>
            <p className="mt-2 text-sm leading-6 text-slate-500">{challengeToken ? "Enter the code from your authenticator app or use a recovery code." : "Use your provisioned Finova staff account. Customer workspace credentials without staff access are denied."}</p>

            <form onSubmit={submit} className="mt-7 space-y-4">
              {!challengeToken ? (
                <>
                  <label className="block text-sm font-medium text-slate-700">Work email
                    <input required type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-200 px-3.5 py-3 outline-none transition focus:border-emerald-700 focus:ring-4 focus:ring-emerald-700/10" placeholder="name@finova.com" />
                  </label>
                  <label className="block text-sm font-medium text-slate-700">Password
                    <input required type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-200 px-3.5 py-3 outline-none transition focus:border-emerald-700 focus:ring-4 focus:ring-emerald-700/10" placeholder="Enter your password" />
                  </label>
                  <div className="text-right"><Link href="/forgot-password" className="text-xs font-semibold text-emerald-800 hover:underline">Forgot password?</Link></div>
                </>
              ) : (
                <label className="block text-sm font-medium text-slate-700">Authenticator or recovery code
                  <input required autoFocus autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-200 px-3.5 py-3 tracking-[0.2em] outline-none transition focus:border-emerald-700 focus:ring-4 focus:ring-emerald-700/10" placeholder="000000" />
                </label>
              )}
              {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-800">{error}</p>}
              <button disabled={busy} className="flex w-full items-center justify-center rounded-xl bg-[#174C38] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#103C2C] disabled:cursor-not-allowed disabled:opacity-60">
                {busy ? "Verifying…" : challengeToken ? "Verify and continue" : "Continue securely"}
              </button>
              {challengeToken && <button type="button" onClick={() => { setChallengeToken(""); setCode(""); setError(""); }} className="w-full rounded-lg py-2 text-sm font-medium text-slate-500 hover:text-slate-800">Back to sign in</button>}
            </form>
            <div className="mt-6 border-t border-slate-100 pt-5 text-center text-xs leading-5 text-slate-500">New to Finova Operations? Your administrator must invite you. There is no public staff registration.</div>
          </div>
          <p className="mt-5 text-center text-[11px] text-slate-400">Separate from customer sign-in · All staff operations are audited</p>
        </div>
      </section>
    </main>
  );
}
