"use client";

import Link from "next/link";
import { useState } from "react";
import { Alert, Badge, Button, Card, Checkbox, EmptyState, Field, Input, Modal, Table, Textarea, type Column } from "@/components/ui";
import { date, dateTime, rub } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { approveRegistry, buildRegistry, excludeLine, resumePayee, retryRegistry, suspendPayee } from "./api";
import { payoutDayLabel, payoutTone, registryTone } from "./labels";
import type { BlockedPayee, Parameter, PayeeRow, PayoutSettings, Registry, RegistryDetail } from "./types";
import { errorMessage, useResource } from "./useResource";

type Tab = "registries" | "blocked" | "payees" | "settings";
const TABS: { value: Tab; label: string }[] = [
  { value: "registries", label: "Реестры" },
  { value: "blocked", label: "Блокировки по супервизии" },
  { value: "payees", label: "Получатели" },
  { value: "settings", label: "Правила автовывода" },
];

type Permissions = { approve: boolean; manage: boolean };

/** ADM-08: weekly registries, approval and exclusions, supervision blocks, suspension of payouts, rules. */
export function AdminPayouts({ timezone, permissions }: { timezone: string; permissions: Permissions }) {
  const [tab, setTab] = useState<Tab>("registries");

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
      {tab === "registries" && <Registries timezone={timezone} permissions={permissions} />}
      {tab === "blocked" && <Blocked timezone={timezone} />}
      {tab === "payees" && <Payees permissions={permissions} />}
      {tab === "settings" && <Settings timezone={timezone} />}
    </div>
  );
}

function Registries({ timezone, permissions }: { timezone: string; permissions: Permissions }) {
  const [page, setPage] = useState(1);
  const list = useResource<Paginated<Registry>>("/admin/payouts/registries", { page });
  const [selected, setSelected] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [building, setBuilding] = useState(false);

  async function build() {
    setBuilding(true);
    setNotice(null);
    try {
      const registry = await buildRegistry();
      setNotice({ tone: "success", text: `Реестр за ${date(registry.period_start)} — ${date(registry.period_end)}: ${registry.summary.lines} строк на ${rub(registry.summary.amount)}.` });
      setSelected(registry.id);
      list.reload();
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    } finally {
      setBuilding(false);
    }
  }

  const columns: Column<Registry>[] = [
    { key: "period", title: "Период", render: (r) => <span className="whitespace-nowrap">{`${date(r.period_start)} — ${date(r.period_end)}`}</span> },
    { key: "status", title: "Статус", render: (r) => <Badge tone={registryTone[r.status]}>{r.status_label}</Badge> },
    { key: "lines", title: "Строк", render: (r) => r.summary.lines },
    { key: "amount", title: "Сумма", className: "text-right", render: (r) => <span className="whitespace-nowrap font-medium">{rub(r.summary.amount)}</span> },
    {
      key: "blocked",
      title: "Не вошли",
      render: (r) => (
        <span className="text-sm text-ink-2">
          {r.summary.blocked} по супервизии, {r.summary.deferred} отложено
        </span>
      ),
    },
    { key: "approved", title: "Утверждён", render: (r) => (r.approved_at ? `${dateTime(r.approved_at, timezone)}${r.approved_by ? `, ${r.approved_by}` : r.auto_approve ? ", автоматически" : ""}` : "—") },
    {
      key: "open",
      title: "",
      render: (r) => (
        <Button variant="ghost" size="sm" onClick={() => setSelected(r.id === selected ? null : r.id)}>
          {r.id === selected ? "Скрыть" : "Открыть"}
        </Button>
      ),
    },
  ];

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-ink-2">
          Реестр формируется автоматически в день выплаты за прошедшую неделю. В него попадают психологи и супервизоры с положительным балансом, выполненным требованием супервизии,
          привязанной картой и суммой не меньше минимальной.
        </p>
        {permissions.manage && (
          <Button onClick={build} loading={building}>
            Сформировать реестр за прошлую неделю
          </Button>
        )}
      </div>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {list.error && <Alert tone="danger">{list.error}</Alert>}
      {!list.data && list.loading && <p className="text-muted">Загрузка…</p>}
      {list.data && (
        <>
          <Table columns={columns} rows={list.data.data} rowKey={(r) => r.id} empty={<EmptyState title="Реестров пока нет" description="Первый реестр появится в ближайший день выплаты." />} />
          {list.data.meta.last_page > 1 && (
            <div className="flex items-center gap-3">
              <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Назад
              </Button>
              <span className="text-sm text-muted">
                Страница {list.data.meta.current_page} из {list.data.meta.last_page}
              </span>
              <Button variant="secondary" size="sm" disabled={page >= list.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                Дальше
              </Button>
            </div>
          )}
        </>
      )}
      {selected && <RegistryPanel key={selected} id={selected} timezone={timezone} permissions={permissions} onChanged={list.reload} />}
    </div>
  );
}

function RegistryPanel({ id, timezone, permissions, onChanged }: { id: string; timezone: string; permissions: Permissions; onChanged: () => void }) {
  const detail = useResource<{ data: RegistryDetail }>(`/admin/payouts/registries/${id}`);
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [excluding, setExcluding] = useState<RegistryDetail["lines"][number] | null>(null);
  const [reason, setReason] = useState("");

  async function run(action: () => Promise<string>) {
    setBusy(true);
    setNotice(null);
    try {
      setNotice({ tone: "success", text: await action() });
      detail.reload();
      onChanged();
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    } finally {
      setBusy(false);
    }
  }

  const r = detail.data?.data;
  if (detail.error && !r) return <Alert tone="danger">{detail.error}</Alert>;
  if (!r) return <p className="text-muted">Загрузка реестра…</p>;

  const open = r.lines.some((l) => ["in_registry", "sent", "unknown"].includes(l.status));
  const columns: Column<RegistryDetail["lines"][number]>[] = [
    {
      key: "payee",
      title: "Получатель",
      render: (l) => (
        <div className="grid">
          <span className="font-medium">{l.payee?.name ?? "—"}</span>
          <span className="text-sm text-muted">{l.payee?.email}</span>
        </div>
      ),
    },
    { key: "amount", title: "Сумма", className: "text-right", render: (l) => <span className="whitespace-nowrap font-medium">{rub(l.amount)}</span> },
    { key: "card", title: "Карта", render: (l) => l.card_mask ?? "—" },
    { key: "status", title: "Статус", render: (l) => <Badge tone={payoutTone[l.status]}>{l.status_label}</Badge> },
    { key: "reason", title: "Причина", render: (l) => <span className="text-sm text-ink-2">{l.reason ?? (l.paid_at ? `Выполнена ${dateTime(l.paid_at, timezone)}` : "—")}</span> },
    {
      key: "actions",
      title: "",
      render: (l) =>
        permissions.approve && l.status === "in_registry" ? (
          <Button variant="ghost" size="sm" onClick={() => setExcluding(l)}>
            Исключить
          </Button>
        ) : null,
    },
  ];

  return (
    <Card className="grid gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="grid gap-1">
          <h2 className="text-lg font-semibold">
            Реестр за {date(r.period_start)} — {date(r.period_end)}
          </h2>
          <p className="text-ink-2">
            {r.summary.lines} строк на {rub(r.summary.amount)}, выплачено {rub(r.summary.paid_amount)}. <Badge tone={registryTone[r.status]}>{r.status_label}</Badge>
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {permissions.approve && r.status === "draft" && (
            <Button
              loading={busy}
              onClick={() =>
                run(async () => {
                  await approveRegistry(r.id);
                  return "Реестр утверждён, выплаты отправляются.";
                })
              }
            >
              Утвердить и отправить
            </Button>
          )}
          {permissions.manage && r.status !== "draft" && open && (
            <Button
              variant="secondary"
              loading={busy}
              onClick={() =>
                run(async () => {
                  const res = await retryRegistry(r.id);
                  return `Повтор выполнен: отправлено ${res.stats.sent}, проверено ${res.stats.checked}.`;
                })
              }
            >
              Повторить отправку и проверку статусов
            </Button>
          )}
        </div>
      </div>
      {r.status === "draft" && <Alert tone="warning">Реестр ждёт ручного утверждения. Перед утверждением можно исключить строки с указанием причины — сумма останется на балансе психолога.</Alert>}
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      <Table columns={columns} rows={r.lines} rowKey={(l) => l.id} empty={<EmptyState title="Строк нет" description="Ни у кого не было положительного баланса за период." />} />

      <Modal
        open={excluding !== null}
        onClose={() => setExcluding(null)}
        title="Исключить строку из реестра"
        footer={
          <>
            <Button variant="secondary" onClick={() => setExcluding(null)}>
              Отмена
            </Button>
            <Button
              variant="danger"
              loading={busy}
              disabled={reason.trim().length < 3}
              onClick={() => {
                const line = excluding;
                if (!line) return;
                setExcluding(null);
                run(async () => {
                  await excludeLine(line.id, reason.trim());
                  setReason("");
                  return `Строка ${line.payee?.name ?? ""} исключена, сумма осталась на балансе.`;
                });
              }}
            >
              Исключить
            </Button>
          </>
        }
      >
        <div className="grid gap-3">
          <p className="text-ink-2">
            {excluding?.payee?.name}: {excluding ? rub(excluding.amount) : ""}. Причину увидит психолог, действие попадёт в журнал аудита.
          </p>
          <Field label="Причина">
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} required />
          </Field>
        </div>
      </Modal>
    </Card>
  );
}

function Blocked({ timezone }: { timezone: string }) {
  const list = useResource<{ data: BlockedPayee[] }>("/admin/payouts/blocked");
  const columns: Column<BlockedPayee>[] = [
    {
      key: "payee",
      title: "Психолог",
      render: (b) => (
        <div className="grid">
          <span className="font-medium">{b.payee?.name ?? "—"}</span>
          <span className="text-sm text-muted">{b.payee?.email}</span>
        </div>
      ),
    },
    { key: "activity", title: "Статус активности", render: (b) => <Badge tone={b.activity_status === "inactive" ? "danger" : "warning"}>{b.activity_status === "inactive" ? "Неактивен" : "Требование не выполнено"}</Badge> },
    { key: "deadline", title: "Срок", render: (b) => `до ${date(b.requirement.deadline, timezone)}` },
    { key: "available", title: "На балансе", className: "text-right", render: (b) => <span className="whitespace-nowrap font-medium">{rub(b.available)}</span> },
    { key: "last", title: "Последняя блокировка", render: (b) => (b.last_blocked_at ? `${date(b.last_blocked_at, timezone)}, ${rub(b.last_blocked_amount)}` : "—") },
  ];

  return (
    <div className="grid gap-4">
      <p className="max-w-3xl text-ink-2">
        Еженедельная выплата проходит, только если требование ежемесячной супервизии текущего месяца выполнено (DEC-37). Пока требование не выполнено, начисления копятся на
        балансе. Засчитать супервизию вручную можно в разделе <Link href="/admin/supervision" className="text-brand">«Супервизия»</Link>.
      </p>
      {list.error && <Alert tone="danger">{list.error}</Alert>}
      {!list.data && list.loading && <p className="text-muted">Загрузка…</p>}
      {list.data && (
        <Table columns={columns} rows={list.data.data} rowKey={(b) => b.psychologist_id} empty={<EmptyState title="Блокировок нет" description="У всех психологов требование месяца выполнено или не действует." />} />
      )}
    </div>
  );
}

function Payees({ permissions }: { permissions: Permissions }) {
  const [q, setQ] = useState("");
  const [search, setSearch] = useState("");
  const [onlySuspended, setOnlySuspended] = useState(false);
  const list = useResource<Paginated<PayeeRow>>("/admin/payouts/payees", { q: search, suspended: onlySuspended ? 1 : undefined });
  const [suspending, setSuspending] = useState<PayeeRow | null>(null);
  const [reason, setReason] = useState("");
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  async function act(action: () => Promise<unknown>, text: string) {
    setBusy(true);
    setNotice(null);
    try {
      await action();
      setNotice({ tone: "success", text });
      list.reload();
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    } finally {
      setBusy(false);
    }
  }

  const columns: Column<PayeeRow>[] = [
    {
      key: "payee",
      title: "Получатель",
      render: (p) => (
        <div className="grid">
          <span className="font-medium">{p.payee?.name ?? "—"}</span>
          <span className="text-sm text-muted">{p.payee?.email}</span>
        </div>
      ),
    },
    { key: "available", title: "Доступно", className: "text-right", render: (p) => <span className="whitespace-nowrap">{rub(p.available)}</span> },
    { key: "in_payout", title: "В выплате", className: "text-right", render: (p) => <span className="whitespace-nowrap">{rub(p.in_payout)}</span> },
    { key: "paid", title: "Выплачено", className: "text-right", render: (p) => <span className="whitespace-nowrap">{rub(p.paid_total)}</span> },
    { key: "card", title: "Карта", render: (p) => (p.has_card ? <Badge tone="success">Есть</Badge> : <Badge tone="warning">Нет</Badge>) },
    {
      key: "status",
      title: "Выплаты",
      render: (p) =>
        p.payouts_suspended ? (
          <div className="grid gap-1">
            <Badge tone="danger">Приостановлены</Badge>
            <span className="text-sm text-ink-2">{p.suspended_reason}</span>
          </div>
        ) : (
          <Badge tone="success">Включены</Badge>
        ),
    },
    {
      key: "actions",
      title: "",
      render: (p) => {
        const payee = p.payee;
        if (!permissions.manage || !payee) return null;
        return p.payouts_suspended ? (
          <Button variant="ghost" size="sm" disabled={busy} onClick={() => act(() => resumePayee(payee.id), `Выплаты для ${payee.name} возобновлены.`)}>
            Возобновить
          </Button>
        ) : (
          <Button variant="ghost" size="sm" onClick={() => setSuspending(p)}>
            Приостановить
          </Button>
        );
      },
    },
  ];

  return (
    <div className="grid gap-4">
      <form
        className="flex flex-wrap items-end gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          setSearch(q.trim());
        }}
      >
        <Field label="Поиск по имени или email" className="w-72">
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="anna@…" />
        </Field>
        <Button type="submit" variant="secondary">
          Найти
        </Button>
        <Checkbox label="Только с приостановленными выплатами" checked={onlySuspended} onChange={(e) => setOnlySuspended(e.target.checked)} className="pb-2" />
      </form>
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      {list.error && <Alert tone="danger">{list.error}</Alert>}
      {!list.data && list.loading && <p className="text-muted">Загрузка…</p>}
      {list.data && <Table columns={columns} rows={list.data.data} rowKey={(p) => p.payee?.id ?? ""} empty={<EmptyState title="Никого не нашли" />} />}

      <Modal
        open={suspending !== null}
        onClose={() => setSuspending(null)}
        title="Приостановить выплаты"
        footer={
          <>
            <Button variant="secondary" onClick={() => setSuspending(null)}>
              Отмена
            </Button>
            <Button
              variant="danger"
              loading={busy}
              disabled={reason.trim().length < 3}
              onClick={() => {
                const payee = suspending?.payee;
                if (!payee) return;
                setSuspending(null);
                act(async () => {
                  await suspendPayee(payee.id, reason.trim());
                  setReason("");
                }, `Выплаты для ${payee.name} приостановлены.`);
              }}
            >
              Приостановить
            </Button>
          </>
        }
      >
        <div className="grid gap-3">
          <p className="text-ink-2">Начисления продолжат копиться на балансе. Причину увидит психолог в разделе «Вывод средств».</p>
          <Field label="Причина">
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} required />
          </Field>
        </div>
      </Modal>
    </div>
  );
}

function formatParameter(key: string, p: Parameter): string {
  if (key === "P-PAYOUT-PERIOD") return typeof p.value === "string" && p.value.startsWith("weekly_") ? "Еженедельно" : String(p.value);
  switch (p.unit) {
    case "kopecks":
      return rub(Number(p.value));
    case "bool":
      return p.value ? "Да" : "Нет";
    case "%":
      return `${p.value} %`;
    case "min":
      return `${p.value} мин`;
    default:
      return String(p.value);
  }
}

function Settings({ timezone }: { timezone: string }) {
  const settings = useResource<{ data: PayoutSettings }>("/admin/payouts/settings");
  const s = settings.data?.data;
  if (settings.error) return <Alert tone="danger">{settings.error}</Alert>;
  if (!s) return <p className="text-muted">Загрузка…</p>;

  return (
    <div className="grid max-w-3xl gap-4">
      <Card className="grid gap-2">
        <p>
          Выплаты уходят <span className="font-medium">{payoutDayLabel(s.payout_weekday)}</span> в 03:00 по московскому времени. Ближайший запуск —{" "}
          <span className="font-medium">{dateTime(s.next_run_at, timezone)}</span>.
        </p>
        <p className="text-sm text-muted">Статус самозанятого и лимит дохода не проверяются (DEC-20). Выплата отправляется с ключом идемпотентности — повторная отправка не создаёт вторую выплату.</p>
      </Card>
      <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
        {Object.entries(s.parameters).map(([key, p]) => (
          <li key={key} className="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3">
            <span>
              {p.title} <span className="text-xs text-muted">{key}</span>
            </span>
            <span className="font-medium">
              {formatParameter(key, p)}
              {p.overridden && <span className="ml-2 text-xs text-muted">(изменено)</span>}
            </span>
          </li>
        ))}
      </ul>
      <p className="text-ink-2">
        Значения меняются в разделе{" "}
        <Link href="/admin/settings" className="text-brand">
          «Настройки платформы»
        </Link>
        .
      </p>
    </div>
  );
}
