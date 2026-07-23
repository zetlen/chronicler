import { describe, it, expect, afterEach } from "vitest";
import { render, screen, fireEvent, cleanup } from "@testing-library/react";
import { RulesPanel } from "@/components/admin/RulesPanel";

// #9: an in-progress "New rule" form must be abortable — opening it should
// never trap the author into committing a rule.

afterEach(cleanup);

function renderPanel() {
  return render(
    <RulesPanel
      allRules={[]}
      attachedIds={[]}
      matchCounts={new Map()}
      hasData={false}
      onAttach={() => {}}
      onDetach={() => {}}
      onCreated={() => {}}
    />,
  );
}

describe("RulesPanel new-rule form", () => {
  it("opens and cancels the inline form without creating a rule", () => {
    renderPanel();

    fireEvent.click(screen.getByRole("button", { name: /New rule/ }));
    expect(screen.getByLabelText("Regular expression")).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(screen.queryByLabelText("Regular expression")).toBeNull();
  });

  it("makes the New rule toggle inert while the form is open", () => {
    renderPanel();

    const toggle = screen.getByRole("button", { name: /New rule/ });
    fireEvent.click(toggle);
    // Cancel is the only way out — the toggle no longer collapses (and
    // silently discards) the in-progress form.
    expect((toggle as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByLabelText("Regular expression")).toBeTruthy();
  });
});
