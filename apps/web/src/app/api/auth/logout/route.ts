import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

const BACKEND_URL =
  process.env.INTERNAL_API_URL ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api/v1";

export async function POST(req: NextRequest) {
  try {
    const token =
      req.cookies.get("erp_auth_token")?.value ||
      req.headers.get("Authorization")?.replace(/^Bearer\s+/i, "");

    if (token) {
      // Invalidate token on backend
      await fetch(`${BACKEND_URL}/auth/logout`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
        },
      }).catch(() => null);
    }

    const response = NextResponse.json({ success: true, message: "Logged out successfully." });

    // Clear secure auth cookies
    response.cookies.set("erp_auth_token", "", {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      path: "/",
      maxAge: 0,
    });

    response.cookies.set("erp_current_org_id", "", {
      httpOnly: false,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      path: "/",
      maxAge: 0,
    });

    return response;
  } catch (error: any) {
    return NextResponse.json(
      { error: error?.message || "Internal server error during logout." },
      { status: 500 }
    );
  }
}
