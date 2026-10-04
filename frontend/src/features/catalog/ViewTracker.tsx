"use client";

import { useEffect } from "react";
import { api } from "@/lib/api";

const seen = new Set<string>();

/** Counts one view of a profile page per browser session (SSR responses are cached and cannot count views). */
export function ViewTracker({ slug }: { slug: string }) {
  useEffect(() => {
    const key = `teta:viewed:${slug}`;
    if (seen.has(key)) return;
    seen.add(key);
    try {
      if (sessionStorage.getItem(key)) return;
      sessionStorage.setItem(key, "1");
    } catch {
      // Storage may be unavailable (private mode): count anyway, once per page load.
    }
    api(`/psychologists/${encodeURIComponent(slug)}/views`, { method: "POST" }).catch(() => undefined);
  }, [slug]);
  return null;
}
