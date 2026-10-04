import type { Metadata } from "next";
import { EmptyState, LinkButton } from "@/components/ui";
import { parseTab } from "@/features/crm/access";
import { ClientCardView } from "@/features/crm/ClientCardView";
import type { ClientCardData } from "@/features/crm/types";
import { requireUser } from "@/lib/guard";
import { serverApi } from "@/lib/server";

export const metadata: Metadata = { title: "Карточка клиента" };

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** PRO-05 card. The backend answers 404 to anyone who is not this client's psychologist. */
export default async function Page({ params, searchParams }: PageProps<"/pro/clients/[id]">) {
  const user = await requireUser(["psychologist", "supervisor"]);
  const { id } = await params;
  const { tab } = await searchParams;
  const res = UUID.test(id) ? await serverApi<{ data: ClientCardData }>(`/pro/clients/${id}`) : null;

  if (!res) {
    return (
      <EmptyState
        title="Карточка недоступна"
        description="Такого клиента нет среди ваших: карточки видят только психологи, к которым клиент записывался."
        action={<LinkButton href="/pro/clients" variant="secondary">Все клиенты</LinkButton>}
      />
    );
  }

  return <ClientCardView initial={res.data} timezone={user.timezone} initialTab={parseTab(tab)} />;
}
