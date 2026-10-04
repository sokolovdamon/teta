import type { Metadata } from "next";
import { getDictionaries } from "@/features/catalog/server";
import { RequestsHub } from "@/features/site/RequestsHub";

export const metadata: Metadata = {
  title: "С чем помогают психологи",
  description: "Все запросы, с которыми работают психологи ТЕТА: состояние, отношения, работа и учёба, события в жизни, запросы для пар.",
  alternates: { canonical: "/help" },
};

/** SITE-06 hub: all requests by group. */
export default async function HelpHubPage() {
  const dictionaries = await getDictionaries();
  return <RequestsHub groups={dictionaries?.request_groups ?? null} />;
}
