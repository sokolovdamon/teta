import type { SessionFormat } from "./types";

/**
 * Catalog filters live in the URL (SITE-02): shareable links, SSR and the back button work.
 * URL: /psychologists?requests=trevoga&requests=stress&approaches=kpt&gender=female&age_min=30&age_max=50
 *      &price=economy&format=pair&within=7&q=…&sort=price_asc&page=2
 */
export const SORTS = ["nearest", "price_asc", "price_desc", "experience", "relevance"] as const;
export type CatalogSort = (typeof SORTS)[number];

export const SORT_LABELS: Record<CatalogSort, string> = {
  nearest: "Ближайшее время",
  price_asc: "Сначала дешевле",
  price_desc: "Сначала дороже",
  experience: "Больше опыта",
  relevance: "По совпадению с поиском",
};

export const WITHIN_OPTIONS = [
  { value: 1, label: "Сегодня–завтра" },
  { value: 3, label: "В ближайшие 3 дня" },
  { value: 7, label: "В течение недели" },
  { value: 14, label: "В течение двух недель" },
];

export type CatalogFilters = {
  requests: string[];
  approaches: string[];
  specializations: string[];
  gender: "" | "female" | "male";
  ageMin: number | null;
  ageMax: number | null;
  price: string[];
  format: "" | SessionFormat;
  within: number | null;
  q: string;
  sort: CatalogSort;
  page: number;
};

export const EMPTY_FILTERS: CatalogFilters = {
  requests: [],
  approaches: [],
  specializations: [],
  gender: "",
  ageMin: null,
  ageMax: null,
  price: [],
  format: "",
  within: null,
  q: "",
  sort: "nearest",
  page: 1,
};

type Raw = Record<string, string | string[] | undefined> | URLSearchParams;

const SLUG = /^[a-z0-9-]{1,128}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function all(raw: Raw, key: string): string[] {
  const values =
    raw instanceof URLSearchParams
      ? [...raw.getAll(key), ...raw.getAll(`${key}[]`)]
      : [raw[key], raw[`${key}[]`]].flat().filter((v): v is string => typeof v === "string");
  return values.flatMap((v) => v.split(",")).map((v) => v.trim()).filter(Boolean);
}

function one(raw: Raw, key: string): string {
  return all(raw, key)[0] ?? "";
}

function int(value: string, min: number, max: number): number | null {
  if (!/^\d+$/.test(value)) return null;
  const n = Number(value);
  return n >= min && n <= max ? n : null;
}

const ids = (values: string[]) => [...new Set(values.filter((v) => SLUG.test(v) || UUID.test(v)))].slice(0, 43);

export function parseFilters(raw: Raw): CatalogFilters {
  const gender = one(raw, "gender");
  const format = one(raw, "format");
  const sort = one(raw, "sort") as CatalogSort;
  let ageMin = int(one(raw, "age_min"), 18, 100);
  let ageMax = int(one(raw, "age_max"), 18, 100);
  if (ageMin !== null && ageMax !== null && ageMin > ageMax) [ageMin, ageMax] = [ageMax, ageMin];
  const q = one(raw, "q").slice(0, 100);
  return {
    requests: ids(all(raw, "requests")),
    approaches: ids(all(raw, "approaches")),
    specializations: ids(all(raw, "specializations")),
    gender: gender === "female" || gender === "male" ? gender : "",
    ageMin,
    ageMax,
    price: ids(all(raw, "price")),
    format: format === "individual" || format === "pair" ? format : "",
    within: int(one(raw, "within"), 1, 60),
    q,
    sort: SORTS.includes(sort) && (sort !== "relevance" || q !== "") ? sort : "nearest",
    page: int(one(raw, "page"), 1, 1000) ?? 1,
  };
}

/** Query string for the site URL ("" when nothing is set); defaults are omitted. */
export function filtersToSearch(f: CatalogFilters): string {
  const p = new URLSearchParams();
  f.requests.forEach((v) => p.append("requests", v));
  f.approaches.forEach((v) => p.append("approaches", v));
  f.specializations.forEach((v) => p.append("specializations", v));
  if (f.gender) p.set("gender", f.gender);
  if (f.ageMin !== null) p.set("age_min", String(f.ageMin));
  if (f.ageMax !== null) p.set("age_max", String(f.ageMax));
  f.price.forEach((v) => p.append("price", v));
  if (f.format) p.set("format", f.format);
  if (f.within !== null) p.set("within", String(f.within));
  if (f.q) p.set("q", f.q);
  if (f.sort !== "nearest") p.set("sort", f.sort);
  if (f.page > 1) p.set("page", String(f.page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** Query for GET /api/v1/psychologists (arrays become key[] in buildQuery). */
export function filtersToApiQuery(f: CatalogFilters, perPage = 12): Record<string, string | number | string[] | undefined> {
  return {
    requests: f.requests.length ? f.requests : undefined,
    approaches: f.approaches.length ? f.approaches : undefined,
    specializations: f.specializations.length ? f.specializations : undefined,
    gender: f.gender || undefined,
    age_min: f.ageMin ?? undefined,
    age_max: f.ageMax ?? undefined,
    price_category: f.price.length ? f.price : undefined,
    format: f.format || undefined,
    available_within_days: f.within ?? undefined,
    q: f.q || undefined,
    sort: f.sort,
    page: f.page,
    per_page: perPage,
  };
}

/** Number of narrowing filters (search text and sorting are not counted). */
export function activeFilterCount(f: CatalogFilters): number {
  return (
    f.requests.length +
    f.approaches.length +
    f.specializations.length +
    f.price.length +
    (f.gender ? 1 : 0) +
    (f.ageMin !== null || f.ageMax !== null ? 1 : 0) +
    (f.format ? 1 : 0) +
    (f.within !== null ? 1 : 0)
  );
}

export function toggleValue(list: string[], value: string): string[] {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}

/** Any change of filters returns to the first page. */
export function withFilters(f: CatalogFilters, patch: Partial<CatalogFilters>): CatalogFilters {
  return { ...f, page: 1, ...patch };
}
