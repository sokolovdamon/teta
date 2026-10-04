import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { PageHeader } from "@/components/ui";
import { AdminFinance, type FinanceTab } from "@/features/payments/AdminFinance";
import { requireAdmin } from "@/lib/guard";
import { can } from "@/lib/types";

export const metadata: Metadata = { title: "Финансы" };

const TABS: FinanceTab[] = ["summary", "payments", "refunds", "complaints", "balance", "charges"];

/** ADM-07: ?tab=summary|payments|refunds|complaints|balance|charges. */
export default async function AdminFinancePage({ searchParams }: PageProps<"/admin/finance">) {
  const user = await requireAdmin();
  if (!can(user, "admin.finance.view")) redirect("/admin");
  const { tab } = await searchParams;
  const current = TABS.includes(tab as FinanceTab) ? (tab as FinanceTab) : "summary";
  return (
    <>
      <PageHeader title="Финансы" description="Платежи, возвраты, жалобы на списание (срок 14 рабочих дней), балансы клиентов и неуспешные списания." />
      <AdminFinance tab={current} perms={{ refund: can(user, "admin.finance.refund"), complaints: can(user, "admin.finance.complaints") }} />
    </>
  );
}
