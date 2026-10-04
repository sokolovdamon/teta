import type { Metadata } from "next";
import { Alert, EmptyState, PageHeader } from "@/components/ui";
import { QualificationPanel } from "@/features/psychologists/QualificationPanel";
import type { QualificationView } from "@/features/psychologists/types";
import { requireUser } from "@/lib/guard";
import { serverApi } from "@/lib/server";

export const metadata: Metadata = { title: "Квалификация" };

/** PRO-01 */
export default async function ProQualificationPage() {
  const user = await requireUser(["psychologist", "supervisor"]);
  const view = await serverApi<{ data: QualificationView }>("/pro/qualification").catch(() => "error" as const);
  return (
    <>
      <PageHeader
        title="Подтверждение квалификации"
        description="Только дипломированные специалисты: администратор проверяет документы о психологическом образовании каждого психолога."
      />
      {view === "error" ? (
        <Alert tone="danger" title="Не удалось загрузить данные">
          Обновите страницу через минуту.
        </Alert>
      ) : view === null ? (
        <EmptyState title="Профиль психолога не найден" description="Этот раздел доступен психологам." />
      ) : (
        <QualificationPanel initial={view.data} timezone={user.timezone} />
      )}
    </>
  );
}
