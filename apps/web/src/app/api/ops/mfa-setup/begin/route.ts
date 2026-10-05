import { NextRequest, NextResponse } from "next/server";
import { backendApiUrl, clearOpsSetupCookie, isOpsHostAllowed, opsHostDeniedResponse, OPS_SETUP_COOKIE, preflightOpsStaffToken, revokeOpsToken } from "@/lib/ops-auth";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();
  const token = request.cookies.get(OPS_SETUP_COOKIE)?.value;
  if (!token) return NextResponse.json({ error: "Staff MFA setup session expired. Sign in again." }, { status: 401 });

  try {
    const { response: staffResponse, session } = await preflightOpsStaffToken(token);
    if (!session) {
      if (staffResponse.status === 401 || staffResponse.status === 403) {
        await revokeOpsToken(token);
        const response = NextResponse.json({ error: "This account no longer has staff access." }, { status: 401 });
        clearOpsSetupCookie(response);
        return response;
      }
      return NextResponse.json({ error: "Staff access could not be verified." }, { status: 502 });
    }

    const upstream = await fetch(`${backendApiUrl}/auth/mfa/setup`, {
      method: "POST",
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      cache: "no-store",
    });
    const body = await upstream.json().catch(() => null);
    if (!upstream.ok || !body?.data) {
      const error = body?.errors?.[0]?.message || body?.errors?.[0] || body?.error || "Unable to start MFA enrollment.";
      return NextResponse.json({ error }, { status: upstream.status || 502 });
    }
    return NextResponse.json({ data: body.data }, { headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ error: "MFA enrollment service is unavailable." }, { status: 502 });
  }
}
