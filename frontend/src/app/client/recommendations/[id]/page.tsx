import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { ClientRecommendationDetail } from "@/features/recommendations/ClientRecommendationDetail";
import { requireUser } from "@/lib/guard";

export const metadata: Metadata = { title: "Рекомендация" };

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** CL-05: one recommendation; opening it marks it viewed (done in the browser, after the page is shown). */
export default async function Page({ params }: PageProps<"/client/recommendations/[id]">) {
  const user = await requireUser(["client"]);
  const { id } = await params;
  if (!UUID.test(id)) notFound();
  return <ClientRecommendationDetail id={id} timezone={user.timezone} />;
}
