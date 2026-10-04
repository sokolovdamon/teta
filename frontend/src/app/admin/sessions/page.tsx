import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { PageHeader } from "@/components/ui";
import { AdminSessions } from "@/features/booking/AdminSessions";
import { requireAdmin } from "@/lib/guard";
import { can } from "@/lib/types";

export const metadata: Metadata = { title: "Сессии" };

/** ADM-04. */
export default async function AdminSessionsPage({ searchParams }: PageProps<"/admin/sessions">) {
  const user = await requireAdmin();
  if (!can(user, "admin.sessions.view")) redirect("/admin");
  const { psychologist_id } = await searchParams;
  return (
    <>
      <PageHeader title="Сессии и журнал сессий" description="Все записи платформы: статусы, оплата, журнал TetaMeet, исправление итогов и отмена платформой." />
      <AdminSessions initialPsychologist={typeof psychologist_id === "string" ? psychologist_id : null} />
    </>
  );
}
