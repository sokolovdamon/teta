import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { ClientSessions } from "@/features/booking/ClientSessions";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Сессии" };

/** CL-03. ?invite={id} — pair session invitation (Q-54); ?choice={id} — choice after a psychologist's cancel. */
export default async function SessionsPage({ searchParams }: PageProps<"/client/sessions">) {
  const user = await requireUser(["client"]);
  const { invite, choice } = await searchParams;
  return (
    <>
      <PageHeader title="Сессии" description="Предстоящие и прошедшие сессии, вход в TetaMeet, перенос и отмена." />
      <ClientSessions
        timezone={user.timezone}
        invite={typeof invite === "string" ? invite : null}
        choice={typeof choice === "string" ? choice : null}
      />
    </>
  );
}
