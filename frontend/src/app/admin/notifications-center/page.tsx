import type { Metadata } from "next";
import { NotificationsList } from "@/components/account/NotificationsList";
import { PageHeader } from "@/components/ui";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Уведомления" };

export default async function NotificationsPage() {
  const user = await requireUser();
  return (
    <>
      <PageHeader title="Уведомления" />
      <NotificationsList timezone={user.timezone} />
    </>
  );
}
