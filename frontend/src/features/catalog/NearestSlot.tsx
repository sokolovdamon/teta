"use client";

import Link from "next/link";
import { bookingHref } from "./links";
import { nearestLabel } from "./slots";
import type { SessionFormat } from "./types";
import { useBrowserTimezone } from "./useTimezone";

/** "Ближайшее время: завтра в 10:00" in the visitor's timezone; the time links straight to booking. */
export function NearestSlot({ slug, iso, format }: { slug: string; iso: string | null; format: SessionFormat }) {
  const tz = useBrowserTimezone();
  if (!iso) return <p className="text-sm text-muted">Свободное время появится позже</p>;
  return (
    <p className="text-sm text-ink-2">
      Ближайшее время:{" "}
      <Link href={bookingHref(slug, format, iso)} className="font-medium text-brand hover:underline" suppressHydrationWarning>
        {nearestLabel(iso, tz)}
      </Link>
    </p>
  );
}
