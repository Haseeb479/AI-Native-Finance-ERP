import { NextRequest, NextResponse } from "next/server";
import { clearOpsCookie, isOpsHostAllowed, OPS_COOKIE, opsHostDeniedResponse, revokeOpsToken, verifyOpsStaffToken } from "@/lib/ops-auth";

export const runtime = "nodejs";

export async function GET(request: NextRequest) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();
  const token = request.cookies.get(OPS_COOKIE)?.value;
  if (!token) {
    return NextResponse.json({ authenticated: false }, { status: 401 });
  }

  try {
    const { response: upstream, session } = await verifyOpsStaffToken(token);
    if (session) {
      return NextResponse.json({ authenticated: true, data: session }, {
        headers: { "Cache-Control": "no-store" },
      });
    }
    if (upstream.status === 403 || upstream.status === 401) {
      await revokeOpsToken(token);
      const response = NextResponse.json({ authenticated: false }, { status: 401 });
      clearOpsCookie(response);
      return response;
    }
    return NextResponse.json({ error: "Finova Operations session could not be verified." }, { status: 502 });
  } catch {
    return NextResponse.json({ error: "Finova Operations is unavailable. Your session was not changed." }, { status: 502 });
  }
}
