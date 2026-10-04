"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";

let instance: Echo<"reverb"> | null = null;

/**
 * Reverb (Pusher protocol) client. Private channels are authorized through the BFF,
 * which attaches the session token: POST /bff/broadcasting/auth → Laravel /api/broadcasting/auth.
 */
export function echo(): Echo<"reverb"> | null {
  if (typeof window === "undefined") return null;
  const key = process.env.NEXT_PUBLIC_REVERB_KEY;
  if (!key) return null;
  if (instance) return instance;
  (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? (window.location.protocol === "https:" ? "https" : "http");
  instance = new Echo({
    broadcaster: "reverb",
    key,
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
    wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 443),
    forceTLS: scheme === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: "/bff/broadcasting/auth",
  });
  return instance;
}
