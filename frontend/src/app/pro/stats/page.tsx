import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { ProStats } from "@/features/payouts/ProStats";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Статистика и доход" };

/** PRO-08 */
export default async function StatsPage() {
  const user = await requireUser(["psychologist", "supervisor"]);
  return (
    <>
      <PageHeader
        title="Статистика и доход"
        description="Проведённые сессии, начисления за вычетом комиссии платформы, сторно с причинами и выплаты. Отчёт за период можно скачать в CSV."
      />
      <ProStats timezone={user.timezone} />
    </>
  );
}
