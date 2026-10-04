import type { Metadata } from "next";
import { getDictionaries } from "@/features/catalog/server";
import { RequestsHub } from "@/features/site/RequestsHub";

export const metadata: Metadata = {
  title: "Психолог для пары",
  description: "Парные сессии с психологом онлайн: измена, развод, кризис в отношениях, созависимость, рождение детей. 90 минут, только дипломированные специалисты.",
  alternates: { canonical: "/help/para" },
};

/** SITE-06 hub: requests for couples. */
export default async function PairHubPage() {
  const dictionaries = await getDictionaries();
  return <RequestsHub groups={dictionaries?.request_groups ?? null} pairOnly />;
}
