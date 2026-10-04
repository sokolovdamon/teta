import { clsx } from "clsx";
import Link from "next/link";
import ReactMarkdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";

/**
 * Markdown from CMS pages, legal documents and landing texts, styled with the design tokens.
 * Raw HTML is not rendered (react-markdown escapes it), so content from the admin panel is safe to show.
 */
const components: Components = {
  h1: ({ children }) => <h1 className="mb-4 mt-2 text-3xl font-semibold tracking-tight">{children}</h1>,
  h2: ({ children }) => <h2 className="mb-3 mt-8 text-2xl font-semibold tracking-tight">{children}</h2>,
  h3: ({ children }) => <h3 className="mb-2 mt-6 text-xl font-semibold">{children}</h3>,
  h4: ({ children }) => <h4 className="mb-2 mt-5 text-lg font-semibold">{children}</h4>,
  p: ({ children }) => <p className="my-3 leading-relaxed text-ink-2">{children}</p>,
  ul: ({ children }) => <ul className="my-3 grid list-disc gap-1.5 pl-6 text-ink-2 marker:text-brand-tint">{children}</ul>,
  ol: ({ children }) => <ol className="my-3 grid list-decimal gap-1.5 pl-6 text-ink-2 marker:text-muted">{children}</ol>,
  li: ({ children }) => <li className="pl-1">{children}</li>,
  strong: ({ children }) => <strong className="font-semibold text-ink">{children}</strong>,
  em: ({ children }) => <em className="italic">{children}</em>,
  blockquote: ({ children }) => <blockquote className="my-4 rounded-r-xl border-l-4 border-brand-tint bg-brand-soft/60 px-4 py-2">{children}</blockquote>,
  hr: () => <hr className="my-8 border-line" />,
  a: ({ href = "", children }) =>
    href.startsWith("/") ? (
      <Link href={href} className="font-medium text-brand underline-offset-2 hover:underline">
        {children}
      </Link>
    ) : (
      <a href={href} className="font-medium text-brand underline-offset-2 hover:underline" target="_blank" rel="noopener noreferrer">
        {children}
      </a>
    ),
  code: ({ children }) => <code className="rounded bg-sunken px-1.5 py-0.5 text-[0.92em]">{children}</code>,
  pre: ({ children }) => <pre className="my-4 overflow-x-auto rounded-xl bg-sunken p-4 text-sm">{children}</pre>,
  table: ({ children }) => (
    <div className="my-4 overflow-x-auto rounded-xl border border-line">
      <table className="w-full border-collapse text-left text-[15px]">{children}</table>
    </div>
  ),
  th: ({ children }) => <th className="bg-sunken px-3 py-2 text-sm font-semibold">{children}</th>,
  td: ({ children }) => <td className="border-t border-line px-3 py-2 align-top text-ink-2">{children}</td>,
  // CMS images come from arbitrary hosts and have no known size, so next/image is not used here.
  // eslint-disable-next-line @next/next/no-img-element
  img: ({ src, alt }) => (typeof src === "string" ? <img src={src} alt={alt ?? ""} className="my-4 max-w-full rounded-xl" loading="lazy" /> : null),
};

export function Markdown({ children, className }: { children: string | null | undefined; className?: string }) {
  if (!children) return null;
  return (
    <div className={clsx("max-w-none break-words", className)}>
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>
        {children}
      </ReactMarkdown>
    </div>
  );
}
