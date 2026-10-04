import Link from "next/link";
import { fileSize, isInternalLink, linkTitle } from "./labels";
import type { Recommendation } from "./types";

/** Text, links and files of a recommendation (shared by the client view and the psychologist's preview). */
export function RecoContent({ reco }: { reco: Recommendation }) {
  return (
    <div className="grid gap-4">
      {reco.body && <p className="whitespace-pre-line text-ink-2">{reco.body}</p>}
      {reco.links.length > 0 && (
        <div className="grid gap-1.5">
          <p className="text-sm font-medium text-muted">Ссылки и материалы</p>
          <ul className="grid gap-1">
            {reco.links.map((l, i) => (
              <li key={`${l.url}-${i}`}>
                {isInternalLink(l) ? (
                  <Link href={l.url} className="text-brand underline-offset-2 hover:underline">
                    {linkTitle(l)}
                  </Link>
                ) : (
                  <a href={l.url} target="_blank" rel="noopener noreferrer nofollow" className="text-brand underline-offset-2 hover:underline">
                    {linkTitle(l)} ↗
                  </a>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
      {reco.files.length > 0 && (
        <div className="grid gap-1.5">
          <p className="text-sm font-medium text-muted">Файлы</p>
          <ul className="grid gap-1.5">
            {reco.files.map((f) => (
              <li key={f.id}>
                <a href={f.url} download={f.name} className="inline-flex items-center gap-2 rounded-xl border border-line px-3 py-2 text-sm hover:border-brand-tint">
                  <span aria-hidden>📎</span>
                  <span className="font-medium">{f.name}</span>
                  <span className="text-muted">{fileSize(f.size)}</span>
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
