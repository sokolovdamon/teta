import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { InviteFriend } from "@/features/promo/InviteFriend";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Пригласить друга" };

/** CL-13 */
export default async function InvitePage() {
  const user = await requireUser(["client"]);
  return (
    <>
      <PageHeader title="Пригласить друга" description="Подарите другу скидку на первую сессию с психологом и получите промокод для себя." />
      <InviteFriend timezone={user.timezone} />
    </>
  );
}
