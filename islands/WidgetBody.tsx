import { SummaryMetric } from "@tracht-digital-solutions/tds-shared/components";

interface Summary {
  /** Admins only — whether invoices can be sent at all. */
  configured?: boolean;
  open: number;
}

/** Billing widget body — the open-invoice count from `/billing/summary`. */
export default function WidgetBody() {
  return (
    <SummaryMetric<Summary>
      endpoint="/billing/summary"
      value={(d) => d.open}
      caption={(d) => (d.configured === false ? "Stripe nicht konfiguriert" : d.open === 1 ? "offene Rechnung" : "offene Rechnungen")}
    >
      {(d) => (
        <a className="link-underline text-sm inline-flex items-center min-h-11 self-start" href="/rechnungen">
          {d.open > 0 ? "Jetzt ansehen und bezahlen" : "Alle Rechnungen"}
        </a>
      )}
    </SummaryMetric>
  );
}
