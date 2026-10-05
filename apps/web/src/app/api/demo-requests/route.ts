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
    return NextResponse.json({ error: "A valid JSON body is required." }, { status: 400 });
  }

  try {
    const backendResponse = await fetch(`${BACKEND_URL}/demo-requests`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(body),
      cache: "no-store",
    });
    const payload = await backendResponse.json().catch(() => null);

    if (payload === null) {
      return NextResponse.json({ error: "The demo request service returned an invalid response." }, { status: 502 });
    }

    return NextResponse.json(payload, { status: backendResponse.status });
  } catch (error) {
    console.error("Demo request could not reach the request service.", {
      exception: error instanceof Error ? error.name : "UnknownError",
    });
    return NextResponse.json(
      { error: "Demo requests are temporarily unavailable. Please try again." },
      { status: 503 },
    );
  }
}
