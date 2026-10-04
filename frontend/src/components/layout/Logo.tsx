import Image from "next/image";
import Link from "next/link";

/** Customer-supplied logo files only (DEC-04): light and dark variants switch with the colour scheme. */
export function Logo({ href = "/", height = 36 }: { href?: string; height?: number }) {
  const width = Math.round((height * 430) / 168);
  return (
    <Link href={href} aria-label="ТЕТА — на главную" className="inline-flex shrink-0">
      <Image src="/brand/logo-light.png" alt="ТЕТА" width={width} height={height} className="block dark:hidden" priority />
      <Image src="/brand/logo-dark.png" alt="ТЕТА" width={width} height={height} className="hidden dark:block" priority />
    </Link>
  );
}
