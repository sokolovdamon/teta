import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { AdminDictionaries } from "@/features/psychologists/AdminDictionaries";
import { Forbidden } from "@/features/site/Forbidden";
import { requireAdmin } from "@/lib/guard";
import { can } from "@/lib/types";

export const metadata: Metadata = { title: "Справочники" };

/** ADM-13 */
export default async function AdminDictionariesPage() {
  const user = await requireAdmin();
  if (!can(user, "admin.dictionaries.view")) return <Forbidden section="Справочники" />;
  return (
    <>
      <PageHeader title="Справочники" description="Запросы и посадочные страницы, группы запросов, подходы с пояснениями, специализации, типы услуг и ценовые категории." />
      <AdminDictionaries canManage={can(user, "admin.dictionaries.manage")} />
    </>
  );
}
