"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Alert, Badge, Button, Card, EmptyState, Field, Input, Modal, Select, Textarea } from "@/components/ui";
import { date, dateTime, rub } from "@/lib/format";
import { sessionDateTime } from "@/features/booking/time";
import type { ClientSession, SessionsResponse } from "@/features/booking/types";
import { errorMessage, useResource } from "@/features/booking/useResource";
import { activateCertificate, answerComplaint, makeDefault, payCharge, removeCard, startBinding, submitComplaint, withdraw, withdrawComplaint } from "./api";
import { BALANCE_STATUS, CHARGE_STATUS, COMPLAINT_STATUS, COMPLAINT_TONE, PAYMENT_TONE, balanceSign, normalizeCertificateCode } from "./labels";
import type { BalanceOperation, Card as CardT, Complaint, PaymentRow, PaymentsSummary } from "./types";

type Notice = { tone: "success" | "danger" | "info" | "warning"; text: string } | null;

type Props = { timezone: string; payTask?: string | null; complaintSession?: string | null; bound?: boolean };

/**
 * CL-07: cabinet balance (incl. certificate funds), cards, charges awaiting payment, payment history with receipts,
 * complaints about a charge, withdrawal of the remainder, gift certificate activation.
 */
export function ClientPayments({ timezone, payTask, complaintSession, bound }: Props) {
  const router = useRouter();
  const summary = useResource<{ data: PaymentsSummary }>("/payments/summary");
  const history = useResource<{ data: PaymentRow[] }>("/payments/history");
  const operations = useResource<{ data: BalanceOperation[] }>("/payments/balance/operations");
  const complaints = useResource<{ data: Complaint[] }>("/payments/complaints");
  const [notice, setNotice] = useState<Notice>(bound ? { tone: "success", text: "Карта привязана." } : null);
  const [busy, setBusy] = useState<string | null>(null);
  const [removeTarget, setRemoveTarget] = useState<CardT | null>(null);
  const [confirmWithdraw, setConfirmWithdraw] = useState(false);
  const [complaintFor, setComplaintFor] = useState<string | null>(complaintSession ?? null);

  function reloadAll() {
    summary.reload();
    history.reload();
    operations.reload();
    complaints.reload();
  }

  async function act(key: string, fn: () => Promise<void>) {
    setBusy(key);
    setNotice(null);
    try {
      await fn();
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    } finally {
      setBusy(null);
    }
  }

  const bind = () =>
    act("bind", async () => {
      const b = await startBinding("/client/payments?bound=1");
      if (b.confirmation_url) window.location.assign(b.confirmation_url);
      else reloadAll();
    });

  const pay = (taskId: string) =>
    act(`pay-${taskId}`, async () => {
      const res = await payCharge(taskId);
      if (res.confirmation_url) window.location.assign(res.confirmation_url);
      else {
        setNotice({ tone: "success", text: "Сессия оплачена с баланса личного кабинета." });
        reloadAll();
      }
    });

  if (summary.error && !summary.data) {
    return (
      <Alert tone="danger" title="Раздел не загрузился">
        {summary.error}{" "}
        <button type="button" className="text-brand underline" onClick={summary.reload}>
          Повторить
        </button>
      </Alert>
    );
  }
  const s = summary.data?.data;
  if (!s) return <p className="text-muted">Загрузка…</p>;

  return (
    <div className="grid gap-8">
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <section aria-label="Баланс" className="grid gap-4 md:grid-cols-3">
        <Card className="grid gap-1">
          <p className="text-sm text-muted">Баланс личного кабинета</p>
          <p className="text-3xl font-semibold tracking-tight">{rub(s.balance.available)}</p>
          {s.balance.certificate_available > 0 && <p className="text-sm text-ink-2">из них средства сертификатов: {rub(s.balance.certificate_available)}</p>}
          {s.balance.reserved > 0 && <p className="text-sm text-muted">в резерве: {rub(s.balance.reserved)}</p>}
        </Card>
        <Card className="grid content-between gap-3 md:col-span-2">
          <p className="text-[15px] text-ink-2">
            Возвраты зачисляются на баланс; при следующей оплате сначала расходуется баланс, затем карта. Остаток (кроме средств подарочных сертификатов) можно вернуть на карты, с которых вы платили.
          </p>
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="secondary" disabled={s.balance.withdrawable <= 0 || !!s.withdrawal} onClick={() => setConfirmWithdraw(true)}>
              Вывести {rub(s.balance.withdrawable)} на карту
            </Button>
            {s.withdrawal && (
              <Badge tone="warning">
                {BALANCE_STATUS[s.withdrawal.status] ?? s.withdrawal.status}: {rub(s.withdrawal.amount)}
              </Badge>
            )}
          </div>
        </Card>
      </section>

      {s.charges.length > 0 && (
        <section className="grid gap-3" aria-label="Оплата сессий">
          <h2 className="text-lg font-semibold">Оплата предстоящих сессий</h2>
          {s.charges.map((c) => (
            <Card key={c.id} className={c.id === payTask ? "border-brand" : undefined}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                  <p className="font-medium">
                    {c.session ? sessionDateTime(c.session.starts_at, timezone) : "Сессия"}
                    {c.session?.psychologist ? ` · ${c.session.psychologist}` : ""}
                  </p>
                  <p className="text-sm text-ink-2">
                    {rub(c.amount)}
                    {c.status === "scheduled" && c.due_at ? ` спишется ${sessionDateTime(c.due_at, timezone)}` : ""}
                    {c.balance_part > 0 ? ` (с баланса — ${rub(c.balance_part)})` : ""}
                  </p>
                  {c.status === "retry_wait" && (
                    <p className="text-sm text-danger">
                      {c.last_error_message ?? "Оплата не прошла."} Оплатите до {c.deadline_at ? sessionDateTime(c.deadline_at, timezone) : "начала"} — иначе запись отменится без удержаний.
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={c.status === "retry_wait" ? "danger" : "brand"}>{CHARGE_STATUS[c.status]}</Badge>
                  {c.can_pay && (
                    <Button size="sm" loading={busy === `pay-${c.id}`} onClick={() => pay(c.id)}>
                      Оплатить другой картой
                    </Button>
                  )}
                </div>
              </div>
            </Card>
          ))}
        </section>
      )}

      <section className="grid gap-3" aria-label="Карты">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-semibold">Карты</h2>
          <Button size="sm" variant="secondary" loading={busy === "bind"} onClick={bind}>
            Привязать карту
          </Button>
        </div>
        {s.cards.length === 0 ? (
          <EmptyState title="Карта не привязана" description="Привяжите карту: оплата сессии спишется автоматически за 12 часов до начала. Данные карты хранит платёжный сервис." />
        ) : (
          <ul className="grid gap-3 sm:grid-cols-2">
            {s.cards.map((c) => (
              <li key={c.id}>
                <Card className="flex items-center justify-between gap-3">
                  <div>
                    <p className="font-medium">
                      {c.card_brand} {c.card_mask}
                    </p>
                    <p className="text-sm text-muted">
                      до {String(c.exp_month ?? "").padStart(2, "0")}/{String(c.exp_year ?? "").slice(-2)}
                      {c.is_default ? " · основная" : ""}
                    </p>
                  </div>
                  <div className="flex gap-1">
                    {!c.is_default && (
                      <Button size="sm" variant="ghost" onClick={() => act(`def-${c.id}`, async () => (await makeDefault(c.id), reloadAll()))}>
                        Сделать основной
                      </Button>
                    )}
                    <Button size="sm" variant="ghost" onClick={() => setRemoveTarget(c)}>
                      Удалить
                    </Button>
                  </div>
                </Card>
              </li>
            ))}
          </ul>
        )}
      </section>

      <CertificateActivation onActivated={(text) => (setNotice({ tone: "success", text }), reloadAll())} certificates={s.certificates} />

      <Complaints
        tz={timezone}
        items={complaints.data?.data ?? []}
        openFor={complaintFor}
        onOpen={setComplaintFor}
        onChanged={(text) => {
          setNotice({ tone: "success", text });
          setComplaintFor(null);
          complaints.reload();
          if (complaintSession) router.replace("/client/payments#complaints");
        }}
      />

      <section className="grid gap-3" aria-label="История платежей">
        <h2 className="text-lg font-semibold">История платежей</h2>
        {history.data && history.data.data.length === 0 && <EmptyState title="Платежей пока нет" />}
        <ul className="grid gap-3">
          {(history.data?.data ?? []).map((p) => (
            <li key={p.id}>
              <PaymentCard p={p} tz={timezone} />
            </li>
          ))}
        </ul>
      </section>

      <section className="grid gap-3" aria-label="Движение баланса">
        <h2 className="text-lg font-semibold">Движение баланса</h2>
        {operations.data && operations.data.data.length === 0 && <p className="text-muted">Операций по балансу ещё не было.</p>}
        <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
          {(operations.data?.data ?? []).map((op) => {
            const sign = balanceSign(op.type, op.status);
            return (
              <li key={op.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                <div>
                  <p>{op.reason_label}</p>
                  <p className="text-sm text-muted">
                    {dateTime(op.created_at, timezone)} · {BALANCE_STATUS[op.status] ?? op.status}
                    {op.is_certificate_funds || op.certificate_amount > 0 ? " · средства сертификата" : ""}
                  </p>
                </div>
                <p className={sign > 0 ? "font-medium text-success" : sign < 0 ? "font-medium" : "text-muted line-through"}>
                  {sign > 0 ? "+" : sign < 0 ? "−" : ""}
                  {rub(op.amount)}
                </p>
              </li>
            );
          })}
        </ul>
      </section>

      <Modal
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        title="Удалить карту?"
        footer={
          <>
            <Button variant="secondary" onClick={() => setRemoveTarget(null)}>
              Оставить
            </Button>
            <Button
              variant="danger"
              loading={busy === "remove"}
              onClick={() =>
                act("remove", async () => {
                  const res = await removeCard(removeTarget!.id);
                  setRemoveTarget(null);
                  setNotice(
                    res.unpaid_sessions > 0 && !res.has_other_card
                      ? { tone: "warning", text: `Карта удалена. Неоплаченных записей: ${res.unpaid_sessions}. Привяжите другую карту до ${res.deadline ? sessionDateTime(res.deadline, timezone) : "срока оплаты"}, иначе записи отменятся без удержаний.` }
                      : { tone: "success", text: "Карта удалена." },
                  );
                  reloadAll();
                })
              }
            >
              Удалить
            </Button>
          </>
        }
      >
        <p>
          Карта {removeTarget?.card_mask} больше не будет использоваться для списаний. Если есть неоплаченные записи, привяжите другую карту до срока оплаты — иначе они отменятся без удержаний.
        </p>
      </Modal>

      <Modal
        open={confirmWithdraw}
        onClose={() => setConfirmWithdraw(false)}
        title="Вывод остатка на карту"
        footer={
          <Button
            loading={busy === "withdraw"}
            onClick={() =>
              act("withdraw", async () => {
                await withdraw();
                setConfirmWithdraw(false);
                setNotice({ tone: "success", text: "Заявка на вывод принята. Деньги вернутся на карты, с которых вы оплачивали сессии." });
                reloadAll();
              })
            }
          >
            Вывести {rub(s.balance.withdrawable)}
          </Button>
        }
      >
        <p>
          Сумма вернётся на карты, с которых вы оплачивали сессии, в пределах этих оплат. Срок зачисления зависит от банка. Средства подарочных сертификатов на карту не выводятся.
        </p>
      </Modal>
    </div>
  );
}

function PaymentCard({ p, tz }: { p: PaymentRow; tz: string }) {
  return (
    <Card className="grid gap-2">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="font-medium">
            {p.purpose_label}: {rub(p.amount)}
          </p>
          <p className="text-sm text-muted">
            {dateTime(p.paid_at ?? p.created_at, tz)}
            {p.card_mask ? ` · карта ${p.card_mask}` : ""}
            {p.session ? ` · сессия ${sessionDateTime(p.session.starts_at, tz)}` : ""}
          </p>
          {p.session && p.session.discount > 0 && (
            <p className="text-sm text-success">
              Скидка по промокоду: {rub(p.session.discount)} (цена сессии {rub(p.session.price)})
            </p>
          )}
          {p.session && p.session.paid_balance > 0 && <p className="text-sm text-ink-2">С баланса: {rub(p.session.paid_balance)}</p>}
          {p.error_message && p.status === "declined" && <p className="text-sm text-danger">{p.error_message}</p>}
        </div>
        <Badge tone={PAYMENT_TONE[p.status] ?? "neutral"}>{p.status_label}</Badge>
      </div>
      {p.receipts.length > 0 && (
        <details className="text-sm">
          <summary className="cursor-pointer text-brand">Чеки ({p.receipts.length})</summary>
          <ul className="mt-2 grid gap-1 text-ink-2">
            {p.receipts.map((r) => (
              <li key={r.id}>
                {r.kind_label} · {rub(r.amount)} · {r.calculation_method === "PREPAYMENT_FULL" ? "предоплата 100 %" : r.calculation_method === "ADVANCE" ? "аванс" : r.calculation_method}
                {r.fiscal ? ` · ФН ${r.fiscal.fn}, ФД ${r.fiscal.fd}, ФПД ${r.fiscal.fpd}` : " · регистрируется"}
              </li>
            ))}
          </ul>
        </details>
      )}
      {p.refunds.length > 0 && (
        <p className="text-sm text-ink-2">
          Возвраты на карту: {p.refunds.map((r) => `${rub(r.amount)} (${r.status === "succeeded" ? "выполнен" : r.status === "pending" ? "в обработке" : "не выполнен"})`).join(", ")}
        </p>
      )}
    </Card>
  );
}

function CertificateActivation({ onActivated, certificates }: { onActivated: (text: string) => void; certificates: PaymentsSummary["certificates"] }) {
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await activateCertificate(code);
      setCode("");
      onActivated(`Сертификат на ${rub(res.data.nominal)} активирован: сумма на балансе.`);
    } catch (err) {
      setError(errorMessage(err, "Не удалось активировать сертификат."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="grid gap-3" aria-label="Подарочный сертификат">
      <h2 className="text-lg font-semibold">Подарочный сертификат</h2>
      <Card>
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
          <Field label="Код сертификата" error={error} className="min-w-64 flex-1">
            <Input value={code} onChange={(e) => setCode(normalizeCertificateCode(e.target.value))} placeholder="TETA-XXXX-XXXX-XXXX" autoComplete="off" />
          </Field>
          <Button type="submit" loading={busy} disabled={code.length < 8}>
            Активировать
          </Button>
        </form>
        <p className="mt-3 text-sm text-muted">Вся сумма сертификата зачисляется на баланс и расходуется при оплате сессий. Средства сертификата на карту не выводятся.</p>
        {certificates.length > 0 && (
          <ul className="mt-3 grid gap-1 text-sm text-ink-2">
            {certificates.map((c) => (
              <li key={c.id}>
                Сертификат •••• {c.code_hint} на {rub(c.nominal)} — активирован {date(c.activated_at)}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </section>
  );
}

function Complaints({
  tz,
  items,
  openFor,
  onOpen,
  onChanged,
}: {
  tz: string;
  items: Complaint[];
  openFor: string | null;
  onOpen: (sessionId: string | null) => void;
  onChanged: (text: string) => void;
}) {
  const [sessionId, setSessionId] = useState(openFor ?? "");
  const [reason, setReason] = useState("");
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const formOpen = openFor !== null;
  const past = useResource<SessionsResponse>(formOpen ? "/booking/sessions" : null, { scope: "past" });
  const upcoming = useResource<SessionsResponse>(formOpen ? "/booking/sessions" : null, { scope: "upcoming" });
  const eligible: ClientSession[] = [...(upcoming.data?.data ?? []), ...(past.data?.data ?? [])].filter((s) => s.actions.complaint);
  const selected = sessionId || openFor || "";

  async function run(fn: () => Promise<unknown>, text: string) {
    setBusy(true);
    setError(null);
    try {
      await fn();
      setReason("");
      onChanged(text);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section id="complaints" className="grid gap-3" aria-label="Жалобы на списание">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">Жалобы на списание</h2>
        <Button size="sm" variant="secondary" onClick={() => onOpen("")}>
          Подать жалобу
        </Button>
      </div>
      <p className="text-sm text-muted">Жалоба принимается по списанной оплате. Решение — в течение 14 рабочих дней; при возврате сумма зачисляется на баланс.</p>
      {items.length === 0 && <p className="text-muted">Жалоб нет.</p>}
      <ul className="grid gap-3">
        {items.map((c) => (
          <li key={c.id}>
            <Card className="grid gap-2">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-medium">
                    {c.session ? `Сессия ${sessionDateTime(c.session.starts_at, tz)}` : "Сессия"}
                    {c.session?.psychologist ? ` · ${c.session.psychologist}` : ""}
                  </p>
                  <p className="text-sm text-muted">
                    Подана {date(c.created_at, tz)} · решение до {date(c.due_date)}
                  </p>
                </div>
                <Badge tone={COMPLAINT_TONE[c.status]}>{COMPLAINT_STATUS[c.status]}</Badge>
              </div>
              <ul className="grid gap-1 text-[15px]">
                {c.messages.map((m, i) => (
                  <li key={i} className={m.from === "admin" ? "rounded-xl bg-brand-soft px-3 py-2" : "px-3 py-1 text-ink-2"}>
                    <span className="text-xs text-muted">{m.from === "admin" ? "Администратор" : "Вы"} · {dateTime(m.at, tz)}</span>
                    <br />
                    {m.text}
                  </li>
                ))}
              </ul>
              {c.decision_comment && (
                <p className="text-[15px]">
                  Решение: {c.decision_comment}
                  {c.refund_amount ? ` Возврат ${rub(c.refund_amount)} зачислен на баланс.` : ""}
                </p>
              )}
              {c.status === "waiting_client" && (
                <div className="grid gap-2">
                  <Textarea value={answers[c.id] ?? ""} onChange={(e) => setAnswers((a) => ({ ...a, [c.id]: e.target.value }))} placeholder="Ваш ответ администратору" />
                  <Button size="sm" className="justify-self-start" disabled={!(answers[c.id] ?? "").trim()} loading={busy} onClick={() => run(() => answerComplaint(c.id, answers[c.id]), "Ответ отправлен.")}>
                    Ответить
                  </Button>
                </div>
              )}
              {c.can_withdraw && (
                <Button size="sm" variant="ghost" className="justify-self-start" onClick={() => run(() => withdrawComplaint(c.id), "Жалоба отозвана.")}>
                  Отозвать жалобу
                </Button>
              )}
            </Card>
          </li>
        ))}
      </ul>

      <Modal
        open={formOpen}
        onClose={() => onOpen(null)}
        title="Жалоба на списание"
        footer={
          <Button loading={busy} disabled={!selected || reason.trim().length < 10} onClick={() => run(() => submitComplaint(selected, reason.trim()), "Жалоба принята. Мы ответим в течение 14 рабочих дней.")}>
            Отправить
          </Button>
        }
      >
        <div className="grid gap-4">
          <Field label="Сессия">
            <Select value={selected} onChange={(e) => setSessionId(e.target.value)}>
              <option value="">Выберите сессию</option>
              {eligible.map((s) => (
                <option key={s.id} value={s.id}>
                  {sessionDateTime(s.starts_at, tz)} · {s.psychologist?.name} · {rub(s.amount_charged ?? 0)}
                </option>
              ))}
            </Select>
          </Field>
          {formOpen && eligible.length === 0 && (past.data || upcoming.data) && <p className="text-sm text-muted">Нет сессий со списанной оплатой, по которым можно подать жалобу.</p>}
          <Field label="Что произошло" hint="Минимум 10 символов. Не описывайте своё состояние — достаточно обстоятельств списания.">
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={5000} />
          </Field>
          {error && <Alert tone="danger">{error}</Alert>}
        </div>
      </Modal>
    </section>
  );
}
