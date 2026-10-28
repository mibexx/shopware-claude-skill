# Admin-Komponenten überschreiben und erweitern, Styles (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eine bestehende Administration-Komponente verändert (`override`), davon abgeleitet (`extend`) oder eine neue Komponente samt Template, Mixin, Direktive und SCSS angelegt werden soll. Sie beschreibt die Options-API-Variante mit Twig-Blöcken, die in 6.7.15.1 der stabile Weg für Plugins ist.

Abgrenzung: Modul, Routen, Menü → [T-24 Admin-Module](./24-admin-module.md). Repositories, Services, State, Notifications → [T-26 Admin-Datenhandling](./26-admin-datenhandling.md). Snippets → [T-18 Snippets](./18-snippets-uebersetzungen.md). Build-Skripte → [T-49 Build und Deployment](./49-build-deployment.md). Gerüst: [../templates/admin-module/README.md](../templates/admin-module/README.md).

## 2. Konzepte

- Die Administration ist eine Vue-3-App (Vue 3.5.22, Vite). Es gibt in 6.7.15.1 keinen `@vue/compat`-Alias (`vite.config.mts` aliasiert `vue` auf `vue/dist/vue.esm-bundler.js`). Plugins schreiben reines Vue 3 Options API.
- Komponenten-Factory `Shopware.Component` (`core/factory/async-component.factory.ts`):
  - `register(name, config)`: neue Komponente. Doppelter Name führt zu Warnung und `false`. Ohne `template` (bzw. `render`/`functional`) wird sie mit Warnung abgelehnt.
  - `extend(name, extendName, config)`: erzeugt eine NEUE Komponente, die erbt. Das Original bleibt unverändert. `template` ist optional (ohne Template wird das Parent-Template übernommen).
  - `override(name, config, index = null)`: verändert die BESTEHENDE Komponente. Mehrere Overrides sind möglich, sortiert nach `index` (Default 0).
  - Alle drei akzeptieren auch eine Funktion mit Promise, z. B. `() => import('./swag-foo')` (ES-Modul-`default` wird ausgepackt).
- Templates sind `*.html.twig`. TwigJS wertet nur `{% block %}` und `{% parent %}` aus, `{{ }}` bleibt für Vue erhalten. Blockinhalt ist normales Vue-Template. Twig-Blöcke gibt es hier nur als Admin-Mechanismus, für Storefront-Twig siehe [T-14](./14-storefront-twig-templates.md).
- `this.$super('name', ...args)` ruft die Implementierung der Vorlage auf (`methods` und `computed`, auch Getter/Setter). Erkennung per Regex `/\.\$super/` auf `fn.toString()`.
- Meteor-Komponenten `mt-*` (`@shopware-ag/meteor-component-library` 5.7.1) sind global registriert (`app/adapter/view/vue.adapter.ts`, ca. 45 global, lazy: `mt-data-table`, `mt-colorpicker`, `mt-popover`, `mt-popover-item`). Wrapper mit Twig-Blöcken: `mt-card`, `mt-tabs`, `mt-datepicker`, `mt-text-editor` (`app/component/meteor-wrapper/*`). Reine `mt-*` haben keine Twig-Blöcke. Für neue UI `mt-*` statt `sw-*` bevorzugen.
- Mixins: `Shopware.Mixin.register/getByName`. Direktiven: `Shopware.Directive.register`. Core-Mixins in `app/mixin`, Core-Direktiven in `app/directive`.
- Entscheidungshilfe: Überall verändern (z. B. Block in `sw-product-detail`) → `override`. Zweite, eigene Variante bei unverändertem Original → `extend`. Komplett neu → `register`.

Verzeichnisbaum (Plugin-relativ, Namen unterhalb `src/` sind Konvention, nur `main.js`/`main.ts` ist Pflicht):

```
src/Resources/app/administration/src/
├── main.js
├── mixin/swag-hello.mixin.js
├── directive/swag-highlight.directive.js
├── snippet/{de-DE,en-GB}.json
└── extension/
    ├── sw-product-detail/{index.js, sw-product-detail.html.twig}   # Override
    └── swag-custom-text-field/{index.js, swag-custom-text-field.scss} # Extend
```

`BundleConfigGenerator::getEntryFile()` sucht `Resources/app/administration/src/main.{js,ts}`. Build-Output: `src/Resources/public/administration/`.

## 3. Anleitung

1. Zielkomponente und Block finden: `grep -rn "{% block" <komponente>.html.twig`. Globale Komponenten stehen in `app/component/index.ts`, Modul-Komponenten in `module/<modul>/index.js` (im Core überwiegend `.js`, teils `.ts`; Seiten und Komponenten ebenfalls meist `index.js`).
2. Entscheiden: override, extend oder register (siehe Abschnitt 2).
3. Template anlegen: ausschließlich `{% block name %}...{% endblock %}`. `{% parent %}` im Block fügt den Original-Inhalt ein. Import per `import template from './x.html.twig'` (Vite-Plugin `twigjs-plugin`).
4. Komponente in `index.js` registrieren und in `main.js` importieren. Reihenfolge: Mixins und Direktiven zuerst (`Mixin.getByName` wirft, wenn noch nicht registriert).
5. Mehrere Overrides derselben Komponente ordnet der dritte Parameter: `override(name, config, 10)`.
6. SCSS: Datei im `index.js` importieren, Variablen per `@import "~scss/variables";` (Alias auf `app/assets/scss/variables.scss`; weitere: `mixins.scss`, `typography.scss`, `global.scss`, `all.scss`).
7. Meteor-Komponenten direkt im Template nutzen (`<mt-button variant="primary">`), keine Registrierung nötig. `mt-card` verlangt die Prop `positionIdentifier`.
8. Bauen (im web-Container):

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh
bin/console assets:install
bin/console cache:clear
bin/watch-administration.sh          # Entwicklung mit Hot Reload, siehe T-49
```

`cache:clear` allein baut das JS nicht neu. Danach Hard-Reload im Browser.

## 4. Beispiele

`src/Resources/app/administration/src/main.js`

```js
import './mixin/swag-hello.mixin';
import './directive/swag-highlight.directive';
import './extension/sw-product-detail';
import './extension/swag-custom-text-field';
```

`src/Resources/app/administration/src/mixin/swag-hello.mixin.js`

```js
Shopware.Mixin.register('swag-hello', {
    methods: {
        swagSayHello(name) {
            return `Hello ${name}`;
        },
    },
});
```

`src/Resources/app/administration/src/directive/swag-highlight.directive.js`

```js
Shopware.Directive.register('swag-highlight', {
    mounted(el, binding) {
        el.style.backgroundColor = binding.value ?? 'yellow';
    },
    updated(el, binding) {
        el.style.backgroundColor = binding.value ?? 'yellow';
    },
});
```

`src/Resources/app/administration/src/extension/sw-product-detail/index.js` (Override mit `$super`)

```js
import template from './sw-product-detail.html.twig';

Shopware.Component.override('sw-product-detail', {
    template,

    mixins: [
        Shopware.Mixin.getByName('swag-hello'),
    ],

    computed: {
        swagGreeting() {
            return this.swagSayHello(this.product?.name ?? '');
        },
    },

    methods: {
        onSave() {
            // eigene Vorprüfung, danach Original aufrufen
            return this.$super('onSave');
        },
    },
});
```

`src/Resources/app/administration/src/extension/sw-product-detail/sw-product-detail.html.twig`

```twig
{% block sw_product_detail_content_language_info %}
    {% parent %}
    <p v-swag-highlight="'#e8f4ff'" class="swag-product-detail__greeting">
        {{ swagGreeting }}
    </p>
{% endblock %}
```

Am Quellcode geprüft: Block `sw_product_detail_content_language_info` und Methode `onSave()` existieren in `module/sw-product/page/sw-product-detail/` (6.7.15.1). `product` als Data/Computed der Seite nicht einzeln geprüft (`?.` schützt davor).

`src/Resources/app/administration/src/extension/swag-custom-text-field/index.js` (Extend, Methode überschreiben, SCSS)

```js
import './swag-custom-text-field.scss';

// Ohne eigenes `template` wird das Template von sw-text-field übernommen.
Shopware.Component.extend('swag-custom-text-field', 'sw-text-field', {
    methods: {
        handleUpdateModelValue(event) {
            // vorhandene Methode von sw-text-field (index.ts), Wert trimmen
            return this.$super('handleUpdateModelValue', typeof event === 'string' ? event.trim() : event);
        },
    },
});
```

`.../swag-custom-text-field/swag-custom-text-field.scss`

```scss
@import "~scss/variables";

.swag-custom-text-field {
    border-left: 3px solid $color-shopware-brand-500;
}
```

Verwendung: `<swag-custom-text-field v-model:value="foo" class="swag-custom-text-field" />`. **Warnung `@private`:** `sw-text-field` ist im Core als `@private` und `@deprecated tag:v6.8.0` markiert (`app/component/form/sw-text-field/index.ts`). Das Beispiel dient nur dem Extend-/`$super`-Muster. Für produktiven Code keine `@private`-Komponente erweitern, sondern eine eigene Komponente um das öffentliche `mt-text-field` bauen oder Meteor-Komponenten direkt einsetzen. Weitere Hinweise: `sw-text-field` ist (Wrapper um `mt-text-field`); der Block `sw_text_field` umschließt nur den `v-if`-Zweig. Ein Wrapper-`<div>` darin würde die `v-if`/`v-else`-Kette brechen, daher wird hier der Block nicht verändert.

⚠ UNGEPRÜFT: Beispielcode wurde nicht in einer laufenden 6.7.15.1-Admin gebaut. Der Variablenname `$color-shopware-brand-500` wurde nicht gegen `variables.scss` geprüft.

## 5. Fallstricke

1. Falscher Blockname: TwigJS ignoriert unbekannte Blöcke ohne Fehler, der Inhalt erscheint einfach nicht. Blocknamen ändern sich zwischen Versionen, immer im Quell-Template nachsehen.
2. `{% parent %}` vergessen: Der Override ersetzt den Block komplett, andere Plugins verlieren ihre Inhalte.
3. `$super` wird nicht erkannt bei Alias (`const s = this.$super`), ausgelagertem Aufruf oder Arrow-Funktion als Methode. `this.$super('$super')` wirft einen Error. Immer `this.$super('name')` in normaler Methodensyntax.
4. `register` für einen Core-Namen: wird verworfen. Für Core-Komponenten `override` oder `extend`.
5. Doppelter Name bei `register`/`extend`: Warnung, Konflikt. Vendor-Präfix (`swag-`) nutzen.
6. Template mit Markup außerhalb von `{% block %}`: wird nicht gemergt.
7. `{{ }}`/`{% if %}` aus Twig sind in Vue-Templates nicht nutzbar, nur `v-if`/`v-for`.
8. `Mixin.getByName('x')` wirft, wenn `main.js` die Mixin-Datei nach der Komponente importiert.
9. Async-Override (Funktions-Config): Der Blockindex wird erst beim Auflösen befüllt. Für Template-Overrides ein Objekt-Config verwenden, dann wird synchron indexiert.
10. Hartkodierte Farben/Abstände statt Variablen aus `~scss/variables` bzw. Meteor-Tokens.
11. Direkte Manipulation per `Object.assign` statt `override`: kein `$super`, bricht bei Updates.
12. Neue UI mit `sw-*` statt `mt-*` bauen.
13. Single-File-Components und `swDefineOverride()` in 6.7.15.1 nicht verwenden (siehe unten).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

| Thema | 6.5/6.6 | 6.7.15.1 |
|---|---|---|
| Vue | 6.5: Vue 3 mit `@vue/compat` (Vorwissen, ⚠ UNGEPRÜFT) | Vue 3.5.22, kein Compat-Alias |
| Bundler | Webpack | Vite (`vite.config.mts`, `build/plugins.vite.ts`) |
| `register/extend/override`, `$super` | ja | ja, stabil |
| Twig-Blöcke | ja | ja, plus Adapter zu `<sw-block>` (`core/factory/twig-block-index.ts`) |
| `<sw-block>`, `overrideComponentSetup` | nein | vorhanden, `@experimental` (Feature `ADMIN_COMPOSITION_API_EXTENSION_SYSTEM`, stable 6.8.0 geplant). Für Plugins nicht nutzen |
| Composables statt Mixins (`use-*.ts`) | nein | `@experimental`, `ADMIN_MIXIN_COMPOSABLES`, stable 6.9.0 geplant. Mixins bleiben |
| SFC `*.vue`, `*.override.vue`, `swDefineOverride()` | nein | Doku: experimentell seit 6.7.16.0, in 6.7.15.1 nur Build-Teile (`build/vue-setup-transform/*`). Nicht empfohlen |
| `sw-*` Basiskomponenten | Standard | Meteor `mt-*` löst sie schrittweise ab, z. B. `sw-text-field` `@deprecated tag:v6.8.0` |

Bei Übernahme von `sw-block` beim Zugriff auf einen migrierten Block erscheint eine `console.warn`-Deprecation. Bestehende Twig-Overrides greifen weiterhin.

## 6. Checkliste

- [ ] Zielkomponente und Blockname im Quellcode der installierten Version verifiziert
- [ ] override/extend/register passend gewählt, Vendor-Präfix bei neuen Namen
- [ ] Template enthält nur `{% block %}`, `{% parent %}` wo Original erhalten bleiben soll
- [ ] `this.$super('name')` in normaler Methodensyntax
- [ ] Mixins/Direktiven in `main.js` vor den Komponenten importiert
- [ ] Neue UI mit `mt-*`, SCSS mit `~scss/variables`
- [ ] Kein SFC/`swDefineOverride`/`sw-block`/Composables im Produktivcode
- [ ] `bin/build-administration.sh` ausgeführt, Hard-Reload getestet

## 7. Quellen

Doku (https://developer.shopware.com): `/docs/guides/plugins/plugins/administration/module-component-management/customizing-components.html`, `.../using-base-components.html`, `.../templates-styling/writing-templates.html`, `.../templates-styling/add-custom-styles.html`, `.../mixins-directives/add-mixins.html`, `.../single-file-components/tutorial/your-first-override.html`. Mitgeliefert: `administration/Resources/app/administration/technical-docs/03-extensibility/*`, `06-ui/01-meteor-component-library.md`.

Quellcode (unter `administration/Resources/app/administration/`): `src/core/factory/async-component.factory.ts` (`register`, `extend`, `override`, `buildSuperRegistry`, `addSuperBehaviour`), `src/core/factory/template.factory.js`, `mixin.factory.ts`, `directive.factory.ts`, `src/app/component/index.ts`, `src/app/component/meteor-wrapper/*`, `src/app/adapter/view/vue.adapter.ts`, `build/plugins.vite.ts`, `vite.config.mts`, `package.json`. PHP: `core/Framework/Plugin/BundleConfigGenerator.php`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispielcode nicht in laufender Admin gebaut (Blöcke/Methoden `sw_product_detail_content_language_info`, `onSave`, `sw_text_field`, `handleUpdateModelValue` am Quellcode vorhanden, Laufzeitverhalten nicht getestet).
- ⚠ LÜCKE: Reihenfolge mehrerer Overrides bei gleichem `index` (Annahme: Plugin-Ladereihenfolge, `Array.sort` ist stabil).
- ⚠ LÜCKE: Wirkung von `compatConfig: { MODE: 2 }` im Plugin-Vite-Build (`build/plugins.vite.ts`) und von `Shopware.compatConfig` (nur Referenzen, keine Definition gefunden) nicht geklärt.
- ⚠ LÜCKE: Feature-Flag `ADMIN_COMPOSITION_API_EXTENSION_SYSTEM` Definition in `core/**` nicht gefunden.
- ⚠ LÜCKE: Doku uneinheitlich (technical-docs "6.8+", Web "stable 6.8.0/SFC 6.9", Code enthält Teile bereits in 6.7.15.1). Hier gilt der Code.
- ⚠ UNGEPRÜFT: Seite `add-custom-component.html` nicht abgerufen, Inhalt nur über Code bestätigt.
