import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

const BACKEND_URL =
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api/v1";

export async function POST(req: NextRequest) {
  try {
    const body = await req.json().catch(() => ({}));
    const { email, password, device_name } = body;

    if (!email || !password) {
      return NextResponse.json(
        { error: "Email and password are required." },
        { status: 400 }
      );
    }

    const backendRes = await fetch(`${BACKEND_URL}/auth/login`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify({
        email,
        password,
        device_name: device_name || "web_dashboard",
      }),
    });

    const data = await backendRes.json().catch(() => null);

    if (!backendRes.ok || !data) {
      return NextResponse.json(
        data || { error: "Authentication failed." },
        { status: backendRes.status || 401 }
      );
    }

    const token = data.data?.token || data.token;
    const user = data.data?.user || data.user;
    const organization = data.data?.organization || data.organization;

    const response = NextResponse.json({
      success: true,
      data: {
        token, // Included for backward compatibility if needed
        user,
        organization,
      },
    });

    // Set secure, HttpOnly cookie for browser auth
    if (token) {
      response.cookies.set("erp_auth_token", token, {
        httpOnly: true,
        secure: process.env.NODE_ENV === "production",
        sameSite: "lax",
        path: "/",
        maxAge: 60 * 60 * 24 * 7, // 7 days
      });
    }

    if (organization?.id) {
      response.cookies.set("erp_current_org_id", organization.id, {
        httpOnly: false, // accessible to client for UI org selector
        secure: process.env.NODE_ENV === "production",
        sameSite: "lax",
        path: "/",
        maxAge: 60 * 60 * 24 * 7,
      });
    }

    return response;
  } catch (error: any) {
    return NextResponse.json(
      { error: error?.message || "Internal server error during authentication." },
      { status: 500 }
    );
  }
}
