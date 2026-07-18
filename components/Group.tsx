/** Titled section in the options pane: small uppercase heading over content. */
export function Group({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-2">
      <h3 className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">
        {title}
      </h3>
      {children}
    </div>
  );
}
