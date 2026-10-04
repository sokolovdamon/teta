import type { PromoCodeRow, PromoKind, PromoStatus, PromoType, Restrictions, ServiceType } from "./types";

export type Tone = "neutral" | "brand" | "success" | "warning" | "danger";

export const promoTone: Record<PromoStatus, Tone> = {
  draft: "neutral",
  scheduled: "brand",
  active: "success",
  exhausted: "warning",
  expired: "neutral",
  deactivated: "danger",
};

export const TYPE_OPTIONS: { value: PromoType; label: string; hint: string }[] = [
  { value: "percent", label: "Процентная скидка", hint: "Процент от цены сессии" },
  { value: "fixed", label: "Фиксированная скидка", hint: "Сумма в рублях, не больше цены сессии" },
  { value: "first_session", label: "Льготная первая сессия", hint: "Процент на первую оплаченную сессию клиента" },
];

export const STATUS_OPTIONS: { value: PromoStatus; label: string }[] = [
  { value: "draft", label: "Черновик" },
  { value: "scheduled", label: "Запланирован" },
  { value: "active", label: "Активен" },
  { value: "exhausted", label: "Лимит исчерпан" },
  { value: "expired", label: "Истёк" },
  { value: "deactivated", label: "Деактивирован" },
];

/** Values of the create / edit form: inputs keep strings, money in roubles. */
export type PromoFormValues = {
  code: string;
  title: string;
  description: string;
  type: PromoType;
  value: string;
  kind: PromoKind;
  owner_user_id: string;
  owner_label: string;
  valid_from: string;
  valid_until: string;
  total_limit: string;
  per_user_limit: string;
  min_amount: string;
  service_types: ServiceType[];
  psychologist_ids: string[];
  price_category_ids: string[];
  new_clients: boolean;
  registered_after: string;
  min_held_sessions: string;
  publish: boolean;
};

export const emptyForm = (): PromoFormValues => ({
  code: "",
  title: "",
  description: "",
  type: "percent",
  value: "",
  kind: "mass",
  owner_user_id: "",
  owner_label: "",
  valid_from: "",
  valid_until: "",
  total_limit: "",
  per_user_limit: "1",
  min_amount: "",
  service_types: [],
  psychologist_ids: [],
  price_category_ids: [],
  new_clients: false,
  registered_after: "",
  min_held_sessions: "",
  publish: false,
});

const int = (v: string): number | null => {
  const n = Number.parseInt(v.replace(/\s/g, ""), 10);
  return Number.isFinite(n) ? n : null;
};

/** Roubles typed by the admin ("1 500,50") → kopecks. */
export function rublesToKopecks(v: string): number | null {
  const clean = v.replace(/\s/g, "").replace(",", ".");
  if (clean === "") return null;
  const n = Number(clean);
  return Number.isFinite(n) ? Math.round(n * 100) : null;
}

export function kopecksToRubles(k: number | null | undefined): string {
  if (k === null || k === undefined) return "";
  return k % 100 === 0 ? String(k / 100) : (k / 100).toFixed(2).replace(".", ",");
}

/** "YYYY-MM-DDTHH:mm" of a datetime-local input (browser time) → ISO with offset; "" → null. */
export function localToIso(v: string): string | null {
  if (!v) return null;
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

export function isoToLocal(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function restrictionsOf(v: PromoFormValues): Restrictions | null {
  const r: Restrictions = {};
  if (v.service_types.length) r.service_types = v.service_types;
  if (v.psychologist_ids.length) r.psychologist_ids = v.psychologist_ids;
  if (v.price_category_ids.length) r.price_category_ids = v.price_category_ids;
  const segment: NonNullable<Restrictions["segment"]> = {};
  if (v.new_clients) segment.new_clients = true;
  if (v.registered_after) segment.registered_after = v.registered_after;
  const held = int(v.min_held_sessions);
  if (held) segment.min_held_sessions = held;
  if (Object.keys(segment).length) r.segment = segment;
  return Object.keys(r).length ? r : null;
}

/** Form → API payload (kopecks, ISO dates, nulls for empty limits). */
export function toPayload(v: PromoFormValues): Record<string, unknown> {
  return {
    code: v.code.trim() || null,
    title: v.title.trim() || null,
    description: v.description.trim() || null,
    type: v.type,
    value: v.type === "fixed" ? rublesToKopecks(v.value) : int(v.value),
    kind: v.kind,
    owner_user_id: v.kind === "individual" && v.owner_user_id ? v.owner_user_id : null,
    valid_from: localToIso(v.valid_from),
    valid_until: localToIso(v.valid_until),
    total_limit: int(v.total_limit),
    per_user_limit: int(v.per_user_limit),
    min_amount: rublesToKopecks(v.min_amount),
    restrictions: restrictionsOf(v),
    publish: v.publish,
  };
}

export function fromCode(c: PromoCodeRow): PromoFormValues {
  const r = c.restrictions ?? {};
  return {
    code: c.code,
    title: c.title ?? "",
    description: c.description ?? "",
    type: c.type,
    value: c.type === "fixed" ? kopecksToRubles(c.value) : String(c.value),
    kind: c.kind,
    owner_user_id: c.owner?.id ?? "",
    owner_label: c.owner ? `${c.owner.name} (${c.owner.email})` : "",
    valid_from: isoToLocal(c.valid_from),
    valid_until: isoToLocal(c.valid_until),
    total_limit: c.total_limit === null ? "" : String(c.total_limit),
    per_user_limit: c.per_user_limit === null ? "" : String(c.per_user_limit),
    min_amount: kopecksToRubles(c.min_amount),
    service_types: r.service_types ?? [],
    psychologist_ids: r.psychologist_ids ?? [],
    price_category_ids: r.price_category_ids ?? [],
    new_clients: !!r.segment?.new_clients,
    registered_after: r.segment?.registered_after ?? "",
    min_held_sessions: r.segment?.min_held_sessions ? String(r.segment.min_held_sessions) : "",
    publish: false,
  };
}

/** Human summary of restrictions for tables and cards. */
export function describeRestrictions(r: Restrictions | null | undefined, names: { psychologists?: Record<string, string>; categories?: Record<string, string> } = {}): string[] {
  if (!r) return [];
  const out: string[] = [];
  if (r.service_types?.length) out.push(r.service_types.map((t) => (t === "pair" ? "парные сессии" : "индивидуальные сессии")).join(" и "));
  if (r.psychologist_ids?.length) {
    const list = r.psychologist_ids.map((id) => names.psychologists?.[id]).filter(Boolean);
    out.push(list.length ? `психологи: ${list.join(", ")}` : `психологов: ${r.psychologist_ids.length}`);
  }
  if (r.price_category_ids?.length) {
    const list = r.price_category_ids.map((id) => names.categories?.[id]).filter(Boolean);
    out.push(list.length ? `категории: ${list.join(", ")}` : `ценовых категорий: ${r.price_category_ids.length}`);
  }
  if (r.segment?.new_clients) out.push("только новые клиенты");
  if (r.segment?.registered_after) out.push(`зарегистрированные с ${r.segment.registered_after.split("-").reverse().join(".")}`);
  if (r.segment?.min_held_sessions) out.push(`проведено от ${r.segment.min_held_sessions} сессий`);
  return out;
}
