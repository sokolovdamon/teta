import type { Metadata } from "next";
import { EmulatorCheckout } from "@/features/payments/EmulatorCheckout";

export const metadata: Metadata = { title: "Оплата", robots: { index: false, follow: false } };

/** Hosted checkout of the payment emulator (DEC-38). */
export default async function EmulatorCheckoutPage({ params }: PageProps<"/pay/emulator/[id]">) {
  const { id } = await params;
  return <EmulatorCheckout id={id} />;
}
