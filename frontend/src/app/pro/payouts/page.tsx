import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { ProPayouts } from "@/features/payouts/ProPayouts";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Вывод средств" };

/** PRO-09. After the gateway confirmation page the browser returns here with ?binding={id}. */
export default async function PayoutsPage({ searchParams }: PageProps<"/pro/payouts">) {
  const user = await requireUser(["psychologist", "supervisor"]);
  const { binding } = await searchParams;
  return (
    <>
      <PageHeader title="Вывод средств" description="Баланс за вычетом комиссии, еженедельная выплата на карту самозанятого и условие ежемесячной супервизии." />
      <ProPayouts timezone={user.timezone} bindingId={typeof binding === "string" ? binding : null} />
    </>
  );
}
