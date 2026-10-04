import { NextResponse, type NextRequest } from "next/server";

const TOKEN_COOKIE = "teta_token";

/** Cabinets and the video room require a session; role checks happen in each section's layout. */
export function proxy(request: NextRequest) {
  if (!request.cookies.get(TOKEN_COOKIE)) {
    const url = new URL("/auth/login", request.url);
    url.searchParams.set("next", request.nextUrl.pathname + request.nextUrl.search);
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

export const config = {
  matcher: ["/client/:path*", "/pro/:path*", "/admin/:path*", "/hr/:path*", "/room/:path*"],
};
