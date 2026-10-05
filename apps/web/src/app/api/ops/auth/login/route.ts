import { NextRequest, NextResponse } from "next/server";
import { backendApiUrl, isOpsHostAllowed, opsHostDeniedResponse, preflightOpsStaffToken, revokeOpsToken, setOpsCookie, setOpsSetupCookie, verifyOpsStaffToken } from "@/lib/ops-auth";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();
  const payload = await request.json().catch(() => null);
  const email = typeof payload?.email === "string" ? payload.email.trim() : "";
  const password = typeof payload?.password === "string" ? payload.password : "";
  if (!email || !password) {
    return NextResponse.json({ error: "Enter your staff email and password." }, { status: 400 });
  }

  try {
    const upstream = await fetch(`${backendApiUrl}/auth/login`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ email, password, device_name: "finova_ops_web" }),
      cache: "no-store",
    });
    const body = await upstream.json().catch(() => null);
    if (!upstream.ok || !body) {
      const error = body?.errors?.[0]?.message || body?.errors?.[0] || body?.error || "Sign-in failed.";
      return NextResponse.json({ error }, { status: upstream.status || 502 });
    }

    if (body.data?.mfa_required && body.data?.mfa_challenge_token) {
      return NextResponse.json({
        mfaRequired: true,
        challengeToken: body.data.mfa_challenge_token,
      });
    }

    const token = body.data?.token;
    if (typeof token !== "string" || !token) {
      return NextResponse.json({ error: "The authentication service returned an invalid session." }, { status: 502 });
    }

    const { response: staffResponse, session } = await preflightOpsStaffToken(token);
    if (!session) {
      await revokeOpsToken(token);
      const status = staffResponse.status === 403 ? 403 : 502;
      return NextResponse.json({
        error: status === 403
          ? "This account is not provisioned for Finova Operations."
          : "Staff access could not be verified. Please retry.",
      }, { status });
    }

    if (!session.mfa_enabled) {
      const response = NextResponse.json({ mfaSetupRequired: true });
      setOpsSetupCookie(response, token);
      return response;
    }

    const { session: verifiedSession } = await verifyOpsStaffToken(token);
    if (!verifiedSession) {
      await revokeOpsToken(token);
      return NextResponse.json({ error: "Staff session verification failed. Please retry." }, { status: 403 });
    }

    const response = NextResponse.json({ data: verifiedSession });
    setOpsCookie(response, token);
    return response;
  } catch {
    return NextResponse.json({ error: "Finova Operations sign-in is unavailable. Please retry." }, { status: 502 });
  }
}
