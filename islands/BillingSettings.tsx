import { useEffect, useState } from "react";
import { Alert, Spinner } from "@tracht-digital-solutions/tds-shared/components";
import { SecretField, useRuntimeSettings } from "@tracht-digital-solutions/tds-shared/admin";

/**
 * Stripe settings — secret key + webhook secret + default currency + payment
 * term, in the core runtime settings store (`/admin/settings/billing`,
 * admin-only), loaded and saved by tds-shared `useRuntimeSettings`. Secrets
 * come back masked (configured + last4); a blank secret on save keeps the
 * existing value.
 */
export default function BillingSettings() {
  const settings = useRuntimeSettings("billing");
  const [keyInput, setKeyInput] = useState("");
  const [whInput, setWhInput] = useState("");
  const [currency, setCurrency] = useState("EUR");
  const [days, setDays] = useState("14");

  useEffect(() => {
    setCurrency(settings.value("default_currency", "EUR"));
    setDays(settings.value("days_until_due", "14"));
  }, [settings.settings]);

  const save = async () => {
    const saved = await settings.save([
      { key: "stripe_secret_key", secret: true, value: keyInput.trim() },
      { key: "stripe_webhook_secret", secret: true, value: whInput.trim() },
      { key: "default_currency", value: currency.trim().toUpperCase() },
      { key: "days_until_due", value: days.trim() },
    ]);
    // The typed secrets stay in their fields after a failure, for another attempt.
    if (saved) {
      setKeyInput("");
      setWhInput("");
    }
  };

  if (!settings.loaded) return <p><Spinner /></p>;

  return (
    <div className="billing-settings space-y-4">
      <p className="text-sm opacity-70">Nur nötig, wenn dieses Modul ein eigenes Stripe-Konto nutzen soll. Ohne eigenen Schlüssel gilt das zentrale Konto unter Einstellungen → Zahlungen (Stripe); dort stehen auch alle Webhook-Adressen.</p>
      <SecretField label="Stripe Secret Key, optional" setting={settings.get("stripe_secret_key")} value={keyInput} onChange={setKeyInput} placeholder="sk_… (leer = behalten)" />
      <SecretField label="Webhook Secret" setting={settings.get("stripe_webhook_secret")} value={whInput} onChange={setWhInput} placeholder="whsec_… (leer = behalten)" />
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
      {/* Outcomes are toasts; what is left here is the load failure. */}
      <Alert>{settings.error}</Alert>
      <button type="button" className="btn btn-primary" onClick={() => void save()} disabled={settings.busy} aria-busy={settings.busy}>Speichern</button>
    </div>
  );
}
