import { NextRequest, NextResponse } from "next/server";
import { backendApiUrl, isOpsHostAllowed, opsHostDeniedResponse, revokeOpsToken, setOpsCookie, verifyOpsStaffToken } from "@/lib/ops-auth";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  if (!isOpsHostAllowed(request.headers.get("host"))) return opsHostDeniedResponse();
  const payload = await request.json().catch(() => null);
  const challengeToken = typeof payload?.challengeToken === "string" ? payload.challengeToken : "";
  const code = typeof payload?.code === "string" ? payload.code.trim() : "";
  if (!challengeToken || !code) {
    return NextResponse.json({ error: "Enter the authenticator or recovery code." }, { status: 400 });
  }

  try {
    const upstream = await fetch(`${backendApiUrl}/auth/mfa/challenge`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ mfa_challenge_token: challengeToken, code }),
      cache: "no-store",
    });
    const body = await upstream.json().catch(() => null);
    if (!upstream.ok || !body) {
      const error = body?.errors?.[0]?.message || body?.errors?.[0] || body?.error || "MFA verification failed.";
      return NextResponse.json({ error }, { status: upstream.status || 502 });
    }

    const token = body.data?.token;
    if (typeof token !== "string" || !token) {
      return NextResponse.json({ error: "The authentication service returned an invalid session." }, { status: 502 });
    }

    const { response: staffResponse, session } = await verifyOpsStaffToken(token);
    if (!session) {
      await revokeOpsToken(token);
      const status = staffResponse.status === 403 ? 403 : 502;
      return NextResponse.json({
        error: status === 403
          ? "This account is not provisioned for Finova Operations."
          : "Staff access could not be verified. Please retry.",
      }, { status });
    }

    const response = NextResponse.json({ data: session });
    setOpsCookie(response, token);
    return response;
  } catch {
    return NextResponse.json({ error: "Finova Operations MFA verification is unavailable. Please retry." }, { status: 502 });
  }
}
