import type { Metadata } from "next";
import { Alert, EmptyState, PageHeader } from "@/components/ui";
import { ScheduleEditor } from "@/features/psychologists/ScheduleEditor";
import type { ScheduleOverview } from "@/features/psychologists/types";
import { requireUser } from "@/lib/guard";
import { serverApi } from "@/lib/server";

export const metadata: Metadata = { title: "График работы" };

/** PRO-03 */
export default async function ProSchedulePage() {
  await requireUser(["psychologist", "supervisor"]);
  const schedule = await serverApi<{ data: ScheduleOverview }>("/pro/schedule").catch(() => "error" as const);
  return (
    <>
      <PageHeader title="График работы" description="Рабочие интервалы, перерывы, отпуска и блокировка дат. Клиенты видят время в своём часовом поясе." />
      {schedule === "error" ? (
        <Alert tone="danger" title="Не удалось загрузить график">
          Обновите страницу через минуту.
        </Alert>
      ) : schedule === null ? (
        <EmptyState title="Профиль психолога не найден" description="Этот раздел доступен психологам." />
      ) : (
        <ScheduleEditor initial={schedule.data} />
      )}
    </>
  );
}
