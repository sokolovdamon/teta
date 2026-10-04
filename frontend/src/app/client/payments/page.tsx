import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { ClientPayments } from "@/features/payments/ClientPayments";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Платежи и баланс" };

/** CL-07. ?pay={task} — pay a failed charge; ?complaint={session} — new complaint; ?bound=1 — back from card binding. */
export default async function PaymentsPage({ searchParams }: PageProps<"/client/payments">) {
  const user = await requireUser(["client"]);
  const { pay, complaint, bound } = await searchParams;
  return (
    <>
      <PageHeader title="Платежи и баланс" description="Баланс личного кабинета, карты, оплата сессий, чеки, жалобы на списание и подарочные сертификаты." />
      <ClientPayments
        timezone={user.timezone}
        payTask={typeof pay === "string" ? pay : null}
        complaintSession={typeof complaint === "string" ? complaint : null}
        bound={bound === "1"}
      />
    </>
  );
}
