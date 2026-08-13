<?php
/**
 * API documentation for this module's routes — consumed through `ApiDocSource`
 * and rendered in the admin frontend's API reference (`GET /wiki.json`).
 *
 * `pattern` must match the Slim pattern in `register()` VERBATIM, inline regex
 * included: it is the join key for the route introspection.
 * `php/tests/BillingApiDocsTest.php` fails the build if the documented set and
 * the registered set drift apart in either direction.
 */

declare(strict_types=1);

return [
    [
        'method' => 'GET',
        'pattern' => '/billing/summary',
        'tag' => 'Übersicht',
        'summary' => 'Offene Rechnungen und Stripe-Konfigurationszustand',
        'description' => 'Die `dataEndpoint`-Route des Dashboard-Widgets. `configured` '
            . 'unterscheidet „keine offenen Rechnungen" von „Stripe ist gar nicht '
            . 'eingerichtet" — ohne das zeigt das Widget in beiden Fällen dieselbe Null.',
        'permission' => 'billing:read',
        'responses' => [
            ['status' => 200, 'description' => '`{configured: bool, open: <Anzahl>}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `billing:read`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/invoices',
        'tag' => 'Verwaltung',
        'summary' => 'Alle Rechnungen auflisten',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => '`{invoices: [{id, customer_id, status, currency, total, …}]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/invoices',
        'tag' => 'Verwaltung',
        'summary' => 'Rechnungsentwurf anlegen',
        'description' => 'Legt nur lokal an — bei Stripe passiert noch nichts. Das ist der '
            . 'erste Schritt der Kette Entwurf → senden → per Webhook bezahlt.',
        'auth' => 'admin',
        'params' => [
            ['in' => 'body', 'name' => 'items', 'type' => 'array', 'required' => true, 'description' => 'Mindestens eine Position aus `{description, unit_amount_cents, quantity?}`.'],
            ['in' => 'body', 'name' => 'customer_id', 'type' => 'int', 'description' => 'Kunde aus dem Verzeichnis. Leer lassen und beim Senden Name/E-Mail angeben.'],
            ['in' => 'body', 'name' => 'currency', 'type' => 'string', 'description' => 'Vorgabe aus den Einstellungen (`billing`-Namensraum).'],
            ['in' => 'body', 'name' => 'description', 'type' => 'string', 'description' => 'Optional, auf 500 Zeichen gekürzt.'],
            ['in' => 'body', 'name' => 'due_date', 'type' => 'date', 'description' => 'Optional, `YYYY-MM-DD`.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{id}` des Entwurfs.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 422, 'description' => 'Keine gültige Position (`description` + `unit_amount_cents`).'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/invoices/{id:[0-9]+}',
        'tag' => 'Verwaltung',
        'summary' => 'Eine Rechnung samt Positionen lesen',
        'auth' => 'admin',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id der Rechnung.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => 'Die Rechnung mit `items`.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/invoices/{id:[0-9]+}/send',
        'tag' => 'Verwaltung',
        'summary' => 'Entwurf bei Stripe anlegen und versenden',
        'description' => 'Der unumkehrbare Schritt: die Rechnung entsteht bei Stripe und '
            . 'wird finalisiert. Nur aus dem Status `draft` möglich (sonst 409). Name '
            . 'und E-Mail kommen aus dem Kundenverzeichnis, können aber im Body '
            . 'überschrieben werden — für Rechnungen ohne hinterlegten Kunden. '
            . 'Ein Fehler von Stripe wird als **502** durchgereicht, nicht als 500: '
            . 'die Anfrage war in Ordnung, der nachgelagerte Dienst nicht.',
        'auth' => 'admin',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id des Entwurfs.'],
            ['in' => 'body', 'name' => 'name', 'type' => 'string', 'description' => 'Überschreibt den Namen aus dem Kundenverzeichnis.'],
            ['in' => 'body', 'name' => 'email', 'type' => 'string', 'description' => 'Überschreibt die E-Mail aus dem Kundenverzeichnis.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{stripe_invoice_id, hosted_invoice_url, status}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
            ['status' => 409, 'description' => 'Die Rechnung ist kein Entwurf mehr.'],
            ['status' => 422, 'description' => 'Weder Kunde noch Name/E-Mail vorhanden.'],
            ['status' => 502, 'description' => 'Stripe hat die Anlage abgelehnt — die Meldung wird wörtlich durchgereicht.'],
            ['status' => 503, 'description' => 'Kein Stripe Secret Key konfiguriert.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/admin/invoices/{id:[0-9]+}',
        'tag' => 'Verwaltung',
        'summary' => 'Rechnung lokal löschen',
        'description' => 'Löscht nur den lokalen Datensatz — eine bereits bei Stripe '
            . 'angelegte Rechnung bleibt dort bestehen und muss dort storniert werden.',
        'auth' => 'admin',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id der Rechnung.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/billing/invoices',
        'tag' => 'Portal',
        'summary' => 'Rechnungen der aktiven Firma',
        'description' => 'Ohne aktive Firma eine leere Liste, kein Fehler.',
        'permission' => 'billing:read',
        'responses' => [
            ['status' => 200, 'description' => '`{invoices: [...]}` inklusive `hosted_invoice_url` als Bezahl-Link.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `billing:read`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/billing/invoices/{id:[0-9]+}',
        'tag' => 'Portal',
        'summary' => 'Eine eigene Rechnung lesen',
        'description' => 'Ein Admin darf jede Rechnung lesen; sonst muss sie zur aktiven '
            . 'Firma gehören. Beides ergibt sonst 404, nicht 403 — die Existenz einer '
            . 'fremden Rechnung wird nicht preisgegeben.',
        'permission' => 'billing:read',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id der Rechnung.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => 'Die Rechnung mit `items`.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `billing:read`.'],
            ['status' => 404, 'description' => 'Unbekannt oder nicht in der aktiven Firma.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/billing/webhook',
        'tag' => 'Portal',
        'summary' => 'Stripe-Webhook (Zahlungseingang)',
        'description' => 'Unauthentifiziert, aber **signaturgeprüft**: der rohe Body wird '
            . 'gegen den `Stripe-Signature`-Header und das Webhook-Secret verifiziert, '
            . 'bevor irgendetwas gelesen wird. Reagiert auf `invoice.paid` und '
            . '`invoice.payment_succeeded` und setzt die passende Rechnung auf bezahlt. '
            . 'Andere Ereignistypen werden bewusst mit 200 quittiert, damit Stripe sie '
            . 'nicht endlos wiederholt.',
        'auth' => 'token',
        'params' => [
            ['in' => 'header', 'name' => 'Stripe-Signature', 'type' => 'string', 'required' => true, 'description' => 'Von Stripe erzeugte Signatur über den rohen Body.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{received: true}` — auch für nicht behandelte Ereignistypen.'],
            ['status' => 400, 'description' => 'Signatur ungültig.'],
            ['status' => 503, 'description' => 'Kein Webhook-Secret konfiguriert.'],
        ],
    ],
];
