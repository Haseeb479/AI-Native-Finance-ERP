import { NextRequest, NextResponse } from "next/server";
import { backendApiUrl, clearOpsSetupCookie, isOpsHostAllowed, opsHostDeniedResponse, OPS_SETUP_COOKIE } from "@/lib/ops-auth";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();
  const token = request.cookies.get(OPS_SETUP_COOKIE)?.value;
  if (token) {
    try {
      const upstream = await fetch(`${backendApiUrl}/auth/logout`, {
        method: "POST",
        headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
        cache: "no-store",
      });
      if (!upstream.ok && upstream.status !== 401) {
        return NextResponse.json({ error: "Could not revoke the pending staff session." }, { status: 502 });
      }
    } catch {
      return NextResponse.json({ error: "Staff session service is unavailable; pending session was not revoked." }, { status: 502 });
    }
  }
  const response = NextResponse.json({ success: true });
  clearOpsSetupCookie(response);
  return response;
}
