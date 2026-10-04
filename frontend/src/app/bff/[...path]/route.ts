import { NextResponse, type NextRequest } from "next/server";
import { BACKEND_URL, TOKEN_COOKIE } from "@/lib/config";

/**
 * Backend-for-frontend proxy: /bff/<path> → Laravel /api/<path>.
 * The Sanctum token lives in an httpOnly cookie and is attached here, so it is never readable by page scripts.
 * Login/registration responses carry a token: it is moved into the cookie and stripped from the body.
 */
const TOKEN_ISSUING = /^v1\/auth\/(login|register|reset-password|invitations\/[^/]+\/accept)$/;
const LOGOUT = /^v1\/auth\/logout$/;
const FORWARDED_HEADERS = ["accept", "content-type", "x-socket-id", "x-request-id"];
const MAX_AGE = 60 * 60 * 24 * 30;

async function handle(request: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const { path } = await ctx.params;
  const joined = path.join("/");
  const target = `${BACKEND_URL}/api/${joined}${request.nextUrl.search}`;

  const headers = new Headers();
  for (const name of FORWARDED_HEADERS) {
    const value = request.headers.get(name);
    if (value) headers.set(name, value);
  }
  headers.set("accept", headers.get("accept") ?? "application/json");
  const token = request.cookies.get(TOKEN_COOKIE)?.value;
  if (token) headers.set("authorization", `Bearer ${token}`);
  const forwardedFor = request.headers.get("x-forwarded-for");
  if (forwardedFor) headers.set("x-forwarded-for", forwardedFor);
  const ua = request.headers.get("user-agent");
  if (ua) headers.set("user-agent", ua);

  const hasBody = !["GET", "HEAD"].includes(request.method);
  const upstream = await fetch(target, {
    method: request.method,
    headers,
    body: hasBody ? await request.arrayBuffer() : undefined,
    redirect: "manual",
    cache: "no-store",
  });

  const contentType = upstream.headers.get("content-type") ?? "";

  if (TOKEN_ISSUING.test(joined) && upstream.ok && contentType.includes("application/json")) {
    const body = (await upstream.json()) as { token?: string } & Record<string, unknown>;
    const { token: issued, ...rest } = body;
    const res = NextResponse.json(rest, { status: upstream.status });
    if (issued) {
      res.cookies.set(TOKEN_COOKIE, issued, {
        httpOnly: true,
        sameSite: "lax",
        secure: process.env.NODE_ENV === "production",
        path: "/",
        maxAge: MAX_AGE,
      });
    }
    return res;
  }

  const res = new NextResponse(upstream.body, { status: upstream.status, headers: passthroughHeaders(upstream.headers) });
  if (LOGOUT.test(joined) || upstream.status === 401) {
    res.cookies.delete(TOKEN_COOKIE);
  }
  return res;
}

function passthroughHeaders(source: Headers): Headers {
  const out = new Headers();
  for (const name of ["content-type", "content-disposition", "cache-control", "retry-after"]) {
    const v = source.get(name);
    if (v) out.set(name, v);
  }
  return out;
}

export { handle as GET, handle as POST, handle as PUT, handle as PATCH, handle as DELETE };
