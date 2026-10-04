"use client";

import { useEffect, useState } from "react";
import { Alert, Badge, Button, Card, Field, Input } from "@/components/ui";
import { rub } from "@/lib/format";
import { errorMessage } from "@/features/booking/useResource";
import { emulator3ds, emulatorCancel, emulatorOperation, emulatorSubmitCard } from "./api";
import { formatCardNumber } from "./labels";
import type { EmulatorOperation } from "./types";

/**
 * Checkout of the payment emulator (DEC-38): the payer enters a test card, passes or declines 3-D Secure, and is
 * returned to the platform. A real provider replaces this page with its own form; nothing else changes.
 */
export function EmulatorCheckout({ id }: { id: string }) {
  const [op, setOp] = useState<EmulatorOperation | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [number, setNumber] = useState("");
  const [exp, setExp] = useState("12/30");
  const [cvc, setCvc] = useState("123");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    emulatorOperation(id).then(
      (data) => !cancelled && setOp(data),
      (e) => !cancelled && setLoadError(errorMessage(e, "Операция не найдена.")),
    );
    return () => {
      cancelled = true;
    };
  }, [id]);

  // Final status: back to the platform after a short pause.
  const returnUrl = op && ["succeeded", "declined"].includes(op.status) ? op.return_url : null;
  useEffect(() => {
    if (!returnUrl) return;
    const t = setTimeout(() => window.location.assign(returnUrl), 1500);
    return () => clearTimeout(t);
  }, [returnUrl]);

  async function run(fn: () => Promise<EmulatorOperation>) {
    setBusy(true);
    setError(null);
    try {
      setOp(await fn());
    } catch (e) {
      setError(errorMessage(e, "Не удалось выполнить операцию."));
    } finally {
      setBusy(false);
    }
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    const [mm, yy] = exp.split("/").map((v) => Number(v.trim()));
    if (!mm || !yy) {
      setError("Укажите срок действия в формате ММ/ГГ.");
      return;
    }
    run(() => emulatorSubmitCard(id, { card_number: number.replace(/\s/g, ""), exp_month: mm, exp_year: yy, cvc }));
  }

  if (loadError) return <Alert tone="danger" title="Оплата недоступна">{loadError}</Alert>;
  if (!op) return <p className="text-muted">Загрузка…</p>;

  return (
    <div className="grid gap-6">
      <div className="grid gap-1">
        <Badge tone="warning" className="justify-self-start">
          Тестовый платёжный сервис (эмулятор)
        </Badge>
        <h1 className="text-2xl font-semibold">{op.kind === "binding" ? "Привязка карты" : "Оплата"}</h1>
        <p className="text-ink-2">{op.description}</p>
        {op.kind === "payment" && <p className="text-3xl font-semibold tracking-tight">{rub(op.amount, { fraction: op.amount % 100 !== 0 })}</p>}
      </div>

      {op.status === "requires_action" && (
        <Card>
          <form onSubmit={submit} className="grid gap-4">
            <Field label="Номер карты">
              <Input inputMode="numeric" autoComplete="cc-number" value={number} onChange={(e) => setNumber(formatCardNumber(e.target.value))} placeholder="4111 1111 1111 1111" />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Срок действия">
                <Input inputMode="numeric" autoComplete="cc-exp" value={exp} onChange={(e) => setExp(e.target.value)} placeholder="ММ/ГГ" />
              </Field>
              <Field label="CVC">
                <Input inputMode="numeric" autoComplete="cc-csc" value={cvc} onChange={(e) => setCvc(e.target.value.replace(/\D/g, "").slice(0, 4))} />
              </Field>
            </div>
            {error && <Alert tone="danger">{error}</Alert>}
            <div className="flex flex-wrap gap-3">
              <Button type="submit" loading={busy} disabled={number.replace(/\s/g, "").length < 13}>
                {op.kind === "binding" ? "Привязать карту" : "Оплатить"}
              </Button>
              <Button type="button" variant="ghost" disabled={busy} onClick={() => run(() => emulatorCancel(id))}>
                Отменить
              </Button>
            </div>
          </form>
        </Card>
      )}

      {op.status === "awaiting_3ds" && (
        <Card className="grid gap-4">
          <p className="text-sm font-medium text-muted">Страница банка-эмитента · 3-D Secure</p>
          <p>
            Подтвердите {op.kind === "binding" ? "привязку карты" : `оплату ${rub(op.amount)}`} картой {op.card_mask}. В реальном банке здесь вводится код из SMS.
          </p>
          {error && <Alert tone="danger">{error}</Alert>}
          <div className="flex flex-wrap gap-3">
            <Button loading={busy} onClick={() => run(() => emulator3ds(id, "confirm"))}>
              Подтвердить
            </Button>
            <Button variant="secondary" disabled={busy} onClick={() => run(() => emulator3ds(id, "decline"))}>
              Отклонить
            </Button>
          </div>
        </Card>
      )}

      {(op.status === "succeeded" || op.status === "declined") && (
        <Alert tone={op.status === "succeeded" ? "success" : "danger"} title={op.status === "succeeded" ? "Готово" : "Операция отклонена"}>
          {op.status === "succeeded" ? "Операция выполнена." : `Причина: ${op.error_code ?? "отказ"}.`} Возвращаем вас в ТЕТА…{" "}
          {op.return_url && (
            <a className="text-brand underline" href={op.return_url}>
              Вернуться сейчас
            </a>
          )}
        </Alert>
      )}

      <details className="rounded-2xl border border-line bg-surface p-5" open={op.status === "requires_action"}>
        <summary className="cursor-pointer font-medium">Тестовые карты</summary>
        <ul className="mt-3 grid gap-2 text-[15px]">
          {op.test_cards.map((c) => (
            <li key={c.number} className="flex flex-wrap items-center gap-2">
              <button type="button" className="font-mono text-brand underline" onClick={() => setNumber(c.number)} disabled={op.status !== "requires_action"}>
                {c.number}
              </button>
              <span className="text-ink-2">— {c.title}</span>
            </li>
          ))}
        </ul>
        <p className="mt-3 text-sm text-muted">Срок действия — любой в будущем, CVC — любые 3 цифры. {op.binding_note}</p>
        <p className="mt-1 text-sm text-muted">{op.timeout_rule}</p>
      </details>
    </div>
  );
}
