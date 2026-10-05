import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

const BACKEND_URL =
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api/v1";

export async function POST(request: NextRequest) {
  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "A valid Google credential is required." }, { status: 400 });
  }

  if (
    typeof body !== "object" ||
    body === null ||
    !("credential" in body) ||
    typeof body.credential !== "string" ||
    !body.credential
  ) {
    return NextResponse.json({ error: "A valid Google credential is required." }, { status: 400 });
  }

  try {
    const backendResponse = await fetch(`${BACKEND_URL}/auth/google`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ credential: body.credential }),
      cache: "no-store",
    });
    const payload = await backendResponse.json().catch(() => null);

    if (!backendResponse.ok || !payload?.data?.token) {
      return NextResponse.json(
        payload ?? { error: "Google sign-in could not be completed." },
        { status: backendResponse.status || 502 },
      );
    }

    const response = NextResponse.json({
      success: true,
      data: payload.data,
    });
    response.cookies.set("erp_auth_token", payload.data.token, {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      path: "/",
      maxAge: 60 * 60 * 24 * 7,
    });

    return response;
  } catch (error) {
    console.error("Google sign-in could not reach the authentication service.", {
      exception: error instanceof Error ? error.name : "UnknownError",
    });
    return NextResponse.json(
      { error: "Google sign-in is temporarily unavailable. Please try again." },
      { status: 503 },
    );
  }
}
