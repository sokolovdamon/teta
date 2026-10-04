/** Server-side address of the Laravel backend (never exposed to the browser). */
export const BACKEND_URL = process.env.BACKEND_INTERNAL_URL ?? "http://127.0.0.1:8000";

/** httpOnly cookie that holds the Sanctum token issued at login. */
export const TOKEN_COOKIE = "teta_token";

export const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";
export const SITE_NAME = process.env.NEXT_PUBLIC_SITE_NAME ?? "ТЕТА";
