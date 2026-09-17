import type React from "react";
import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { CookieConsent } from "@/components/ui/cookie-consent";
import "./globals.css";

const _geist = Geist({ subsets: ["latin"] });
const _geistMono = Geist_Mono({ subsets: ["latin"] });

export const metadata: Metadata = {
  title: "Airis - Preparación ESRS asistida",
  description: "Asistente para preparar reportes ESRS 2023 con materialidad, datapoints y evidencias organizadas.",
  icons: {
    icon: "/icon-light-32x32.png",
    apple: "/apple-icon.png",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es">
      <body className={`font-sans antialiased`}>
        {children}
        <CookieConsent />
      </body>
    </html>
  );
}
