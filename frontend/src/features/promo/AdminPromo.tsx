"use client";

import { useMemo, useState, type ReactNode } from "react";
import { Alert, Badge, Button, Card, EmptyState, Field, Input, Modal, Select, Table, Textarea, buttonClass, type Column } from "@/components/ui";
import { date, dateTime, rub } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { errorMessage, useResource } from "@/features/payouts/useResource";
import { batchExportUrl, createBatch, createCode, deactivateBatch, deactivateCode, publishBatch, publishCode, saveReferralSettings, updateCode } from "./api";
import { STATUS_OPTIONS, TYPE_OPTIONS, describeRestrictions, fromCode, promoTone } from "./form";
import { PromoForm } from "./PromoForm";
import type { Batch, Lookups, Overview, PromoCodeRow, PromoDetail, ReferralSettings } from "./types";

type Tab = "codes" | "batches" | "stats" | "referral";
const TABS: { value: Tab; label: string }[] = [
  { value: "codes", label: "Промокоды" },
  { value: "batches", label: "Пакеты кодов" },
  { value: "stats", label: "Статистика" },
  { value: "referral", label: "«Пригласи друга»" },
];

type Permissions = { manage: boolean; export: boolean };
type Notice = { tone: "success" | "danger"; text: string } | null;

/** ADM-09 (Э8): promo codes and batches, statistics, referral program settings. */
export function AdminPromo({ timezone, permissions }: { timezone: string; permissions: Permissions }) {
  const [tab, setTab] = useState<Tab>("codes");
  const lookups = useResource<{ data: Lookups }>("/admin/promo/lookups");
  const names = useMemo(
    () => ({
      psychologists: Object.fromEntries((lookups.data?.data.psychologists ?? []).map((p) => [p.id, p.name])),
      categories: Object.fromEntries((lookups.data?.data.price_categories ?? []).map((c) => [c.id, c.title])),
    }),
    [lookups.data],
  );
  const ctx = { timezone, permissions, lookups: lookups.data?.data ?? null, names };

  return (
    <div className="grid gap-6">
      <nav className="flex gap-1 overflow-x-auto border-b border-line" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.value}
            type="button"
            role="tab"
            aria-selected={tab === t.value}
            onClick={() => setTab(t.value)}
            className={
              tab === t.value
                ? "-mb-px whitespace-nowrap border-b-2 border-brand px-3 py-2.5 text-[15px] font-medium text-brand"
                : "-mb-px whitespace-nowrap border-b-2 border-transparent px-3 py-2.5 text-[15px] text-ink-2 hover:text-ink"
            }
          >
            {t.label}
          </button>
        ))}
      </nav>
      {tab === "codes" && <Codes {...ctx} />}
      {tab === "batches" && <Batches {...ctx} />}
      {tab === "stats" && <Stats />}
      {tab === "referral" && <Referral canManage={permissions.manage} />}
    </div>
  );
}

type Ctx = { timezone: string; permissions: Permissions; lookups: Lookups | null; names: { psychologists: Record<string, string>; categories: Record<string, string> } };

function period(from: string | null, until: string | null, timezone: string): string {
  if (!from && !until) return "Без срока";
  if (!from) return `до ${dateTime(until, timezone)}`;
  if (!until) return `с ${dateTime(from, timezone)}`;
  return `${dateTime(from, timezone)} — ${dateTime(until, timezone)}`;
}

function Codes({ timezone, permissions, lookups, names }: Ctx) {
  const [filters, setFilters] = useState({ q: "", status: "", type: "", kind: "", source: "" });
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const list = useResource<Paginated<PromoCodeRow>>("/admin/promo/codes", { ...filters, q: search, page });
  const [mode, setMode] = useState<{ kind: "create" } | { kind: "edit"; code: PromoDetail } | null>(null);
  const [openId, setOpenId] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice>(null);

  const setFilter = (key: keyof typeof filters, value: string) => {
    setFilters((f) => ({ ...f, [key]: value }));
    setPage(1);
  };

  const columns: Column<PromoCodeRow>[] = [
    {
      key: "code",
      title: "Код",
      render: (c) => (
        <div className="grid">
          <span className="font-mono font-semibold tracking-wide">{c.code}</span>
          {c.title && <span className="text-sm text-muted">{c.title}</span>}
        </div>
      ),
    },
    {
      key: "discount",
      title: "Скидка",
      render: (c) => (
        <div className="grid">
          <span className="font-medium">{c.discount_label}</span>
          <span className="text-sm text-muted">{c.type_label}</span>
        </div>
      ),
    },
    {
      key: "kind",
      title: "Вид",
      render: (c) => (
        <div className="grid text-sm">
          <span>{c.kind_label}</span>
          <span className="text-muted">{c.owner ? c.owner.email : c.source_label}</span>
        </div>
      ),
    },
    { key: "period", title: "Период", render: (c) => <span className="text-sm">{period(c.valid_from, c.valid_until, timezone)}</span> },
    { key: "uses", title: "Применений", render: (c) => `${c.stats.applied}${c.total_limit ? ` / ${c.total_limit}` : ""}` },
    {
      key: "stats",
      title: "Скидки и конверсия",
      render: (c) => (
        <span className="text-sm">
          {rub(c.stats.discount_sum)}
          {c.stats.conversion !== null ? ` · ${c.stats.conversion} %` : ""}
        </span>
      ),
    },
    { key: "status", title: "Статус", render: (c) => <Badge tone={promoTone[c.status]}>{c.status_label}</Badge> },
    {
      key: "open",
      title: "",
      render: (c) => (
        <Button variant="ghost" size="sm" onClick={() => setOpenId(openId === c.id ? null : c.id)}>
          {openId === c.id ? "Скрыть" : "Открыть"}
        </Button>
      ),
    },
  ];

  return (
    <div className="grid gap-4">
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {mode ? (
        <PromoForm
          key={mode.kind === "edit" ? mode.code.id : "new"}
          mode={mode.kind}
          initial={mode.kind === "edit" ? fromCode(mode.code) : undefined}
          editable={mode.kind === "edit" ? mode.code.editable : undefined}
          lookups={lookups}
          onCancel={() => setMode(null)}
          onSubmit={async (payload) => {
            const saved = mode.kind === "edit" ? await updateCode(mode.code.id, payload) : await createCode(payload);
            setNotice({ tone: "success", text: mode.kind === "edit" ? `Промокод ${saved.code} сохранён.` : `Промокод ${saved.code} создан: ${saved.status_label.toLowerCase()}.` });
            setMode(null);
            setOpenId(saved.id);
            list.reload();
          }}
        />
      ) : (
        <div className="flex flex-wrap items-end gap-3">
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              setSearch(filters.q.trim());
              setPage(1);
            }}
          >
            <Field label="Код или название" className="w-56">
              <Input value={filters.q} onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))} />
            </Field>
            <Button type="submit" variant="secondary">
              Найти
            </Button>
          </form>
          <Field label="Статус" className="w-44">
            <Select value={filters.status} onChange={(e) => setFilter("status", e.target.value)}>
              <option value="">Все</option>
              {STATUS_OPTIONS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Тип" className="w-52">
            <Select value={filters.type} onChange={(e) => setFilter("type", e.target.value)}>
              <option value="">Все</option>
              {TYPE_OPTIONS.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Вид" className="w-40">
            <Select value={filters.kind} onChange={(e) => setFilter("kind", e.target.value)}>
              <option value="">Все</option>
              <option value="mass">Массовые</option>
              <option value="individual">Индивидуальные</option>
            </Select>
          </Field>
          <Field label="Источник" className="w-48">
            <Select value={filters.source} onChange={(e) => setFilter("source", e.target.value)}>
              <option value="">Кроме пакетов</option>
              <option value="admin">Администратор</option>
              <option value="referral">«Пригласи друга»</option>
              <option value="batch">Пакеты</option>
            </Select>
          </Field>
          {permissions.manage && (
            <Button className="ml-auto" onClick={() => setMode({ kind: "create" })}>
              Создать промокод
            </Button>
          )}
        </div>
      )}

      {list.error && <Alert tone="danger">{list.error}</Alert>}
      {!list.data && list.loading && <p className="text-muted">Загрузка…</p>}
      {list.data && (
        <>
          <Table columns={columns} rows={list.data.data} rowKey={(c) => c.id} empty={<EmptyState title="Промокодов не найдено" description="Измените фильтры или создайте новый промокод." />} />
          {list.data.meta.last_page > 1 && (
            <Pager page={list.data.meta.current_page} last={list.data.meta.last_page} onPage={setPage} />
          )}
        </>
      )}
      {openId && !mode && (
        <CodePanel
          key={openId}
          id={openId}
          timezone={timezone}
          canManage={permissions.manage}
          names={names}
          onEdit={(code) => setMode({ kind: "edit", code })}
          onChanged={list.reload}
        />
      )}
    </div>
  );
}

function Pager({ page, last, onPage }: { page: number; last: number; onPage: (p: number) => void }) {
  return (
    <div className="flex items-center gap-3">
      <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        Назад
      </Button>
      <span className="text-sm text-muted">
        Страница {page} из {last}
      </span>
      <Button variant="secondary" size="sm" disabled={page >= last} onClick={() => onPage(page + 1)}>
        Дальше
      </Button>
    </div>
  );
}

function CodePanel({
  id,
  timezone,
  canManage,
  names,
  onEdit,
  onChanged,
}: {
  id: string;
  timezone: string;
  canManage: boolean;
  names: Ctx["names"];
  onEdit: (code: PromoDetail) => void;
  onChanged: () => void;
}) {
  const detail = useResource<{ data: PromoDetail }>(`/admin/promo/codes/${id}`);
  const [notice, setNotice] = useState<Notice>(null);
  const [busy, setBusy] = useState(false);
  const [deactivating, setDeactivating] = useState(false);
  const [reason, setReason] = useState("");

  async function run(action: () => Promise<PromoDetail>, text: string) {
    setBusy(true);
    setNotice(null);
    try {
      detail.setData({ data: await action() });
      setNotice({ tone: "success", text });
      onChanged();
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    } finally {
      setBusy(false);
    }
  }

  const c = detail.data?.data;
  if (detail.error && !c) return <Alert tone="danger">{detail.error}</Alert>;
  if (!c) return <p className="text-muted">Загрузка промокода…</p>;
  const restrictions = describeRestrictions(c.restrictions, names);

  return (
    <Card className="grid gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="grid gap-1">
          <h2 className="font-mono text-xl font-semibold tracking-wide">{c.code}</h2>
          <p className="text-ink-2">
            {c.discount_label} · {c.kind_label.toLowerCase()} · {c.source_label.toLowerCase()} <Badge tone={promoTone[c.status]}>{c.status_label}</Badge>
          </p>
        </div>
        {canManage && (
          <div className="flex flex-wrap gap-2">
            {c.editable.length > 0 && (
              <Button variant="secondary" onClick={() => onEdit(c)}>
                Изменить
              </Button>
            )}
            {c.status === "draft" && (
              <Button loading={busy} onClick={() => run(() => publishCode(c.id), "Условия опубликованы.")}>
                Опубликовать
              </Button>
            )}
            {["draft", "scheduled", "active"].includes(c.status) && (
              <Button variant="danger" onClick={() => setDeactivating(true)}>
                Деактивировать
              </Button>
            )}
          </div>
        )}
      </div>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {c.deactivation_reason && <Alert tone="warning">Деактивирован: {c.deactivation_reason}</Alert>}

      <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
        <Term title="Период">{period(c.valid_from, c.valid_until, timezone)}</Term>
        <Term title="Лимиты">
          {c.total_limit ? `всего ${c.total_limit}` : "без общего лимита"}, {c.per_user_limit ? `на клиента ${c.per_user_limit}` : "без лимита на клиента"}
        </Term>
        <Term title="Минимальная цена">{c.min_amount ? rub(c.min_amount) : "—"}</Term>
        <Term title="Ограничения">{restrictions.length ? restrictions.join("; ") : "нет"}</Term>
        {c.owner && (
          <Term title="Владелец">
            {c.owner.name}, {c.owner.email}
          </Term>
        )}
        {c.batch && <Term title="Пакет">{c.batch.title}</Term>}
        {c.description && <Term title="Условия акции">{c.description}</Term>}
      </dl>

      <div className="grid gap-3 sm:grid-cols-4">
        <Stat title="Применений" value={String(c.stats.applied)} />
        <Stat title="Сумма скидок" value={rub(c.stats.discount_sum)} />
        <Stat title="Конверсия" value={c.stats.conversion === null ? "—" : `${c.stats.conversion} %`} hint="оплаченные сессии / все брони" />
        <Stat title="Ждут списания" value={String(c.stats.pending)} hint={`восстановлено: ${c.stats.restored}`} />
      </div>

      <h3 className="font-semibold">Применения</h3>
      <Table
        columns={[
          { key: "user", title: "Клиент", render: (r) => (r.user ? `${r.user.name}, ${r.user.email}` : "—") },
          { key: "session", title: "Сессия", render: (r) => dateTime(r.session_starts_at, timezone) },
          { key: "discount", title: "Скидка", className: "text-right", render: (r) => rub(r.discount_amount) },
          {
            key: "status",
            title: "Статус",
            render: (r) => <Badge tone={r.status === "applied" ? "success" : r.status === "reserved" ? "brand" : "neutral"}>{r.status === "applied" ? "Применён" : r.status === "reserved" ? "Забронирован" : "Восстановлен"}</Badge>,
          },
          { key: "at", title: "Когда", render: (r) => dateTime(r.applied_at ?? r.restored_at ?? r.created_at, timezone) },
        ]}
        rows={c.redemptions}
        rowKey={(r) => r.id}
        empty={<EmptyState title="Применений пока нет" />}
      />

      <details>
        <summary className="cursor-pointer text-sm text-brand">История статусов</summary>
        <ul className="mt-2 grid gap-1 text-sm text-ink-2">
          {c.history.map((h, i) => (
            <li key={i}>
              {dateTime(h.at, timezone)}: {h.from ? `${h.from} → ` : ""}
              {h.to}
              {h.reason ? ` — ${h.reason}` : ""}
            </li>
          ))}
        </ul>
      </details>

      <Modal
        open={deactivating}
        onClose={() => setDeactivating(false)}
        title={`Деактивировать ${c.code}?`}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeactivating(false)}>
              Отмена
            </Button>
            <Button
              variant="danger"
              loading={busy}
              disabled={reason.trim().length < 3}
              onClick={() => {
                setDeactivating(false);
                run(() => deactivateCode(c.id, reason.trim()), "Промокод деактивирован.");
              }}
            >
              Деактивировать
            </Button>
          </>
        }
      >
        <div className="grid gap-3">
          <p className="text-ink-2">Новые применения станут невозможны. Уже созданные записи сохранят скидку. Причина попадёт в журнал аудита.</p>
          <Field label="Причина">
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
        </div>
      </Modal>
    </Card>
  );
}

function Term({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-sm text-muted">{title}</dt>
      <dd>{children}</dd>
    </div>
  );
}

function Stat({ title, value, hint }: { title: string; value: string; hint?: string }) {
  return (
    <div className="rounded-xl border border-line px-4 py-3">
      <p className="text-sm text-muted">{title}</p>
      <p className="text-xl font-semibold">{value}</p>
      {hint && <p className="text-xs text-muted">{hint}</p>}
    </div>
  );
}

function Batches({ timezone, permissions, lookups, names }: Ctx) {
  const [page, setPage] = useState(1);
  const list = useResource<Paginated<Batch>>("/admin/promo/batches", { page });
  const [creating, setCreating] = useState(false);
  const [notice, setNotice] = useState<Notice>(null);
  const [deactivating, setDeactivating] = useState<Batch | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);

  async function act(action: () => Promise<{ affected: number }>, text: (n: number) => string) {
    setBusy(true);
    setNotice(null);
    try {
      const res = await action();
      setNotice({ tone: "success", text: text(res.affected) });
      list.reload();
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    } finally {
      setBusy(false);
    }
  }

  const columns: Column<Batch>[] = [
    {
      key: "title",
      title: "Пакет",
      render: (b) => (
        <div className="grid">
          <span className="font-medium">{b.title}</span>
          <span className="text-sm text-muted">
            {b.size} кодов{b.prefix ? ` · ${b.prefix}-…` : ""} · {date(b.created_at, timezone)}
          </span>
        </div>
      ),
    },
    {
      key: "terms",
      title: "Условия",
      render: (b) =>
        b.terms ? (
          <div className="grid text-sm">
            <span className="font-medium">{b.terms.discount_label}</span>
            <span className="text-muted">{period(b.terms.valid_from, b.terms.valid_until, timezone)}</span>
            {describeRestrictions(b.terms.restrictions, names).map((r) => (
              <span key={r} className="text-muted">
                {r}
              </span>
            ))}
          </div>
        ) : (
          "—"
        ),
    },
    {
      key: "statuses",
      title: "Коды",
      render: (b) => (
        <div className="flex flex-wrap gap-1">
          {Object.entries(b.codes_by_status).map(([status, n]) => (
            <Badge key={status} tone={promoTone[status as keyof typeof promoTone]}>
              {STATUS_OPTIONS.find((s) => s.value === status)?.label}: {n}
            </Badge>
          ))}
        </div>
      ),
    },
    {
      key: "stats",
      title: "Применений и скидки",
      render: (b) => (
        <span className="text-sm">
          {b.stats.applied} · {rub(b.stats.discount_sum)}
          {b.stats.conversion !== null ? ` · ${b.stats.conversion} %` : ""}
        </span>
      ),
    },
    {
      key: "actions",
      title: "",
      render: (b) => (
        <div className="flex flex-wrap gap-2">
          {permissions.export && (
            <a href={batchExportUrl(b.id)} className={buttonClass("ghost", "sm")} download>
              CSV
            </a>
          )}
          {permissions.manage && (b.codes_by_status.draft ?? 0) > 0 && (
            <Button size="sm" variant="ghost" disabled={busy} onClick={() => act(() => publishBatch(b.id), (n) => `Опубликовано кодов: ${n}.`)}>
              Опубликовать
            </Button>
          )}
          {permissions.manage && ((b.codes_by_status.active ?? 0) + (b.codes_by_status.scheduled ?? 0) + (b.codes_by_status.draft ?? 0) > 0) && (
            <Button size="sm" variant="ghost" onClick={() => setDeactivating(b)}>
              Деактивировать
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="grid gap-4">
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {creating ? (
        <PromoForm
          mode="batch"
          lookups={lookups}
          onCancel={() => setCreating(false)}
          onSubmit={async (payload) => {
            const batch = await createBatch(payload);
            setNotice({ tone: "success", text: `Пакет «${batch.title}»: создано ${batch.size} кодов.` });
            setCreating(false);
            list.reload();
          }}
        />
      ) : (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="max-w-2xl text-ink-2">Пакет — набор уникальных индивидуальных кодов с одинаковыми условиями, например для партнёров или рассылки. Список кодов выгружается в CSV.</p>
          {permissions.manage && <Button onClick={() => setCreating(true)}>Сгенерировать пакет</Button>}
        </div>
      )}
      {list.error && <Alert tone="danger">{list.error}</Alert>}
      {!list.data && list.loading && <p className="text-muted">Загрузка…</p>}
      {list.data && (
        <>
          <Table columns={columns} rows={list.data.data} rowKey={(b) => b.id} empty={<EmptyState title="Пакетов пока нет" />} />
          {list.data.meta.last_page > 1 && <Pager page={list.data.meta.current_page} last={list.data.meta.last_page} onPage={setPage} />}
        </>
      )}
      <Modal
        open={deactivating !== null}
        onClose={() => setDeactivating(null)}
        title={`Деактивировать пакет «${deactivating?.title ?? ""}»?`}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeactivating(null)}>
              Отмена
            </Button>
            <Button
              variant="danger"
              loading={busy}
              disabled={reason.trim().length < 3}
              onClick={() => {
                const b = deactivating;
                if (!b) return;
                setDeactivating(null);
                act(() => deactivateBatch(b.id, reason.trim()), (n) => `Деактивировано кодов: ${n}.`);
              }}
            >
              Деактивировать
            </Button>
          </>
        }
      >
        <Field label="Причина">
          <Textarea value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
      </Modal>
    </div>
  );
}

function Stats() {
  const res = useResource<{ data: Overview }>("/admin/promo/overview");
  const o = res.data?.data;
  if (res.error) return <Alert tone="danger">{res.error}</Alert>;
  if (!o) return <p className="text-muted">Загрузка…</p>;

  return (
    <div className="grid gap-6">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat title="Применений" value={String(o.applied)} hint="сессии оплачены со скидкой" />
        <Stat title="Сумма скидок" value={rub(o.discount_sum)} hint="уменьшает только долю платформы" />
        <Stat title="Конверсия" value={o.conversion === null ? "—" : `${o.conversion} %`} hint="оплаченные / все брони с промокодом" />
        <Stat title="Ждут списания" value={String(o.pending)} hint={`восстановлено при отменах: ${o.restored}`} />
      </div>
      <Card className="grid gap-3">
        <h2 className="text-lg font-semibold">По типам</h2>
        <ul className="grid gap-2">
          {TYPE_OPTIONS.map((t) => (
            <li key={t.value} className="flex flex-wrap justify-between gap-2">
              <span>{t.label}</span>
              <span className="text-ink-2">
                {o.by_type[t.value]?.applied ?? 0} применений · {rub(o.by_type[t.value]?.discount_sum ?? 0)}
              </span>
            </li>
          ))}
        </ul>
      </Card>
      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="grid gap-2">
          <h2 className="text-lg font-semibold">Промокоды по статусам</h2>
          <div className="flex flex-wrap gap-2">
            {STATUS_OPTIONS.map((s) => (
              <Badge key={s.value} tone={promoTone[s.value]}>
                {s.label}: {o.codes_by_status[s.value] ?? 0}
              </Badge>
            ))}
          </div>
        </Card>
        <Card className="grid gap-2">
          <h2 className="text-lg font-semibold">«Пригласи друга»</h2>
          <p className="text-ink-2">
            Зарегистрировались по приглашению: {(o.referral.registered ?? 0) + (o.referral.rewarded ?? 0)}, вознаграждено: {o.referral.rewarded ?? 0}, не засчитано:{" "}
            {o.referral.rejected ?? 0}
          </p>
        </Card>
      </div>
    </div>
  );
}

function Referral({ canManage }: { canManage: boolean }) {
  const res = useResource<{ data: ReferralSettings }>("/admin/promo/referral-settings");
  if (res.error) return <Alert tone="danger">{res.error}</Alert>;
  if (!res.data) return <p className="text-muted">Загрузка…</p>;
  return <ReferralForm key={JSON.stringify(res.data.data)} initial={res.data.data} canManage={canManage} onSaved={(s) => res.setData({ data: s })} />;
}

function ReferralForm({ initial, canManage, onSaved }: { initial: ReferralSettings; canManage: boolean; onSaved: (s: ReferralSettings) => void }) {
  const [values, setValues] = useState({
    friend_discount: String(initial.friend_discount),
    reward_type: initial.reward_type,
    reward_value: initial.reward_type === "fixed" ? String(initial.reward_value / 100) : String(initial.reward_value),
    validity_days: String(initial.validity_days),
  });
  const [notice, setNotice] = useState<Notice>(null);
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    setNotice(null);
    try {
      const saved = await saveReferralSettings({
        friend_discount: Number.parseInt(values.friend_discount, 10),
        reward_type: values.reward_type,
        reward_value: values.reward_type === "fixed" ? Math.round(Number(values.reward_value.replace(",", ".")) * 100) : Number.parseInt(values.reward_value, 10),
        validity_days: Number.parseInt(values.validity_days, 10),
      });
      setNotice({ tone: "success", text: "Настройки программы сохранены. Новые размеры действуют для следующих приглашений." });
      onSaved(saved);
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card className="grid max-w-2xl gap-4">
      <p className="text-ink-2">
        Программа работает на индивидуальных промокодах (DEC-42): друг при регистрации по ссылке получает код на льготную первую сессию, пригласивший — код после первой оплаченной
        сессии друга. Скидки уменьшают только долю платформы.
      </p>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      <fieldset className="grid gap-4 sm:grid-cols-2" disabled={!canManage}>
        <Field label="Скидка другу на первую сессию, %">
          <Input type="number" min={1} max={100} value={values.friend_discount} onChange={(e) => setValues((v) => ({ ...v, friend_discount: e.target.value }))} />
        </Field>
        <Field label="Срок действия кодов, дней">
          <Input type="number" min={1} max={730} value={values.validity_days} onChange={(e) => setValues((v) => ({ ...v, validity_days: e.target.value }))} />
        </Field>
        <Field label="Промокод пригласившему">
          <Select value={values.reward_type} onChange={(e) => setValues((v) => ({ ...v, reward_type: e.target.value as ReferralSettings["reward_type"] }))}>
            <option value="fixed">Фиксированная скидка, ₽</option>
            <option value="percent">Процентная скидка, %</option>
          </Select>
        </Field>
        <Field label={values.reward_type === "fixed" ? "Размер, ₽" : "Размер, %"}>
          <Input inputMode="decimal" value={values.reward_value} onChange={(e) => setValues((v) => ({ ...v, reward_value: e.target.value }))} />
        </Field>
      </fieldset>
      {canManage && (
        <div>
          <Button onClick={save} loading={saving}>
            Сохранить
          </Button>
        </div>
      )}
    </Card>
  );
}
