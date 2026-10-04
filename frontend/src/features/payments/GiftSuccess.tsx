"use client";

import { useEffect, useState } from "react";
import { Alert, Card, LinkButton } from "@/components/ui";
import { date, rub } from "@/lib/format";
import { errorMessage } from "@/features/booking/useResource";
import { certificateStatus } from "./api";

type Status = Awaited<ReturnType<typeof certificateStatus>>;

/** SITE-20 success page: waits until the certificate is paid (the code itself is only sent by email). */
export function GiftSuccess({ id }: { id: string | null }) {
  const [c, setC] = useState<Status | null>(null);
  const [error, setError] = useState<string | null>(id ? null : "Сертификат не найден.");

  useEffect(() => {
    if (!id) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let polls = 0;
    const tick = () =>
      certificateStatus(id).then(
        (data) => {
          if (cancelled) return;
          setC(data);
          if (data.status === "awaiting_payment" && ++polls < 30) timer = setTimeout(tick, 2000);
        },
        (e) => !cancelled && setError(errorMessage(e, "Сертификат не найден.")),
      );
    tick();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [id]);

  if (error) return <Alert tone="danger">{error}</Alert>;
  if (!c) return <p className="text-muted">Проверяем оплату…</p>;

  if (c.status === "unpaid") {
    return (
      <Alert tone="danger" title="Оплата не прошла">
        Сертификат не выпущен, деньги не списаны. <a className="text-brand underline" href="/gift">Попробовать ещё раз</a>
      </Alert>
    );
  }
  if (c.status === "awaiting_payment") return <p className="text-muted">Ждём подтверждения оплаты…</p>;

  return (
    <Card className="grid justify-items-start gap-3">
      <h1 className="text-2xl font-semibold">Сертификат на {rub(c.nominal)} оплачен</h1>
      <p className="text-ink-2">
        {c.send_to === "recipient" ? "Код отправлен получателю на email. Вам пришло подтверждение покупки." : "Код отправлен вам на email — подарите его лично."} Сертификат действует до{" "}
        {date(c.valid_until)}.
      </p>
      <LinkButton href="/">На главную</LinkButton>
    </Card>
  );
}
