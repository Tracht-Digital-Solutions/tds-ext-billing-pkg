import { useEffect, useState } from "react";
import { ConfirmDialog, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

const api = apiFetch;

interface Invoice {
  id: number;
  customer_id: number | null;
  currency: string;
  status: string;
  description: string | null;
  total_cents: number;
  hosted_invoice_url: string | null;
  created_at: string;
}
interface ItemForm {
  description: string;
  quantity: string;
  amount: string; // euros
}

const euros = (cents: number, currency: string) =>
  new Intl.NumberFormat("de-DE", { style: "currency", currency }).format(cents / 100);

const STATUS_LABEL: Record<string, string> = { draft: "Entwurf", open: "Offen", paid: "Bezahlt", void: "Storniert" };

export default function BillingAdmin() {
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<Invoice | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [customerId, setCustomerId] = useState("");
  const [description, setDescription] = useState("");
  const [dueDate, setDueDate] = useState("");
  const [items, setItems] = useState<ItemForm[]>([{ description: "", quantity: "1", amount: "" }]);

  const load = async () => {
    const res = await api("/admin/invoices");
    if (res.ok) setInvoices((await res.json()).invoices ?? []);
    // A failed LOAD is persistent state (the list stays empty until it is
    // fixed), so it keeps the in-flow banner — but it is a failure, and the
    // banner used to render it in the info hue.
    else setStatus(res.status === 403 ? "Nur für Administratoren." : `Rechnungen konnten nicht geladen werden (HTTP ${res.status}).`);
    setLoaded(true);
  };
  useEffect(() => {
    void load();
  }, []);

  const setItem = (i: number, patch: Partial<ItemForm>) =>
    setItems((prev) => prev.map((it, idx) => (idx === i ? { ...it, ...patch } : it)));

  const create = async () => {
    const payloadItems = items
      .filter((it) => it.description.trim() !== "" && Number(it.amount) > 0)
      .map((it) => ({
        description: it.description.trim(),
        quantity: Math.max(1, Number(it.quantity) || 1),
        unit_amount_cents: Math.round(Number(it.amount) * 100),
      }));
    if (payloadItems.length === 0) {
      // Validation stays in the form — it names what the user must still fix.
      setStatus("Mindestens eine Position mit Betrag angeben.");
      return;
    }
    const res = await api("/admin/invoices", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        customer_id: customerId.trim() === "" ? null : Number(customerId),
        description,
        due_date: dueDate || null,
        items: payloadItems,
      }),
    });
    if (res.ok) {
      setShowForm(false);
      setCustomerId("");
      setDescription("");
      setDueDate("");
      setItems([{ description: "", quantity: "1", amount: "" }]);
      setStatus(null);
      toast.success("Entwurf erstellt.");
      void load();
    } else {
      toast.danger(`Entwurf konnte nicht erstellt werden (HTTP ${res.status}).`);
    }
  };

  const send = async (id: number) => {
    // This funnelled progress, success AND failure through one info-hued
    // banner, so "Fehler: card_declined" was rendered in the same blue as
    // "An Stripe gesendet." — a failed transfer that looked like a success.
    const res = await api(`/admin/invoices/${id}/send`, { method: "POST" });
    const d = await res.json().catch(() => ({}));
    if (res.ok) toast.success("An Stripe gesendet.");
    else toast.danger(`Senden fehlgeschlagen: ${d.error ?? `HTTP ${res.status}`}`);
    void load();
  };

  // An invoice is a financial record — deleting one was a single unguarded
  // click. Gated by <ConfirmDialog> like every other cascading delete.
  const confirmRemove = async () => {
    const inv = pendingDelete;
    if (!inv) return;
    setDeleting(true);
    try {
      const res = await api(`/admin/invoices/${inv.id}`, { method: "DELETE" });
      setPendingDelete(null);
      if (res.ok) {
        toast.success("Rechnung gelöscht.");
        void load();
      } else {
        // Used to close the dialog and do nothing else — on a financial
        // record, of all things.
        toast.danger(`Löschen fehlgeschlagen (HTTP ${res.status}).`);
      }
    } catch {
      setPendingDelete(null);
      toast.danger("Löschen fehlgeschlagen — die API ist nicht erreichbar.");
    } finally {
      setDeleting(false);
    }
  };

  if (!loaded) return <p><Spinner /></p>;

  return (
    <div className="tds-stack">
      {/* Only the load failure and form validation reach this now (outcomes
          are toasts), so it is a failure banner and gets the danger hue. */}
      {status ? <p className="tds-alert tds-alert--danger" role="alert">{status}</p> : null}

      {showForm ? (
        <div className="tds-card tds-stack">
          <h4>Neue Rechnung</h4>
          <input className="field-boxed" type="number" placeholder="Kunden-ID (optional)" aria-label="Kunden-ID" value={customerId} onChange={(e) => setCustomerId(e.target.value)} />
          <input className="field-boxed" type="text" placeholder="Beschreibung (optional)" aria-label="Beschreibung" value={description} onChange={(e) => setDescription(e.target.value)} />
          <label className="tds-field-row">
            <span>Fällig am</span>
            <input className="field-boxed" type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </label>

          <h5>Positionen</h5>
          {items.map((it, i) => (
            <div key={i} className="tds-toolbar">
              <input className="field-boxed" type="text" placeholder="Beschreibung" aria-label="Positionsbeschreibung" value={it.description} onChange={(e) => setItem(i, { description: e.target.value })} />
              <input className="field-boxed" type="number" min="1" placeholder="Menge" aria-label="Menge" value={it.quantity} onChange={(e) => setItem(i, { quantity: e.target.value })} />
              <input className="field-boxed" type="number" min="0" step="0.01" placeholder="Einzelpreis €" aria-label="Einzelpreis" value={it.amount} onChange={(e) => setItem(i, { amount: e.target.value })} />
            </div>
          ))}
          <button type="button" className="btn btn-ghost" onClick={() => setItems((p) => [...p, { description: "", quantity: "1", amount: "" }])}>
            + Position
          </button>

          <div className="tds-toolbar">
            <button type="button" className="btn btn-primary" onClick={create}>Entwurf erstellen</button>
            <button type="button" className="btn btn-ghost" onClick={() => setShowForm(false)}>Abbrechen</button>
          </div>
        </div>
      ) : (
        <button type="button" className="btn btn-primary" onClick={() => setShowForm(true)}>Neue Rechnung</button>
      )}

      <table className="tds-table">
        <thead>
          <tr>
            <th>Datum</th>
            <th>Kunde</th>
            <th>Betrag</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {invoices.map((inv) => (
            <tr key={inv.id}>
              <td>{inv.created_at.slice(0, 10)}</td>
              <td>{inv.customer_id ?? "—"}</td>
              <td>{euros(inv.total_cents, inv.currency)}</td>
              <td>
                {STATUS_LABEL[inv.status] ?? inv.status}
                {inv.hosted_invoice_url ? (
                  <>
                    {" "}
                    <a href={inv.hosted_invoice_url} target="_blank" rel="noreferrer">↗</a>
                  </>
                ) : null}
              </td>
              <td>
                {inv.status === "draft" ? (
                  // Bare siblings in a cell: a <td> is a table-cell, so there
                  // was no flex and no gap here — the two buttons sat flush
                  // and the column could not wrap them on a narrow screen.
                  <span className="tds-toolbar">
                    <button type="button" className="btn btn-primary" onClick={() => void send(inv.id)}>Senden</button>
                    <button type="button" className="btn btn-ghost" onClick={() => setPendingDelete(inv)}>Löschen</button>
                  </span>
                ) : null}
              </td>
            </tr>
          ))}
          {invoices.length === 0 ? (
            <tr>
              <td colSpan={5} className="opacity-70">Noch keine Rechnungen.</td>
            </tr>
          ) : null}
        </tbody>
      </table>

      <ConfirmDialog
        open={pendingDelete !== null}
        title={`Rechnung #${pendingDelete?.id ?? ""} löschen?`}
        message="Der Rechnungsentwurf und alle Positionen werden dauerhaft entfernt."
        busy={deleting}
        onConfirm={() => void confirmRemove()}
        onCancel={() => setPendingDelete(null)}
      />
    </div>
  );
}
