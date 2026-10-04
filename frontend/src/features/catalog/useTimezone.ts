"use client";

import { useSyncExternalStore } from "react";
import { DEFAULT_TZ } from "@/lib/format";
import { browserTimezone } from "./slots";

const subscribe = () => () => {};

/**
 * The visitor's timezone. The server renders in Moscow time; after hydration React switches to the browser's
 * timezone without a hydration mismatch (server snapshot first, then the client snapshot).
 */
export function useBrowserTimezone(fallback: string = DEFAULT_TZ): string {
  return useSyncExternalStore(subscribe, () => browserTimezone(fallback), () => fallback);
}
