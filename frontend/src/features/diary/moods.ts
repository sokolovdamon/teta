import type { PeriodPreset } from "./types";

/** DEC-41: the 5-emoji scale, from 1 (very bad) to 5 (very good). */
export const MOODS = [
  { value: 1, emoji: "😣", label: "Очень плохо" },
  { value: 2, emoji: "🙁", label: "Плохо" },
  { value: 3, emoji: "😐", label: "Нормально" },
  { value: 4, emoji: "🙂", label: "Хорошо" },
  { value: 5, emoji: "😄", label: "Отлично" },
] as const;

export const NOTE_MAX = 500;

export const PERIODS: { value: PeriodPreset; label: string }[] = [
  { value: "week", label: "Неделя" },
  { value: "month", label: "Месяц" },
  { value: "quarter", label: "3 месяца" },
  { value: "year", label: "Год" },
];

/** The mood closest to an average value (3.4 → «Нормально»). */
export function moodFor(value: number | null | undefined) {
  if (value === null || value === undefined || Number.isNaN(value)) return null;
  const rounded = Math.min(5, Math.max(1, Math.round(value)));
  return MOODS[rounded - 1];
}

/** "3,4" — one decimal, Russian decimal comma. */
export function formatMood(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return new Intl.NumberFormat("ru-RU", { maximumFractionDigits: 1, minimumFractionDigits: Number.isInteger(value) ? 0 : 1 }).format(value);
}
