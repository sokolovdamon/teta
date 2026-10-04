import { clsx } from "clsx";
import Link from "next/link";
import type { ReactNode } from "react";
import { LinkButton, Markdown } from "@/components/ui";
import type { CmsPage } from "./server";

/** Page header of public pages: eyebrow, H1, lead and actions. */
export function PageHero({
  eyebrow,
  title,
  lead,
  actions,
  className,
}: {
  eyebrow?: ReactNode;
  title: ReactNode;
  lead?: ReactNode;
  actions?: ReactNode;
  className?: string;
}) {
  return (
    <header className={clsx("grid gap-4", className)}>
      {eyebrow && <p className="text-sm font-semibold uppercase tracking-wider text-brand">{eyebrow}</p>}
      <h1 className="max-w-4xl text-3xl font-bold tracking-tight sm:text-5xl">{title}</h1>
      {lead && <div className="max-w-3xl text-lg text-ink-2">{lead}</div>}
      {actions && <div className="mt-2 flex flex-wrap gap-3">{actions}</div>}
    </header>
  );
}

export function Container({ children, className, narrow }: { children: ReactNode; className?: string; narrow?: boolean }) {
  return <main className={clsx("mx-auto grid gap-14 px-4 py-12 sm:px-6 sm:py-16", narrow ? "max-w-3xl" : "max-w-6xl", className)}>{children}</main>;
}

export function Section({ title, lead, children, id, className }: { title: ReactNode; lead?: ReactNode; children: ReactNode; id?: string; className?: string }) {
  return (
    <section id={id} className={clsx("grid gap-6 scroll-mt-24", className)}>
      <div className="grid gap-2">
        <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">{title}</h2>
        {lead && <p className="max-w-3xl text-ink-2">{lead}</p>}
      </div>
      {children}
    </section>
  );
}

/** Numbered steps (how it works, onboarding). */
export function Steps({ items }: { items: { title: string; text: ReactNode }[] }) {
  return (
    <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {items.map((s, i) => (
        <li key={s.title} className="grid content-start gap-2 rounded-2xl border border-line bg-surface p-5">
          <span className="inline-flex size-9 items-center justify-center rounded-full bg-brand text-sm font-semibold text-on-brand">{i + 1}</span>
          <p className="font-semibold">{s.title}</p>
          <div className="text-[15px] text-ink-2">{s.text}</div>
        </li>
      ))}
    </ol>
  );
}

export function FeatureGrid({ items, columns = 3 }: { items: { title: string; text: ReactNode }[]; columns?: 2 | 3 | 4 }) {
  return (
    <ul className={clsx("grid gap-4 sm:grid-cols-2", columns === 3 && "lg:grid-cols-3", columns === 4 && "lg:grid-cols-4")}>
      {items.map((f) => (
        <li key={f.title} className="grid content-start gap-2 rounded-2xl bg-surface p-5 ring-1 ring-line">
          <p className="font-semibold">{f.title}</p>
          <div className="text-[15px] text-ink-2">{f.text}</div>
        </li>
      ))}
    </ul>
  );
}

export function CtaBand({ title, text, actions }: { title: ReactNode; text?: ReactNode; actions: ReactNode }) {
  return (
    <section className="grid gap-4 rounded-3xl bg-brand-deep px-6 py-10 text-ground sm:px-10">
      <h2 className="max-w-2xl text-2xl font-semibold tracking-tight sm:text-3xl">{title}</h2>
      {text && <p className="max-w-2xl opacity-85">{text}</p>}
      <div className="flex flex-wrap gap-3">{actions}</div>
    </section>
  );
}

export type FaqItem = { q: string; a: ReactNode; text: string };

/** Questions and answers with FAQPage structured data (answers as plain text for search engines). */
export function FaqList({ items, withSchema = true }: { items: FaqItem[]; withSchema?: boolean }) {
  const schema = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: items.map((i) => ({ "@type": "Question", name: i.q, acceptedAnswer: { "@type": "Answer", text: i.text } })),
  };
  return (
    <div className="grid gap-3">
      {withSchema && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(schema).replace(/</g, "\\u003c") }} />}
      {items.map((i) => (
        <details key={i.q} className="group rounded-2xl border border-line bg-surface px-5 py-4 open:border-brand-tint">
          <summary className="flex cursor-pointer list-none items-start justify-between gap-4 font-medium">
            <span>{i.q}</span>
            <span aria-hidden className="mt-0.5 text-brand transition-transform group-open:rotate-45">
              +
            </span>
          </summary>
          <div className="mt-3 text-ink-2">{i.a}</div>
        </details>
      ))}
    </div>
  );
}

/** CMS-managed page: markdown from the CMS when published, otherwise the built-in default content. */
export function CmsBody({ cms, children }: { cms: CmsPage | null; children: ReactNode }) {
  if (cms) {
    return (
      <article className="rounded-3xl bg-surface p-6 ring-1 ring-line sm:p-10">
        <Markdown>{cms.body}</Markdown>
      </article>
    );
  }
  return <>{children}</>;
}

export function TextLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="font-medium text-brand underline-offset-2 hover:underline">
      {children}
    </Link>
  );
}

export function PodborButton({ children = "Подобрать психолога", size = "lg" as const }: { children?: ReactNode; size?: "md" | "lg" }) {
  return (
    <LinkButton href="/podbor" size={size}>
      {children}
    </LinkButton>
  );
}
