// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import BillingAdmin from "./BillingAdmin";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

/**
 * The invoice admin. This island creates invoices and hands them to STRIPE,
 * where they become real money owed by a real customer, so the assertions
 * concentrate on the things that cannot be walked back:
 *
 *  - **Senden and Löschen exist only on a `draft`.** Once an invoice is open or
 *    paid it must not be re-sent or deleted from here — that is the only guard
 *    against double-charging a customer.
 *  - **Amounts are typed in EUROS and sent in CENTS.** A 100× slip in either
 *    direction is a billing incident, so the conversion is pinned at its edges.
 *  - **A line with no amount never reaches Stripe.** The empty starter row is
 *    always present in the form; if it were submitted, every invoice would
 *    carry a phantom 0 € position.
 *
 * Error-path tests deliberately answer with a POPULATED body and a non-OK
 * status: against an empty error body the `res.ok` check is unobservable.
 */

interface Reply {
  status: number;
  body: unknown;
}
type Handler = (url: string, init?: RequestInit) => Reply | undefined;

let calls: Array<{ url: string; method: string; body: unknown }> = [];
let handlers: Handler[] = [];


/**
 * Path + query of a request. The island calls an ABSOLUTE URL now (via
 * `apiFetch`); a relative one would hit the product's own static host and come
 * back as SPA-fallback HTML with a 200. Matching on the path keeps the route
 * matchers below anchored.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");

function respond(match: RegExp, body: unknown, status = 200, method?: string) {
  handlers.unshift((url, init) => {
    if (!match.test(pathOf(url))) return undefined;
    if (method && (init?.method ?? "GET") !== method) return undefined;
    return { status, body };
  });
}

/** The same formatting the island uses, with NBSPs normalised for matching. */
const money = (cents: number, currency = "EUR") =>
  new Intl.NumberFormat("de-DE", { style: "currency", currency }).format(cents / 100).replace(/\s/g, " ");

const DRAFT = {
  id: 3,
  customer_id: 12,
  currency: "EUR",
  status: "draft",
  description: "Website-Relaunch",
  total_cents: 119000,
  hosted_invoice_url: null as string | null,
  created_at: "2026-07-20T09:00:00Z",
};
const OPEN = { ...DRAFT, id: 4, status: "open", hosted_invoice_url: "https://invoice.stripe.com/i/abc" };

/** Outcomes are toasts now — collected off the `tds:toast` bus. */
let toasts: Array<{ variant: string; message: string }> = [];
const collectToast = (e: Event) => {
  toasts.push((e as CustomEvent<{ variant: string; message: string }>).detail);
};

beforeEach(() => {
  toasts = [];
  window.addEventListener(TOAST_EVENT, collectToast);
  calls = [];
  handlers = [() => ({ status: 200, body: {} })];
  respond(/^\/admin\/invoices$/, { invoices: [] });
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      const reply = handlers.map((h) => h(url, init)).find((r) => r !== undefined)!;
      return { ok: reply.status < 300, status: reply.status, json: async () => reply.body } as Response;
    }),
  );
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, collectToast);
  cleanup();
});

const user = () => userEvent.setup({ delay: null });
const sent = (method: string, match: RegExp) => calls.filter((c) => c.method === method && match.test(pathOf(c.url)));
const created = () => sent("POST", /^\/admin\/invoices$/);

async function open(invoices: unknown[] = []) {
  respond(/^\/admin\/invoices$/, { invoices }, 200, "GET");
  render(<BillingAdmin />);
  const u = user();
  await waitFor(() => expect(calls.length).toBeGreaterThan(0));
  await screen.findByRole("table");
  return u;
}

const row = (text: string) => screen.getAllByRole("row").find((r) => r.textContent!.includes(text))!;

/** Open the create form and fill one valid position. */
async function fillOnePosition(u: ReturnType<typeof user>, desc = "Konzeption", qty?: string, amount = "100") {
  await u.click(screen.getByRole("button", { name: "Neue Rechnung" }));
  await u.type(screen.getByPlaceholderText("Beschreibung"), desc);
  if (qty !== undefined) {
    await u.clear(screen.getByPlaceholderText("Menge"));
    await u.type(screen.getByPlaceholderText("Menge"), qty);
  }
  await u.type(screen.getByPlaceholderText("Einzelpreis €"), amount);
}

describe("loading", () => {
  it("reads the invoice list with credentials", async () => {
    await open();
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(pathOf(fetchMock.mock.calls[0]![0] as string)).toBe("/admin/invoices");
    // Absolute, on the API host. Every other assertion here matches the PATH,
    // which a relative fetch satisfies too — so this is the one that fails if
    // the call ever goes back to the product's own origin (whose SPA fallback
    // answers 200 + HTML and turns into a silent empty state).
    expect(String(fetchMock.mock.calls[0]![0]).startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows a loading line until the list arrives", () => {
    render(<BillingAdmin />);
    expect(screen.getByLabelText("Wird geladen")).toBeTruthy();
  });

  it("says so when nothing has been invoiced", async () => {
    await open();
    expect(screen.getByText("Noch keine Rechnungen.")).toBeTruthy();
  });

  it("lists an invoice with its date, customer and amount", async () => {
    await open([DRAFT]);
    const r = row("Entwurf");
    expect(r.textContent).toContain("2026-07-20");
    expect(r.textContent).toContain("12");
    expect(within(r).getByText(money(119000))).toBeTruthy();
  });

  it("shows a dash for an invoice with no customer", async () => {
    await open([{ ...DRAFT, customer_id: null }]);
    expect(within(row("Entwurf")).getByText("—")).toBeTruthy();
  });

  it("formats the amount in the invoice's OWN currency", async () => {
    // Rendering a CHF invoice with a € sign misstates what is owed.
    await open([{ ...DRAFT, currency: "CHF", total_cents: 5000 }]);
    expect(within(row("Entwurf")).getByText(money(5000, "CHF"))).toBeTruthy();
  });

  it("translates each Stripe status", async () => {
    await open([
      DRAFT,
      { ...DRAFT, id: 4, status: "open", total_cents: 100 },
      { ...DRAFT, id: 5, status: "paid", total_cents: 200 },
      { ...DRAFT, id: 6, status: "void", total_cents: 300 },
    ]);
    for (const label of ["Entwurf", "Offen", "Bezahlt", "Storniert"]) {
      expect(screen.getByText(new RegExp(label))).toBeTruthy();
    }
  });

  it("falls back to the raw status for one it does not know", async () => {
    await open([{ ...DRAFT, status: "uncollectible" }]);
    expect(screen.getByText(/uncollectible/)).toBeTruthy();
  });

  it("links to the hosted Stripe invoice, safely", async () => {
    await open([OPEN]);
    const link = within(row("Offen")).getByRole("link") as HTMLAnchorElement;
    expect(link.getAttribute("href")).toBe("https://invoice.stripe.com/i/abc");
    expect(link.getAttribute("target")).toBe("_blank");
    expect(link.getAttribute("rel")).toBe("noreferrer");
  });

  it("shows no link when Stripe has not hosted it yet", async () => {
    await open([DRAFT]);
    expect(within(row("Entwurf")).queryByRole("link")).toBeNull();
  });

  it("names the reason when the user is not an admin", async () => {
    respond(/^\/admin\/invoices$/, {}, 403, "GET");
    render(<BillingAdmin />);
    expect(await screen.findByText("Nur für Administratoren.")).toBeTruthy();
  });

  it("reports any other failure with its status", async () => {
    respond(/^\/admin\/invoices$/, {}, 500, "GET");
    render(<BillingAdmin />);
    expect(await screen.findByText("Rechnungen konnten nicht geladen werden (HTTP 500).")).toBeTruthy();
  });

  it("does NOT list invoices carried by a non-OK response", async () => {
    respond(/^\/admin\/invoices$/, { invoices: [DRAFT] }, 403, "GET");
    render(<BillingAdmin />);
    await screen.findByText("Nur für Administratoren.");
    expect(screen.getByText("Noch keine Rechnungen.")).toBeTruthy();
  });

  it("leaves the loading state even when the request fails", async () => {
    respond(/^\/admin\/invoices$/, {}, 500, "GET");
    render(<BillingAdmin />);
    await screen.findByText("Rechnungen konnten nicht geladen werden (HTTP 500).");
    expect(screen.queryByLabelText("Wird geladen")).toBeNull();
  });

  it("tolerates a response with no invoices field", async () => {
    respond(/^\/admin\/invoices$/, {}, 200, "GET");
    render(<BillingAdmin />);
    expect(await screen.findByText("Noch keine Rechnungen.")).toBeTruthy();
  });
});

describe("the draft-only actions", () => {
  it("offers Senden and Löschen on a draft", async () => {
    await open([DRAFT]);
    const r = row("Entwurf");
    expect(within(r).getByRole("button", { name: "Senden" })).toBeTruthy();
    expect(within(r).getByRole("button", { name: "Löschen" })).toBeTruthy();
  });

  it("offers NEITHER on an invoice already sent to Stripe", async () => {
    // Re-sending an open invoice would charge the customer twice; deleting one
    // Stripe already knows about would desync the two ledgers.
    await open([OPEN]);
    const r = row("Offen");
    expect(within(r).queryByRole("button", { name: "Senden" })).toBeNull();
    expect(within(r).queryByRole("button", { name: "Löschen" })).toBeNull();
  });

  it("offers neither on a PAID invoice", async () => {
    await open([{ ...DRAFT, status: "paid" }]);
    expect(within(row("Bezahlt")).queryByRole("button", { name: "Senden" })).toBeNull();
  });

  it("offers neither on a voided invoice", async () => {
    await open([{ ...DRAFT, status: "void" }]);
    expect(within(row("Storniert")).queryByRole("button", { name: "Senden" })).toBeNull();
  });
});

describe("sending an invoice to Stripe", () => {
  it("posts to the invoice's own send endpoint", async () => {
    const u = await open([DRAFT]);
    await u.click(within(row("Entwurf")).getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(sent("POST", /invoices\/3\/send$/)).toHaveLength(1));
  });

  it("sends the invoice whose button was pressed", async () => {
    const u = await open([DRAFT, { ...DRAFT, id: 9, description: "Zweite", total_cents: 100 }]);
    const second = screen.getAllByRole("row").filter((r) => r.textContent!.includes("Entwurf"))[1]!;
    await u.click(within(second).getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(sent("POST", /invoices\/9\/send$/)).toHaveLength(1));
    expect(sent("POST", /invoices\/3\/send$/)).toHaveLength(0);
  });

  it("confirms the hand-off", async () => {
    const u = await open([DRAFT]);
    await u.click(within(row("Entwurf")).getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("An Stripe gesendet"))).toBe(true));
  });

  it("does NOT claim a hand-off that failed", async () => {
    respond(/send$/, { error: "Stripe: no such customer" }, 502, "POST");
    const u = await open([DRAFT]);
    await u.click(within(row("Entwurf")).getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("Stripe"))).toBe(true));
    expect(toasts.some((t) => t.message.includes("An Stripe gesendet"))).toBe(false);
  });

  it("falls back to the status code when Stripe returns no message", async () => {
    respond(/send$/, {}, 502, "POST");
    const u = await open([DRAFT]);
    await u.click(within(row("Entwurf")).getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("502"))).toBe(true));
  });

  it("re-reads the list either way, so a half-finished send cannot hide", async () => {
    // The invoice may have reached Stripe even when the response failed; the
    // reload is what shows the real status.
    respond(/send$/, { error: "timeout" }, 504, "POST");
    const u = await open([DRAFT]);
    await u.click(within(row("Entwurf")).getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(sent("GET", /^\/admin\/invoices$/)).toHaveLength(2));
  });
});

describe("deleting a draft", () => {
  // Deleting an invoice moved behind the shared <ConfirmDialog> — it is a
  // financial record — and these tests were never migrated with it, so they
  // clicked the row button and waited for a DELETE that now only fires once
  // the dialog is confirmed. All three had been failing.
  async function pressDelete(u: ReturnType<typeof user>, target?: HTMLElement) {
    await u.click(within(target ?? row("Entwurf")).getByRole("button", { name: "Löschen" }));
    await u.click(screen.getAllByRole("button", { name: /Löschen/ }).at(-1)!);
  }

  it("deletes the draft it was asked to delete", async () => {
    const u = await open([DRAFT, { ...DRAFT, id: 9, total_cents: 100 }]);
    const second = screen.getAllByRole("row").filter((r) => r.textContent!.includes("Entwurf"))[1]!;
    await pressDelete(u, second);
    await waitFor(() => expect(sent("DELETE", /invoices\/9$/)).toHaveLength(1));
    expect(sent("DELETE", /invoices\/3$/)).toHaveLength(0);
  });

  it("sends nothing until the dialog is confirmed", async () => {
    const u = await open([DRAFT]);
    await u.click(within(row("Entwurf")).getByRole("button", { name: "Löschen" }));
    expect(sent("DELETE", /invoices/)).toHaveLength(0);
  });

  it("reloads the list afterwards", async () => {
    const u = await open([DRAFT]);
    await pressDelete(u);
    await waitFor(() => expect(sent("GET", /^\/admin\/invoices$/)).toHaveLength(2));
  });

  it("does NOT reload after a failed delete, and says why", async () => {
    // A reload would make it look as though the row simply vanished.
    respond(/invoices\/3$/, { error: "nope" }, 500, "DELETE");
    const u = await open([DRAFT]);
    await pressDelete(u);
    await waitFor(() => expect(sent("DELETE", /invoices\/3$/)).toHaveLength(1));
    expect(sent("GET", /^\/admin\/invoices$/)).toHaveLength(1);
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("500"))).toBe(true));
  });
});

describe("creating a draft", () => {
  it("hides the form until it is asked for", async () => {
    await open();
    expect(screen.queryByPlaceholderText("Einzelpreis €")).toBeNull();
    expect(screen.getByRole("button", { name: "Neue Rechnung" })).toBeTruthy();
  });

  it("REFUSES to create an invoice with no priced position", async () => {
    // The starter row is empty; submitting it would create a 0 € invoice.
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neue Rechnung" }));
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    expect(await screen.findByText("Mindestens eine Position mit Betrag angeben.")).toBeTruthy();
    expect(created()).toHaveLength(0);
  });

  it("refuses a position with a description but no amount", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neue Rechnung" }));
    await u.type(screen.getByPlaceholderText("Beschreibung"), "Konzeption");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    expect(await screen.findByText("Mindestens eine Position mit Betrag angeben.")).toBeTruthy();
    expect(created()).toHaveLength(0);
  });

  it("refuses a position priced at zero", async () => {
    const u = await open();
    await fillOnePosition(u, "Kulanz", undefined, "0");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    expect(await screen.findByText("Mindestens eine Position mit Betrag angeben.")).toBeTruthy();
    expect(created()).toHaveLength(0);
  });

  it("refuses an amount with no description", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neue Rechnung" }));
    await u.type(screen.getByPlaceholderText("Einzelpreis €"), "100");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    expect(await screen.findByText("Mindestens eine Position mit Betrag angeben.")).toBeTruthy();
    expect(created()).toHaveLength(0);
  });

  it("SENDS the unit amount in CENTS", async () => {
    const u = await open();
    await fillOnePosition(u, "Konzeption", undefined, "1190");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    const items = (created()[0]!.body as { items: Array<{ unit_amount_cents: number }> }).items;
    expect(items[0]!.unit_amount_cents).toBe(119000);
  });

  it("rounds a sub-cent amount rather than truncating it", async () => {
    const u = await open();
    await fillOnePosition(u, "Konzeption", undefined, "1.999");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    const items = (created()[0]!.body as { items: Array<{ unit_amount_cents: number }> }).items;
    expect(items[0]!.unit_amount_cents).toBe(200);
  });

  it("trims the position description", async () => {
    const u = await open();
    await fillOnePosition(u, "  Konzeption  ");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    const items = (created()[0]!.body as { items: Array<{ description: string }> }).items;
    expect(items[0]!.description).toBe("Konzeption");
  });

  it("reads a zero quantity as 1 — a 0 would invoice nothing at all", async () => {
    const u = await open();
    await fillOnePosition(u, "Konzeption", "0");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    const items = (created()[0]!.body as { items: Array<{ quantity: number }> }).items;
    expect(items[0]!.quantity).toBe(1);
  });

  it("CLAMPS a negative quantity to 1 rather than crediting the customer", async () => {
    // `Number("0") || 1` already yields 1, so the zero case above never reaches
    // the `Math.max(1, …)`. A negative does — and a negative quantity on a
    // Stripe line item turns an invoice into a refund.
    const u = await open();
    await fillOnePosition(u, "Konzeption");
    fireEvent.change(screen.getByPlaceholderText("Menge"), { target: { value: "-3" } });
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    const items = (created()[0]!.body as { items: Array<{ quantity: number }> }).items;
    expect(items[0]!.quantity).toBe(1);
  });

  it("keeps a real quantity", async () => {
    const u = await open();
    await fillOnePosition(u, "Konzeption", "3");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    const items = (created()[0]!.body as { items: Array<{ quantity: number }> }).items;
    expect(items[0]!.quantity).toBe(3);
  });

  it("DROPS the empty starter row from a multi-position invoice", async () => {
    const u = await open();
    await fillOnePosition(u, "Konzeption", undefined, "100");
    await u.click(screen.getByRole("button", { name: "+ Position" }));
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    const items = (created()[0]!.body as { items: unknown[] }).items;
    expect(items).toHaveLength(1);
  });

  it("sends both positions when both are priced", async () => {
    const u = await open();
    await fillOnePosition(u, "Konzeption", undefined, "100");
    await u.click(screen.getByRole("button", { name: "+ Position" }));
    const descs = screen.getAllByPlaceholderText("Beschreibung");
    const amounts = screen.getAllByPlaceholderText("Einzelpreis €");
    await u.type(descs[1]!, "Umsetzung");
    await u.type(amounts[1]!, "250");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    const items = (created()[0]!.body as { items: Array<{ description: string; unit_amount_cents: number }> }).items;
    expect(items).toHaveLength(2);
    expect(items[1]).toMatchObject({ description: "Umsetzung", unit_amount_cents: 25000 });
  });

  it("edits only the position that was typed into", async () => {
    const u = await open();
    await fillOnePosition(u, "Konzeption", undefined, "100");
    await u.click(screen.getByRole("button", { name: "+ Position" }));
    await u.type(screen.getAllByPlaceholderText("Beschreibung")[1]!, "Umsetzung");
    expect((screen.getAllByPlaceholderText("Beschreibung")[0]! as HTMLInputElement).value).toBe("Konzeption");
  });

  it("sends NULL for an unassigned customer, not an empty string", async () => {
    // `Number("")` is 0, which would attach the invoice to customer 0.
    const u = await open();
    await fillOnePosition(u);
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    expect((created()[0]!.body as { customer_id: unknown }).customer_id).toBeNull();
  });

  it("sends the customer id as a number", async () => {
    const u = await open();
    await u.click(screen.getByRole("button", { name: "Neue Rechnung" }));
    await u.type(screen.getByPlaceholderText("Kunden-ID (optional)"), "12");
    await u.type(screen.getByPlaceholderText("Beschreibung"), "Konzeption");
    await u.type(screen.getByPlaceholderText("Einzelpreis €"), "100");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    expect((created()[0]!.body as { customer_id: unknown }).customer_id).toBe(12);
  });

  it("sends NULL for an unset due date", async () => {
    const u = await open();
    await fillOnePosition(u);
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    expect((created()[0]!.body as { due_date: unknown }).due_date).toBeNull();
  });

  it("sends the chosen due date", async () => {
    const u = await open();
    await fillOnePosition(u);
    await u.type(screen.getByLabelText("Fällig am"), "2026-08-31");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(created()).toHaveLength(1));
    expect((created()[0]!.body as { due_date: unknown }).due_date).toBe("2026-08-31");
  });

  it("closes the form, resets it and reloads on success", async () => {
    const u = await open();
    await fillOnePosition(u);
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "success" && t.message.includes("Entwurf erstellt"))).toBe(true));
    expect(screen.queryByPlaceholderText("Einzelpreis €")).toBeNull();
    await waitFor(() => expect(sent("GET", /^\/admin\/invoices$/)).toHaveLength(2));
    await u.click(screen.getByRole("button", { name: "Neue Rechnung" }));
    expect((screen.getByPlaceholderText("Beschreibung") as HTMLInputElement).value).toBe("");
  });

  it("KEEPS the form and its content when creation fails", async () => {
    // Re-typing a multi-position invoice from memory is how numbers go wrong.
    respond(/^\/admin\/invoices$/, { error: "nope" }, 500, "POST");
    const u = await open();
    await fillOnePosition(u, "Konzeption", undefined, "100");
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("500"))).toBe(true));
    expect((screen.getByPlaceholderText("Beschreibung") as HTMLInputElement).value).toBe("Konzeption");
  });

  it("does not reload after a failed creation", async () => {
    respond(/^\/admin\/invoices$/, { error: "nope" }, 500, "POST");
    const u = await open();
    await fillOnePosition(u);
    await u.click(screen.getByRole("button", { name: "Entwurf erstellen" }));
await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("500"))).toBe(true));
    expect(sent("GET", /^\/admin\/invoices$/)).toHaveLength(1);
  });

  it("abandons the form without sending anything", async () => {
    const u = await open();
    await fillOnePosition(u);
    await u.click(screen.getByRole("button", { name: "Abbrechen" }));
    expect(screen.queryByPlaceholderText("Einzelpreis €")).toBeNull();
    expect(created()).toHaveLength(0);
  });
});
