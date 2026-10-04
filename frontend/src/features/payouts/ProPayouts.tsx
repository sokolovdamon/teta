"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { Alert, Badge, Button, Card, EmptyState, LinkButton, Modal, Table, type Column } from "@/components/ui";
import { date, dateTime, rub } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { confirmCardBinding, removeCard, startCardBinding } from "./api";
import { payoutTone } from "./labels";
import type { CardBinding, PayoutLine, PayoutOverview } from "./types";
import { errorMessage, useResource } from "./useResource";

type Notice = { tone: "success" | "danger" | "info"; text: string } | null;

/**
 * PRO-09: balance net of commission (DEC-20), automatic weekly payout to the self-employed card, the monthly
 * supervision requirement (DEC-21, DEC-37), payout card and payout history.
 */
export function ProPayouts({ timezone, bindingId }: { timezone: string; bindingId?: string | null }) {
  const router = useRouter();
  const overview = useResource<{ data: PayoutOverview }>("/pro/payouts");
  const [page, setPage] = useState(1);
  const history = useResource<Paginated<PayoutLine>>("/pro/payouts/history", { page, per_page: 10 });
  const [notice, setNotice] = useState<Notice>(null);
  const [busy, setBusy] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);
  const handled = useRef<string | null>(null);

  // Back from the gateway confirmation page: finish the binding, polling while the gateway is still processing.
  const reloadOverview = overview.reload;
  useEffect(() => {
    if (!bindingId || handled.current === bindingId) return;
    handled.current = bindingId;
    (async () => {
      let result: CardBinding | null = null;
      for (let attempt = 0; attempt < 6; attempt++) {
        try {
          result = await confirmCardBinding(bindingId);
        } catch (e) {
          setNotice({ tone: "danger", text: errorMessage(e, "Не удалось проверить привязку карты.") });
          return;
        }
        if (result.status !== "pending") break;
        await new Promise((r) => setTimeout(r, 2000));
      }
      if (!result) return;
      if (result.status === "succeeded") setNotice({ tone: "success", text: `Карта ${result.card?.card_mask ?? ""} привязана для выплат.` });
      else if (result.status === "declined") setNotice({ tone: "danger", text: "Банк отклонил привязку карты. Попробуйте другую карту." });
      else setNotice({ tone: "info", text: "Платёжный сервис ещё проверяет карту. Обновите страницу через минуту." });
      reloadOverview();
      router.replace("/pro/payouts");
    })();
  }, [bindingId, reloadOverview, router]);

  async function bindCard() {
    setBusy(true);
    setNotice(null);
    try {
      const binding = await startCardBinding();
      if (binding.status === "pending" && binding.confirmation_url) {
        window.location.assign(binding.confirmation_url);
        return;
      }
      if (binding.status === "succeeded") {
        setNotice({ tone: "success", text: `Карта ${binding.card?.card_mask ?? ""} привязана для выплат.` });
        overview.reload();
      } else {
        setNotice({ tone: "danger", text: "Банк отклонил привязку карты. Попробуйте другую карту." });
      }
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e, "Не удалось начать привязку карты.") });
    } finally {
      setBusy(false);
    }
  }

  async function doRemove() {
    setBusy(true);
    try {
      await removeCard();
      setConfirmRemove(false);
      setNotice({ tone: "info", text: "Карта для выплат удалена. Пока карты нет, начисления копятся на балансе." });
      overview.reload();
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    } finally {
      setBusy(false);
    }
  }

  if (overview.error && !overview.data) {
    return (
      <Alert tone="danger" title="Раздел не загрузился">
        {overview.error}{" "}
        <button type="button" className="text-brand underline" onClick={overview.reload}>
          Повторить
        </button>
      </Alert>
    );
  }
  const o = overview.data?.data;
  if (!o) return <p className="text-muted">Загрузка…</p>;

  const ready = o.checks.every((c) => c.ok);
  const sup = o.supervision;

  return (
    <div className="grid gap-6">
      {notice && <Alert tone={notice.tone === "info" ? "info" : notice.tone}>{notice.text}</Alert>}
      {o.balance.payouts_suspended && (
        <Alert tone="danger" title="Выплаты приостановлены администратором">
          {o.balance.suspended_reason ?? "Причина не указана."} Начисления сохраняются на балансе. Если есть вопросы, напишите в техподдержку.
        </Alert>
      )}

      <section aria-label="Баланс" className="grid gap-4 sm:grid-cols-3">
        <Card className="grid gap-1 sm:col-span-1">
          <p className="text-sm text-muted">Доступно к выплате</p>
          <p className="text-3xl font-semibold tracking-tight">{rub(o.balance.available)}</p>
          <p className="text-sm text-ink-2">за вычетом комиссии платформы {o.commission_percent} %</p>
        </Card>
        <Card className="grid gap-1">
          <p className="text-sm text-muted">В выплате</p>
          <p className="text-2xl font-semibold tracking-tight">{rub(o.balance.in_payout)}</p>
          <p className="text-sm text-ink-2">отправлено на карту, ждёт зачисления</p>
        </Card>
        <Card className="grid gap-1">
          <p className="text-sm text-muted">Выплачено всего</p>
          <p className="text-2xl font-semibold tracking-tight">{rub(o.balance.paid_total)}</p>
          <Link href="/pro/stats" className="text-sm text-brand">
            Статистика и начисления →
          </Link>
        </Card>
      </section>

      <Card className="grid gap-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-lg font-semibold">Автоматическая выплата</h2>
          <p className="text-ink-2">
            Следующая: <span className="font-medium text-ink">{dateTime(o.next_payout_at, timezone)}</span>
          </p>
        </div>
        <p className="text-ink-2">
          Раз в неделю платформа сама отправляет доступный баланс на карту самозанятого, если выполнены условия ниже. Минимальная сумма выплаты — {rub(o.min_amount)}; меньшая сумма
          переходит в следующую неделю.
        </p>
        <ul className="grid gap-2">
          {o.checks.map((c) => (
            <li key={c.code} className="flex items-start gap-3">
              <span aria-hidden className={c.ok ? "mt-0.5 text-success" : "mt-0.5 text-danger"}>
                {c.ok ? "✓" : "✕"}
              </span>
              <span>
                <span className="font-medium">{c.title}</span>
                <span className="sr-only">{c.ok ? " — выполнено" : " — не выполнено"}</span>
                {c.hint && <span className="block text-sm text-muted">{c.hint}</span>}
              </span>
            </li>
          ))}
        </ul>
        {ready ? (
          <Alert tone="success">Все условия выполнены — баланс уйдёт на карту в ближайшую выплату.</Alert>
        ) : (
          o.last_line &&
          ["blocked_supervision", "deferred", "rejected", "excluded"].includes(o.last_line.status) && (
            <Alert tone="warning" title={`Последняя выплата: ${o.last_line.status_label.toLowerCase()}`}>
              {o.last_line.reason}
            </Alert>
          )
        )}
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="grid content-start gap-3">
          <div className="flex items-center justify-between gap-2">
            <h2 className="text-lg font-semibold">Супервизия этого месяца</h2>
            {sup.applies ? <Badge tone={sup.met ? "success" : "danger"}>{sup.met ? "Пройдена" : "Не пройдена"}</Badge> : <Badge tone="neutral">Не требуется</Badge>}
          </div>
          <p className="text-ink-2">
            Вывести заработанное можно, только если в текущем календарном месяце (по московскому времени) пройдена и оплачена супервизия — индивидуальная или групповая. До этого
            начисления копятся на балансе и ничего не теряется.
          </p>
          {sup.applies && !sup.met && (
            <Alert tone="warning">
              Запишитесь на супервизию до {date(sup.deadline, timezone)}. После того как она будет засчитана, выплата пройдёт в ближайший понедельник.
            </Alert>
          )}
          {!sup.applies && <p className="text-sm text-muted">Требование действует с первого полного календарного месяца после подтверждения квалификации.</p>}
          <div>
            <LinkButton href="/pro/supervision" variant="secondary" size="sm">
              Перейти к супервизии
            </LinkButton>
          </div>
        </Card>

        <Card className="grid content-start gap-3">
          <h2 className="text-lg font-semibold">Карта для выплат</h2>
          {o.card ? (
            <div className="flex items-center justify-between gap-3 rounded-xl border border-line px-4 py-3">
              <div>
                <p className="font-medium">{o.card.card_mask}</p>
                <p className="text-sm text-muted">
                  {o.card.card_brand ?? "Карта"}
                  {o.card.exp_month && o.card.exp_year ? ` · до ${String(o.card.exp_month).padStart(2, "0")}/${String(o.card.exp_year).slice(-2)}` : ""}
                </p>
              </div>
              <Badge tone="success">Привязана</Badge>
            </div>
          ) : (
            <EmptyState title="Карта не привязана" description="Привяжите карту, оформленную на вас как на самозанятого, — на неё будут приходить еженедельные выплаты." />
          )}
          <div className="flex flex-wrap gap-3">
            <Button onClick={bindCard} loading={busy && !confirmRemove}>
              {o.card ? "Сменить карту" : "Привязать карту"}
            </Button>
            {o.card && (
              <Button variant="ghost" onClick={() => setConfirmRemove(true)}>
                Удалить карту
              </Button>
            )}
          </div>
          <p className="text-sm text-muted">
            Номер карты хранит платёжный сервис, платформа получает только маску. Статус самозанятого платформа не проверяет, чек в «Мой налог» вы формируете сами.
          </p>
        </Card>
      </div>

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">История выплат</h2>
        {history.error && <Alert tone="danger">{history.error}</Alert>}
        {!history.data && history.loading && <p className="text-muted">Загрузка…</p>}
        {history.data && (
          <>
            <Table
              columns={historyColumns(timezone)}
              rows={history.data.data}
              rowKey={(r) => r.id}
              empty={<EmptyState title="Выплат пока не было" description="Первая выплата придёт в понедельник после проведённых сессий, если выполнены условия выше." />}
            />
            {history.data.meta.last_page > 1 && (
              <div className="flex items-center gap-3">
                <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  Назад
                </Button>
                <span className="text-sm text-muted">
                  Страница {history.data.meta.current_page} из {history.data.meta.last_page}
                </span>
                <Button variant="secondary" size="sm" disabled={page >= history.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                  Дальше
                </Button>
              </div>
            )}
          </>
        )}
      </section>

      <Modal
        open={confirmRemove}
        onClose={() => setConfirmRemove(false)}
        title="Удалить карту для выплат?"
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirmRemove(false)}>
              Отмена
            </Button>
            <Button variant="danger" loading={busy} onClick={doRemove}>
              Удалить
            </Button>
          </>
        }
      >
        <p className="text-ink-2">Пока новая карта не привязана, выплаты не отправляются, а начисления копятся на балансе.</p>
      </Modal>
    </div>
  );
}

function historyColumns(timezone: string): Column<PayoutLine>[] {
  return [
    { key: "date", title: "Дата", render: (r) => <span className="whitespace-nowrap">{date(r.paid_at ?? r.sent_at ?? r.created_at, timezone)}</span> },
    { key: "amount", title: "Сумма", className: "text-right", render: (r) => <span className="font-medium whitespace-nowrap">{rub(r.amount)}</span> },
    { key: "card", title: "Карта", render: (r) => r.card_mask ?? "—" },
    { key: "status", title: "Статус", render: (r) => <Badge tone={payoutTone[r.status]}>{r.status_label}</Badge> },
    { key: "reason", title: "Комментарий", render: (r) => <span className="text-sm text-ink-2">{r.reason ?? "—"}</span> },
  ];
}
