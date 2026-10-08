import { useEffect, useState } from "react";
import { Spinner } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

/**
 * The portal's invoice view: the active company's own invoices, newest first,
 * with the Stripe page to pay an open one.
 *
 * The API (`GET /billing/invoices`, scoped to the active company, drafts never
 * included) has existed since the extension shipped, but the route always
 * rendered the ADMIN island — so in the portal "Rechnungen" opened a screen
 * whose first call answered 403, and a customer had no way to see or pay an
 * invoice. `pages/Index.astro` picks this island in the customer product.
 */

interface Invoice {
  id: number;
  status: string;
  description: string | null;
  total_cents: number;
  currency: string;
  due_date: string | null;
  hosted_invoice_url: string | null;
  paid_at: string | null;
  created_at: string;
}

const euros = (cents: number, currency: string) =>
  new Intl.NumberFormat("de-DE", { style: "currency", currency }).format(cents / 100);

/** `2026-10-07…` → `07.10.2026`, without a time-zone round trip. */
const day = (iso: string | null) => (iso ? iso.slice(0, 10).split("-").reverse().join(".") : "—");

const STATUS: Record<string, { label: string; tone: string }> = {
  open: { label: "Offen", tone: "warning" },
  paid: { label: "Bezahlt", tone: "success" },
  void: { label: "Storniert", tone: "muted" },
  uncollectible: { label: "Uneinbringlich", tone: "danger" },
};

/** The date line of a phone card. */
const dueLabel = (inv: Invoice) =>
  inv.status === "paid"
    ? inv.paid_at
      ? `Bezahlt am ${day(inv.paid_at)}`
      : "Bezahlt"
    : inv.due_date
      ? `Fällig am ${day(inv.due_date)}`
      : `Vom ${day(inv.created_at)}`;

/** Due date in the past and still open. */
const overdue = (inv: Invoice) =>
  inv.status === "open" && inv.due_date !== null && inv.due_date.slice(0, 10) < new Date().toISOString().slice(0, 10);

export default function BillingPortal() {
  const [invoices, setInvoices] = useState<Invoice[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    (async () => {
      // apiFetch hands back every HTTP status but REJECTS when the API is
      // unreachable; uncaught, the view would sit on its spinner for good.
      const res = await apiFetch("/billing/invoices").catch(() => null);
      if (!alive) return;
      if (res === null) {
        setError("Rechnungen konnten nicht geladen werden — die Verbindung ist unterbrochen. Bitte später erneut versuchen.");
      } else if (res.status === 403) {
        setError("Ihr Zugang enthält keine Berechtigung für Rechnungen. Ihr Firmen-Administrator kann sie freigeben.");
      } else if (!res.ok) {
        setError(`Rechnungen konnten nicht geladen werden (HTTP ${res.status}).`);
      } else {
        setInvoices(((await res.json()) as { invoices?: Invoice[] }).invoices ?? []);
      }
    })();
    return () => {
      alive = false;
    };
  }, []);

  if (error) return <p className="tds-alert tds-alert--danger" role="alert">{error}</p>;
  if (invoices === null) {
    return (
      <p className="tds-empty" aria-busy="true">
        <Spinner /> Rechnungen werden geladen …
      </p>
    );
  }
  if (invoices.length === 0) {
    return <p className="tds-empty">Noch keine Rechnungen. Sobald wir Ihnen eine Rechnung stellen, finden Sie sie hier.</p>;
  }

  const open = invoices.filter((i) => i.status === "open");
  const openTotal = open.reduce((sum, i) => sum + i.total_cents, 0);

  return (
    <div className="tds-stack">
      {/* The one thing a customer comes here to learn: is anything to pay? */}
      {open.length > 0 ? (
        // One text run: `.tds-alert` is a flex row, and loose text nodes beside
        // a <strong> became separate flex items with a gap before the period.
        <p className="tds-alert tds-alert--warning" role="status">
          <span>
            {open.length === 1 ? "1 offene Rechnung" : `${open.length} offene Rechnungen`} über{" "}
            <strong>{euros(openTotal, open[0]!.currency)}</strong>.
          </span>
        </p>
      ) : (
        <p className="tds-alert tds-alert--success" role="status">Alle Rechnungen sind bezahlt.</p>
      )}

      {/* Phone: one card per invoice. Six columns do not fit 390px, and the one
          that fell off the edge was the status — the thing a customer looks
          for. The breakpoint sits on a wrapper: `.tds-stack` is unlayered CSS and
          its `display: flex` beat Tailwind's `sm:hidden`, so a desktop showed
          every invoice twice, as cards and in the table. */}
      <div className="sm:hidden">
        <ul className="tds-stack" aria-label="Rechnungen">
          {invoices.map((inv) => {
            const s = STATUS[inv.status] ?? { label: inv.status, tone: "muted" };
            const late = overdue(inv);
            return (
              <li key={inv.id} className="tds-card tds-stack p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-semibold tabular-nums">#{inv.id}</p>
                    {inv.description ? <p className="text-sm opacity-70">{inv.description}</p> : null}
                  </div>
                  <span className={`status-pill status-pill--${late ? "danger" : s.tone}`}>{late ? "Überfällig" : s.label}</span>
                </div>
                <p className="flex items-baseline justify-between gap-3">
                  <span className="text-sm opacity-70">{dueLabel(inv)}</span>
                  <strong className="tabular-nums">{euros(inv.total_cents, inv.currency)}</strong>
                </p>
                {inv.hosted_invoice_url ? (
                  <a
                    className={`${inv.status === "open" ? "btn btn-primary" : "btn btn-ghost"} w-full justify-center`}
                    href={inv.hosted_invoice_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={`${inv.status === "open" ? "Rechnung bezahlen" : "Rechnung ansehen"}: #${inv.id} (neuer Tab)`}
                  >
                    {inv.status === "open" ? "Bezahlen" : "Ansehen"}
                  </a>
                ) : null}
              </li>
            );
          })}
        </ul>
      </div>

      <div className="hidden sm:block overflow-x-auto">
        <table className="tds-table">
          <thead>
            <tr>
              <th scope="col">Rechnung</th>
              <th scope="col">Datum</th>
              <th scope="col">Fällig</th>
              <th scope="col" className="text-right">Betrag</th>
              <th scope="col">Status</th>
              <th scope="col"><span className="sr-only">Aktion</span></th>
            </tr>
          </thead>
          <tbody>
            {invoices.map((inv) => {
              const s = STATUS[inv.status] ?? { label: inv.status, tone: "muted" };
              const late = overdue(inv);
              return (
                <tr key={inv.id}>
                  <th scope="row">
                    <span className="tabular-nums">#{inv.id}</span>
                    {inv.description ? <span className="block text-sm opacity-70">{inv.description}</span> : null}
                  </th>
                  <td className="tabular-nums">{day(inv.created_at)}</td>
                  <td className="tabular-nums">{inv.status === "paid" ? (inv.paid_at ? `bezahlt ${day(inv.paid_at)}` : "bezahlt") : day(inv.due_date)}</td>
                  <td className="tabular-nums text-right">{euros(inv.total_cents, inv.currency)}</td>
                  <td>
                    <span className={`status-pill status-pill--${late ? "danger" : s.tone}`}>{late ? "Überfällig" : s.label}</span>
                  </td>
                  <td>
                    {inv.hosted_invoice_url ? (
                      <a
                        className={inv.status === "open" ? "btn btn-primary" : "btn btn-ghost"}
                        href={inv.hosted_invoice_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`${inv.status === "open" ? "Rechnung bezahlen" : "Rechnung ansehen"}: #${inv.id} (neuer Tab)`}
                      >
                        {inv.status === "open" ? "Bezahlen" : "Ansehen"}
                      </a>
                    ) : null}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="marginalia">Bezahlt wird sicher über Stripe; der Status aktualisiert sich nach Zahlungseingang automatisch.</p>
    </div>
  );
}
