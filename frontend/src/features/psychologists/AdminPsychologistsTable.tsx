"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert, Badge, Button, Chip, EmptyState, Input, Select, Table, type Column } from "@/components/ui";
import { Avatar } from "@/features/catalog/Avatar";
import type { Dictionaries } from "@/features/catalog/types";
import { api } from "@/lib/api";
import { date, rub } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { ACTIVITY, QUALIFICATION, VIDEO, WORK } from "./labels";
import type { AdminRow } from "./types";

type Response = Paginated<AdminRow> & { counts: { in_review: number; changes: number; video: number } };

const FILTER_KEYS = ["q", "qualification_status", "activity_status", "work_status", "price_category", "moderation", "sort", "page"] as const;

/** ADM-03 list: qualification, activity and work statuses, price categories, moderation queues. */
export function AdminPsychologistsTable({ categories }: { categories: Dictionaries["price_categories"] }) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const filters = Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? ""])) as Record<(typeof FILTER_KEYS)[number], string>;
  const [q, setQ] = useState(filters.q);
  const [state, setState] = useState<{ status: "loading" } | { status: "error" } | { status: "ok"; data: Response }>({ status: "loading" });
  const query = params.toString();

  useEffect(() => {
    const controller = new AbortController();
    const search = new URLSearchParams(query);
    api<Response>("/admin/psychologists", {
      query: Object.fromEntries([...search.entries()].filter(([, v]) => v !== "")),
      signal: controller.signal,
    })
      .then((data) => setState({ status: "ok", data }))
      .catch((e: unknown) => {
        if ((e as { name?: string })?.name !== "AbortError") setState({ status: "error" });
      });
    return () => controller.abort();
  }, [query]);

  function set(patch: Partial<typeof filters>) {
    const next = new URLSearchParams();
    for (const [k, v] of Object.entries({ ...filters, page: "", ...patch })) if (v) next.set(k, v);
    router.push(`${pathname}${next.toString() ? `?${next}` : ""}`, { scroll: false });
  }

  const counts = state.status === "ok" ? state.data.counts : null;
  const columns: Column<AdminRow>[] = [
    {
      key: "name",
      title: "Психолог",
      render: (p) => (
        <Link href={`/admin/psychologists/${p.id}`} className="flex items-center gap-3 hover:text-brand">
          <Avatar photoUrl={p.photo_url} firstName={p.name.split(" ")[0]} lastName={p.name.split(" ")[1]} size={40} className="rounded-xl" />
          <span className="grid">
            <span className="font-medium">{p.name}</span>
            <span className="text-sm text-muted">{p.email}</span>
          </span>
        </Link>
      ),
    },
    {
      key: "qualification",
      title: "Квалификация",
      render: (p) => (
        <span className="grid justify-items-start gap-1">
          <Badge tone={QUALIFICATION[p.qualification_status].tone}>{QUALIFICATION[p.qualification_status].label}</Badge>
          {p.qualification_status === "in_review" && p.qualification_submitted_at && <span className="text-xs text-muted">с {date(p.qualification_submitted_at)}</span>}
        </span>
      ),
    },
    {
      key: "status",
      title: "Работа и активность",
      render: (p) => (
        <span className="grid justify-items-start gap-1">
          <Badge tone={WORK[p.work_status].tone}>{WORK[p.work_status].label}</Badge>
          {p.activity_status && <span className="text-xs text-muted">{ACTIVITY[p.activity_status].label}</span>}
          {!p.is_published && p.qualification_status === "approved" && <span className="text-xs text-warning">не опубликован</span>}
        </span>
      ),
    },
    {
      key: "price",
      title: "Цена",
      render: (p) => (
        <span className="grid">
          <span className="num">{rub(p.price_individual)}</span>
          {p.price_category && <span className="text-xs text-muted">{p.price_category.title}</span>}
        </span>
      ),
    },
    {
      key: "moderation",
      title: "Модерация",
      render: (p) => (
        <span className="flex flex-wrap gap-1">
          {p.has_pending_changes && <Badge tone="warning">Изменения профиля</Badge>}
          {p.video_status === "pending" && <Badge tone="warning">{`Видео: ${VIDEO.pending.label.toLowerCase()}`}</Badge>}
          {p.documents_pending > 0 && p.qualification_status === "approved" && <Badge tone="warning">{`Документы: ${p.documents_pending}`}</Badge>}
        </span>
      ),
    },
  ];

  return (
    <div className="grid gap-5">
      <div className="flex flex-wrap gap-2">
        <Chip active={filters.moderation === "" && filters.qualification_status === ""} onClick={() => set({ moderation: "", qualification_status: "" })}>
          Все
        </Chip>
        <Chip active={filters.qualification_status === "in_review"} onClick={() => set({ qualification_status: "in_review", moderation: "" })}>
          Заявки на проверку{counts ? ` · ${counts.in_review}` : ""}
        </Chip>
        <Chip active={filters.moderation === "changes"} onClick={() => set({ moderation: "changes", qualification_status: "" })}>
          Изменения профилей{counts ? ` · ${counts.changes}` : ""}
        </Chip>
        <Chip active={filters.moderation === "video"} onClick={() => set({ moderation: "video", qualification_status: "" })}>
          Видеовизитки{counts ? ` · ${counts.video}` : ""}
        </Chip>
        <Chip active={filters.moderation === "documents"} onClick={() => set({ moderation: "documents", qualification_status: "" })}>
          Новые документы
        </Chip>
      </div>

      <div className="grid gap-3 rounded-2xl border border-line bg-surface p-4 md:grid-cols-[2fr_repeat(4,1fr)]">
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            set({ q: q.trim() });
          }}
        >
          <Input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Имя, email или адрес профиля" aria-label="Поиск" />
          <Button type="submit" variant="secondary">
            Найти
          </Button>
        </form>
        <Select aria-label="Квалификация" value={filters.qualification_status} onChange={(e) => set({ qualification_status: e.target.value })}>
          <option value="">Любая квалификация</option>
          {Object.entries(QUALIFICATION).map(([k, v]) => (
            <option key={k} value={k}>
              {v.label}
            </option>
          ))}
        </Select>
        <Select aria-label="Активность" value={filters.activity_status} onChange={(e) => set({ activity_status: e.target.value })}>
          <option value="">Любая активность</option>
          {(["grace", "active_not_met", "active_met", "inactive"] as const).map((k) => (
            <option key={k} value={k}>
              {ACTIVITY[k].label}
            </option>
          ))}
        </Select>
        <Select aria-label="Работа" value={filters.work_status} onChange={(e) => set({ work_status: e.target.value })}>
          <option value="">Любой статус работы</option>
          {Object.entries(WORK).map(([k, v]) => (
            <option key={k} value={k}>
              {v.label}
            </option>
          ))}
        </Select>
        <Select aria-label="Ценовая категория" value={filters.price_category} onChange={(e) => set({ price_category: e.target.value })}>
          <option value="">Любая цена</option>
          {categories.map((c) => (
            <option key={c.code} value={c.code}>
              {c.title}
            </option>
          ))}
        </Select>
      </div>

      {state.status === "loading" && <p className="text-muted">Загрузка…</p>}
      {state.status === "error" && <Alert tone="danger">Не удалось загрузить список психологов.</Alert>}
      {state.status === "ok" && (
        <>
          <p className="text-sm text-muted">Найдено: {state.data.meta.total}</p>
          <Table
            columns={columns}
            rows={state.data.data}
            rowKey={(p) => p.id}
            empty={<EmptyState title="Никого не нашли" description="Измените фильтры или поисковый запрос." />}
          />
          {state.data.meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-3">
              <Button variant="secondary" size="sm" disabled={state.data.meta.current_page <= 1} onClick={() => set({ page: String(state.data.meta.current_page - 1) })}>
                ← Назад
              </Button>
              <span className="text-sm text-muted">
                {state.data.meta.current_page} из {state.data.meta.last_page}
              </span>
              <Button
                variant="secondary"
                size="sm"
                disabled={state.data.meta.current_page >= state.data.meta.last_page}
                onClick={() => set({ page: String(state.data.meta.current_page + 1) })}
              >
                Дальше →
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
