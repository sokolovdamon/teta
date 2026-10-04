import { Alert, PageHeader } from "@/components/ui";
import { HomeView } from "@/features/client-home/HomeView";
import type { ClientHome } from "@/features/client-home/types";
import { MoodCheckIn } from "@/features/diary/MoodCheckIn";
import { requireUser } from "@/lib/guard";
import { serverApi } from "@/lib/server";

/** CL-02 home with the CL-01 mood check-in (offered not more than once a day). */
export default async function Page() {
  const user = await requireUser(["client"]);
  let home: ClientHome | null = null;
  try {
    home = (await serverApi<{ data: ClientHome }>("/client/home"))?.data ?? null;
  } catch {
    home = null;
  }

  return (
    <>
      <PageHeader title={`Здравствуйте, ${user.name}!`} description="Здесь — ближайшая сессия, настроение и рекомендации психолога." />
      {home ? (
        <>
          <HomeView home={home} timezone={user.timezone} />
          <MoodCheckIn initialShow={home.diary?.show_prompt ?? false} />
        </>
      ) : (
        <Alert tone="warning" title="Не удалось загрузить данные кабинета">
          Обновите страницу через минуту. Если срочно нужна помощь — откройте раздел «Экстренная помощь».
        </Alert>
      )}
    </>
  );
}
