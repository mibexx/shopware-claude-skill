# Shopware 6 Development – Claude Skill

Ein [Agent Skill](https://docs.claude.com/en/docs/claude-code/skills) für **Claude Code** (und andere Claude-Clients mit Skill-Unterstützung), der Claude zu einem sachkundigen Partner für die **Plugin- und Theme-Entwicklung mit Shopware 6.7** macht.

Der Skill bündelt geprüftes Wissen zu 49 Themen, einsatzbereite Code-Gerüste und Review-/Release-Checklisten. Claude lädt ihn automatisch, sobald eine Aufgabe Shopware betrifft, und liest dann gezielt nur die Referenz, die zur Aufgabe passt.

> **Versionsstand:** Shopware **6.7.15.1** (Core, Storefront, Administration, Elasticsearch).
> Alle Aussagen wurden gegen den Quellcode dieser Version geprüft. Weicht die offizielle Doku vom Code ab, gilt der Code – solche Stellen sind im Skill explizit markiert.

---

## Inhalt

- [Was der Skill kann](#was-der-skill-kann)
- [Aufbau des Repositorys](#aufbau-des-repositorys)
- [Installation](#installation)
- [Nutzung](#nutzung)
- [Themenübersicht](#themenübersicht)
- [Templates](#templates)
- [Checklisten](#checklisten)
- [Qualitätsanspruch und bekannte Lücken](#qualitätsanspruch-und-bekannte-lücken)
- [Anpassen und Weiterentwickeln](#anpassen-und-weiterentwickeln)

---

## Was der Skill kann

**Grundprinzipien, die Claude mit dem Skill einhält**

1. **Keine Core-Änderungen** – nie `vendor/shopware/**` anfassen, Erweiterung nur über Plugin, Theme oder App.
2. **Event/Subscriber → Extension Point → Service-Dekoration → Template-Override** als Reihenfolge der Mittel.
3. **BC-Vertrag** – kein `@internal`, kein Erweitern von `@final`, keine neuen Deprecations.
4. **Shopware-6.7-Konventionen** – `declare(strict_types=1)`, Konstruktor-Injection mit `private readonly`, `services.php` statt (deprecated) XML, eigener Namespace, Domain-Exceptions.
5. **Sauberer Lifecycle** – idempotente Install/Update/Uninstall-Hooks, `keepUserData`, Schema nur über Migrationen.
6. **Nichts erfinden** – Unbelegtes wird mit `⚠ LÜCKE` bzw. `⚠ UNGEPRÜFT` gekennzeichnet.

**Typische Aufgaben**

- Plugins und Themes anlegen, installieren, bauen und ausliefern
- Storefront erweitern: Twig-Templates, SCSS, `theme.json`, JS-Plugins, Controller und Pages
- Administration erweitern: Module, Komponenten, Datenhandling, ACL-Rechte
- CMS-Elemente und -Blöcke (Shopping Experiences) bauen
- DAL: Entities, Associations, EntityExtensions, Custom Fields, Migrationen
- Store-API- und Admin-API-Routen erstellen oder dekorieren
- Rule Builder, Flow Builder, Scheduled Tasks, Message Queue, Mail-Templates, Warenkorb, Payment
- Tests (PHPUnit, Jest), PHPStan, php-cs-fixer, Build und Deployment
- Entscheidungen treffen: Plugin vs. App, Event vs. Decorator vs. Extension Point, EntityExtension vs. Custom Field, Theme vs. Plugin

---

## Aufbau des Repositorys

```
shopware-claude-skill/
├── README.md
└── shopware-development/          ← der eigentliche Skill (dieser Ordner wird installiert)
    ├── SKILL.md                   ← Einstieg: Prinzipien, Ablauf, Entscheidungshilfen, Inhaltsverzeichnis
    ├── references/                ← 49 Themenreferenzen (T-01 … T-49)
    ├── templates/                 ← 14 lauffähige Gerüste mit Platzhalter „SwagExample“
    └── checklists/                ← Review- und Release-Checkliste
```

`SKILL.md` ist bewusst kompakt gehalten. Details stehen in `references/`, sodass Claude nur das in den Kontext lädt, was für die aktuelle Aufgabe nötig ist (Progressive Disclosure).

---

## Installation

### Voraussetzungen

- [Claude Code](https://docs.claude.com/en/docs/claude-code) (CLI, Desktop-App oder IDE-Erweiterung)
- Git
- Für die Arbeit mit dem Skill: ein Shopware-6.7-Projekt (empfohlen: 6.7.15.1), z. B. per `composer create-project shopware/production`

### Variante A – global für alle Projekte (empfohlen)

Der Skill steht danach in jedem Projekt zur Verfügung.

```bash
git clone git@github.com:mibexx/shopware-claude-skill.git
mkdir -p ~/.claude/skills
cp -R shopware-claude-skill/shopware-development ~/.claude/skills/
```

Alternativ per Symlink, damit ein `git pull` den Skill direkt aktualisiert:

```bash
git clone git@github.com:mibexx/shopware-claude-skill.git ~/src/shopware-claude-skill
mkdir -p ~/.claude/skills
ln -s ~/src/shopware-claude-skill/shopware-development ~/.claude/skills/shopware-development
```

### Variante B – nur für ein Projekt (im Team teilbar)

Der Skill liegt im Projekt-Repository und wird mit eingecheckt, sodass alle im Team ihn automatisch nutzen.

```bash
cd /pfad/zum/shopware-projekt
mkdir -p .claude/skills
git clone git@github.com:mibexx/shopware-claude-skill.git /tmp/shopware-claude-skill
cp -R /tmp/shopware-claude-skill/shopware-development .claude/skills/
```

Oder als Git-Submodule:

```bash
git submodule add git@github.com:mibexx/shopware-claude-skill.git .claude/vendor/shopware-claude-skill
ln -s ../vendor/shopware-claude-skill/shopware-development .claude/skills/shopware-development
```

### Variante C – claude.ai / Claude Desktop

1. Den Ordner `shopware-development` als ZIP packen:
   ```bash
   cd shopware-claude-skill && zip -r shopware-development.zip shopware-development
   ```
2. In claude.ai unter **Settings → Capabilities → Skills** die ZIP-Datei hochladen.

### Installation prüfen

Claude Code neu starten (bzw. eine neue Session öffnen) und fragen:

```
Welche Skills stehen dir zur Verfügung?
```

`shopware-development` sollte in der Liste erscheinen.

### Aktualisieren

```bash
cd ~/src/shopware-claude-skill && git pull      # bei Symlink-Installation
# bei Kopie: erneut klonen und den Ordner überschreiben
```

---

## Nutzung

### Automatisch

Der Skill wird automatisch aktiv, sobald eine Aufgabe Shopware betrifft – auch wenn nur Begriffe wie „Shopware“, `bin/console`, `custom/plugins` oder `services.php` fallen. Arbeite einfach im Shopware-Projekt und beschreibe die Aufgabe:

```
Lege ein Plugin SwagCustomerBonus an, das Kunden nach der Bestellung
Bonuspunkte gutschreibt und diese im Kundenkonto der Storefront anzeigt.
```

```
Ich brauche ein CMS-Element „Countdown“ mit Zieldatum und Text,
konfigurierbar in den Shopping Experiences.
```

```
Wie unterscheide ich in einem Subscriber zwischen zwei Sales Channels
und lese dafür eine Plugin-Konfiguration pro Sales Channel?
```

```
Dekoriere die ProductListingRoute, sodass ausverkaufte Produkte
am Ende der Liste stehen.
```

```
Richte PHPUnit, PHPStan und php-cs-fixer für custom/plugins/SwagExample ein
und schreibe einen Integrationstest für die Store-API-Route.
```

### Explizit

Du kannst den Skill auch direkt ansprechen:

```
/shopware-development
```

oder im Prompt: *„Nutze den Skill shopware-development und …“*

### Was Claude dabei macht

Claude folgt dem im Skill hinterlegten Standardablauf:

1. **Einordnen** – Plugin, Theme oder App? Welches Erweiterungsmittel? Passende Referenz laden.
2. **Gerüst anlegen** – `bin/console plugin:create` / `theme:create` oder Template aus `templates/` kopieren.
3. **Schema und Daten** – Migration, Entity-Definition, Lifecycle-Hooks.
4. **Backend-Logik** – Services in `services.php`, Subscriber, Decorator, Routen, Commands, Tasks.
5. **Frontend** – Storefront (Twig, SCSS, JS-Plugin) bzw. Administration (Modul, Komponenten, Snippets, ACL).
6. **Installieren und bauen** – `plugin:refresh`, `plugin:install --activate`, `cache:clear`, Storefront-/Admin-Build.
7. **Prüfen** – Tests, statische Analyse, Review-Checkliste, Release-Checkliste.

### Hinweise zur Umgebung

Die Befehle im Skill sind für ein Docker-Compose-Setup auf Basis von `shopware/production` formuliert (Container `web`, Arbeitsverzeichnis `/var/www/html`), z. B.:

```bash
docker compose exec web php bin/console plugin:refresh
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist
```

Einige Werte stammen aus dem Referenz-Setup, mit dem der Skill erstellt wurde (z. B. Storefront-Port `8100`, MariaDB-Port `3307`) und sind in der Referenz als projektspezifisch gekennzeichnet. Wer ein anderes Setup nutzt (DDEV, Dockware, native Installation), sagt Claude das einfach im Prompt – die Shopware-Inhalte gelten unabhängig davon.

---

## Themenübersicht

| Bereich | Referenzen |
|---|---|
| **Grundlagen** | T-01 Projektstruktur & Dev-Umgebung · T-02 Plugin-Aufbau · T-03 Lifecycle · T-04 Migrationen · T-05 Services registrieren · T-06 Service-Dekoration · T-07 Events & Subscriber · T-08 Extension Points · T-09 Flow Builder · T-10 App-System & Script-Hooks |
| **Theme & Storefront** | T-11 Theme-Grundlagen · T-12 Theme-Konfiguration & SCSS-Variablen · T-13 Styling, Assets & Icons · T-14 Twig-Templates · T-15 JS-Plugins · T-16 Controller, Pages & Pagelets · T-17 Listing, Suche & Elasticsearch · T-18 Snippets & Übersetzungen |
| **Konfiguration, Sales Channel, CMS, Rechte** | T-19 Plugin-Konfiguration · T-20 Sales-Channel-Unterscheidung · T-21 CMS-Elemente · T-22 CMS-Blöcke & Sichtbarkeit · T-23 ACL |
| **Administration** | T-24 Admin-Module, Routen & Menü · T-25 Komponenten & Styles · T-26 Datenhandling, Services & State |
| **Daten & APIs** | T-27 DAL-Entities · T-28 DAL lesen/schreiben, Indexer · T-29 EntityExtensions & Custom Fields · T-30 Store-API-Routen · T-31 Admin-API, Integrationen, Sync-API |
| **Fachliche Erweiterungen** | T-32 Rule Builder · T-33 Scheduled Tasks · T-34 Message Queue · T-35 Mail-Templates · T-36 Warenkorb · T-37 Payment · T-38 Bestellungen, State Machine, Dokumente · T-39 Media & Filesystem · T-40 SEO-URLs & Sitemap · T-41 Caching · T-42 Cookies & Consent |
| **Werkzeuge, Qualität, Betrieb** | T-43 CLI, Logging, System-Checks · T-44 MCP-Server · T-45 Coding-Guidelines · T-46 PHPUnit · T-47 Jest · T-48 Statische Analyse · T-49 Build & Deployment |

Jede Referenz endet mit einem Abschnitt **„Quellen → Lücken und Unsicherheiten“**.

---

## Templates

Alle Gerüste verwenden den Platzhalter `SwagExample` (bzw. `SwagExampleTheme`). Jedes Template hat eine eigene `README.md` mit Ersetzungsregeln und Installationsschritten.

| Template | Inhalt |
|---|---|
| `plugin` | `composer.json`, Plugin-Klasse, `services.php` (+ `services.xml.dist` als Legacy-Beispiel) |
| `theme` | `theme.json`, Theme-Klasse, SCSS- und JS-Einstieg, Snippets, Twig-Override |
| `cms-element` | Admin-Komponente, Config, Preview, Storefront-Template, Data-Resolver, Struct |
| `cms-block` | Admin-Block, Preview, Storefront-Template |
| `admin-module` | Modul mit Liste/Detail, Routen, Snippets, ACL-Privilegien |
| `config` | `config.xml` mit gängigen Feldtypen |
| `migration` | Migration mit Tabellenanlage |
| `entity` | Definition, Entity, Collection, Übersetzungs-Aggregat, EntityExtension |
| `event-subscriber` | Subscriber für Entity-Loaded/-Written und Page-Events, eigenes Event |
| `decorator` | Dekoration eines eigenen Abstract-Service und der `ProductListingRoute` |
| `storefront-js-plugin` | Eigenes JS-Plugin und Override eines Core-Plugins |
| `testing` | `phpunit.xml.dist`, `TestBootstrap.php`, Unit- und Integrationstests |
| `testing-js` | Jest-Setup für Administration und Storefront |
| `quality` | `phpstan.neon.dist`, `.php-cs-fixer.dist.php`, `shopware-extension.yml` |

---

## Checklisten

- **`checklists/review-checklist.md`** – Code-Review: Grundprinzipien, Struktur & Lifecycle, DAL, Services/Events/Routen, Konfiguration & Rechte, Storefront, Administration, Fachlogik, Tests & statische Analyse.
- **`checklists/release-checklist.md`** – vor der Auslieferung: Version & Kompatibilität (inkl. Deprecation-Lauf mit `V6_8_0_0=1`), Qualität, Build & Artefakte, Betrieb & Deployment, Dokumentation.

---

## Qualitätsanspruch und bekannte Lücken

- Inhalte sind gegen den **Quellcode von Shopware 6.7.15.1** geprüft; JSON/XML/JS der Templates wurden syntaktisch validiert.
- Teile (u. a. Tests und statische Analyse) wurden live in einem Docker-Setup ausgeführt; viele Beispiele aber **nicht** in einer laufenden Installation.
- Widersprüche zwischen Doku und Code sind dokumentiert – **der Code gilt**.
- Offene Punkte sind im Text mit `⚠ LÜCKE` / `⚠ UNGEPRÜFT` markiert und in `SKILL.md` unter **„Bekannte Lücken“** zusammengefasst. Diese Stellen vor einem Produktiveinsatz am Quellcode oder in einer Instanz klären.

Bei neueren Shopware-Versionen bleibt der Großteil gültig; Claude ist angewiesen, Signaturen im Zweifel am installierten Code unter `vendor/shopware/` zu prüfen.

---

## Anpassen und Weiterentwickeln

- **Eigene Konventionen** (Namespace-Präfix, Agentur-Standards, CI-Setup) lassen sich in `SKILL.md` unter „Grundprinzipien“ ergänzen.
- **Neue Themen** als `references/NN-thema.md` anlegen und in `SKILL.md` im Inhaltsverzeichnis verlinken.
- Die `description` im Frontmatter von `SKILL.md` steuert, wann Claude den Skill automatisch lädt – neue Schlagwörter dort ergänzen.

Issues und Pull Requests sind willkommen.
