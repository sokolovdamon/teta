import { plural } from "@/lib/format";
import type { SessionFormat } from "./types";

/** Booking wizard of stream D: /podbor/book?psychologist={slug}&format={individual|pair}&starts_at={ISO UTC}. */
export function bookingHref(slug: string, format: SessionFormat, startsAt?: string): string {
  const p = new URLSearchParams({ psychologist: slug, format });
  if (startsAt) p.set("starts_at", startsAt);
  return `/podbor/book?${p.toString()}`;
}

export function profileHref(slug: string): string {
  return `/psychologists/${slug}`;
}

export const FORMAT_LABELS: Record<SessionFormat, string> = { individual: "Индивидуальная", pair: "Парная" };

/** "7 лет опыта" */
export function experienceLabel(years: number | null): string | null {
  if (years === null || years === undefined) return null;
  if (years === 0) return "Опыт меньше года";
  return `Опыт ${years} ${plural(years, ["год", "года", "лет"])}`;
}
