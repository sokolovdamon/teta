/** Money is stored in kopecks on the backend. */
export function rub(kopecks: number | null | undefined, opts: { fraction?: boolean } = {}): string {
  if (kopecks === null || kopecks === undefined) return "—";
  return new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "RUB",
    minimumFractionDigits: opts.fraction ? 2 : 0,
    maximumFractionDigits: opts.fraction ? 2 : 0,
  }).format(kopecks / 100);
}

export const DEFAULT_TZ = "Europe/Moscow";

export function dateTime(iso: string | null | undefined, tz: string = DEFAULT_TZ): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("ru-RU", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
}

export function date(iso: string | null | undefined, tz: string = DEFAULT_TZ): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("ru-RU", { day: "numeric", month: "long", year: "numeric", timeZone: tz }).format(new Date(iso));
}

export function time(iso: string | null | undefined, tz: string = DEFAULT_TZ): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("ru-RU", { hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
}

/** Russian plural: plural(5, ["сессия", "сессии", "сессий"]) → "сессий". */
export function plural(n: number, forms: [string, string, string]): string {
  const a = Math.abs(n) % 100;
  const b = a % 10;
  if (a > 10 && a < 20) return forms[2];
  if (b > 1 && b < 5) return forms[1];
  if (b === 1) return forms[0];
  return forms[2];
}
