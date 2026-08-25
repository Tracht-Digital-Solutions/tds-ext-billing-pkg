
/**
 * Path + query of a request. The island calls an ABSOLUTE URL now (via
 * `apiFetch`); a relative one would hit the product's own static host and come
 * back as SPA-fallback HTML with a 200. Matching on the path keeps the route
 * matchers below anchored.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");
// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { primeRuntimeConfig } from "@tracht-digital-solutions/tds-shared/api";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import BillingSettings from "./BillingSettings";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

/**
 * The Stripe credentials panel, in the core runtime settings store.
 *
 * Two secrets live here and they are NOT interchangeable: the API secret key
 * authenticates our calls TO Stripe, the webhook secret verifies calls FROM
 * Stripe. Swapping them, or dropping one, breaks payment confirmation with no
 * visible symptom until an invoice silently never flips to "paid" — so they are
 * asserted to stay apart, both stored as secrets, and both honouring the
 * store's contract: masked on read, **blank on save = keep the existing one**.
 */

let calls: Array<{ url: string; method: string; body: unknown }> = [];
let getReply: { status: number; body: unknown } = { status: 200, body: { settings: [] } };
let putReply: { status: number; body: unknown } = { status: 200, body: {} };

const NS = "/admin/settings/billing";
const KEYS = ["stripe_secret_key", "stripe_webhook_secret", "default_currency", "days_until_due"];

/** Outcomes are toasts now — collected off the `tds:toast` bus. */
let toasts: Array<{ variant: string; message: string }> = [];
const collectToast = (e: Event) => {
  toasts.push((e as CustomEvent<{ variant: string; message: string }>).detail);
};

beforeEach(() => {
  toasts = [];
  window.addEventListener(TOAST_EVENT, collectToast);
  calls = [];
  getReply = { status: 200, body: { settings: [] } };
  putReply = { status: 200, body: {} };
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      const reply = method === "PUT" ? putReply : getReply;
      return { ok: reply.status < 300, status: reply.status, json: async () => reply.body } as Response;
    }),
  );
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, collectToast);
  cleanup();
});

const user = () => userEvent.setup({ delay: null });
const stored = (pairs: Record<string, string>) => ({
  status: 200,
  body: { settings: Object.entries(pairs).map(([key, value]) => ({ key, secret: false, value })) },
});

async function open() {
  render(<BillingSettings />);
  const u = user();
  await waitFor(() => expect(calls.length).toBeGreaterThan(0));
  await screen.findByRole("button", { name: "Speichern" });
  return u;
}

const keyBox = () => screen.getByPlaceholderText("sk_… (leer = behalten)") as HTMLInputElement;
const whBox = () => screen.getByPlaceholderText("whsec_… (leer = behalten)") as HTMLInputElement;
const box = (name: string) => screen.getByLabelText(name) as HTMLInputElement;
const put = () => calls.find((c) => c.method === "PUT");
const saved = () => (put()!.body as { settings: Array<{ key: string; secret: boolean; value: string }> }).settings;
const setting = (key: string) => saved().find((s) => s.key === key)!;

// apiFetch consults the host-side runtime config (/tds-runtime.json) before it
// resolves a URL, so without this the first entry in fetch.mock.calls is that
// probe rather than the endpoint under test. The panel products never ship the
// file — they render <meta name="tds-api-base"> instead — so "absent" is also
// what actually happens in production.
beforeEach(() => primeRuntimeConfig(null));

describe("loading", () => {
  it("reads its own namespace of the settings store", async () => {
    await open();
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(pathOf(fetchMock.mock.calls[0]![0] as string)).toBe(NS);
    // Absolute, on the API host. Every other assertion here matches the PATH,
    // which a relative fetch satisfies too — so this is the one that fails if
    // the call ever goes back to the product's own origin (whose SPA fallback
    // answers 200 + HTML and turns into a silent empty state).
    expect(String(fetchMock.mock.calls[0]![0]).startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows a loading line until the settings arrive", () => {
    render(<BillingSettings />);
    expect(screen.getByLabelText("Wird geladen")).toBeTruthy();
  });

  it("reports BOTH secrets as unconfigured when neither is set", async () => {
    await open();
    expect(screen.getAllByText("(nicht konfiguriert)")).toHaveLength(2);
  });

  it("shows only the last four characters of a configured secret", async () => {
    getReply = {
      status: 200,
      body: {
        settings: [
          { key: "stripe_secret_key", secret: true, configured: true, last4: "aa11" },
          { key: "stripe_webhook_secret", secret: true, configured: true, last4: "bb22" },
        ],
      },
    };
    await open();
    expect(screen.getByText("(konfiguriert (…aa11))")).toBeTruthy();
    expect(screen.getByText("(konfiguriert (…bb22))")).toBeTruthy();
  });

  it("KEEPS the two secrets' hints apart", async () => {
    // A shared masked state would report the webhook secret as configured
    // purely because the API key is — and payment confirmation would look
    // healthy while it is not.
    getReply = {
      status: 200,
      body: { settings: [{ key: "stripe_secret_key", secret: true, configured: true, last4: "aa11" }] },
    };
    await open();
    expect(screen.getByText("(konfiguriert (…aa11))")).toBeTruthy();
    expect(screen.getByText("(nicht konfiguriert)")).toBeTruthy();
  });

  it("copes with a configured secret that reports no last4", async () => {
    getReply = {
      status: 200,
      body: { settings: [{ key: "stripe_secret_key", secret: true, configured: true, last4: null }] },
    };
    await open();
    expect(screen.getByText("(konfiguriert (…????))")).toBeTruthy();
  });

  it("reports a KNOWN-BUT-EMPTY secret as unconfigured", async () => {
    getReply = {
      status: 200,
      body: { settings: [{ key: "stripe_secret_key", secret: true, configured: false, last4: null }] },
    };
    await open();
    expect(screen.getAllByText("(nicht konfiguriert)")).toHaveLength(2);
  });

  it("never renders a secret value even if the API leaks one", async () => {
    getReply = {
      status: 200,
      body: {
        settings: [{ key: "stripe_secret_key", secret: true, configured: true, last4: "aa11", value: "sk_live_leak" }],
      },
    };
    await open();
    expect(document.body.textContent).not.toContain("sk_live_leak");
    expect(keyBox().value).toBe("");
  });

  it("keeps both secret fields masked", async () => {
    await open();
    expect(keyBox().getAttribute("type")).toBe("password");
    expect(whBox().getAttribute("type")).toBe("password");
    expect(keyBox().getAttribute("autocomplete")).toBe("off");
    expect(whBox().getAttribute("autocomplete")).toBe("off");
  });

  it("defaults the currency to EUR", async () => {
    await open();
    expect(box("Standard-Währung").value).toBe("EUR");
  });

  it("defaults the payment term to 14 days", async () => {
    await open();
    expect(box("Zahlungsziel (Tage)").value).toBe("14");
  });

  it("keeps the stored currency and term", async () => {
    getReply = stored({ default_currency: "CHF", days_until_due: "30" });
    await open();
    expect(box("Standard-Währung").value).toBe("CHF");
    expect(box("Zahlungsziel (Tage)").value).toBe("30");
  });

  it("names the reason when the user is not an admin", async () => {
    getReply = { status: 403, body: {} };
    render(<BillingSettings />);
    expect(await screen.findByText("Nur für Administratoren.")).toBeTruthy();
  });

  it("treats an expired session the same way", async () => {
    getReply = { status: 401, body: {} };
    render(<BillingSettings />);
    expect(await screen.findByText("Nur für Administratoren.")).toBeTruthy();
  });

  it("reports any other failure with its status", async () => {
    getReply = { status: 500, body: {} };
    render(<BillingSettings />);
    expect(await screen.findByText("Fehler (HTTP 500).")).toBeTruthy();
  });

  it("leaves the loading state even when the request fails", async () => {
    getReply = { status: 500, body: {} };
    render(<BillingSettings />);
    await screen.findByText("Fehler (HTTP 500).");
    expect(screen.queryByLabelText("Wird geladen")).toBeNull();
  });

  it("does NOT apply values carried by a non-OK response", async () => {
    getReply = { status: 403, body: { settings: [{ key: "default_currency", secret: false, value: "USD" }] } };
    render(<BillingSettings />);
    await screen.findByText("Nur für Administratoren.");
    expect(screen.queryByDisplayValue("USD")).toBeNull();
  });

  it("tolerates a response with no settings array", async () => {
    getReply = { status: 200, body: {} };
    await open();
    expect(box("Standard-Währung").value).toBe("EUR");
  });
});

describe("saving", () => {
  it("writes back to the same namespace as JSON", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(pathOf(put()!.url)).toBe(NS);
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === "PUT")!;
    expect((call[1] as RequestInit).headers).toMatchObject({ "Content-Type": "application/json" });
  });

  it("sends all four keys", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(saved().map((s) => s.key).sort()).toEqual([...KEYS].sort());
  });

  it("sends EMPTY secrets when neither field was touched", async () => {
    // The store's "keep the existing secret" signal. Anything else would
    // overwrite a live Stripe key.
    getReply = {
      status: 200,
      body: { settings: [{ key: "stripe_secret_key", secret: true, configured: true, last4: "aa11" }] },
    };
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("stripe_secret_key").value).toBe("");
    expect(setting("stripe_webhook_secret").value).toBe("");
  });

  it("stores BOTH Stripe secrets encrypted, and nothing else", async () => {
    // A Stripe key saved with secret:false lands in the DB in plaintext.
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    for (const s of saved()) {
      expect(s.secret, `${s.key}`).toBe(s.key === "stripe_secret_key" || s.key === "stripe_webhook_secret");
    }
  });

  it("does not put the API key into the webhook slot", async () => {
    const u = await open();
    await u.type(keyBox(), "sk_live_123");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("stripe_secret_key").value).toBe("sk_live_123");
    expect(setting("stripe_webhook_secret").value).toBe("");
  });

  it("does not put the webhook secret into the API-key slot", async () => {
    const u = await open();
    await u.type(whBox(), "whsec_456");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("stripe_webhook_secret").value).toBe("whsec_456");
    expect(setting("stripe_secret_key").value).toBe("");
  });

  it("trims whitespace off a pasted secret", async () => {
    // A trailing newline from a copy-paste makes every Stripe call 401.
    const u = await open();
    await u.type(keyBox(), "  sk_live_123  ");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("stripe_secret_key").value).toBe("sk_live_123");
  });

  it("normalises the currency to upper case", async () => {
    // Stripe rejects a lower-case currency code.
    const u = await open();
    await u.clear(box("Standard-Währung"));
    await u.type(box("Standard-Währung"), "chf");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("default_currency").value).toBe("CHF");
  });

  it("trims the currency before upper-casing it", async () => {
    // Set with fireEvent: the field is `maxLength={3}`, so typing " usd " would
    // be truncated to " us" before the trim ever ran. A paste from a spreadsheet
    // cell arrives padded like this.
    const u = await open();
    fireEvent.change(box("Standard-Währung"), { target: { value: " usd " } });
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("default_currency").value).toBe("USD");
  });

  it("sends the edited payment term", async () => {
    const u = await open();
    await u.clear(box("Zahlungsziel (Tage)"));
    await u.type(box("Zahlungsziel (Tage)"), "30");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(setting("days_until_due").value).toBe("30");
  });

  it("confirms and re-reads the masked state after a save", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("Gespeichert"))).toBe(true));
    await waitFor(() => expect(calls.filter((c) => c.method === "GET")).toHaveLength(2));
  });

  it("clears both typed secrets after a successful save", async () => {
    const u = await open();
    await u.type(keyBox(), "sk_live_123");
    await u.type(whBox(), "whsec_456");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("Gespeichert"))).toBe(true));
    expect(keyBox().value).toBe("");
    expect(whBox().value).toBe("");
  });

  it("KEEPS the typed secrets when the save fails", async () => {
    putReply = { status: 500, body: {} };
    const u = await open();
    await u.type(keyBox(), "sk_live_123");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("500"))).toBe(true));
    expect(keyBox().value).toBe("sk_live_123");
  });

  it("does not re-read after a failed save", async () => {
    putReply = { status: 403, body: {} };
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("403"))).toBe(true));
    expect(calls.filter((c) => c.method === "GET")).toHaveLength(1);
  });

  it("re-enables the save button afterwards", async () => {
    const u = await open();
    const button = screen.getByRole("button", { name: "Speichern" }) as HTMLButtonElement;
    await u.click(button);
    await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("Gespeichert"))).toBe(true));
    expect(button.disabled).toBe(false);
  });
});
