import { NextRequest, NextResponse } from "next/server";
import { backendApiUrl, clearOpsCookie, isOpsHostAllowed, OPS_COOKIE, opsHostDeniedResponse } from "@/lib/ops-auth";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();
  const token = request.cookies.get(OPS_COOKIE)?.value;
  if (token) {
    try {
      const upstream = await fetch(`${backendApiUrl}/auth/logout`, {
        method: "POST",
        headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
        cache: "no-store",
      });
      if (!upstream.ok && upstream.status !== 401) {
        return NextResponse.json({ error: "The staff session could not be revoked. Please retry." }, { status: 502 });
      }
    } catch {
      return NextResponse.json({ error: "Finova Operations is unavailable; the staff session was not revoked." }, { status: 502 });
    }
  }

  const response = NextResponse.json({ success: true });
  clearOpsCookie(response);
  return response;
}
