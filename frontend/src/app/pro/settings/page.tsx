import type { Metadata } from "next";
import { AccountSettings } from "@/components/account/AccountSettings";
import { PageHeader } from "@/components/ui";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Настройки" };

export default async function SettingsPage() {
  const user = await requireUser();
  return (
    <>
      <PageHeader title="Настройки" description="Профиль, email, пароль, подписки и согласия" />
      <AccountSettings user={user} />
    </>
  );
}
