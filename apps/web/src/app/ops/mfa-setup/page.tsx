"use client";

import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { KeyRound, ShieldCheck } from "lucide-react";
import QRCode from "qrcode";

type Enrollment = { secret: string; provisioning_uri: string; expires_in_minutes: number };

export default function OperationsMfaSetupPage() {
  const router = useRouter();
  const [enrollment, setEnrollment] = useState<Enrollment | null>(null);
  const [qrImage, setQrImage] = useState("");
  const [code, setCode] = useState("");
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [savedRecoveryCodes, setSavedRecoveryCodes] = useState(false);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    fetch("/api/ops/mfa-setup/begin", { method: "POST" })
      .then(async (response) => {
        const body = await response.json().catch(() => null);
        if (!response.ok) throw new Error(body?.error || "Unable to start staff MFA setup.");
        if (!cancelled) setEnrollment(body.data);
      })
      .catch((reason: Error) => { if (!cancelled) setError(reason.message); });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (!enrollment?.provisioning_uri) return;
    QRCode.toDataURL(enrollment.provisioning_uri, { width: 220, margin: 1 })
      .then(setQrImage)
      .catch(() => setQrImage(""));
  }, [enrollment]);

  async function confirm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      const response = await fetch("/api/ops/mfa-setup/confirm", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ code }),
      });
      const body = await response.json().catch(() => null);
      if (!response.ok) throw new Error(body?.error || "Authenticator code could not be verified.");
      setRecoveryCodes(body.data.recovery_codes);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "MFA setup could not be completed.");
    } finally {
      setBusy(false);
    }
  }

  async function cancel() {
    setBusy(true);
    try {
      const response = await fetch("/api/ops/mfa-setup/cancel", { method: "POST" });
      const body = await response.json().catch(() => null);
      if (!response.ok) throw new Error(body?.error || "Could not revoke pending staff session.");
      router.replace("/ops/login");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Could not cancel MFA setup.");
      setBusy(false);
    }
  }

  if (recoveryCodes.length > 0) {
    return (
      <main className="grid min-h-screen place-items-center bg-[#F5F7F5] p-5">
        <section className="w-full max-w-lg rounded-2xl border border-[#E1E9E2] bg-white p-7 shadow-sm sm:p-9">
          <ShieldCheck className="h-7 w-7 text-emerald-700" /><h1 className="mt-5 text-2xl font-semibold">Save your recovery codes</h1>
          <p className="mt-2 text-sm leading-6 text-slate-600">These one-time codes are shown only now. Store them in your company password manager. Each can be used once if your authenticator is unavailable.</p>
          <div className="mt-5 grid grid-cols-2 gap-2 rounded-xl bg-slate-50 p-4 font-mono text-sm">{recoveryCodes.map((item) => <code key={item}>{item}</code>)}</div>
          <label className="mt-5 flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" checked={savedRecoveryCodes} onChange={(event) => setSavedRecoveryCodes(event.target.checked)} className="mt-1 accent-emerald-800" />I have stored these recovery codes securely.</label>
          <button disabled={!savedRecoveryCodes} onClick={() => router.replace("/ops")} className="mt-5 w-full rounded-xl bg-[#174C38] px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Continue to Finova Operations</button>
        </section>
      </main>
    );
  }

  return (
    <main className="grid min-h-screen place-items-center bg-[#F5F7F5] p-5">
      <section className="w-full max-w-lg rounded-2xl border border-[#E1E9E2] bg-white p-7 shadow-sm sm:p-9">
        <div className="grid h-11 w-11 place-items-center rounded-xl bg-[#EAF3EC] text-[#174C38]"><KeyRound size={21} /></div>
        <p className="mt-5 text-xs font-bold uppercase tracking-[0.16em] text-emerald-800">Required security step</p>
        <h1 className="mt-2 text-2xl font-semibold">Set up staff multi-factor authentication</h1>
        <p className="mt-2 text-sm leading-6 text-slate-600">Finova Operations requires MFA for every staff account. Add the key below to an authenticator app, then verify a current code.</p>
        {error && <p role="alert" className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}
        {!enrollment && !error && <p role="status" className="mt-5 text-sm text-slate-500">Preparing secure enrollment…</p>}
        {enrollment && <>
          {qrImage && <div className="mt-5 flex flex-col items-center rounded-xl border border-slate-200 p-4"><img src={qrImage} alt="Authenticator QR code" width={220} height={220} /><p className="mt-2 text-xs text-slate-500">Scan with Google Authenticator, Microsoft Authenticator or Authy.</p></div>}
          <div className="mt-5 rounded-xl border border-emerald-100 bg-emerald-50 p-4"><div className="text-xs font-semibold text-emerald-900">Manual setup key (no spaces, letters A–Z and digits 2–7 only)</div><code className="mt-2 block break-all font-mono text-lg tracking-wider text-[#174C38]">{enrollment.secret}</code><p className="mt-2 text-xs text-emerald-900/70">In your authenticator app, add an account using this key. Setup expires in {enrollment.expires_in_minutes} minutes.</p></div>
          <form onSubmit={confirm} className="mt-5 space-y-3"><label className="block text-sm font-medium text-slate-700">Authenticator code<input required autoFocus inputMode="numeric" autoComplete="one-time-code" maxLength={6} pattern="[0-9]{6}" value={code} onChange={(event) => setCode(event.target.value)} className="mt-1.5 w-full rounded-xl border border-slate-200 px-3.5 py-3 font-mono tracking-[0.3em] outline-none focus:border-emerald-700" placeholder="000000" /></label><button disabled={busy} className="w-full rounded-xl bg-[#174C38] px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">{busy ? "Verifying…" : "Verify and secure account"}</button></form>
        </>}
        <button disabled={busy} onClick={() => void cancel()} className="mt-3 w-full rounded-lg py-2 text-sm font-medium text-slate-500 hover:text-slate-800 disabled:opacity-50">Cancel and sign out</button>
        <p className="mt-4 text-center text-[11px] leading-5 text-slate-400">Do not share your setup key or recovery codes. Finova staff access is audited.</p>
      </section>
    </main>
  );
}
