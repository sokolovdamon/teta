import type { Metadata } from "next";
import { Alert, PageHeader } from "@/components/ui";
import { AdminPromo } from "@/features/promo/AdminPromo";
import { requireAdmin } from "@/lib/guard";
import { can } from "@/lib/types";

export const metadata: Metadata = { title: "Промокоды" };

/** ADM-09 */
export default async function AdminPromoPage() {
  const user = await requireAdmin();
  return (
    <>
      <PageHeader title="Промокоды" description="Процентные и фиксированные скидки, льготная первая сессия, пакеты индивидуальных кодов, статистика и программа «Пригласи друга»." />
      {can(user, "admin.promo.view") ? (
        <AdminPromo timezone={user.timezone} permissions={{ manage: can(user, "admin.promo.manage"), export: can(user, "admin.promo.export") }} />
      ) : (
        <Alert tone="warning">Недостаточно прав для раздела «Промокоды».</Alert>
      )}
    </>
  );
}
