import type { Metadata } from "next";
import { PageHeader } from "@/components/ui";
import { ClientRecommendations } from "@/features/recommendations/ClientRecommendations";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Рекомендации" };

/** CL-05: recommendations and tasks from the psychologist. */
export default async function Page() {
  const user = await requireUser(["client"]);
  return (
    <>
      <PageHeader title="Рекомендации" description="Задания, упражнения и материалы, которые психолог оставил для вас после сессий." />
      <ClientRecommendations timezone={user.timezone} />
    </>
  );
}
