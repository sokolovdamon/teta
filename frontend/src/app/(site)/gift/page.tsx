import type { Metadata } from "next";
import { GiftPurchase } from "@/features/payments/GiftPurchase";
import { getCurrentUser, serverApi } from "@/lib/server";

export const metadata: Metadata = {
  title: "Подарочный сертификат на консультации психолога",
  description: "Подарите сертификат ТЕТА на онлайн-консультации дипломированного психолога: номинал на выбор, код приходит на email, действует год.",
  alternates: { canonical: "/gift" },
};

/** SITE-20 (DEC-43). */
export default async function GiftPage() {
  const [options, user] = await Promise.all([
    serverApi<{ data: { nominals: number[]; validity_days: number } }>("/payments/gift/options", { auth: false, revalidate: 300 }).catch(() => null),
    getCurrentUser(),
  ]);
  const nominals = options?.data.nominals ?? [300000, 500000, 1000000];
  const validity = options?.data.validity_days ?? 365;

  return (
    <main className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
      <p className="text-sm font-semibold uppercase tracking-wider text-brand">Подарочный сертификат</p>
      <h1 className="mt-3 max-w-3xl text-3xl font-bold tracking-tight sm:text-5xl">Подарите поддержку дипломированного психолога</h1>
      <p className="mt-4 max-w-2xl text-lg text-ink-2">
        Получатель сам выберет специалиста и удобное время. Сумма сертификата поступит на его баланс в личном кабинете и будет расходоваться на сессии.
      </p>
      <div className="mt-10">
        <GiftPurchase nominals={nominals} validityDays={validity} email={user?.email ?? null} />
      </div>
    </main>
  );
}
