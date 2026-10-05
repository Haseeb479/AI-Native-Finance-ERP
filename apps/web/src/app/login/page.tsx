"use client";

import Link from "next/link";
import { useCallback, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ArrowRight, LockKeyhole, ShieldCheck, Sparkles } from "lucide-react";
import GoogleSignInButton from "@/components/auth/GoogleSignInButton";
import { clearStoredSession, erpApi, setStoredSession } from "@/lib/api";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const finishLogin = useCallback(async (data: { token: string; user: { id: string | number; name: string; email: string } }) => {
    clearStoredSession();
    const user = { ...data.user, id: String(data.user.id) };
    setStoredSession(data.token, user);

    const organizations = await erpApi.getOrganizations();
    if (organizations[0]) {
      setStoredSession(data.token, user, organizations[0]);
    }

    const params = new URLSearchParams(window.location.search);
    const next = params.get("next");
    const destination = next && next.startsWith("/") && !next.startsWith("//") ? next : "/app";
    router.push(destination);
  }, [router]);

  const handleGoogleCredential = useCallback(async (credential: string) => {
    setError("");
    setLoading(true);
    try {
      const response = await fetch("/api/auth/google", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ credential }),
      });
      const payload = await response.json();
      if (!response.ok || !payload?.success || !payload.data?.token) {
        throw new Error(
          payload.errors?.[0]?.message ?? payload.error ?? "Google sign-in could not be completed.",
        );
      }
      await finishLogin(payload.data);
    } catch (signInError) {
      setError(signInError instanceof Error ? signInError.message : "Google sign-in could not be completed.");
      setLoading(false);
    }
  }, [finishLogin]);

  async function submitPassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setLoading(true);
    try {
      const data = await erpApi.login(email, password);
      await finishLogin(data);
    } catch (signInError) {
      setError(signInError instanceof Error ? signInError.message : "Unable to sign in. Please try again.");
      setLoading(false);
    }
  }

  return (
    <main className="grid min-h-screen bg-white lg:grid-cols-2">
      <section className="flex items-center justify-center px-6 py-12 sm:px-10 lg:px-16">
        <div className="w-full max-w-md">
          <Link aria-label="Finova home" className="inline-flex items-center gap-2 text-xl font-bold tracking-tight text-[#183d2e] lg:hidden" href="/">
            Finova<span className="h-2 w-2 rounded-full bg-[#55a77a]" />
          </Link>
          <div className="mt-10 lg:mt-0">
            <p className="text-sm font-semibold text-[#2d7651]">Welcome back</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Sign in to Finova</h1>
            <p className="mt-2 text-sm leading-6 text-slate-500">
              Access your finance workspace and continue where your team left off.
            </p>

            <div className="mt-8">
              <GoogleSignInButton disabled={loading} onCredential={handleGoogleCredential} />
            </div>
            <div className="my-6 flex items-center gap-4 text-xs text-slate-400">
              <span className="h-px flex-1 bg-slate-200" />
              <span>OR CONTINUE WITH EMAIL</span>
              <span className="h-px flex-1 bg-slate-200" />
            </div>

            <form className="space-y-5" onSubmit={submitPassword}>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700" htmlFor="email">Work email</label>
                <input
                  autoComplete="email"
                  className="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none transition focus:border-[#37805b] focus:ring-4 focus:ring-[#37805b]/10"
                  id="email"
                  onChange={(event) => setEmail(event.target.value)}
                  placeholder="you@company.com"
                  required
                  type="email"
                  value={email}
                />
              </div>
              <div>
                <div className="mb-1.5 flex items-center justify-between">
                  <label className="text-sm font-medium text-slate-700" htmlFor="password">Password</label>
                  <Link className="text-xs font-medium text-[#28734d] hover:underline" href="/forgot-password">Forgot password?</Link>
                </div>
                <input
                  autoComplete="current-password"
                  className="h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm outline-none transition focus:border-[#37805b] focus:ring-4 focus:ring-[#37805b]/10"
                  id="password"
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder="Enter your password"
                  required
                  type="password"
                  value={password}
                />
              </div>

              {error && <p className="rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-800" role="alert">{error}</p>}

              <button
                className="flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[#1d5c40] px-4 text-sm font-semibold text-white transition hover:bg-[#174a34] disabled:cursor-wait disabled:opacity-60"
                disabled={loading}
                type="submit"
              >
                {loading ? "Signing in..." : <><LockKeyhole className="h-4 w-4" /> Sign in</>}
              </button>
            </form>

            <div className="mt-6 flex items-start gap-2 rounded-xl bg-[#f4f8f4] p-3.5 text-xs leading-5 text-slate-600">
              <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-[#28734d]" />
              <span>Your session is protected. Finova will only show companies your account is authorized to access.</span>
            </div>
            <p className="mt-8 text-center text-sm text-slate-500">
              New to Finova?{" "}
              <Link className="font-semibold text-[#28734d] hover:underline" href="/request-demo">Request a walkthrough</Link>
            </p>
            <Link className="mt-5 inline-flex items-center gap-1 text-xs text-slate-400 hover:text-slate-700" href="/">
              Back to Finova <ArrowRight className="h-3 w-3" />
            </Link>
          </div>
        </div>
      </section>

      <aside className="relative hidden overflow-hidden bg-[#173e2f] px-12 py-14 text-white lg:flex lg:flex-col lg:justify-between xl:px-20">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_70%_30%,rgba(112,181,132,0.34),transparent_42%),linear-gradient(145deg,#183f30,#0f2c22)]" />
        <div className="absolute -right-28 top-24 h-96 w-96 rounded-full border border-white/10 shadow-[0_0_0_38px_rgba(255,255,255,0.025),0_0_0_78px_rgba(255,255,255,0.02)]" />
        <Link className="relative inline-flex w-fit items-center gap-2 text-2xl font-bold tracking-tight" href="/">
          Finova<span className="h-2 w-2 rounded-full bg-[#8bd1a1]" />
        </Link>
        <div className="relative max-w-xl">
          <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs text-emerald-100">
            <Sparkles className="h-3.5 w-3.5" /> Finance, with clarity built in
          </div>
          <h2 className="text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">
            Close with confidence.
            <span className="mt-1 block text-emerald-200">Move your business forward.</span>
          </h2>
          <p className="mt-5 max-w-lg text-sm leading-7 text-emerald-50/75">
            One connected workspace for your ledger, approvals, bank reconciliation, and Pakistan-first compliance.
          </p>
          <div className="mt-10 rounded-2xl border border-white/15 bg-white/[0.08] p-5 shadow-2xl backdrop-blur">
            <div className="flex items-center justify-between border-b border-white/10 pb-4">
              <div>
                <p className="text-xs text-emerald-100/70">MONTH-END CLOSE</p>
                <p className="mt-1 font-medium">July 2026 · Consolidated</p>
              </div>
              <span className="rounded-full bg-emerald-300/15 px-2.5 py-1 text-[11px] font-medium text-emerald-200">On track</span>
            </div>
            <div className="mt-4 grid grid-cols-3 gap-3">
              {[["Reconciliations", "18 / 20"], ["Approvals", "7 / 7"], ["Exceptions", "2 open"]].map(([label, value]) => (
                <div className="rounded-xl bg-black/10 p-3" key={label}>
                  <p className="text-[10px] text-emerald-50/60">{label}</p>
                  <p className="mt-1 text-sm font-semibold">{value}</p>
                </div>
              ))}
            </div>
          </div>
        </div>
        <p className="relative text-xs text-emerald-50/50">Built for finance teams that need every number to tie.</p>
      </aside>
    </main>
  );
}
