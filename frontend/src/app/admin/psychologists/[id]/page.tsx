import type { Metadata } from "next";
import { AdminPsychologistCard } from "@/features/psychologists/AdminPsychologistCard";
import { Forbidden } from "@/features/site/Forbidden";
import { requireAdmin } from "@/lib/guard";
import { can } from "@/lib/types";

export const metadata: Metadata = { title: "Карточка психолога" };

/** ADM-03 card */
export default async function AdminPsychologistPage({ params }: PageProps<"/admin/psychologists/[id]">) {
  const user = await requireAdmin();
  if (!can(user, "admin.psychologists.view")) return <Forbidden section="Психологи" />;
  const { id } = await params;
  return (
    <AdminPsychologistCard
      id={id}
      timezone={user.timezone}
      permissions={{
        verify: can(user, "admin.psychologists.verify"),
        moderate: can(user, "admin.psychologists.moderate_profile"),
        block: can(user, "admin.psychologists.block"),
      }}
    />
  );
}
