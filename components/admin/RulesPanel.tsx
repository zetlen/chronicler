import { useState } from "react";
import { Group } from "@/components/Group";
import {
  ruleBoundaryWarning,
  ruleError,
  type RuleMode,
} from "@/lib/transform/rules";
import { MESSAGE_VARIANTS, type MessageVariant } from "@/lib/transform/variants";
import {
  createRule,
  type RuleCreateBody,
  type WpRule,
} from "@/components/admin/sessionApi";
import { FIELD_CLS, SMALL_BUTTON_CLS } from "@/components/admin/ui";

/**
 * The session's attached-rules section (#96/#109): the rules attached to
 * this Session (rule_ids against GET /rules), attach/detach against the
 * global library, an inline mini-form that POSTs a brand-new rule and
 * attaches it, and a link out to the Rules admin screen. Attached rules
 * apply to the live transform preview exactly as LogFormatterApp's rules
 * do (via applyRules in the editor).
 */

const RULE_MODE_LABELS: Record<RuleMode, string> = {
  hide: "Hide",
  addclass: "Add class",
  start: "Start at",
  end: "End at",
  "wp-tag": "WP tag",
  treatment: "Treatment",
};

/** Author-facing names for the message treatments a "treatment" rule can set. */
const TREATMENT_LABELS: Record<MessageVariant, string> = {
  ooc: "Out of character",
  important: "Important",
};

/** The Rules CPT list screen (#109); relative — we're already in wp-admin. */
export const RULES_ADMIN_URL = "edit.php?post_type=chronicler_rule";

interface Props {
  /** null while GET /rules is in flight. */
  allRules: WpRule[] | null;
  attachedIds: number[];
  /** Live match counts from applyRules, keyed by String(rule.id). */
  matchCounts: ReadonlyMap<string, number>;
  /** Whether fetched data exists for the counts to mean anything. */
  hasData: boolean;
  onAttach: (id: number) => void;
  onDetach: (id: number) => void;
  /** Called with the freshly created rule so the editor can attach it. */
  onCreated: (rule: WpRule) => void;
}

export function RulesPanel(props: Props) {
  const [showForm, setShowForm] = useState(false);

  const byId = new Map((props.allRules ?? []).map((r) => [r.id, r]));
  const attached = props.attachedIds.flatMap((id) => {
    const rule = byId.get(id);
    return rule ? [rule] : [];
  });
  const available = (props.allRules ?? []).filter(
    (r) => !props.attachedIds.includes(r.id),
  );

  return (
    <Group title="Transcription Rules">
      <p className="text-xs text-zinc-400">
        Matched against each message&rsquo;s text. <em>Start at</em> /{" "}
        <em>end at</em> trim the transcript, <em>hide</em> removes matches,{" "}
        <em>add class</em> tags them for custom CSS, <em>WP tag</em> proposes
        post tags, and <em>treatment</em> marks matches out of character or
        important.{" "}
        <a
          className="text-sky-700 hover:underline"
          href={RULES_ADMIN_URL}
          target="_blank"
          rel="noopener noreferrer"
        >
          Manage all transcription rules ↗
        </a>
      </p>

      {props.allRules === null ? (
        <p className="text-xs text-zinc-400">Loading rules…</p>
      ) : (
        <>
          {attached.length === 0 && (
            <p className="text-xs text-zinc-400">No rules attached.</p>
          )}
          {attached.map((rule) => (
            <AttachedRuleRow
              key={rule.id}
              rule={rule}
              matchCount={props.matchCounts.get(String(rule.id))}
              hasData={props.hasData}
              onDetach={() => props.onDetach(rule.id)}
            />
          ))}

          <div className="flex items-center justify-between">
            <AttachMenu available={available} onAttach={props.onAttach} />
            <button
              type="button"
              className={SMALL_BUTTON_CLS}
              aria-expanded={showForm}
              disabled={showForm}
              onClick={() => setShowForm(true)}
            >
              {showForm ? "▾ New rule" : "▸ New rule"}
            </button>
          </div>

          {showForm && (
            <NewRuleForm
              onCreated={(rule) => {
                setShowForm(false);
                props.onCreated(rule);
              }}
              onCancel={() => setShowForm(false)}
            />
          )}
        </>
      )}
    </Group>
  );
}

function AttachedRuleRow({
  rule,
  matchCount,
  hasData,
  onDetach,
}: {
  rule: WpRule;
  matchCount: number | undefined;
  hasData: boolean;
  onDetach: () => void;
}) {
  const error = ruleError(rule);
  const warning = error ? null : ruleBoundaryWarning(rule);
  const count = matchCount ?? 0;
  return (
    <div className="flex items-center gap-2">
      <span className="shrink-0 rounded bg-zinc-200 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600">
        {RULE_MODE_LABELS[rule.mode] ?? rule.mode}
      </span>
      <code
        className="min-w-0 flex-1 truncate font-mono text-xs text-zinc-600"
        title={rule.description || `/${rule.pattern}/${rule.flags}`}
      >
        /{rule.pattern}/{rule.flags}
      </code>
      {error && (
        <span className="shrink-0 text-[10px] text-red-600" title={error}>
          invalid
        </span>
      )}
      {warning && (
        <span className="shrink-0 text-[10px] text-amber-600" title={warning}>
          no \b
        </span>
      )}
      {hasData && !error && (
        <span
          title={`${count} matching message${count === 1 ? "" : "s"}`}
          className={`shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums ${
            count > 0
              ? "bg-emerald-100 text-emerald-800"
              : "bg-amber-100 text-amber-800"
          }`}
        >
          {count}
        </span>
      )}
      <button
        type="button"
        onClick={onDetach}
        title="Detach from this session (kept in the library)"
        aria-label={`Detach /${rule.pattern}/`}
        className="shrink-0 rounded px-1 text-sm text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700"
      >
        ✕
      </button>
    </div>
  );
}

function AttachMenu({
  available,
  onAttach,
}: {
  available: WpRule[];
  onAttach: (id: number) => void;
}) {
  if (available.length === 0) {
    return (
      <span className="text-xs text-zinc-400">
        Every saved rule is attached.
      </span>
    );
  }
  return (
    <select
      className={`${FIELD_CLS} max-w-56`}
      value=""
      aria-label="Attach a rule"
      onChange={(e) => {
        const id = Number(e.target.value);
        if (Number.isInteger(id) && id > 0) onAttach(id);
      }}
    >
      <option value="">Attach a rule…</option>
      {available.map((r) => (
        <option key={r.id} value={r.id}>
          {RULE_MODE_LABELS[r.mode] ?? r.mode}: /{r.pattern}/{r.flags}
          {r.description ? ` — ${r.description}` : ""}
        </option>
      ))}
    </select>
  );
}

function NewRuleForm({
  onCreated,
  onCancel,
}: {
  onCreated: (rule: WpRule) => void;
  onCancel: () => void;
}) {
  const [pattern, setPattern] = useState("");
  const [caseSensitive, setCaseSensitive] = useState(false);
  const [mode, setMode] = useState<RuleMode>("hide");
  const [className, setClassName] = useState("");
  const [tagNames, setTagNames] = useState("");
  const [treatments, setTreatments] = useState<string[]>([]);
  const [description, setDescription] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const flags = caseSensitive ? "" : "i";
  const compileProblem = ruleError({ pattern, flags });
  // Advisory only (#185) — it never blocks saving.
  const boundaryWarning = compileProblem
    ? null
    : ruleBoundaryWarning({ pattern, flags });
  const canSave = pattern.trim() !== "" && !compileProblem && !saving;

  async function handleSave() {
    if (!canSave) return;
    setSaving(true);
    setError(null);
    const body: RuleCreateBody = {
      pattern,
      flags,
      mode,
      className,
      tagNames,
      treatments: treatments.join(","),
      description,
    };
    try {
      onCreated(await createRule(body));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Couldn't save the rule.");
      setSaving(false);
    }
  }

  return (
    <div className="space-y-2 rounded-md border border-zinc-200 bg-zinc-50 p-2">
      <div className="flex items-center gap-1.5">
        <select
          value={mode}
          onChange={(e) => setMode(e.target.value as RuleMode)}
          aria-label="Rule mode"
          className="w-[6.4rem] shrink-0 rounded-md border border-zinc-300 bg-white px-1.5 py-1.5 text-xs shadow-sm"
        >
          {(Object.keys(RULE_MODE_LABELS) as RuleMode[]).map((m) => (
            <option key={m} value={m}>
              {RULE_MODE_LABELS[m]}
            </option>
          ))}
        </select>
        <div
          className={`flex min-w-0 flex-1 items-center rounded-md border bg-white px-2 py-1.5 shadow-sm ${
            compileProblem ? "border-red-400" : "border-zinc-300"
          }`}
        >
          <span className="select-none text-sm text-zinc-400">/</span>
          <input
            value={pattern}
            onChange={(e) => setPattern(e.target.value)}
            placeholder="pattern"
            spellCheck={false}
            aria-label="Regular expression"
            aria-invalid={!!compileProblem}
            className="w-full min-w-0 flex-1 bg-transparent px-0.5 font-mono text-sm focus:outline-none"
          />
          <span className="select-none text-sm text-zinc-400">/{flags}</span>
        </div>
        <button
          type="button"
          onClick={() => setCaseSensitive((v) => !v)}
          aria-pressed={caseSensitive}
          title={
            caseSensitive
              ? "Matching case — click to ignore case"
              : "Ignoring case — click to match case"
          }
          className={`shrink-0 rounded border px-1.5 py-1 text-xs font-medium transition ${
            caseSensitive
              ? "border-zinc-500 bg-zinc-200 text-zinc-900"
              : "border-zinc-300 text-zinc-400 hover:bg-zinc-100"
          }`}
        >
          Aa
        </button>
      </div>

      {mode === "addclass" && (
        <input
          value={className}
          onChange={(e) => setClassName(e.target.value)}
          placeholder="class-name other-class"
          spellCheck={false}
          aria-label="CSS classes to add to matching messages"
          className={FIELD_CLS}
        />
      )}
      {mode === "wp-tag" && (
        <input
          value={tagNames}
          onChange={(e) => setTagNames(e.target.value)}
          placeholder="tag-one, tag two"
          spellCheck={false}
          aria-label="WordPress tags to add when this rule matches"
          className={FIELD_CLS}
        />
      )}
      {mode === "treatment" && (
        <div
          className="flex items-center gap-4"
          role="group"
          aria-label="Treatments for matching messages"
        >
          {MESSAGE_VARIANTS.map((v) => (
            <label key={v} className="flex items-center gap-1.5 text-xs text-zinc-600">
              <input
                type="checkbox"
                checked={treatments.includes(v)}
                onChange={(e) =>
                  setTreatments((prev) =>
                    e.target.checked ? [...prev, v] : prev.filter((t) => t !== v),
                  )
                }
              />
              {TREATMENT_LABELS[v]}
            </label>
          ))}
        </div>
      )}
      <input
        value={description}
        onChange={(e) => setDescription(e.target.value)}
        placeholder="Description (optional)"
        aria-label="Rule description"
        className={FIELD_CLS}
      />

      {compileProblem && pattern.trim() !== "" && (
        <p className="text-xs text-red-600">{compileProblem}</p>
      )}
      {boundaryWarning && (
        <p className="text-xs text-amber-700">{boundaryWarning}</p>
      )}
      {error && <p className="text-xs text-red-600">{error}</p>}

      <div className="mt-2 flex items-center gap-2">
        <button
          type="button"
          disabled={!canSave}
          onClick={() => void handleSave()}
          className={SMALL_BUTTON_CLS}
        >
          {saving ? "Saving…" : "Save & attach"}
        </button>
        <button
          type="button"
          disabled={saving}
          onClick={onCancel}
          className={SMALL_BUTTON_CLS}
        >
          Cancel
        </button>
      </div>
    </div>
  );
}
