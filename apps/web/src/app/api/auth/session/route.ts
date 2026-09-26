import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

const BACKEND_URL =
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api/v1";

export async function GET(req: NextRequest) {
  try {
    const token =
      req.cookies.get("erp_auth_token")?.value ||
      req.headers.get("Authorization")?.replace(/^Bearer\s+/i, "");

    if (!token) {
      return NextResponse.json(
        { authenticated: false, user: null },
        { status: 401 }
      );
    }

    const backendRes = await fetch(`${BACKEND_URL}/auth/me`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
      },
    });

    const data = await backendRes.json().catch(() => null);

    if (!backendRes.ok || !data) {
      return NextResponse.json(
        { authenticated: false, user: null },
        { status: 401 }
      );
    }

    return NextResponse.json({
      authenticated: true,
      data: data.data || data,
    });
  } catch (error: any) {
    return NextResponse.json(
      { authenticated: false, error: error?.message || "Session validation error." },
      { status: 500 }
    );
  }
}
