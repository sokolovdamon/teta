"use client";

import Link from "next/link";
import { useState, type ReactNode } from "react";
import { Alert, Badge, Button, Card, Chip, EmptyState, Field, Input, Select, Table, buttonClass, type Column } from "@/components/ui";
import { date, dateTime, plural, rub } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { statsExportUrl } from "./api";
import { ACCRUAL_STATUS_OPTIONS, PERIOD_PRESETS, accrualTone, presetRange, signed, type PeriodPreset } from "./labels";
import type { AccrualRow, StatsSummary } from "./types";
import { useResource } from "./useResource";

/** PRO-08: statistics and income — sessions held, accruals with statuses and reasons, payouts, CSV report. */
export function ProStats({ timezone }: { timezone: string }) {
  const [preset, setPreset] = useState<PeriodPreset | null>("month");
  const [range, setRange] = useState(() => presetRange("month"));
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);

  const summary = useResource<{ data: StatsSummary }>("/pro/stats", range);
  const accruals = useResource<Paginated<AccrualRow>>("/pro/stats/accruals", { ...range, status, page, per_page: 20 });

  function choosePreset(p: PeriodPreset) {
    setPreset(p);
    setRange(presetRange(p));
    setPage(1);
  }

  function setDate(key: "from" | "to", value: string) {
    if (!value) return;
    setPreset(null);
    setRange((r) => ({ ...r, [key]: value }));
    setPage(1);
  }

  const s = summary.data?.data;

  return (
    <div className="grid gap-6">
      <Card className="grid gap-4">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Период">
          {PERIOD_PRESETS.map((p) => (
            <Chip key={p.value} active={preset === p.value} onClick={() => choosePreset(p.value)}>
              {p.label}
            </Chip>
          ))}
        </div>
        <div className="flex flex-wrap items-end gap-4">
          <Field label="С" className="w-44">
            <Input type="date" value={range.from} max={range.to} onChange={(e) => setDate("from", e.target.value)} />
          </Field>
          <Field label="По" className="w-44">
            <Input type="date" value={range.to} min={range.from} onChange={(e) => setDate("to", e.target.value)} />
          </Field>
          <a href={statsExportUrl(range.from, range.to)} className={buttonClass("secondary")} download>
            Скачать отчёт (CSV)
          </a>
        </div>
      </Card>

      {summary.error && (
        <Alert tone="danger" title="Статистика не загрузилась">
          {summary.error}{" "}
          <button type="button" className="text-brand underline" onClick={summary.reload}>
            Повторить
          </button>
        </Alert>
      )}

      <section aria-label="Итоги периода" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi title="Проведено сессий" value={s ? String(s.sessions.held) : null} hint={s ? sessionsHint(s) : null} />
        <Kpi title="Начислено" value={s ? rub(s.totals.net) : null} hint={s && s.totals.reversed > 0 ? `${rub(s.totals.accrued)} минус сторно ${rub(s.totals.reversed)}` : "за вычетом комиссии платформы"} />
        <Kpi title="Выплачено на карту" value={s ? rub(s.totals.paid_out) : null} hint="выплаты, выполненные за период" />
        <Kpi
          title="Доступно к выплате"
          value={s ? rub(s.balance.available) : null}
          hint={
            <Link href="/pro/payouts" className="text-brand">
              Вывод средств →
            </Link>
          }
        />
      </section>

      <section className="grid gap-3">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h2 className="text-lg font-semibold">Начисления</h2>
          <Field label="Статус" className="w-64">
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Все статусы</option>
              {ACCRUAL_STATUS_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        {accruals.error && (
          <Alert tone="danger">
            {accruals.error}{" "}
            <button type="button" className="text-brand underline" onClick={accruals.reload}>
              Повторить
            </button>
          </Alert>
        )}
        {!accruals.data && accruals.loading && <p className="text-muted">Загрузка…</p>}
        {accruals.data && (
          <>
            <Table
              columns={accrualColumns(timezone)}
              rows={accruals.data.data}
              rowKey={(r) => r.id}
              empty={<EmptyState title="Начислений за период нет" description="Начисления появляются после проведённой сессии, неявки клиента или поздней отмены." />}
            />
            {accruals.data.meta.last_page > 1 && (
              <div className="flex items-center gap-3">
                <Button variant="secondary" size="sm" disabled={page <= 1 || accruals.loading} onClick={() => setPage((p) => p - 1)}>
                  Назад
                </Button>
                <span className="text-sm text-muted">
                  Страница {accruals.data.meta.current_page} из {accruals.data.meta.last_page}
                </span>
                <Button variant="secondary" size="sm" disabled={page >= accruals.data.meta.last_page || accruals.loading} onClick={() => setPage((p) => p + 1)}>
                  Дальше
                </Button>
              </div>
            )}
          </>
        )}
      </section>
    </div>
  );
}

function sessionsHint(s: StatsSummary): string {
  const parts: string[] = [];
  if (s.sessions.client_no_show) parts.push(`${s.sessions.client_no_show} ${plural(s.sessions.client_no_show, ["неявка", "неявки", "неявок"])} клиента`);
  if (s.sessions.cancelled_by_client) parts.push(`${s.sessions.cancelled_by_client} ${plural(s.sessions.cancelled_by_client, ["отмена", "отмены", "отмен"])} клиентом`);
  if (s.sessions.upcoming) parts.push(`${s.sessions.upcoming} впереди`);
  return parts.join(", ") || "за выбранный период";
}

function Kpi({ title, value, hint }: { title: string; value: string | null; hint?: ReactNode }) {
  return (
    <Card className="grid gap-1">
      <p className="text-sm text-muted">{title}</p>
      <p className="text-2xl font-semibold tracking-tight">{value ?? <span className="inline-block h-7 w-24 animate-pulse rounded-lg bg-sunken" aria-label="Загрузка" />}</p>
      {hint && <div className="text-sm text-ink-2">{hint}</div>}
    </Card>
  );
}

function accrualColumns(timezone: string): Column<AccrualRow>[] {
  return [
    { key: "date", title: "Дата", render: (r) => <span className="whitespace-nowrap">{date(r.occurred_at, timezone)}</span> },
    {
      key: "kind",
      title: "Начисление",
      render: (r) => (
        <div className="grid gap-0.5">
          <span className="font-medium">{r.kind_label}</span>
          {r.session && (
            <span className="text-sm text-muted">
              {dateTime(r.session.starts_at, timezone)} · {r.session.format === "pair" ? "парная" : "индивидуальная"}
              {r.session.client_name ? ` · ${r.session.client_name}` : ""}
              {r.session.corporate ? " · корпоративная" : ""}
            </span>
          )}
        </div>
      ),
    },
    {
      key: "amount",
      title: "Сумма",
      className: "text-right",
      render: (r) => (
        <div className="grid justify-items-end gap-0.5 whitespace-nowrap">
          <span className="font-medium">{signed(r.net, (k) => rub(k))}</span>
          {r.reversed_amount > 0 && <span className="text-sm text-muted">из {rub(r.amount)}, сторно {rub(r.reversed_amount)}</span>}
          {r.kind !== "correction" && <span className="text-xs text-muted">{100 - r.commission_percent} % от {rub(r.base_amount)}</span>}
        </div>
      ),
    },
    { key: "status", title: "Статус", render: (r) => <Badge tone={accrualTone[r.status]}>{r.status_label}</Badge> },
    {
      key: "reason",
      title: "Причина",
      render: (r) => (
        <div className="grid max-w-sm gap-1 text-sm text-ink-2">
          {r.adjustments.length > 0 ? (
            r.adjustments.map((a, i) => (
              <span key={i}>
                {a.type === "correction" ? "Корректировка" : "Сторно"} {rub(a.amount)}: {a.reason}
              </span>
            ))
          ) : (
            <span>{r.reason ?? "—"}</span>
          )}
          {r.payout?.paid_at && <span className="text-muted">Выплачено {date(r.payout.paid_at, timezone)}</span>}
        </div>
      ),
    },
  ];
}
