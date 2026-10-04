"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Alert, Badge, Button, Card, EmptyState, Field, Input, Modal, Select, Table, Textarea, type Column } from "@/components/ui";
import { date, dateTime, rub } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { errorMessage, useResource } from "@/features/booking/useResource";
import { adminComplaintAction, adminRefund, adminResolveWithdrawal } from "./api";
import { BALANCE_STATUS, CHARGE_STATUS, COMPLAINT_STATUS, COMPLAINT_TONE, PAYMENT_TONE, slaBadge } from "./labels";
import type { AdminChargeTask, AdminRefund, BalanceOperation, Complaint, FinanceSummary, PaymentRow } from "./types";

const TZ = "Europe/Moscow";

export type FinanceTab = "summary" | "payments" | "refunds" | "complaints" | "balance" | "charges";

const TABS: { id: FinanceTab; label: string }[] = [
  { id: "summary", label: "Сводка" },
  { id: "payments", label: "Платежи" },
  { id: "refunds", label: "Возвраты" },
  { id: "complaints", label: "Жалобы на списание" },
  { id: "balance", label: "Баланс клиентов" },
  { id: "charges", label: "Неуспешные списания" },
];

type Perms = { refund: boolean; complaints: boolean };

/** ADM-07: payments, refunds (incl. manual), complaints queue with the 14-working-day SLA, client balance operations. */
export function AdminFinance({ tab, perms }: { tab: FinanceTab; perms: Perms }) {
  const router = useRouter();
  return (
    <div className="grid gap-6">
      <nav className="flex gap-1 overflow-x-auto border-b border-line">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => router.replace(`/admin/finance?tab=${t.id}`)}
            className={
              t.id === tab
                ? "-mb-px whitespace-nowrap border-b-2 border-brand px-3 py-2.5 text-[15px] font-medium text-brand"
                : "-mb-px whitespace-nowrap border-b-2 border-transparent px-3 py-2.5 text-[15px] text-ink-2 hover:text-ink"
            }
          >
            {t.label}
          </button>
        ))}
      </nav>
      {tab === "summary" && <Summary />}
      {tab === "payments" && <Payments canRefund={perms.refund} />}
      {tab === "refunds" && <Refunds />}
      {tab === "complaints" && <Complaints canAct={perms.complaints} />}
      {tab === "balance" && <BalanceOps canResolve={perms.refund} />}
      {tab === "charges" && <Charges />}
    </div>
  );
}

function monthRange(): { from: string; to: string } {
  const now = new Date();
  const from = new Date(now.getFullYear(), now.getMonth(), 1);
  const to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
  const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  return { from: iso(from), to: iso(to) };
}

function Summary() {
  const [range, setRange] = useState(monthRange);
  const res = useResource<{ data: FinanceSummary }>("/admin/finance/summary", range);
  const s = res.data?.data;
  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-end gap-3">
        <Field label="С">
          <Input type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} />
        </Field>
        <Field label="По">
          <Input type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} />
        </Field>
      </div>
      {res.error && <Alert tone="danger">{res.error}</Alert>}
      {!s && !res.error && <p className="text-muted">Загрузка…</p>}
      {s && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Metric title="Оборот (поступления)" value={rub(s.turnover_total)} />
            <Metric title="Возвраты на карты" value={rub(s.refunds_to_card)} />
            <Metric title="Удержано по сессиям" value={rub(s.sessions.retained)} hint={`${s.sessions.count} сессий: проведены, неявка клиента, поздняя отмена`} />
            <Metric title="Комиссия платформы (оценка)" value={rub(s.sessions.platform_commission)} hint={`Психологам 70 % полной цены: ${rub(s.sessions.psychologist_share)}`} />
            <Metric title="Зачислено на балансы" value={rub(s.balance.credited_total)} />
            <Metric title="Остаток на балансах клиентов" value={rub(s.balance.outstanding)} hint={`из них сертификаты: ${rub(s.balance.outstanding_certificate)}`} />
            <Metric title="Сертификаты" value={rub(s.certificates.sold_amount)} hint={`продано ${s.certificates.sold}, активировано ${s.certificates.activated}`} />
            <Metric title="Жалобы" value={String(s.complaints.open)} hint={s.complaints.overdue ? `просрочено: ${s.complaints.overdue}` : "открытых"} tone={s.complaints.overdue ? "danger" : undefined} />
          </div>
          <Card>
            <p className="mb-2 font-medium">Поступления по назначению</p>
            {s.turnover.length === 0 ? (
              <p className="text-muted">Платежей за период нет.</p>
            ) : (
              <ul className="grid gap-1">
                {s.turnover.map((t) => (
                  <li key={t.purpose} className="flex justify-between gap-3">
                    <span>
                      {t.label} <span className="text-muted">({t.count})</span>
                    </span>
                    <span className="num">{rub(t.amount)}</span>
                  </li>
                ))}
              </ul>
            )}
            {s.failed_charges > 0 && (
              <p className="mt-3 text-sm text-danger">
                Ожидают оплаты после неуспешного списания: {s.failed_charges}.{" "}
                <Link className="underline" href="/admin/finance?tab=charges">
                  Открыть
                </Link>
              </p>
            )}
          </Card>
        </>
      )}
    </div>
  );
}

function Metric({ title, value, hint, tone }: { title: string; value: string; hint?: string; tone?: "danger" }) {
  return (
    <Card className="grid gap-1">
      <p className="text-sm text-muted">{title}</p>
      <p className={tone === "danger" ? "text-2xl font-semibold text-danger" : "text-2xl font-semibold"}>{value}</p>
      {hint && <p className="text-sm text-ink-2">{hint}</p>}
    </Card>
  );
}

function Pager({ meta, page, setPage }: { meta: Paginated<unknown>["meta"]; page: number; setPage: (p: number) => void }) {
  if (meta.last_page <= 1) return null;
  return (
    <div className="flex items-center gap-3">
      <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
        Назад
      </Button>
      <span className="text-sm text-muted">
        {meta.current_page} из {meta.last_page} · всего {meta.total}
      </span>
      <Button size="sm" variant="secondary" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>
        Вперёд
      </Button>
    </div>
  );
}

function Payments({ canRefund }: { canRefund: boolean }) {
  const [filters, setFilters] = useState({ status: "", purpose: "", q: "" });
  const [page, setPage] = useState(1);
  const res = useResource<Paginated<PaymentRow>>("/admin/finance/payments", { ...filters, page });
  const [target, setTarget] = useState<PaymentRow | null>(null);
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const columns: Column<PaymentRow>[] = [
    { key: "date", title: "Дата", render: (p) => dateTime(p.paid_at ?? p.created_at, TZ) },
    { key: "user", title: "Плательщик", render: (p) => p.user?.email ?? "—" },
    { key: "purpose", title: "Назначение", render: (p) => <span>{p.purpose_label}<br /><span className="text-xs text-muted">{p.description}</span></span> },
    { key: "amount", title: "Сумма", render: (p) => <span className="num">{rub(p.amount)}{p.refunded_amount ? <><br /><span className="text-xs text-muted">возвращено {rub(p.refunded_amount)}</span></> : null}</span> },
    { key: "status", title: "Статус", render: (p) => <Badge tone={PAYMENT_TONE[p.status] ?? "neutral"}>{p.status_label}</Badge> },
    { key: "card", title: "Карта", render: (p) => <span>{p.card_mask ?? "—"}<br /><span className="text-xs text-muted">{p.with_payer ? "с плательщиком" : "по токену"}</span></span> },
    {
      key: "actions",
      title: "",
      render: (p) =>
        canRefund && ["succeeded", "partially_refunded"].includes(p.status) ? (
          <Button size="sm" variant="ghost" onClick={() => (setTarget(p), setAmount(String((p.amount - p.refunded_amount) / 100)), setReason(""), setError(null))}>
            Возврат
          </Button>
        ) : null,
    },
  ];

  async function refund() {
    if (!target) return;
    setBusy(true);
    setError(null);
    try {
      const kopecks = Math.round(Number(amount.replace(",", ".")) * 100);
      const r = await adminRefund(target.id, kopecks, reason.trim());
      setNotice(r.refund_status === "succeeded" ? "Возврат выполнен, чек возврата зарегистрирован." : "Возврат отправлен, результат уточняется.");
      setTarget(null);
      res.reload();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="grid gap-4">
      {notice && <Alert tone="success">{notice}</Alert>}
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Поиск по плательщику">
          <Input value={filters.q} onChange={(e) => (setPage(1), setFilters({ ...filters, q: e.target.value }))} placeholder="Email или имя" />
        </Field>
        <Field label="Статус">
          <Select value={filters.status} onChange={(e) => (setPage(1), setFilters({ ...filters, status: e.target.value }))}>
            <option value="">Все</option>
            <option value="succeeded">Проведён</option>
            <option value="declined">Отклонён</option>
            <option value="requires_3ds,unknown,created">В процессе</option>
            <option value="partially_refunded,refunded">С возвратом</option>
          </Select>
        </Field>
        <Field label="Назначение">
          <Select value={filters.purpose} onChange={(e) => (setPage(1), setFilters({ ...filters, purpose: e.target.value }))}>
            <option value="">Все</option>
            <option value="session,booking">Сессии</option>
            <option value="certificate">Сертификаты</option>
            <option value="supervision">Супервизия</option>
            <option value="event">Мероприятия</option>
          </Select>
        </Field>
      </div>
      {res.error && <Alert tone="danger">{res.error}</Alert>}
      {res.data ? <Table columns={columns} rows={res.data.data} rowKey={(p) => p.id} empty={<EmptyState title="Платежей нет" />} /> : !res.error && <p className="text-muted">Загрузка…</p>}
      {res.data && <Pager meta={res.data.meta} page={page} setPage={setPage} />}
      <Modal
        open={target !== null}
        onClose={() => setTarget(null)}
        title="Возврат на карту"
        footer={
          <Button variant="danger" loading={busy} disabled={!reason.trim() || !Number(amount.replace(",", "."))} onClick={refund}>
            Вернуть
          </Button>
        }
      >
        <div className="grid gap-4">
          <p className="text-ink-2">
            Платёж {target ? rub(target.amount) : ""} от {target?.user?.email}. Доступно к возврату: {target ? rub(target.amount - target.refunded_amount) : ""}. Обычные возвраты зачисляются на баланс клиента — ручной возврат на карту используйте в исключительных случаях.
          </p>
          <Field label="Сумма, ₽">
            <Input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
          </Field>
          <Field label="Причина" hint="Обязательно, попадёт в журнал аудита.">
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
          {error && <Alert tone="danger">{error}</Alert>}
        </div>
      </Modal>
    </div>
  );
}

function Refunds() {
  const [page, setPage] = useState(1);
  const res = useResource<Paginated<AdminRefund>>("/admin/finance/refunds", { page });
  const columns: Column<AdminRefund>[] = [
    { key: "date", title: "Дата", render: (r) => dateTime(r.created_at, TZ) },
    { key: "user", title: "Клиент", render: (r) => r.user?.email ?? "—" },
    { key: "amount", title: "Сумма", render: (r) => <span className="num">{rub(r.amount)}</span> },
    { key: "reason", title: "Причина", render: (r) => r.reason ?? "—" },
    { key: "status", title: "Статус", render: (r) => <Badge tone={r.status === "succeeded" ? "success" : r.status === "pending" ? "warning" : "danger"}>{r.status === "succeeded" ? "Выполнен" : r.status === "pending" ? "В обработке" : "Не выполнен"}</Badge> },
    { key: "card", title: "Карта", render: (r) => r.payment.card_mask ?? "—" },
  ];
  return (
    <div className="grid gap-4">
      {res.error && <Alert tone="danger">{res.error}</Alert>}
      {res.data ? <Table columns={columns} rows={res.data.data} rowKey={(r) => r.id} empty={<EmptyState title="Возвратов на карты нет" description="Возвраты по отменам, неявкам психологов и жалобам зачисляются на баланс клиента — см. вкладку «Баланс клиентов»." />} /> : !res.error && <p className="text-muted">Загрузка…</p>}
      {res.data && <Pager meta={res.data.meta} page={page} setPage={setPage} />}
    </div>
  );
}

function Complaints({ canAct }: { canAct: boolean }) {
  const [status, setStatus] = useState("open");
  const res = useResource<{ data: Complaint[] }>("/admin/finance/complaints", { status });
  const [active, setActive] = useState<Complaint | null>(null);
  const [mode, setMode] = useState<"ask" | "reject" | "approve" | null>(null);
  const [text, setText] = useState("");
  const [amount, setAmount] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function act(action: "take" | "resume" | "ask" | "reject" | "approve") {
    if (!active) return;
    setBusy(true);
    setError(null);
    try {
      const body =
        action === "ask" ? { question: text } : action === "reject" ? { comment: text } : action === "approve" ? { comment: text, amount: Math.round(Number(amount.replace(",", ".")) * 100) } : undefined;
      const r = await adminComplaintAction(active.id, action, body);
      setActive(r.data);
      setMode(null);
      setText("");
      res.reload();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const items = res.data?.data ?? [];
  return (
    <div className="grid gap-4">
      <Field label="Показывать" className="max-w-xs">
        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="open">Открытые</option>
          <option value="rejected,refunded,withdrawn,approved">Закрытые</option>
          <option value="all">Все</option>
        </Select>
      </Field>
      {res.error && <Alert tone="danger">{res.error}</Alert>}
      {!res.data && !res.error && <p className="text-muted">Загрузка…</p>}
      {res.data && items.length === 0 && <EmptyState title="Жалоб нет" />}
      <ul className="grid gap-3">
        {items.map((c) => {
          const sla = slaBadge(c.sla, c.working_days_left);
          return (
            <li key={c.id}>
              <button type="button" className="w-full text-left" onClick={() => (setActive(c), setMode(null), setError(null))}>
                <Card className="flex flex-wrap items-center justify-between gap-3 hover:border-brand-tint">
                  <div className="grid gap-0.5">
                    <p className="font-medium">
                      {c.client?.email} · {rub(c.amount_charged)}
                    </p>
                    <p className="text-sm text-ink-2">
                      Сессия {c.session ? dateTime(c.session.starts_at, TZ) : "—"} · {c.session?.psychologist} · подана {date(c.created_at, TZ)}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={COMPLAINT_TONE[c.status]}>{COMPLAINT_STATUS[c.status]}</Badge>
                    {["submitted", "in_review", "waiting_client"].includes(c.status) && <Badge tone={sla.tone}>{sla.text}</Badge>}
                  </div>
                </Card>
              </button>
            </li>
          );
        })}
      </ul>

      <Modal open={active !== null} onClose={() => setActive(null)} title="Жалоба на списание">
        {active && (
          <div className="grid gap-4">
            <div className="flex flex-wrap gap-2">
              <Badge tone={COMPLAINT_TONE[active.status]}>{COMPLAINT_STATUS[active.status]}</Badge>
              <Badge tone={slaBadge(active.sla, active.working_days_left).tone}>Срок {date(active.due_date)}</Badge>
            </div>
            <p className="text-[15px]">
              Клиент: {active.client?.name} ({active.client?.email}). Удержано {rub(active.amount_charged)}.{" "}
              {active.session && (
                <Link className="text-brand underline" href={`/admin/sessions/${active.session.id}`}>
                  Сессия и журнал
                </Link>
              )}
            </p>
            <ul className="grid gap-1 text-[15px]">
              {active.messages.map((m, i) => (
                <li key={i} className={m.from === "admin" ? "rounded-xl bg-brand-soft px-3 py-2" : "rounded-xl bg-sunken px-3 py-2"}>
                  <span className="text-xs text-muted">{m.from === "admin" ? "Администратор" : "Клиент"} · {dateTime(m.at, TZ)}</span>
                  <br />
                  {m.text}
                </li>
              ))}
            </ul>
            {active.decision_comment && <p>Решение: {active.decision_comment}</p>}
            {canAct && (
              <div className="flex flex-wrap gap-2">
                {active.status === "submitted" && <Button size="sm" loading={busy} onClick={() => act("take")}>Взять в работу</Button>}
                {active.status === "waiting_client" && <Button size="sm" variant="secondary" loading={busy} onClick={() => act("resume")}>Продолжить без ответа</Button>}
                {active.status === "in_review" && (
                  <>
                    <Button size="sm" variant="secondary" onClick={() => setMode("ask")}>Задать вопрос</Button>
                    <Button size="sm" onClick={() => (setMode("approve"), setAmount(String(active.amount_charged / 100)))}>Вернуть</Button>
                    <Button size="sm" variant="danger" onClick={() => setMode("reject")}>Отклонить</Button>
                  </>
                )}
              </div>
            )}
            {mode && (
              <div className="grid gap-3 rounded-2xl border border-line p-4">
                {mode === "approve" && (
                  <Field label="Сумма возврата на баланс, ₽" hint={`Не больше ${rub(active.amount_charged)}. Начисление психологу уменьшится в той же доле.`}>
                    <Input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
                  </Field>
                )}
                <Field label={mode === "ask" ? "Вопрос клиенту" : "Комментарий для клиента (обязательно)"}>
                  <Textarea value={text} onChange={(e) => setText(e.target.value)} />
                </Field>
                <Button size="sm" className="justify-self-start" loading={busy} disabled={!text.trim()} onClick={() => act(mode)}>
                  {mode === "ask" ? "Отправить вопрос" : mode === "approve" ? "Подтвердить возврат" : "Отклонить жалобу"}
                </Button>
              </div>
            )}
            {error && <Alert tone="danger">{error}</Alert>}
          </div>
        )}
      </Modal>
    </div>
  );
}

function BalanceOps({ canResolve }: { canResolve: boolean }) {
  const [filters, setFilters] = useState({ type: "", status: "", q: "" });
  const [page, setPage] = useState(1);
  const res = useResource<Paginated<BalanceOperation>>("/admin/finance/balance-operations", { ...filters, page });
  const [target, setTarget] = useState<BalanceOperation | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function resolve(action: "withdrawn" | "cancel") {
    if (!target) return;
    setBusy(true);
    setError(null);
    try {
      await adminResolveWithdrawal(target.id, action, reason.trim());
      setTarget(null);
      setReason("");
      res.reload();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const columns: Column<BalanceOperation>[] = [
    { key: "date", title: "Дата", render: (o) => dateTime(o.created_at, TZ) },
    { key: "user", title: "Клиент", render: (o) => o.user?.email ?? "—" },
    { key: "reason", title: "Операция", render: (o) => <span>{o.reason_label}{o.is_certificate_funds || o.certificate_amount ? <><br /><span className="text-xs text-muted">средства сертификата</span></> : null}</span> },
    { key: "amount", title: "Сумма", render: (o) => <span className="num">{o.type === "credit" ? "+" : "−"}{rub(o.amount)}</span> },
    { key: "status", title: "Статус", render: (o) => <Badge tone={o.status === "withdraw_review" ? "danger" : "neutral"}>{BALANCE_STATUS[o.status] ?? o.status}</Badge> },
    {
      key: "actions",
      title: "",
      render: (o) =>
        canResolve && o.status === "withdraw_review" ? (
          <Button size="sm" variant="secondary" onClick={() => (setTarget(o), setError(null))}>
            Разобрать
          </Button>
        ) : null,
    },
  ];

  return (
    <div className="grid gap-4">
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Клиент (email)">
          <Input value={filters.q} onChange={(e) => (setPage(1), setFilters({ ...filters, q: e.target.value }))} />
        </Field>
        <Field label="Тип">
          <Select value={filters.type} onChange={(e) => (setPage(1), setFilters({ ...filters, type: e.target.value }))}>
            <option value="">Все</option>
            <option value="credit">Зачисления</option>
            <option value="spend">Оплаты с баланса</option>
            <option value="withdraw">Выводы на карту</option>
          </Select>
        </Field>
        <Field label="Статус">
          <Select value={filters.status} onChange={(e) => (setPage(1), setFilters({ ...filters, status: e.target.value }))}>
            <option value="">Все</option>
            <option value="withdraw_review">Вывод требует разбора</option>
            <option value="withdraw_reserved,withdraw_processing">Вывод в обработке</option>
            <option value="spend_reserved">Резерв оплаты</option>
          </Select>
        </Field>
      </div>
      {res.error && <Alert tone="danger">{res.error}</Alert>}
      {res.data ? <Table columns={columns} rows={res.data.data} rowKey={(o) => o.id} empty={<EmptyState title="Операций нет" />} /> : !res.error && <p className="text-muted">Загрузка…</p>}
      {res.data && <Pager meta={res.data.meta} page={page} setPage={setPage} />}
      <Modal open={target !== null} onClose={() => setTarget(null)} title="Вывод требует разбора">
        {target && (
          <div className="grid gap-4">
            <p>
              Клиент {target.user?.email} просит вывести {rub(target.amount)}. Автоматический возврат по исходным оплатам не прошёл. Проведите возврат вручную в платёжном сервисе и отметьте его, либо отклоните заявку — сумма вернётся на баланс.
            </p>
            <Field label="Комментарий" hint="Обязательно, попадёт в журнал аудита и письмо клиенту.">
              <Textarea value={reason} onChange={(e) => setReason(e.target.value)} />
            </Field>
            {error && <Alert tone="danger">{error}</Alert>}
            <div className="flex flex-wrap gap-2">
              <Button loading={busy} disabled={!reason.trim()} onClick={() => resolve("withdrawn")}>
                Возврат проведён вручную
              </Button>
              <Button variant="danger" loading={busy} disabled={!reason.trim()} onClick={() => resolve("cancel")}>
                Отклонить заявку
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}

function Charges() {
  const [status, setStatus] = useState("retry_wait,in_progress");
  const [page, setPage] = useState(1);
  const res = useResource<Paginated<AdminChargeTask>>("/admin/finance/charge-tasks", { status, page });
  const columns: Column<AdminChargeTask>[] = [
    {
      key: "session",
      title: "Сессия",
      render: (t) =>
        t.session ? (
          <Link className="text-brand hover:underline" href={`/admin/sessions/${t.session.id}`}>
            {dateTime(t.session.starts_at, TZ)} · {t.session.psychologist}
          </Link>
        ) : (
          "—"
        ),
    },
    { key: "amount", title: "Сумма", render: (t) => <span className="num">{rub(t.amount)}{t.balance_part ? <><br /><span className="text-xs text-muted">с баланса {rub(t.balance_part)}</span></> : null}</span> },
    { key: "status", title: "Статус", render: (t) => <Badge tone={t.status === "failed_final" ? "danger" : "warning"}>{CHARGE_STATUS[t.status] ?? t.status}</Badge> },
    { key: "attempts", title: "Попытки", render: (t) => <span>{t.attempts}{t.last_error_code ? <><br /><span className="text-xs text-muted">{t.last_error_code} ({t.last_error_category})</span></> : null}</span> },
    { key: "next", title: "Следующая / крайний срок", render: (t) => <span>{t.next_attempt_at ? dateTime(t.next_attempt_at, TZ) : "ждём клиента"}<br /><span className="text-xs text-muted">до {t.deadline_at ? dateTime(t.deadline_at, TZ) : "—"}</span></span> },
  ];
  return (
    <div className="grid gap-4">
      <Field label="Статус" className="max-w-xs">
        <Select value={status} onChange={(e) => (setPage(1), setStatus(e.target.value))}>
          <option value="retry_wait,in_progress">Ожидают повтора или оплаты</option>
          <option value="failed_final">Не выполнены (запись отменена)</option>
          <option value="succeeded">Выполнены</option>
        </Select>
      </Field>
      {res.error && <Alert tone="danger">{res.error}</Alert>}
      {res.data ? <Table columns={columns} rows={res.data.data} rowKey={(t) => t.id} empty={<EmptyState title="Нет таких списаний" />} /> : !res.error && <p className="text-muted">Загрузка…</p>}
      {res.data && <Pager meta={res.data.meta} page={page} setPage={setPage} />}
    </div>
  );
}
