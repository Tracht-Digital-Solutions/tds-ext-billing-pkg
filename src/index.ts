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
    // The portal's own key (tds-shared PORTAL_PERMISSIONS), granted by the auth
    // API's system groups Vollzugriff / Buchhaltung / Nur Lesen. Declared here so
    // it is grantable in the matrix and gates the portal's view of the
    // company's OWN invoices. The admin routes still need a platform admin.
    { id: "invoices:read", label: "Eigene Rechnungen ansehen (Portal)", group: "billing" },
  ],
  nav: [
    {
      id: "billing",
      label: "Rechnungen",
      href: "/rechnungen",
      icon: "file-text",
      group: "abrechnung",
      order: 10,
      // The portal key: the admin side is platform-admin only anyway (admins
      // bypass), and `billing:read` hid the page from exactly the customers it
      // exists for.
      permission: "invoices:read",
    },
  ],
  widgets: [
    {
      id: "billing-open",
      title: "Offene Rechnungen",
      island: "@tracht-digital-solutions/tds-ext-billing/widgets/Widget.astro",
      size: "sm",
      // The portal key, like the page: the summary counts the active company's
      // own open invoices for a customer and every open one for an admin.
      permission: "invoices:read",
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
      permission: "invoices:read",
    },
  ],
  i18n: {
    de: { "billing.title": "Rechnungen" },
    en: { "billing.title": "Invoices" },
  },
});
