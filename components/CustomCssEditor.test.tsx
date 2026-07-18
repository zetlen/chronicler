import { describe, it, expect, vi, afterEach, beforeAll } from "vitest";
import { render, screen, fireEvent, cleanup } from "@testing-library/react";
import { CustomCssEditor } from "@/components/CustomCssEditor";

// CodeMirror needs layout APIs jsdom lacks; a textarea stands in for it.
vi.mock("@uiw/react-codemirror", () => ({
  default: ({
    value,
    onChange,
  }: {
    value: string;
    onChange: (v: string) => void;
  }) => (
    <textarea
      aria-label="Custom CSS source"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    />
  ),
}));

beforeAll(() => {
  window.matchMedia ??= ((query: string) =>
    ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }) as unknown as MediaQueryList) as typeof window.matchMedia;
});

afterEach(cleanup);

function renderEditor(overrides?: Partial<Parameters<typeof CustomCssEditor>[0]>) {
  const props = {
    value: ".slack-log { --slk-fg: #123456; }",
    onChange: vi.fn(),
    template: "/* fresh seed */\n.slack-log { --slk-fg: #1d1c1d; }",
    ...overrides,
  };
  render(<CustomCssEditor {...props} />);
  return props;
}

describe("CustomCssEditor clear flow", () => {
  it("asks for confirmation instead of clearing on the first click", () => {
    const props = renderEditor();
    fireEvent.click(screen.getByRole("button", { name: /clear custom css/i }));
    expect(props.onChange).not.toHaveBeenCalled();
    // role=alert so the prompt's appearance is announced to screen readers.
    expect(screen.getByRole("alert").textContent).toMatch(
      /replace your custom css with the default template/i,
    );
  });

  it("replaces the CSS with the template when confirmed", () => {
    const props = renderEditor();
    fireEvent.click(screen.getByRole("button", { name: /clear custom css/i }));
    fireEvent.click(screen.getByRole("button", { name: /^replace$/i }));
    expect(props.onChange).toHaveBeenCalledExactlyOnceWith(props.template);
    // Disarmed again: the prompt is gone, the entry button is back.
    expect(
      screen.queryByText(/replace your custom css with the default template/i),
    ).toBeNull();
    expect(
      screen.getByRole("button", { name: /clear custom css/i }),
    ).toBeTruthy();
  });

  it("cancel disarms without touching the CSS", () => {
    const props = renderEditor();
    fireEvent.click(screen.getByRole("button", { name: /clear custom css/i }));
    fireEvent.click(screen.getByRole("button", { name: /cancel/i }));
    expect(props.onChange).not.toHaveBeenCalled();
    expect(
      screen.queryByText(/replace your custom css with the default template/i),
    ).toBeNull();
    expect(
      screen.getByRole("button", { name: /clear custom css/i }),
    ).toBeTruthy();
  });
});
