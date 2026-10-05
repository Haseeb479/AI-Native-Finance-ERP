import { NextRequest, NextResponse } from "next/server";
import {
  backendApiUrl,
  clearOpsSetupCookie,
  isOpsHostAllowed,
  opsHostDeniedResponse,
  OPS_SETUP_COOKIE,
  preflightOpsStaffToken,
  revokeOpsToken,
  setOpsCookie,
  verifyOpsStaffToken,
} from "@/lib/ops-auth";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();
  const token = request.cookies.get(OPS_SETUP_COOKIE)?.value;
  if (!token) return NextResponse.json({ error: "Staff MFA setup session expired. Sign in again." }, { status: 401 });
  const payload = await request.json().catch(() => null);
  const code = typeof payload?.code === "string" ? payload.code.trim() : "";
  if (!/^\d{6}$/.test(code)) return NextResponse.json({ error: "Enter the six-digit code from your authenticator app." }, { status: 422 });

  try {
    const { response: staffResponse, session: preflight } = await preflightOpsStaffToken(token);
    if (!preflight) {
      if (staffResponse.status === 401 || staffResponse.status === 403) {
        await revokeOpsToken(token);
        const response = NextResponse.json({ error: "This account no longer has staff access." }, { status: 401 });
        clearOpsSetupCookie(response);
        return response;
      }
      return NextResponse.json({ error: "Staff access could not be verified." }, { status: 502 });
    }

    const upstream = await fetch(`${backendApiUrl}/auth/mfa/confirm`, {
      method: "POST",
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify({ code }),
      cache: "no-store",
    });
    const body = await upstream.json().catch(() => null);
    if (!upstream.ok || !body?.data) {
      const error = body?.errors?.[0]?.message || body?.errors?.[0] || body?.error || "MFA code verification failed.";
      return NextResponse.json({ error }, { status: upstream.status || 502 });
    }

    const activation = await fetch(`${backendApiUrl}/internal/control-center/staff-mfa-complete`, {
      method: "POST",
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      cache: "no-store",
    });
    if (!activation.ok) {
      const activationBody = await activation.json().catch(() => null);
      return NextResponse.json({
        error: activationBody?.errors?.[0]?.message || activationBody?.errors?.[0] || activationBody?.error || "MFA is enabled, but staff access could not be activated.",
      }, { status: activation.status >= 500 ? 502 : activation.status });
    }

    const { response: sessionResponse, session } = await verifyOpsStaffToken(token);
    if (!session?.mfa_enabled) {
      if (sessionResponse.status === 401 || sessionResponse.status === 403) {
        await revokeOpsToken(token);
        const response = NextResponse.json({ error: "Staff access was revoked before enrollment completed." }, { status: 403 });
        clearOpsSetupCookie(response);
        return response;
      }
      return NextResponse.json({ error: "MFA enrollment was not confirmed by the staff authentication service." }, { status: 502 });
    }

    const response = NextResponse.json({ data: { recovery_codes: body.data.recovery_codes || [] } });
    setOpsCookie(response, token);
    clearOpsSetupCookie(response);
    return response;
  } catch {
    return NextResponse.json({ error: "MFA confirmation service is unavailable." }, { status: 502 });
  }
}
