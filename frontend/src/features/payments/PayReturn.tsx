"use client";

import { useEffect, useState } from "react";
import { Alert, Card, LinkButton } from "@/components/ui";
import { errorMessage } from "@/features/booking/useResource";
import { bindingStatus, paymentStatus } from "./api";
import { bindingOutcome, paymentOutcome, safeNext, type ReturnOutcome } from "./returnOutcome";

const POLL_MS = 2000;
const MAX_POLLS = 30;

/** /pay/return: the payer is back from the checkout; the result is polled until it is final. */
export function PayReturn({ payment, binding }: { payment?: string | null; binding?: string | null }) {
  const [outcome, setOutcome] = useState<ReturnOutcome | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [checkout, setCheckout] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    let polls = 0;
    let timer: ReturnType<typeof setTimeout> | null = null;

    async function tick() {
      polls++;
      try {
        let result: ReturnOutcome;
        if (payment) {
          const p = await paymentStatus(payment);
          result = paymentOutcome(p);
          if (!cancelled) setCheckout(p.status === "requires_3ds" ? p.confirmation_url : null);
        } else if (binding) {
          result = bindingOutcome(await bindingStatus(binding));
        } else {
          setError("Не указана операция.");
          return;
        }
        if (cancelled) return;
        setOutcome(result);
        if (result.state === "pending" && polls < MAX_POLLS) timer = setTimeout(tick, POLL_MS);
      } catch (e) {
        if (!cancelled) setError(errorMessage(e, "Не удалось проверить оплату."));
      }
    }
    tick();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [payment, binding]);

  if (error) return <Alert tone="danger" title="Ошибка">{error}</Alert>;
  if (!outcome) return <p className="text-muted">Проверяем результат…</p>;

  return (
    <Card className="grid justify-items-start gap-4">
      <h1 className="text-2xl font-semibold">{outcome.title}</h1>
      <p className="text-ink-2">{outcome.text}</p>
      {outcome.state === "pending" && <span className="size-6 animate-spin rounded-full border-2 border-brand border-t-transparent" aria-label="Ожидание" />}
      <div className="flex flex-wrap gap-3">
        {checkout && <LinkButton href={checkout}>Завершить оплату</LinkButton>}
        <LinkButton href={safeNext(outcome.next)} variant={outcome.state === "success" ? "primary" : "secondary"}>
          {outcome.nextLabel}
        </LinkButton>
      </div>
    </Card>
  );
}
