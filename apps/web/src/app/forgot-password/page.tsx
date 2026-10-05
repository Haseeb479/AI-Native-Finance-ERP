"use client";

import { FormEvent, useState } from "react";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    setError("");
    setSubmitting(true);

    try {
      const response = await fetch("/api/auth/forgot-password", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email }),
      });
      const payload = await response.json();
      if (!response.ok) {
        throw new Error(payload.errors?.[0]?.message ?? payload.error ?? "Unable to request a password reset.");
      }

      setMessage(payload.data?.message ?? "If an account with that email address exists, a password reset link has been sent.");
    } catch (submissionError) {
      setError(submissionError instanceof Error ? submissionError.message : "Unable to request a password reset.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-6">
      <h1 className="text-2xl font-semibold">Forgot your password?</h1>
      <p className="mt-2 text-sm text-gray-600">Enter your account email and we will send a reset link if an account matches.</p>
      <form className="mt-6 space-y-4" onSubmit={submit}>
        <label className="block text-sm font-medium" htmlFor="email">Email address</label>
        <input
          autoComplete="email"
          className="w-full rounded border px-3 py-2"
          id="email"
          onChange={(event) => setEmail(event.target.value)}
          required
          type="email"
          value={email}
        />
        <button className="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-60" disabled={submitting} type="submit">
          {submitting ? "Sending..." : "Send reset link"}
        </button>
      </form>
      {message && <p className="mt-4" role="status">{message}</p>}
      {error && <p className="mt-4 text-red-700" role="alert">{error}</p>}
    </main>
  );
}
