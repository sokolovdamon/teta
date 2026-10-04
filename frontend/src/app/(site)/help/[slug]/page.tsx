import type { Metadata } from "next";
import { RequestLandingPage, requestLandingMetadata } from "@/features/site/RequestLanding";

export async function generateMetadata({ params }: PageProps<"/help/[slug]">): Promise<Metadata> {
  return requestLandingMetadata((await params).slug, "individual");
}

/** SITE-06: landing of an individual request, /help/{slug}. */
export default async function RequestPage({ params }: PageProps<"/help/[slug]">) {
  return <RequestLandingPage slug={(await params).slug} format="individual" />;
}
