import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { MyPsychologists } from "@/features/client-home/MyPsychologists";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Мой психолог" };

/** CL-04: my psychologist, change of psychologist (DEC-48) and the repeated questionnaire. */
export default async function Page() {
  const user = await requireUser(["client"]);
  return (
    <>
      <PageHeader title="Мой психолог" description="Специалисты, с которыми вы работаете. Здесь же можно сменить психолога и пройти анкету подбора заново." />
      <MyPsychologists timezone={user.timezone} />
    </>
  );
}
