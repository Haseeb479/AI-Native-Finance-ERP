"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { ArrowLeft, ArrowRight, CheckCircle2, Sparkles } from "lucide-react";

const initialForm = {
  first_name: "",
  last_name: "",
  email: "",
  company_name: "",
  company_size: "11-50",
  role: "",
  referral_source: "",
  message: "",
};

export default function RequestDemoPage() {
  const [form, setForm] = useState(initialForm);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [received, setReceived] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError("");

    try {
      const response = await fetch("/api/demo-requests", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(form),
      });
      const payload = await response.json();
      if (!response.ok) {
        throw new Error(payload.errors?.[0]?.message ?? payload.error ?? "We could not submit your request.");
      }
      setReceived(true);
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : "We could not submit your request.");
    } finally {
      setSubmitting(false);
    }
  }

  function update(field: keyof typeof initialForm, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  return (
    <main className="grid min-h-screen bg-white lg:grid-cols-2">
      <section className="flex items-center justify-center px-6 py-12 sm:px-10 lg:px-14">
        <div className="w-full max-w-xl">
          <Link className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900" href="/">
            <ArrowLeft className="h-4 w-4" /> Back to Finova
          </Link>
          {received ? (
            <div className="py-16 text-center">
              <CheckCircle2 className="mx-auto h-12 w-12 text-emerald-600" />
              <h1 className="mt-5 text-3xl font-semibold tracking-tight text-slate-950">Request received</h1>
              <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-600">
                Thanks, {form.first_name}. Your request is with the Finova team. We&apos;ll follow up using the work email you provided.
              </p>
              <Link className="mt-7 inline-flex items-center gap-2 rounded-xl bg-[#1d5c40] px-5 py-3 text-sm font-semibold text-white hover:bg-[#174a34]" href="/login">
                Sign in to Finova <ArrowRight className="h-4 w-4" />
              </Link>
            </div>
          ) : (
            <>
              <div className="mt-8">
                <p className="text-sm font-semibold text-[#28734d]">Talk to our team</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">See what a better close looks like.</h1>
                <p className="mt-3 text-sm leading-6 text-slate-500">
                  Tell us a little about your finance team. We&apos;ll tailor a walkthrough around your workflows.
                </p>
              </div>

              <form className="mt-8 space-y-4" onSubmit={submit}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field id="first-name" label="First name">
                    <input autoComplete="given-name" className="demo-input" id="first-name" maxLength={100} onChange={(e) => update("first_name", e.target.value)} required value={form.first_name} />
                  </Field>
                  <Field id="last-name" label="Last name">
                    <input autoComplete="family-name" className="demo-input" id="last-name" maxLength={100} onChange={(e) => update("last_name", e.target.value)} required value={form.last_name} />
                  </Field>
                </div>
                <Field id="demo-email" label="Business email">
                  <input autoComplete="email" className="demo-input" id="demo-email" maxLength={254} onChange={(e) => update("email", e.target.value)} required type="email" value={form.email} />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field id="company-name" label="Company name">
                    <input autoComplete="organization" className="demo-input" id="company-name" maxLength={200} onChange={(e) => update("company_name", e.target.value)} required value={form.company_name} />
                  </Field>
                  <Field id="company-size" label="Company size">
                    <select className="demo-input bg-white" id="company-size" onChange={(e) => update("company_size", e.target.value)} value={form.company_size}>
                      <option value="1-10">1–10</option>
                      <option value="11-50">11–50</option>
                      <option value="51-200">51–200</option>
                      <option value="201-1000">201–1,000</option>
                      <option value="1000+">1,000+</option>
                    </select>
                  </Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field id="role" label="Your role (optional)">
                    <input className="demo-input" id="role" maxLength={120} onChange={(e) => update("role", e.target.value)} value={form.role} />
                  </Field>
                  <Field id="referral-source" label="How did you hear about us?">
                    <select className="demo-input bg-white" id="referral-source" onChange={(e) => update("referral_source", e.target.value)} value={form.referral_source}>
                      <option value="">Select an option</option>
                      <option value="Search">Search</option>
                      <option value="Referral">Referral</option>
                      <option value="Partner">Partner</option>
                      <option value="Event">Event</option>
                      <option value="Social media">Social media</option>
                      <option value="Other">Other</option>
                    </select>
                  </Field>
                </div>
                <Field id="demo-message" label="What would you like to improve? (optional)">
                  <textarea className="demo-input min-h-24 resize-y py-2" id="demo-message" maxLength={2000} onChange={(e) => update("message", e.target.value)} value={form.message} />
                </Field>
                {error && <p className="rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-800" role="alert">{error}</p>}
                <button className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#1d5c40] px-4 text-sm font-semibold text-white transition hover:bg-[#174a34] disabled:cursor-wait disabled:opacity-60" disabled={submitting} type="submit">
                  {submitting ? "Sending request..." : <>Request a walkthrough <ArrowRight className="h-4 w-4" /></>}
                </button>
                <p className="text-center text-xs leading-5 text-slate-400">
                  By submitting, you agree Finova may contact you about your request. This form requests a conversation; it does not reserve a calendar slot.
                </p>
              </form>
            </>
          )}
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
            <Sparkles className="h-3.5 w-3.5" /> FINANCE, CONNECTED
          </div>
          <h2 className="text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">
            Close with confidence.
            <span className="mt-1 block text-emerald-200">Move your business forward.</span>
          </h2>
          <p className="mt-5 max-w-lg text-sm leading-7 text-emerald-50/75">
            Explore one connected workspace for your ledger, reconciliations, approvals, AI, and Pakistan-first compliance.
          </p>
          <ul className="mt-9 space-y-4 text-sm text-emerald-50/85">
            {["See your accounting workflows end to end", "Explore multi-entity and local tax scenarios", "Get a walkthrough tailored to your team"].map((item) => (
              <li className="flex items-center gap-3" key={item}>
                <CheckCircle2 className="h-4 w-4 text-emerald-300" /> {item}
              </li>
            ))}
          </ul>
        </div>
        <p className="relative text-xs text-emerald-50/50">Built for finance teams that need every number to tie.</p>
      </aside>

      <style jsx global>{`
        .demo-input {
          display: block;
          width: 100%;
          min-height: 2.75rem;
          border: 1px solid #dbe3dd;
          border-radius: 0.75rem;
          padding: 0.65rem 0.85rem;
          color: #14231d;
          font-size: 0.875rem;
          outline: none;
          transition: border-color 160ms ease, box-shadow 160ms ease;
        }
        .demo-input:focus {
          border-color: #37805b;
          box-shadow: 0 0 0 4px rgb(55 128 91 / 10%);
        }
      `}</style>
    </main>
  );
}

function Field({ id, label, children }: { id: string; label: string; children: React.ReactNode }) {
  return (
    <div>
      <label className="mb-1.5 block text-sm font-medium text-slate-700" htmlFor={id}>{label}</label>
      {children}
    </div>
  );
}
