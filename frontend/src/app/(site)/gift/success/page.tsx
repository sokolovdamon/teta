import type { Metadata } from "next";
import { GiftSuccess } from "@/features/payments/GiftSuccess";

export const metadata: Metadata = { title: "Сертификат оплачен", robots: { index: false, follow: false } };

export default async function GiftSuccessPage({ searchParams }: PageProps<"/gift/success">) {
  const { certificate } = await searchParams;
  return (
    <main className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
      <GiftSuccess id={typeof certificate === "string" ? certificate : null} />
    </main>
  );
}
