import type { Metadata, Viewport } from "next";
import "@fontsource-variable/onest";
import "./globals.css";
import { SITE_NAME, SITE_URL } from "@/lib/config";

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: { default: `${SITE_NAME} — онлайн-психологи с подтверждённой квалификацией`, template: `%s · ${SITE_NAME}` },
  description:
    "Платформа онлайн-психологии: только дипломированные специалисты с ежемесячной супервизией, подбор психолога по запросу и видеосессии TetaMeet.",
  applicationName: SITE_NAME,
  openGraph: { siteName: SITE_NAME, locale: "ru_RU", type: "website" },
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#f4f4f7" },
    { media: "(prefers-color-scheme: dark)", color: "#191527" },
  ],
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="ru" className="h-full antialiased">
      <body className="flex min-h-full flex-col">{children}</body>
    </html>
  );
}
