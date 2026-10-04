import type { Metadata } from "next";
import { Alert, EmptyState, PageHeader } from "@/components/ui";
import { getDictionaries } from "@/features/catalog/server";
import { ProfileForm } from "@/features/psychologists/ProfileForm";
import type { OwnProfile } from "@/features/psychologists/types";
import { requireUser } from "@/lib/guard";
import { serverApi } from "@/lib/server";

export const metadata: Metadata = { title: "Профиль" };

/** PRO-02 */
export default async function ProProfilePage() {
  await requireUser(["psychologist", "supervisor"]);
  const [profile, dictionaries] = await Promise.all([
    serverApi<{ data: OwnProfile }>("/pro/profile").catch(() => "error" as const),
    getDictionaries(),
  ]);

  return (
    <>
      <PageHeader title="Профиль" description="То, что увидят клиенты в каталоге и на странице психолога. Подходы с пояснениями и запросы помогают подбору." />
      {profile === "error" || !dictionaries ? (
        <Alert tone="danger" title="Не удалось загрузить профиль">
          Обновите страницу через минуту.
        </Alert>
      ) : profile === null ? (
        <EmptyState title="Профиль психолога не найден" description="Этот раздел доступен психологам. Если вы зарегистрировались как психолог, напишите в поддержку." />
      ) : (
        <ProfileForm initial={profile.data} dictionaries={dictionaries} />
      )}
    </>
  );
}
