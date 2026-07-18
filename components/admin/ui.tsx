/** Shared styling atoms for the session-editor views (#96). */

export const FIELD_CLS =
  "w-full rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm shadow-sm focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500";

export const LABEL_CLS = "block text-xs font-medium text-zinc-600 mb-1";

export const SMALL_BUTTON_CLS =
  "rounded border border-zinc-300 px-2 py-0.5 text-xs font-medium text-zinc-700 transition hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50";

export const PRIMARY_BUTTON_CLS =
  "rounded-md bg-zinc-900 px-5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-50";

export const ERROR_NOTICE_CLS =
  "rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-700";

export const WARN_NOTICE_CLS =
  "rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800";

export function Toggle({
  label,
  checked,
  onChange,
  hint,
}: {
  label: string;
  checked: boolean;
  onChange: (v: boolean) => void;
  hint?: string;
}) {
  return (
    <label className="flex items-center gap-2 text-sm text-zinc-700">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="h-4 w-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500"
      />
      <span>
        {label}
        {hint && <span className="ml-1 text-xs text-zinc-400">({hint})</span>}
      </span>
    </label>
  );
}

export function PaneShell({
  title,
  children,
  actions,
  className = "",
}: {
  title: string;
  children: React.ReactNode;
  actions?: React.ReactNode;
  className?: string;
}) {
  return (
    <section
      className={`flex min-h-0 flex-col overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm ${className}`}
    >
      <div className="flex items-center justify-between border-b border-zinc-200 px-3 py-2">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
          {title}
        </h2>
        {actions}
      </div>
      {children}
    </section>
  );
}
