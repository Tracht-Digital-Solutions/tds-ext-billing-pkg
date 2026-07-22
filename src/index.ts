import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Stripe billing/invoices manifest. Admin drafts + sends invoices; portal
 * customers view + pay theirs. No hard dependsOn — invoice.customer_id references
 * the tds-ext-customers directory softly (queried defensively at send time).
 */
export default defineExtension({
  id: "billing",
  name: "Rechnungen",
  version: "0.1.0",
  permissions: [
    { id: "billing:read", label: "Rechnungen ansehen", group: "billing" },
    { id: "billing:write", label: "Rechnungen erstellen & senden", group: "billing" },
  ],
  nav: [
    {
      id: "billing",
      label: "Rechnungen",
      href: "/rechnungen",
      icon: "file-text",
      group: "abrechnung",
      order: 10,
      permission: "billing:read",
    },
  ],
  widgets: [
    {
      id: "billing-open",
      title: "Offene Rechnungen",
      island: "@tracht-digital-solutions/tds-ext-billing/widgets/Widget.astro",
      size: "sm",
      permission: "billing:read",
      dataEndpoint: "/billing/summary",
      order: 10,
    },
  ],
  settings: [
    {
      id: "billing",
      label: "Stripe / Rechnungen",
      island: "@tracht-digital-solutions/tds-ext-billing/islands/Settings.astro",
      order: 10,
    },
  ],
  routes: [
    {
      pattern: "/rechnungen",
      entrypoint: "@tracht-digital-solutions/tds-ext-billing/pages/Index.astro",
      permission: "billing:read",
    },
  ],
  i18n: {
    de: { "billing.title": "Rechnungen" },
    en: { "billing.title": "Invoices" },
  },
});
