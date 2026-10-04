import type { Metadata } from "next";
import { Alert, PageHeader } from "@/components/ui";
import { AdminPayouts } from "@/features/payouts/AdminPayouts";
import { requireAdmin } from "@/lib/guard";
import { can } from "@/lib/types";

export const metadata: Metadata = { title: "Выплаты" };

/** ADM-08 */
export default async function AdminPayoutsPage() {
  const user = await requireAdmin();
  return (
    <>
      <PageHeader title="Выплаты" description="Еженедельные реестры выплат психологам и супервизорам, блокировки по требованию супервизии, приостановка выплат и правила автовывода." />
      {can(user, "admin.payouts.view") ? (
        <AdminPayouts timezone={user.timezone} permissions={{ approve: can(user, "admin.payouts.approve"), manage: can(user, "admin.payouts.manage") }} />
      ) : (
        <Alert tone="warning">Недостаточно прав для раздела «Выплаты».</Alert>
      )}
    </>
  );
}
