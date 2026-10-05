"use client";

import { FormEvent, Suspense, useState } from "react";
import { useSearchParams } from "next/navigation";

function ResetPasswordForm() {
  const searchParams = useSearchParams();
  const email = searchParams.get("email") ?? "";
  const token = searchParams.get("token") ?? "";
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    setError("");

    if (password !== passwordConfirmation) {
      setError("The password confirmation does not match.");
      return;
    }

    setSubmitting(true);
    try {
      const response = await fetch("/api/auth/reset-password", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          email,
          token,
          password,
          password_confirmation: passwordConfirmation,
        }),
      });
      const payload = await response.json();
      if (!response.ok) {
        throw new Error(payload.errors?.[0]?.message ?? payload.error ?? "Unable to reset your password.");
      }

      setMessage(payload.data?.message ?? "Your password has been reset. You can now sign in.");
    } catch (submissionError) {
      setError(submissionError instanceof Error ? submissionError.message : "Unable to reset your password.");
    } finally {
      setSubmitting(false);
    }
  }

  if (!email || !token) {
    return <p role="alert">This password reset link is invalid or incomplete. Request a new reset link.</p>;
  }

  return (
    <>
      <h1 className="text-2xl font-semibold">Reset your password</h1>
      <p className="mt-2 text-sm text-gray-600">Choose a new password for {email}.</p>
      <form className="mt-6 space-y-4" onSubmit={submit}>
        <label className="block text-sm font-medium" htmlFor="password">New password</label>
        <input
          autoComplete="new-password"
          className="w-full rounded border px-3 py-2"
          id="password"
          minLength={12}
          onChange={(event) => setPassword(event.target.value)}
          required
          type="password"
          value={password}
        />
        <label className="block text-sm font-medium" htmlFor="password-confirmation">Confirm new password</label>
        <input
          autoComplete="new-password"
          className="w-full rounded border px-3 py-2"
          id="password-confirmation"
          minLength={12}
          onChange={(event) => setPasswordConfirmation(event.target.value)}
          required
          type="password"
          value={passwordConfirmation}
        />
        <button className="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-60" disabled={submitting} type="submit">
          {submitting ? "Updating..." : "Update password"}
        </button>
      </form>
      {message && <p className="mt-4" role="status">{message}</p>}
      {error && <p className="mt-4 text-red-700" role="alert">{error}</p>}
    </>
  );
}

export default function ResetPasswordPage() {
  return (
    <main className="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-6">
      <Suspense fallback={<p>Loading password reset...</p>}>
        <ResetPasswordForm />
      </Suspense>
    </main>
  );
}
