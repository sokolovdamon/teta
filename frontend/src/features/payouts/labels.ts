import type { AccrualStatus, PayoutStatus, RegistryStatus } from "./types";

export type Tone = "neutral" | "brand" | "success" | "warning" | "danger";

export const accrualTone: Record<AccrualStatus, Tone> = {
  accrued: "brand",
  in_registry: "warning",
  paid: "success",
  reversed: "neutral",
  corrected: "warning",
};

export const payoutTone: Record<PayoutStatus, Tone> = {
  checking: "neutral",
  blocked_supervision: "danger",
  deferred: "neutral",
  in_registry: "brand",
  excluded: "neutral",
  sent: "warning",
  unknown: "warning",
  paid: "success",
  rejected: "danger",
};

export const registryTone: Record<RegistryStatus, Tone> = {
  draft: "warning",
  approved: "brand",
  sent: "brand",
  completed: "success",
};

export const ACCRUAL_STATUS_OPTIONS: { value: AccrualStatus; label: string }[] = [
  { value: "accrued", label: "Начислено" },
  { value: "in_registry", label: "В реестре выплат" },
  { value: "paid", label: "Выплачено" },
  { value: "reversed", label: "Сторнировано" },
  { value: "corrected", label: "Скорректировано после выплаты" },
];

/** YYYY-MM-DD of a local date (no timezone shift). */
export function isoDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

export type PeriodPreset = "month" | "prev_month" | "quarter" | "year";

export const PERIOD_PRESETS: { value: PeriodPreset; label: string }[] = [
  { value: "month", label: "Этот месяц" },
  { value: "prev_month", label: "Прошлый месяц" },
  { value: "quarter", label: "3 месяца" },
  { value: "year", label: "Год" },
];

/** Inclusive [from, to] dates of a period preset relative to `today`. */
export function presetRange(preset: PeriodPreset, today: Date = new Date()): { from: string; to: string } {
  const y = today.getFullYear();
  const m = today.getMonth();
  switch (preset) {
    case "prev_month":
      return { from: isoDate(new Date(y, m - 1, 1)), to: isoDate(new Date(y, m, 0)) };
    case "quarter":
      return { from: isoDate(new Date(y, m - 2, 1)), to: isoDate(today) };
    case "year":
      return { from: isoDate(new Date(y, 0, 1)), to: isoDate(today) };
    default:
      return { from: isoDate(new Date(y, m, 1)), to: isoDate(today) };
  }
}

const PAYOUT_DAYS = ["", "каждый понедельник", "каждый вторник", "каждую среду", "каждый четверг", "каждую пятницу", "каждую субботу", "каждое воскресенье"];

/** "каждый понедельник" from the ISO weekday of P-PAYOUT-PERIOD. */
export function payoutDayLabel(isoWeekday: number): string {
  return PAYOUT_DAYS[isoWeekday] ?? "раз в неделю";
}

/** Signed money: "+2 800 ₽" / "−1 400 ₽" for ledger rows. */
export function signed(kopecks: number, format: (k: number) => string): string {
  if (kopecks === 0) return format(0);
  return kopecks > 0 ? `+${format(kopecks)}` : `−${format(Math.abs(kopecks))}`;
}
