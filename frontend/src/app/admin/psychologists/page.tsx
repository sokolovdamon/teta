import type { Metadata } from "next";
import { Suspense } from "react";
import { PageHeader } from "@/components/ui";
import { getDictionaries } from "@/features/catalog/server";
import { AdminPsychologistsTable } from "@/features/psychologists/AdminPsychologistsTable";
import { Forbidden } from "@/features/site/Forbidden";
import { requireAdmin } from "@/lib/guard";
import { can } from "@/lib/types";

export const metadata: Metadata = { title: "Психологи" };

/** ADM-03 */
export default async function AdminPsychologistsPage() {
  const user = await requireAdmin();
  if (!can(user, "admin.psychologists.view")) return <Forbidden section="Психологи" />;
  const dictionaries = await getDictionaries();
  return (
    <>
      <PageHeader title="Психологи" description="Проверка квалификации, модерация профилей и видеовизиток, цены и ценовые категории, статусы активности и работы." />
      <Suspense fallback={<p className="text-muted">Загрузка…</p>}>
        <AdminPsychologistsTable categories={dictionaries?.price_categories ?? []} />
      </Suspense>
    </>
  );
}
