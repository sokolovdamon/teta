import type { Metadata } from "next";
import { PayReturn } from "@/features/payments/PayReturn";

export const metadata: Metadata = { title: "Результат оплаты", robots: { index: false, follow: false } };

/** Return from the provider's (emulator's) form: ?payment={id} or ?binding={id}. */
export default async function PayReturnPage({ searchParams }: PageProps<"/pay/return">) {
  const { payment, binding } = await searchParams;
  return <PayReturn payment={typeof payment === "string" ? payment : null} binding={typeof binding === "string" ? binding : null} />;
}
