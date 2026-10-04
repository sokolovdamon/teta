"use client";

import Link from "next/link";
import { useState } from "react";
import { Alert, Badge, Button, Checkbox, EmptyState, Field, Input, Select, Table, type Column } from "@/components/ui";
import { rub } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { STATUS, STATUS_TONE } from "./labels";
import { sessionDateTime } from "./time";
import type { AdminSession, SessionStatus } from "./types";
import { useResource } from "./useResource";

const ADMIN_TZ = "Europe/Moscow";

/** ADM-04: sessions with filters by status, date, psychologist and client. Times in Moscow time. */
export function AdminSessions({ initialPsychologist }: { initialPsychologist?: string | null }) {
  const [filters, setFilters] = useState({ status: "", date_from: "", date_to: "", q: "", psychologist_id: initialPsychologist ?? "", needs_attention: false });
  const [applied, setApplied] = useState(filters);
  const [page, setPage] = useState(1);
  const res = useResource<Paginated<AdminSession>>("/admin/sessions", {
    status: applied.status,
    date_from: applied.date_from,
    date_to: applied.date_to,
    q: applied.q,
    psychologist_id: applied.psychologist_id,
    needs_attention: applied.needs_attention ? 1 : undefined,
    page,
  });

  const columns: Column<AdminSession>[] = [
    {
      key: "when",
      title: "Время (МСК)",
      render: (s) => (
        <Link href={`/admin/sessions/${s.id}`} className="font-medium text-brand hover:underline">
          {sessionDateTime(s.starts_at, ADMIN_TZ)}
        </Link>
      ),
    },
    { key: "client", title: "Клиент", render: (s) => (s.client ? <span>{s.client.name}<br /><span className="text-sm text-muted">{s.client.email}</span></span> : "—") },
    { key: "psy", title: "Психолог", render: (s) => s.psychologist?.name ?? "—" },
    { key: "format", title: "Формат", render: (s) => s.format_label },
    {
      key: "status",
      title: "Статус",
      render: (s) => (
        <span className="grid gap-1">
          <Badge tone={STATUS_TONE[s.status]}>{STATUS[s.status]}</Badge>
          {s.client_choice === "pending" && <Badge tone="warning">Ждёт выбора клиента</Badge>}
        </span>
      ),
    },
    { key: "money", title: "Оплата", render: (s) => <span>{s.payment_status}<br /><span className="text-sm text-muted">{rub(s.price)}</span></span> },
  ];

  return (
    <div className="grid gap-4">
      <form
        className="grid gap-3 rounded-2xl border border-line bg-surface p-4 sm:grid-cols-2 lg:grid-cols-6"
        onSubmit={(e) => {
          e.preventDefault();
          setPage(1);
          setApplied(filters);
        }}
      >
        <Field label="Поиск" className="lg:col-span-2">
          <Input value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} placeholder="Email или имя клиента, имя психолога" />
        </Field>
        <Field label="Статус">
          <Select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
            <option value="">Все</option>
            {(Object.keys(STATUS) as SessionStatus[]).map((k) => (
              <option key={k} value={k}>
                {STATUS[k]}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="С даты">
          <Input type="date" value={filters.date_from} onChange={(e) => setFilters({ ...filters, date_from: e.target.value })} />
        </Field>
        <Field label="По дату">
          <Input type="date" value={filters.date_to} onChange={(e) => setFilters({ ...filters, date_to: e.target.value })} />
        </Field>
        <div className="flex items-end">
          <Button type="submit" className="w-full">
            Показать
          </Button>
        </div>
        <Checkbox
          className="lg:col-span-6"
          label="Требуют внимания: идут без итога или ждут выбора клиента"
          checked={filters.needs_attention}
          onChange={(e) => setFilters({ ...filters, needs_attention: e.target.checked })}
        />
      </form>
      {applied.psychologist_id && (
        <p className="text-sm text-ink-2">
          Фильтр по психологу.{" "}
          <button type="button" className="text-brand underline" onClick={() => (setFilters({ ...filters, psychologist_id: "" }), setApplied({ ...applied, psychologist_id: "" }))}>
            Сбросить
          </button>
        </p>
      )}
      {res.error && <Alert tone="danger">{res.error}</Alert>}
      {!res.data && !res.error && <p className="text-muted">Загрузка…</p>}
      {res.data && <Table columns={columns} rows={res.data.data} rowKey={(s) => s.id} empty={<EmptyState title="Сессий не найдено" />} />}
      {res.data && res.data.meta.last_page > 1 && (
        <div className="flex items-center gap-3">
          <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            Назад
          </Button>
          <span className="text-sm text-muted">
            {res.data.meta.current_page} из {res.data.meta.last_page} · всего {res.data.meta.total}
          </span>
          <Button size="sm" variant="secondary" disabled={page >= res.data.meta.last_page} onClick={() => setPage(page + 1)}>
            Вперёд
          </Button>
        </div>
      )}
    </div>
  );
}
