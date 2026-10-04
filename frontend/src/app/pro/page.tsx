import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { ProCalendar } from "@/features/booking/ProCalendar";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Календарь записей" };

/** PRO-04: calendar of bookings; ?tab=requests opens the "Нет подходящего времени" inbox. */
export default async function ProHomePage({ searchParams }: PageProps<"/pro">) {
  const user = await requireUser(["psychologist", "supervisor"]);
  const { tab } = await searchParams;
  return (
    <>
      <PageHeader title="Календарь записей" description="Предстоящие и прошедшие сессии, перенос и отмена, итоги сессий и запросы времени от клиентов." />
      <ProCalendar timezone={user.timezone} initialTab={tab === "requests" ? "requests" : "calendar"} />
    </>
  );
}
