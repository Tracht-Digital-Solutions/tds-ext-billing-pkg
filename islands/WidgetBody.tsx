import { useEffect, useState } from "react";
import { Skeleton } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

interface Summary {
  /** Admins only — whether invoices can be sent at all. */
  configured?: boolean;
  open: number;
}

/** Billing widget body — open-invoice count. Same-origin fetch with credentials. */
export default function WidgetBody() {
  const [data, setData] = useState<Summary | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    apiFetch("/billing/summary")
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((d: Summary) => setData(d))
      .catch(() => setError(true));
  }, []);

  if (error) return <p className="tds-widget__metric">—</p>;
  if (!data) return <p className="tds-widget__metric" aria-busy="true"><Skeleton width="3ch" height="1.75rem" /></p>;

  return (
    <div className="tds-stack">
      <p className="tds-widget__metric">{data.open}</p>
      <p className="marginalia">
        {data.configured === false ? "Stripe nicht konfiguriert" : data.open === 1 ? "offene Rechnung" : "offene Rechnungen"}
      </p>
      <a className="link-underline text-sm inline-flex items-center min-h-11 self-start" href="/rechnungen">
        {data.open > 0 ? "Jetzt ansehen und bezahlen" : "Alle Rechnungen"}
      </a>
    </div>
  );
}
