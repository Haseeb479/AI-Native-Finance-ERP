import { NextRequest, NextResponse } from "next/server";
import { backendApiUrl, isOpsHostAllowed, opsHostDeniedResponse } from "@/lib/ops-auth";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();

  const contentLength = Number(request.headers.get("content-length") || 0);
  if (contentLength > 20_000) {
    return NextResponse.json({ error: "Request body is too large." }, { status: 413 });
  }

  const body = await request.json().catch(() => null);
  if (!body || typeof body !== "object") {
    return NextResponse.json({ error: "A valid invitation form is required." }, { status: 400 });
  }

  try {
    const upstream = await fetch(`${backendApiUrl}/internal/control-center/staff-invitations/accept`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify(body),
      cache: "no-store",
    });
    const responseBody = await upstream.json().catch(() => null);
    if (!responseBody) {
      return NextResponse.json({ error: "The staff identity service returned an invalid response." }, { status: 502 });
    }

    return NextResponse.json(responseBody, {
      status: upstream.status,
      headers: { "Cache-Control": "no-store" },
    });
  } catch {
    return NextResponse.json({ error: "Finova staff invitations are temporarily unavailable." }, { status: 502 });
  }
}
