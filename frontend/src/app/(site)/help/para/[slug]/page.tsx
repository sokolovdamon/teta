import type { Metadata } from "next";
import { RequestLandingPage, requestLandingMetadata } from "@/features/site/RequestLanding";

export async function generateMetadata({ params }: PageProps<"/help/para/[slug]">): Promise<Metadata> {
  return requestLandingMetadata((await params).slug, "pair");
}

/** SITE-06: landing of a pair request, /help/para/{slug}. */
export default async function PairRequestPage({ params }: PageProps<"/help/para/[slug]">) {
  return <RequestLandingPage slug={(await params).slug} format="pair" />;
}
