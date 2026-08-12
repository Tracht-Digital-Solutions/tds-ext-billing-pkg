import { useEffect, useState } from "react";
import { Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

interface Masked {
  key: string;
  secret: boolean;
  configured?: boolean;
  last4?: string | null;
  value?: string;
}

const api = apiFetch;
const NS = "/admin/settings/billing";

/**
 * Stripe settings — secret key + webhook secret + default currency + payment
 * term, in the core runtime settings store (admin-only). Secrets come back masked
 * (configured + last4); a blank secret on save keeps the existing value.
 */
export default function BillingSettings() {
  const [loaded, setLoaded] = useState(false);
  const [keyState, setKeyState] = useState<Masked | null>(null);
  const [whState, setWhState] = useState<Masked | null>(null);
  const [keyInput, setKeyInput] = useState("");
  const [whInput, setWhInput] = useState("");
  const [currency, setCurrency] = useState("EUR");
  const [days, setDays] = useState("14");
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const res = await api(NS);
    if (!res.ok) {
      setStatus(res.status === 403 || res.status === 401 ? "Nur für Administratoren." : `Fehler (HTTP ${res.status}).`);
      setLoaded(true);
      return;
    }
    const d = await res.json();
    const map = new Map<string, Masked>((d.settings ?? []).map((s: Masked) => [s.key, s]));
    setKeyState(map.get("stripe_secret_key") ?? null);
    setWhState(map.get("stripe_webhook_secret") ?? null);
    setCurrency(map.get("default_currency")?.value || "EUR");
    setDays(map.get("days_until_due")?.value || "14");
    setLoaded(true);
  };

  useEffect(() => {
    void load();
  }, []);

  const save = async () => {
    setBusy(true);
    setStatus(null);
    const settings: Masked[] = [
      { key: "stripe_secret_key", secret: true, value: keyInput.trim() },
      { key: "stripe_webhook_secret", secret: true, value: whInput.trim() },
      { key: "default_currency", secret: false, value: currency.trim().toUpperCase() },
      { key: "days_until_due", secret: false, value: days.trim() },
    ];
    const res = await api(NS, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ settings }),
    });
    setBusy(false);
    if (res.ok) {
      setKeyInput("");
      setWhInput("");
      toast.success("Gespeichert.");
      void load();
    } else {
      toast.danger(`Speichern fehlgeschlagen (HTTP ${res.status}).`);
    }
  };

  const hint = (s: Masked | null) => (s?.configured ? `konfiguriert (…${s.last4 ?? "????"})` : "nicht konfiguriert");

  if (!loaded) return <p><Spinner /></p>;

  return (
    <div className="billing-settings space-y-4">
      <label className="block">
        <span className="text-sm">Stripe Secret Key <em className="opacity-60">({hint(keyState)})</em></span>
        <input className="field-boxed" type="password" value={keyInput} onChange={(e) => setKeyInput(e.target.value)} placeholder="sk_… (leer = behalten)" autoComplete="off" />
      </label>
      <label className="block">
        <span className="text-sm">Webhook Secret <em className="opacity-60">({hint(whState)})</em></span>
        <input className="field-boxed" type="password" value={whInput} onChange={(e) => setWhInput(e.target.value)} placeholder="whsec_… (leer = behalten)" autoComplete="off" />
      </label>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label className="block">
          <span className="text-sm">Standard-Währung</span>
          <input className="field-boxed" type="text" maxLength={3} value={currency} onChange={(e) => setCurrency(e.target.value)} placeholder="EUR" />
        </label>
        <label className="block">
          <span className="text-sm">Zahlungsziel (Tage)</span>
          <input className="field-boxed" type="number" min="0" value={days} onChange={(e) => setDays(e.target.value)} placeholder="14" />
        </label>
      </div>
      {/* Outcomes are toasts now; what is left here is the load failure and
          form validation — i.e. a failure, which used to render in the info hue. */}
      {status ? <p className="tds-alert tds-alert--danger" role="alert">{status}</p> : null}
      <button type="button" className="btn btn-primary" onClick={save} disabled={busy} aria-busy={busy}>Speichern</button>
    </div>
  );
}
