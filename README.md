# InventoryCheck

[![Nextcloud](https://img.shields.io/badge/Nextcloud-32–35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2–8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](LICENSE)

**[English](#english)** · **[Deutsch](#deutsch)**

Stock. Locations. Clear bookings — on your Nextcloud.

---

## English

**Stock. Locations. Clear bookings.**

InventoryCheck keeps parts and supplies on the Nextcloud you already host: warehouses, vans and site boxes, quantities, and an append-only booking history. See low stock early, print labels, run stocktakes — then optional InventoryCheck Mobile uses the same ledger for shelf and van scanning.

**Free web app** (AGPL-3.0-or-later). Companion apps: https://nextcloud.software-by-design.de/

### Why teams install it

- Know what is where — locations and items with SKU, scan code, unit of measure and reorder level
- Book without rewriting history — receive, issue, transfer, adjust (compensating bookings for corrections)
- Act on low stock — dashboard, movement history and printable labels
- Count the shelf — stocktake from create → count → close
- Stay scoped — office / field roles and optional access restriction
- Optional mobile — seats and paired scan devices

### Clear limits

- Movements are append-only — corrections use compensating bookings.
- Declared databases: MySQL and PostgreSQL.

### Requirements

- Nextcloud 32–35 · PHP 8.2–8.5 · MySQL or PostgreSQL

### Personal workspaces in this fork

Open **Workspaces** in the sidebar. Each account gets its own **Demo workspace** on first use, containing the original three demo items and locations. Create as many additional workspaces as you need; new workspaces start empty. Deleting the demo is permanent and does not cause it to be recreated.

Only owners and accepted invitees can open a workspace. Owners invite existing Nextcloud accounts as **editors** or **viewers**. Recipients receive a Nextcloud notification with **Accept invitation** and **Decline invitation** buttons, also included in Nextcloud's notification emails when enabled. Email delivery requires a configured Nextcloud mail server, an account email address, background jobs and enabled notification emails in personal settings. Each button applies its response directly and dismisses the notification. Opening the notification itself dismisses it and shows Workspaces, leaving the invitation pending. Editors can manage inventory and stocktakes. Viewers can browse inventory. Owners can rename or permanently delete a workspace, cancel invitations and remove members. Members can leave. Deletion removes the workspace's ledger, photos, scanner slots and memberships.

Workspace creation, renaming, deletion, invitations and membership changes appear in Nextcloud Activity for the people affected. Pending invitees only see their own invitation events and workspace deletion. Answered or revoked invitations disappear from notifications; old links cannot act on a later invitation.

Nextcloud and app administrators manage instance settings; those privileges do not grant access to other people's workspaces. The old global Office allow-lists no longer grant inventory permissions. Existing instance-wide inventory rows remain unassigned and hidden; this fork does not import them into user workspaces.

The workspace ID travels with each page and API request, so different tabs can use different workspaces. Licensing, app access restrictions and stock/quantity policies remain instance-wide. Owners cannot override an administrator's restriction on entry to the app.

For the implementation rules and API, see [WORKSPACES.md](WORKSPACES.md). For a self-contained local environment with no host PHP installation, see [dev/README.md](dev/README.md).

### Install from Git

```bash
cd /path/to/nextcloud/apps/
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-inventorycheck.git inventorycheck
cd inventorycheck
composer install --no-dev
```

Enable the app in Nextcloud (Apps → InventoryCheck) or run `php occ app:enable inventorycheck`.

The schema repair does not seed shared inventory. Each account receives its private demo on first use.

### App Store release (maintainers)

```bash
make release          # unsigned tarball under build/release/
make release-signed   # + occ integrity:sign-app (needs certs)
make verify-release   # refuses tests/, docs/, vendor/, secrets paths
```

Before cutting a store version: fold `CHANGELOG.md` `## Unreleased` into a dated version section, bump `appinfo/info.xml` / `appinfo/version`, and push `screenshots/` so App Store screenshot URLs resolve.

### Security

Do not open public issues that contain production secrets, personal data, or internal hostnames. Report sensitive findings privately to the maintainer (see `appinfo/info.xml` author). See [SECURITY.md](SECURITY.md).

### Project & support

**Software by Design GbR** · [nextcloud.software-by-design.de](https://nextcloud.software-by-design.de/) · [info@software-by-design.de](mailto:info@software-by-design.de)  
[Support packages](https://nextcloud.software-by-design.de/en/support.html#packages)

### License

[AGPL-3.0-or-later](LICENSE).

---

## Deutsch

**Bestände. Lagerorte. Klare Buchungen.**

InventoryCheck verwaltet Teile und Verbrauchsmaterial in der Nextcloud, die Sie schon betreiben: Lager, Transporter und Baustellenboxen, Mengen und eine append-only Buchungshistorie. Mindestbestand früh sehen, Etiketten drucken, Inventuren fahren — optional nutzt InventoryCheck Mobile dasselbe Journal für Scan im Regal oder Transporter.

**Kostenlose Web-App** (AGPL-3.0-or-later). Companion-Apps: https://nextcloud.software-by-design.de/

### Warum Teams es einsetzen

- Wissen, was wo liegt — Lagerorte und Artikel mit SKU, Scan-Code, Einheit und Mindestbestand
- Buchen ohne Historie zu überschreiben — Zugang, Abgang, Umbuchung, Korrektur (Gegenbuchungen)
- Auf Mindestbestand reagieren — Dashboard, Buchungshistorie und druckbare Etiketten
- Regal zählen — Inventur von Anlegen → Zählen → Abschließen
- Zugriff steuern — Büro-/Außendienst-Rollen und optionale Zugriffsbeschränkung
- Optional Mobile — Sitze und gekoppelte Scan-Geräte

### Klare Grenzen

- Buchungen sind append-only — Korrekturen laufen über Gegenbuchungen.
- Deklarierte Datenbanken: MySQL und PostgreSQL.

### Voraussetzungen

- Nextcloud 32–35 · PHP 8.2–8.5 · MySQL oder PostgreSQL

### Installation von Git

```bash
cd /path/to/nextcloud/apps/
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-inventorycheck.git inventorycheck
cd inventorycheck
composer install --no-dev
```

App in Nextcloud aktivieren (Apps → InventoryCheck) oder `php occ app:enable inventorycheck`.

### Sicherheit

Keine öffentlichen Issues mit Produktionsgeheimnissen, personenbezogenen Daten oder internen Hostnamen. Sensible Funde privat an den Maintainer (siehe `appinfo/info.xml`). Siehe [SECURITY.md](SECURITY.md).

### Projekt & Support

**Software by Design GbR** · [nextcloud.software-by-design.de](https://nextcloud.software-by-design.de/de/) · [info@software-by-design.de](mailto:info@software-by-design.de)  
[Support-Pakete](https://nextcloud.software-by-design.de/de/support.html#packages)

### Lizenz

[AGPL-3.0-or-later](LICENSE).
