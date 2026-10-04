import { clsx } from "clsx";

export function initials(first?: string | null, last?: string | null): string {
  return `${(first ?? "").trim().charAt(0)}${(last ?? "").trim().charAt(0)}`.toUpperCase() || "Т";
}

/** Photo of a psychologist or their initials on a calm brand background. */
export function Avatar({
  photoUrl,
  firstName,
  lastName,
  size = 96,
  className,
}: {
  photoUrl: string | null | undefined;
  firstName?: string | null;
  lastName?: string | null;
  size?: number;
  className?: string;
}) {
  const style = { width: size, height: size };
  if (photoUrl) {
    return (
      // Photos are served by the backend storage (any host in dev/stage/prod), so a plain <img> is used.
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={photoUrl}
        alt={`${firstName ?? ""} ${lastName ?? ""}`.trim() || "Фото психолога"}
        width={size}
        height={size}
        loading="lazy"
        className={clsx("shrink-0 rounded-2xl bg-sunken object-cover", className)}
        style={style}
      />
    );
  }
  return (
    <span
      aria-hidden
      className={clsx("inline-flex shrink-0 items-center justify-center rounded-2xl bg-brand-soft font-semibold text-brand", className)}
      style={{ ...style, fontSize: Math.round(size / 2.8) }}
    >
      {initials(firstName, lastName)}
    </span>
  );
}
