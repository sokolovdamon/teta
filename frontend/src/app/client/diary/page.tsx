import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { DiaryView } from "@/features/diary/DiaryView";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Дневник эмоций" };

/** CL-06: diary history and dynamics. */
export default async function Page() {
  const user = await requireUser(["client"]);
  return (
    <>
      <PageHeader
        title="Дневник эмоций"
        description="Отмечайте настроение и эмоции — со временем станет видно, что на вас влияет. Психолог, с которым вы работаете, видит только динамику настроения и метки, заметки видите только вы."
      />
      <DiaryView timezone={user.timezone} />
    </>
  );
}
