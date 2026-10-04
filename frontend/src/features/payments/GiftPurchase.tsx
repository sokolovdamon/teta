"use client";

import Link from "next/link";
import { useState } from "react";
import { Alert, Button, Card, Checkbox, Chip, Field, Input, Textarea } from "@/components/ui";
import { rub } from "@/lib/format";
import { useForm } from "@/lib/useForm";
import { buyCertificate } from "./api";

type Values = {
  nominal: number;
  buyer_email: string;
  buyer_name: string;
  recipient_name: string;
  recipient_email: string;
  message: string;
  send_to: "recipient" | "buyer";
  accept_offer: boolean;
  accept_personal_data: boolean;
};

/** SITE-20: anyone can buy a certificate; payment in the provider's form; the code is emailed after payment. */
export function GiftPurchase({ nominals, validityDays, email }: { nominals: number[]; validityDays: number; email?: string | null }) {
  const form = useForm<Values>({
    nominal: nominals[1] ?? nominals[0] ?? 0,
    buyer_email: email ?? "",
    buyer_name: "",
    recipient_name: "",
    recipient_email: "",
    message: "",
    send_to: "recipient",
    accept_offer: false,
    accept_personal_data: false,
  });
  const [redirecting, setRedirecting] = useState(false);
  const v = form.values;

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.submit(async (values) => {
      const res = await buyCertificate({
        ...values,
        recipient_email: values.send_to === "recipient" ? values.recipient_email : undefined,
        buyer_name: values.buyer_name || undefined,
        recipient_name: values.recipient_name || undefined,
        message: values.message || undefined,
      });
      if (res.confirmation_url) {
        setRedirecting(true);
        window.location.assign(res.confirmation_url);
      }
    });
  }

  return (
    <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[1fr_320px]">
      <div className="grid gap-6">
        <Card className="grid gap-3">
          <h2 className="text-lg font-semibold">Номинал</h2>
          <div className="flex flex-wrap gap-2">
            {nominals.map((n) => (
              <Chip key={n} active={v.nominal === n} onClick={() => form.set("nominal", n)}>
                {rub(n)}
              </Chip>
            ))}
          </div>
          {form.errors.nominal && <p className="text-sm text-danger">{form.errors.nominal}</p>}
          <p className="text-sm text-muted">
            Сертификат действует {validityDays} дней. Получатель активирует код в личном кабинете — вся сумма поступит на его баланс и будет списываться при оплате сессий.
          </p>
        </Card>

        <Card className="grid gap-4">
          <h2 className="text-lg font-semibold">Кому</h2>
          <Field label="Имя получателя" error={form.errors.recipient_name}>
            <Input value={v.recipient_name} onChange={(e) => form.set("recipient_name", e.target.value)} maxLength={100} />
          </Field>
          <div className="grid gap-2">
            <label className="flex items-center gap-2 text-[15px]">
              <input type="radio" name="send_to" className="accent-brand" checked={v.send_to === "recipient"} onChange={() => form.set("send_to", "recipient")} />
              Отправить код получателю на email
            </label>
            <label className="flex items-center gap-2 text-[15px]">
              <input type="radio" name="send_to" className="accent-brand" checked={v.send_to === "buyer"} onChange={() => form.set("send_to", "buyer")} />
              Отправить код мне — подарю сам(а)
            </label>
          </div>
          {v.send_to === "recipient" && (
            <Field label="Email получателя" error={form.errors.recipient_email}>
              <Input type="email" value={v.recipient_email} onChange={(e) => form.set("recipient_email", e.target.value)} required />
            </Field>
          )}
          <Field label="Поздравление" hint="Попадёт в письмо с кодом." error={form.errors.message}>
            <Textarea value={v.message} onChange={(e) => form.set("message", e.target.value)} maxLength={500} />
          </Field>
        </Card>

        <Card className="grid gap-4">
          <h2 className="text-lg font-semibold">От кого</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Ваш email" hint="Пришлём подтверждение и чек." error={form.errors.buyer_email}>
              <Input type="email" value={v.buyer_email} onChange={(e) => form.set("buyer_email", e.target.value)} required />
            </Field>
            <Field label="Ваше имя" error={form.errors.buyer_name}>
              <Input value={v.buyer_name} onChange={(e) => form.set("buyer_name", e.target.value)} maxLength={100} />
            </Field>
          </div>
          <Checkbox
            checked={v.accept_offer}
            onChange={(e) => form.set("accept_offer", e.target.checked)}
            label={
              <>
                Принимаю условия{" "}
                <Link href="/legal/offer" className="text-brand underline" target="_blank">
                  публичной оферты
                </Link>
              </>
            }
          />
          {form.errors.accept_offer && <p className="text-sm text-danger">{form.errors.accept_offer}</p>}
          <Checkbox
            checked={v.accept_personal_data}
            onChange={(e) => form.set("accept_personal_data", e.target.checked)}
            label={
              <>
                Даю{" "}
                <Link href="/legal/personal-data" className="text-brand underline" target="_blank">
                  согласие на обработку персональных данных
                </Link>
              </>
            }
          />
          {form.errors.accept_personal_data && <p className="text-sm text-danger">{form.errors.accept_personal_data}</p>}
        </Card>
      </div>

      <aside className="lg:sticky lg:top-24 lg:self-start">
        <Card className="grid gap-4">
          <p className="text-sm text-muted">К оплате</p>
          <p className="text-3xl font-semibold tracking-tight">{rub(v.nominal)}</p>
          <p className="text-sm text-ink-2">Отдельный платёж с чеком. Сертификат — предоплата консультаций, а не скидка.</p>
          {form.message && <Alert tone="danger">{form.message}</Alert>}
          <Button type="submit" size="lg" loading={form.submitting || redirecting} disabled={!v.accept_offer || !v.accept_personal_data}>
            Перейти к оплате
          </Button>
        </Card>
      </aside>
    </form>
  );
}
