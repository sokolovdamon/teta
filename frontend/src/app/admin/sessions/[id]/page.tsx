import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { PageHeader } from "@/components/ui";
import { AdminSessionCard } from "@/features/booking/AdminSessionCard";
import { requireAdmin } from "@/lib/guard";
import { can } from "@/lib/types";

export const metadata: Metadata = { title: "Карточка сессии" };

/** ADM-04: session card. */
export default async function AdminSessionPage({ params }: PageProps<"/admin/sessions/[id]">) {
  const user = await requireAdmin();
  if (!can(user, "admin.sessions.view")) redirect("/admin");
  const { id } = await params;
  return (
    <>
      <Link href="/admin/sessions" className="text-sm text-brand">
        ← Все сессии
      </Link>
      <PageHeader title="Карточка сессии" className="mt-2" />
      <AdminSessionCard id={id} canSetOutcome={can(user, "admin.sessions.set_outcome")} canCancel={can(user, "admin.sessions.cancel")} />
    </>
  );
}
