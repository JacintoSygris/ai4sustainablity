import type React from "react";
import type { Metadata } from "next";
import { LocaleProvider } from "@/components/locale-provider";
import { getLaravelServerLocale } from "@/lib/laravel-server";
import { localeMetadata } from "@/lib/i18n/locale.mjs";
import { CookieConsent } from "@/components/ui/cookie-consent";
import "./globals.css";
import "../public/consent/consent.css";

export async function generateMetadata(): Promise<Metadata> {
  const locale = await getLaravelServerLocale();
  return { ...localeMetadata[locale], icons: { icon: "/icon-light-32x32.png", apple: "/apple-icon.png" } };
}

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const locale = await getLaravelServerLocale();
  return (
    <html lang={locale}>
      <body className={`font-sans antialiased`}>
        <LocaleProvider initialLocale={locale}>
          {children}
          <CookieConsent />
        </LocaleProvider>
      </body>
    </html>
  );
}
