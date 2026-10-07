// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, cleanup } from "@testing-library/react";
import { primeRuntimeConfig } from "@tracht-digital-solutions/tds-shared/api";
import BillingPortal from "./BillingPortal";

/**
 * The portal's invoice view. The route used to render the admin island in the
 * customer product, so every customer met a 403 instead of their invoices.
 */

const fetchMock = vi.fn();
let reply: { status: number; body: unknown } | "offline" = { status: 200, body: { invoices: [] } };

beforeEach(() => {
  primeRuntimeConfig(null);
  fetchMock.mockReset();
  fetchMock.mockImplementation(async () => {
    if (reply === "offline") throw new TypeError("Failed to fetch");
    const { status, body } = reply;
    return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  });
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const OPEN = {
  id: 7,
  status: "open",
  description: "Webshop-Pflege September",
  total_cents: 34000,
  currency: "eur",
  due_date: "2099-12-31",
  hosted_invoice_url: "https://invoice.stripe.test/i/7",
  paid_at: null,
  created_at: "2026-10-01 09:00:00",
};
const PAID = { ...OPEN, id: 3, status: "paid", total_cents: 12000, paid_at: "2026-09-10 12:00:00", hosted_invoice_url: "https://invoice.stripe.test/i/3" };

describe("BillingPortal", () => {
  it("reads the company's own invoices, never the admin list", async () => {
    reply = { status: 200, body: { invoices: [OPEN] } };
    render(<BillingPortal />);
    // Both layouts render (cards below `sm`, the table above); CSS picks one.
    await screen.findAllByText("#7");
    const url = String(fetchMock.mock.calls[0]![0]);
    expect(new URL(url, "http://x").pathname).toBe("/billing/invoices");
  });

  it("leads with what is open and offers the Stripe page to pay it", async () => {
    reply = { status: 200, body: { invoices: [OPEN, PAID] } };
    render(<BillingPortal />);
    expect(await screen.findByText(/1 offene Rechnung/)).toBeTruthy();
    for (const pay of screen.getAllByRole("link", { name: /Rechnung bezahlen: #7/ })) {
      expect(pay.getAttribute("href")).toBe(OPEN.hosted_invoice_url);
      expect(pay.getAttribute("target")).toBe("_blank");
    }
    expect(screen.getAllByRole("link", { name: /Rechnung ansehen: #3/ })).toHaveLength(2);
  });

  it("marks an open invoice past its due date as overdue", async () => {
    reply = { status: 200, body: { invoices: [{ ...OPEN, due_date: "2020-01-01" }] } };
    render(<BillingPortal />);
    expect(await screen.findAllByText("Überfällig")).toHaveLength(2);
  });

  it("says so when there is nothing yet", async () => {
    reply = { status: 200, body: { invoices: [] } };
    render(<BillingPortal />);
    expect(await screen.findByText(/Noch keine Rechnungen/)).toBeTruthy();
  });

  it("explains a missing permission instead of showing a status code", async () => {
    reply = { status: 403, body: { error: "Forbidden" } };
    render(<BillingPortal />);
    expect((await screen.findByRole("alert")).textContent).toContain("Firmen-Administrator");
  });

  it("leaves the spinner when the API is unreachable", async () => {
    reply = "offline";
    render(<BillingPortal />);
    expect((await screen.findByRole("alert")).textContent).toContain("Verbindung");
  });
});
