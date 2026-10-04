import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { ClientsList } from "@/features/crm/ClientsList";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Клиенты" };

/** PRO-05: clients of the psychologist. */
export default async function Page() {
  const user = await requireUser(["psychologist", "supervisor"]);
  return (
    <>
      <PageHeader title="Клиенты" description="Клиенты, которые записывались к вам. Карточка, динамика дневника, рекомендации и личные заметки — только для вас." />
      <ClientsList timezone={user.timezone} />
    </>
  );
}
