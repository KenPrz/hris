import type { Metadata } from "next";
import localFont from "next/font/local";

import { PRODUCT_NAME } from "@/lib/brand";

import "./globals.css";

const plexSans = localFont({
  variable: "--font-plex",
  src: [
    { path: "../fonts/IBMPlexSans-Light.woff2", weight: "300", style: "normal" },
    { path: "../fonts/IBMPlexSans-Regular.woff2", weight: "400", style: "normal" },
    { path: "../fonts/IBMPlexSans-SemiBold.woff2", weight: "600", style: "normal" },
  ],
});

export const metadata: Metadata = {
  title: PRODUCT_NAME,
  description: "Human Resource Information System",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className={`${plexSans.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
