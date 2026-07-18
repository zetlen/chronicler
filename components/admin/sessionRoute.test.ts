import { describe, it, expect, afterEach } from "vitest";
import {
  getSessionRouteSnapshot,
  navigateToSession,
  subscribeSessionRoute,
} from "@/components/admin/sessionRoute";

afterEach(() => {
  window.history.replaceState(null, "", "/wp-admin/admin.php?page=chronicler");
  // Nudge the module store back in sync for the next test.
  window.dispatchEvent(new PopStateEvent("popstate"));
});

describe("sessionRoute", () => {
  it("navigates between views, preserving other query args", () => {
    window.history.replaceState(null, "", "/wp-admin/admin.php?page=chronicler");
    const unsubscribe = subscribeSessionRoute(() => {});
    expect(getSessionRouteSnapshot()).toBeNull();

    navigateToSession("new");
    expect(getSessionRouteSnapshot()).toBe("new");
    expect(new URLSearchParams(window.location.search).get("page")).toBe("chronicler");

    navigateToSession("12");
    expect(getSessionRouteSnapshot()).toBe("12");
    expect(new URLSearchParams(window.location.search).get("session")).toBe("12");

    navigateToSession(null);
    expect(getSessionRouteSnapshot()).toBeNull();
    expect(new URLSearchParams(window.location.search).has("session")).toBe(false);
    unsubscribe();
  });

  it("notifies subscribers and follows popstate (deep links, back button)", () => {
    window.history.replaceState(null, "", "/wp-admin/admin.php?page=chronicler");
    let notified = 0;
    const unsubscribe = subscribeSessionRoute(() => {
      notified++;
    });

    navigateToSession("7");
    expect(notified).toBe(1);

    // Simulate the back button: the URL changes, then popstate fires.
    window.history.replaceState(null, "", "/wp-admin/admin.php?page=chronicler");
    window.dispatchEvent(new PopStateEvent("popstate"));
    expect(getSessionRouteSnapshot()).toBeNull();
    expect(notified).toBe(2);
    unsubscribe();
  });
});
