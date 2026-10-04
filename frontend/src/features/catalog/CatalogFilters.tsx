"use client";

import { clsx } from "clsx";
import { useRouter } from "next/navigation";
import { useState, useTransition, type ReactNode } from "react";
import { Button, Chip, Input, Select } from "@/components/ui";
import {
  activeFilterCount,
  EMPTY_FILTERS,
  filtersToSearch,
  SORT_LABELS,
  SORTS,
  toggleValue,
  WITHIN_OPTIONS,
  withFilters,
  type CatalogFilters as Filters,
} from "./query";
import type { Dictionaries } from "./types";

type Props = { filters: Filters; dictionaries: Dictionaries | null; basePath?: string; total: number | null };

const AGE_PRESETS = [
  { label: "Любой", min: null, max: null },
  { label: "До 35", min: null, max: 35 },
  { label: "35–45", min: 35, max: 45 },
  { label: "45–55", min: 45, max: 55 },
  { label: "Старше 55", min: 55, max: null },
] as const;

/** SITE-02 filters. State lives in the URL; each change navigates and the server renders the new list. */
export function CatalogFilters({ filters, dictionaries, basePath = "/psychologists", total }: Props) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [q, setQ] = useState(filters.q);
  const count = activeFilterCount(filters);

  function go(next: Filters) {
    startTransition(() => router.push(`${basePath}${filtersToSearch(next)}`, { scroll: false }));
  }
  const set = (patch: Partial<Filters>) => go(withFilters(filters, patch));

  const groups = dictionaries?.request_groups ?? [];
  const visibleGroups = filters.format ? groups.filter((g) => g.format === filters.format) : groups;
  const agePreset = AGE_PRESETS.findIndex((a) => a.min === filters.ageMin && a.max === filters.ageMax);

  return (
    <div className={clsx("grid gap-4", pending && "opacity-70")} aria-busy={pending}>
      <form
        role="search"
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          set({ q: q.trim(), sort: q.trim() ? filters.sort : filters.sort === "relevance" ? "nearest" : filters.sort });
        }}
      >
        <Input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Имя, запрос или подход"
          aria-label="Поиск по психологам"
          maxLength={100}
        />
        <Button type="submit" variant="secondary">
          Найти
        </Button>
      </form>

      <details className="group rounded-2xl border border-line bg-surface lg:open:bg-surface" open={count > 0 || undefined}>
        <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 font-medium">
          <span>
            Фильтры{count > 0 && <span className="ml-2 rounded-full bg-brand px-2 py-0.5 text-xs text-on-brand">{count}</span>}
          </span>
          <span className="text-sm text-muted group-open:hidden">Показать</span>
          <span className="hidden text-sm text-muted group-open:inline">Свернуть</span>
        </summary>

        <div className="grid gap-6 border-t border-line px-5 py-5">
          <FilterRow title="Формат">
            <Chip active={filters.format === ""} onClick={() => set({ format: "" })}>
              Любой
            </Chip>
            <Chip active={filters.format === "individual"} onClick={() => set({ format: "individual" })}>
              Индивидуальная, 50 мин
            </Chip>
            <Chip active={filters.format === "pair"} onClick={() => set({ format: "pair" })}>
              Для пары, 90 мин
            </Chip>
          </FilterRow>

          {(dictionaries?.price_categories.length ?? 0) > 0 && (
            <FilterRow title="Стоимость сессии">
              {dictionaries!.price_categories.map((c) => (
                <Chip key={c.code} active={filters.price.includes(c.code)} onClick={() => set({ price: toggleValue(filters.price, c.code) })}>
                  {c.title}
                </Chip>
              ))}
            </FilterRow>
          )}

          <div className="grid gap-4 sm:grid-cols-3">
            <label className="grid gap-1.5 text-sm font-medium text-ink-2">
              Свободное время
              <Select value={filters.within ?? ""} onChange={(e) => set({ within: e.target.value ? Number(e.target.value) : null })}>
                <option value="">Неважно</option>
                {WITHIN_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </label>
            <label className="grid gap-1.5 text-sm font-medium text-ink-2">
              Пол психолога
              <Select value={filters.gender} onChange={(e) => set({ gender: e.target.value as Filters["gender"] })}>
                <option value="">Неважно</option>
                <option value="female">Женщина</option>
                <option value="male">Мужчина</option>
              </Select>
            </label>
            <label className="grid gap-1.5 text-sm font-medium text-ink-2">
              Возраст психолога
              <Select
                value={agePreset >= 0 ? agePreset : "custom"}
                onChange={(e) => {
                  const preset = AGE_PRESETS[Number(e.target.value)];
                  if (preset) set({ ageMin: preset.min, ageMax: preset.max });
                }}
              >
                {AGE_PRESETS.map((a, i) => (
                  <option key={a.label} value={i}>
                    {a.label}
                  </option>
                ))}
                {agePreset < 0 && (
                  <option value="custom" disabled>
                    {filters.ageMin ?? 18}–{filters.ageMax ?? "…"}
                  </option>
                )}
              </Select>
            </label>
          </div>

          {visibleGroups.length > 0 && (
            <fieldset className="grid gap-3">
              <legend className="mb-2 text-sm font-medium text-ink-2">Запросы{filters.requests.length > 0 && ` · выбрано ${filters.requests.length}`}</legend>
              {visibleGroups.map((g) => (
                <details key={g.slug} className="rounded-xl bg-ground px-4 py-3" open={g.requests.some((r) => filters.requests.includes(r.slug)) || undefined}>
                  <summary className="cursor-pointer text-[15px] font-medium">{g.title}</summary>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {g.requests.map((r) => (
                      <Chip key={r.id} active={filters.requests.includes(r.slug)} onClick={() => set({ requests: toggleValue(filters.requests, r.slug) })}>
                        {r.title}
                        {r.age_label ? ` (${r.age_label})` : ""}
                      </Chip>
                    ))}
                  </div>
                </details>
              ))}
            </fieldset>
          )}

          {(dictionaries?.approaches.length ?? 0) > 0 && (
            <FilterRow title="Подходы">
              {dictionaries!.approaches.map((a) => (
                <Chip key={a.slug} active={filters.approaches.includes(a.slug)} onClick={() => set({ approaches: toggleValue(filters.approaches, a.slug) })}>
                  {a.title}
                </Chip>
              ))}
            </FilterRow>
          )}

          {count > 0 && (
            <div>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  setQ("");
                  go({ ...EMPTY_FILTERS, sort: filters.sort === "relevance" ? "nearest" : filters.sort });
                }}
              >
                Сбросить фильтры
              </Button>
            </div>
          )}
        </div>
      </details>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted" aria-live="polite">
          {pending ? "Ищем…" : total === null ? "" : total === 0 ? "Никого не нашли" : `Найдено: ${total}`}
        </p>
        <label className="flex items-center gap-2 text-sm text-ink-2">
          Сортировка
          <Select className="h-9 w-auto text-sm" value={filters.sort} onChange={(e) => set({ sort: e.target.value as Filters["sort"] })}>
            {SORTS.filter((s) => s !== "relevance" || filters.q).map((s) => (
              <option key={s} value={s}>
                {SORT_LABELS[s]}
              </option>
            ))}
          </Select>
        </label>
      </div>
    </div>
  );
}

function FilterRow({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="grid gap-2">
      <p className="text-sm font-medium text-ink-2">{title}</p>
      <div className="flex flex-wrap gap-2">{children}</div>
    </div>
  );
}
