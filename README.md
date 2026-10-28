# Shopware 6 Development – Claude Skill

An [Agent Skill](https://docs.claude.com/en/docs/claude-code/skills) for **Claude Code** (and other Claude clients with skill support) that turns Claude into a knowledgeable partner for **plugin and theme development with Shopware 6.7**.

The skill bundles verified knowledge on 49 topics, ready-to-use code scaffolds, and review/release checklists. Claude loads it automatically as soon as a task involves Shopware, and then reads only the reference that matches the task.

> **Version:** Shopware **6.7.15.1** (Core, Storefront, Administration, Elasticsearch).
> All statements were verified against the source code of this version. Where the official docs deviate from the code, the code wins – such places are explicitly marked in the skill.

---

## Contents

- [What the skill does](#what-the-skill-does)
- [Repository structure](#repository-structure)
- [Installation](#installation)
- [Usage](#usage)
- [Topic overview](#topic-overview)
- [Templates](#templates)
- [Checklists](#checklists)
- [Quality standard and known gaps](#quality-standard-and-known-gaps)
- [Customizing and extending](#customizing-and-extending)

---

## What the skill does

**Core principles Claude follows with this skill**

1. **No core changes** – never touch `vendor/shopware/**`; extend only via plugin, theme, or app.
2. **Event/subscriber → extension point → service decoration → template override** as the order of preference.
3. **BC contract** – no `@internal`, no extending `@final`, no new deprecations.
4. **Shopware 6.7 conventions** – `declare(strict_types=1)`, constructor injection with `private readonly`, `services.php` instead of (deprecated) XML, own namespace, domain exceptions.
5. **Clean lifecycle** – idempotent install/update/uninstall hooks, `keepUserData`, schema only via migrations.
6. **Invent nothing** – unverified content is marked with `⚠ GAP` or `⚠ UNVERIFIED`.

**Typical tasks**

- Create, install, build, and ship plugins and themes
- Extend the Storefront: Twig templates, SCSS, `theme.json`, JS plugins, controllers and pages
- Extend the Administration: modules, components, data handling, ACL permissions
- Build CMS elements and blocks (Shopping Experiences)
- DAL: entities, associations, EntityExtensions, custom fields, migrations
- Create or decorate Store API and Admin API routes
- Rule Builder, Flow Builder, scheduled tasks, message queue, mail templates, cart, payment
- Tests (PHPUnit, Jest), PHPStan, php-cs-fixer, build and deployment
- Make decisions: plugin vs. app, event vs. decorator vs. extension point, EntityExtension vs. custom field, theme vs. plugin

---

## Repository structure

```
shopware-claude-skill/
├── README.md
└── shopware-development/          ← the actual skill (this folder gets installed)
    ├── SKILL.md                   ← entry point: principles, workflow, decision guide, table of contents
    ├── references/                ← 49 topic references (T-01 … T-49)
    ├── templates/                 ← 14 working scaffolds with the placeholder "SwagExample"
    └── checklists/                ← review and release checklist
```

`SKILL.md` is intentionally kept compact. Details live in `references/`, so Claude only loads into context what the current task needs (progressive disclosure).

---

## Installation

### Prerequisites

- [Claude Code](https://docs.claude.com/en/docs/claude-code) (CLI, desktop app, or IDE extension)
- Git
- To work with the skill: a Shopware 6.7 project (recommended: 6.7.15.1), e.g. via `composer create-project shopware/production`

### Option A – globally for all projects (recommended)

The skill is then available in every project.

```bash
git clone git@github.com:mibexx/shopware-claude-skill.git
mkdir -p ~/.claude/skills
cp -R shopware-claude-skill/shopware-development ~/.claude/skills/
```

Alternatively via symlink, so that a `git pull` updates the skill directly:

```bash
git clone git@github.com:mibexx/shopware-claude-skill.git ~/src/shopware-claude-skill
mkdir -p ~/.claude/skills
ln -s ~/src/shopware-claude-skill/shopware-development ~/.claude/skills/shopware-development
```

### Option B – for a single project only (shareable with the team)

The skill lives in the project repository and is committed along with it, so everyone on the team uses it automatically.

```bash
cd /path/to/shopware-project
mkdir -p .claude/skills
git clone git@github.com:mibexx/shopware-claude-skill.git /tmp/shopware-claude-skill
cp -R /tmp/shopware-claude-skill/shopware-development .claude/skills/
```

Or as a Git submodule:

```bash
git submodule add git@github.com:mibexx/shopware-claude-skill.git .claude/vendor/shopware-claude-skill
ln -s ../vendor/shopware-claude-skill/shopware-development .claude/skills/shopware-development
```

### Option C – claude.ai / Claude Desktop

1. Pack the `shopware-development` folder as a ZIP:
   ```bash
   cd shopware-claude-skill && zip -r shopware-development.zip shopware-development
   ```
2. Upload the ZIP file in claude.ai under **Settings → Capabilities → Skills**.

### Verifying the installation

Restart Claude Code (or open a new session) and ask:

```
Which skills are available to you?
```

`shopware-development` should appear in the list.

### Updating

```bash
cd ~/src/shopware-claude-skill && git pull      # for the symlink installation
# for a copy: clone again and overwrite the folder
```

---

## Usage

### Automatic

The skill activates automatically as soon as a task involves Shopware – even if only terms like "Shopware", `bin/console`, `custom/plugins`, or `services.php` come up. Just work in your Shopware project and describe the task:

```
Create a plugin SwagCustomerBonus that credits customers with bonus points
after an order and shows them in the customer account in the Storefront.
```

```
I need a CMS element "Countdown" with a target date and text,
configurable in the Shopping Experiences.
```

```
How do I distinguish between two sales channels in a subscriber
and read a plugin configuration per sales channel for it?
```

```
Decorate the ProductListingRoute so that sold-out products
appear at the end of the list.
```

```
Set up PHPUnit, PHPStan, and php-cs-fixer for custom/plugins/SwagExample
and write an integration test for the Store API route.
```

### Explicit

You can also invoke the skill directly:

```
/shopware-development
```

or in the prompt: *"Use the shopware-development skill and …"*

### What Claude does

Claude follows the standard workflow defined in the skill:

1. **Classify** – plugin, theme, or app? Which extension mechanism? Load the matching reference.
2. **Create the scaffold** – `bin/console plugin:create` / `theme:create` or copy a template from `templates/`.
3. **Schema and data** – migration, entity definition, lifecycle hooks.
4. **Backend logic** – services in `services.php`, subscribers, decorators, routes, commands, tasks.
5. **Frontend** – Storefront (Twig, SCSS, JS plugin) or Administration (module, components, snippets, ACL).
6. **Install and build** – `plugin:refresh`, `plugin:install --activate`, `cache:clear`, Storefront/Admin build.
7. **Verify** – tests, static analysis, review checklist, release checklist.

### Notes on the environment

The commands in the skill are written for a Docker Compose setup based on `shopware/production` (container `web`, working directory `/var/www/html`), e.g.:

```bash
docker compose exec web php bin/console plugin:refresh
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist
```

Some values come from the reference setup the skill was created with (e.g. Storefront port `8100`, MariaDB port `3307`) and are marked as project-specific in the reference. If you use a different setup (DDEV, Dockware, native installation), just tell Claude in the prompt – the Shopware content applies regardless.

---

## Topic overview

| Area | References |
|---|---|
| **Fundamentals** | T-01 Project structure & dev environment · T-02 Plugin structure · T-03 Lifecycle · T-04 Migrations · T-05 Registering services · T-06 Service decoration · T-07 Events & subscribers · T-08 Extension points · T-09 Flow Builder · T-10 App system & script hooks |
| **Theme & Storefront** | T-11 Theme basics · T-12 Theme configuration & SCSS variables · T-13 Styling, assets & icons · T-14 Twig templates · T-15 JS plugins · T-16 Controllers, pages & pagelets · T-17 Listing, search & Elasticsearch · T-18 Snippets & translations |
| **Configuration, sales channel, CMS, permissions** | T-19 Plugin configuration · T-20 Sales channel distinction · T-21 CMS elements · T-22 CMS blocks & visibility · T-23 ACL |
| **Administration** | T-24 Admin modules, routes & menu · T-25 Components & styles · T-26 Data handling, services & state |
| **Data & APIs** | T-27 DAL entities · T-28 DAL reading/writing, indexers · T-29 EntityExtensions & custom fields · T-30 Store API routes · T-31 Admin API, integrations, Sync API |
| **Business extensions** | T-32 Rule Builder · T-33 Scheduled tasks · T-34 Message queue · T-35 Mail templates · T-36 Cart · T-37 Payment · T-38 Orders, state machine, documents · T-39 Media & filesystem · T-40 SEO URLs & sitemap · T-41 Caching · T-42 Cookies & consent |
| **Tools, quality, operations** | T-43 CLI, logging, system checks · T-44 MCP server · T-45 Coding guidelines · T-46 PHPUnit · T-47 Jest · T-48 Static analysis · T-49 Build & deployment |

Each reference ends with a section **"Sources → Gaps and uncertainties"**.

---

## Templates

All scaffolds use the placeholder `SwagExample` (or `SwagExampleTheme`). Each template has its own `README.md` with replacement rules and installation steps.

| Template | Contents |
|---|---|
| `plugin` | `composer.json`, plugin class, `services.php` (+ `services.xml.dist` as a legacy example) |
| `theme` | `theme.json`, theme class, SCSS and JS entry points, snippets, Twig override |
| `cms-element` | Admin component, config, preview, Storefront template, data resolver, struct |
| `cms-block` | Admin block, preview, Storefront template |
| `admin-module` | Module with list/detail, routes, snippets, ACL privileges |
| `config` | `config.xml` with common field types |
| `migration` | Migration creating a table |
| `entity` | Definition, entity, collection, translation aggregate, EntityExtension |
| `event-subscriber` | Subscribers for entity loaded/written and page events, custom event |
| `decorator` | Decoration of a custom abstract service and of the `ProductListingRoute` |
| `storefront-js-plugin` | Custom JS plugin and override of a core plugin |
| `testing` | `phpunit.xml.dist`, `TestBootstrap.php`, unit and integration tests |
| `testing-js` | Jest setup for Administration and Storefront |
| `quality` | `phpstan.neon.dist`, `.php-cs-fixer.dist.php`, `shopware-extension.yml` |

---

## Checklists

- **`checklists/review-checklist.md`** – code review: core principles, structure & lifecycle, DAL, services/events/routes, configuration & permissions, Storefront, Administration, business logic, tests & static analysis.
- **`checklists/release-checklist.md`** – before shipping: version & compatibility (incl. deprecation run with `V6_8_0_0=1`), quality, build & artifacts, operations & deployment, documentation.

---

## Quality standard and known gaps

- Content is verified against the **source code of Shopware 6.7.15.1**; the JSON/XML/JS of the templates was syntax-validated.
- Parts (among others tests and static analysis) were executed live in a Docker setup; many examples, however, were **not** run in a live installation.
- Contradictions between docs and code are documented – **the code wins**.
- Open points are marked in the text with `⚠ GAP` / `⚠ UNVERIFIED` and summarized in `SKILL.md` under **"Known gaps"**. Clarify these places against the source code or in an instance before production use.

For newer Shopware versions most of the content remains valid; Claude is instructed to check signatures against the installed code under `vendor/shopware/` when in doubt.

---

## Customizing and extending

- **Your own conventions** (namespace prefix, agency standards, CI setup) can be added to `SKILL.md` under "Core principles".
- **New topics**: create them as `references/NN-topic.md` and link them in the table of contents in `SKILL.md`.
- The `description` in the frontmatter of `SKILL.md` controls when Claude loads the skill automatically – add new keywords there.

Issues and pull requests are welcome.
