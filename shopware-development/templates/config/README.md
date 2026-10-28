# Template: config (Plugin-Konfiguration)

Shopware 6.7.15.1. `config.xml` mit allen laut XSD erlaubten Feldtypen und den gängigen Komponenten. Das Admin-UI (Erweiterungen > Meine Erweiterungen > Konfigurieren) wird daraus generiert.

## Dateibaum

```
SwagExample/
└── src/
    └── Resources/
        └── config/
            └── config.xml
```

Wurzel-Gerüst (composer.json, Plugin-Klasse, services.php): ../plugin/README.md

## Platzhalter

| Platzhalter | Bedeutung | Ersetzen durch |
|---|---|---|
| `SwagExample` | Plugin-Name = Bundle-Name; bestimmt den Schlüssel `SwagExample.config.<feld>` | eigener Plugin-Name |
| `<name>` je Feld | Regex `[a-zA-Z][a-zA-Z0-9]*`, eindeutig über alle `input-field` der Datei | eigene Feldnamen |
| Demo-Felder (`enabled`, `badgeText`, ...) | nur Beispiele | löschen/ersetzen |

## Aufbau (aus `config.xsd`)

`<config>` > `<card>`+ > `<title lang>`, optional `<subtitle>`, `<name>`, `<ai-badge>`, `<flag>`, dann `<input-field type="...">` oder `<component name="...">`.

Erlaubte `type`-Werte (6.7.15.1): `text` (Default), `textarea`, `text-editor`, `url`, `password`, `int`, `float`, `bool`, `checkbox`, `datetime`, `date`, `time`, `colorpicker`, `single-select`, `multi-select`, `price`. Alle sind in der Vorlage enthalten. `sw-entity-single-select` ist KEIN `type`, sondern `<component name="sw-entity-single-select">` mit `<entity>`.

Feldoptionen: übersetzbar (`lang`, Fallback `en-GB`): `label`, `placeholder`, `helpText`; boolesch: `copyable`, `disabled`, `required`; `options` (nur Select); `defaultValue` (typisiert: bool/checkbox, int, float, multi-select als Array, sonst String). Weitere Kindelemente (`min`, `max`, `minLength`, `maxLength`, `entity`, ...) werden unverändert als Option durchgereicht. Attribut `cache-relevant="true"` an `input-field`/`component` (laut Doku ab 6.7.12.0).

Core-Komponenten-Beispiele: `sw-entity-single-select`, `sw-entity-multi-id-select`, `sw-cms-page-select`, `sw-system-config-media-upload` (siehe `core/System/Resources/config/*.xml`). Eigene Komponenten müssen im Admin registriert sein.

## Einbindung / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate -c SwagExample   # schreibt Defaults (override = true)
bin/console plugin:update SwagExample                  # schreibt nur FEHLENDE Default-Keys
bin/console system:config:get SwagExample.config.badgeText [-s <salesChannelId>]
bin/console system:config:set SwagExample.config.maxItems 10 --json [-s <salesChannelId>]
```

Neue Felder mit Default erscheinen bei bestehenden Installationen erst nach `plugin:update` (bestehende Werte bleiben). Ohne Aktivierung/mit ungültiger XML ist die Konfiguration im Admin nicht sichtbar.

## Lesen im Code

Ausschnitt für `src/Resources/config/services.php` (Service nur als Beispiel, nicht in dieser Vorlage enthalten):

```php
$services->set(\SwagExample\Service\PriceLabelService::class)
    ->args([service(\Shopware\Core\System\SystemConfig\SystemConfigService::class)]);
```

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class PriceLabelService
{
    private const DOMAIN = 'SwagExample.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function label(?string $salesChannelId): string
    {
        if (!$this->systemConfigService->getBool(self::DOMAIN . 'enabled', $salesChannelId)) {
            return '';
        }

        return $this->systemConfigService->getString(self::DOMAIN . 'badgeText', $salesChannelId);
    }
}
```

Twig (Storefront, Sales Channel automatisch aus dem Kontext): `{{ config('SwagExample.config.badgeText') }}`.

Änderungen abfangen: `BeforeSystemConfigChangedEvent` (`getKey()`, `setValue()`) und `SystemConfigChangedEvent` (nur Getter) in einem Subscriber, Registrierung siehe ../event-subscriber/README.md.

## Wichtige Regeln

- Vererbung: Sales-Channel-Wert überschreibt den globalen Wert; leere Werte (`null`, `''`, `0`, `[]`) erben den globalen Wert, Booleans nicht.
- `getString/getInt/...` werfen bei Array-Werten (z. B. `multi-select`): dort `get()` nutzen.
- `$salesChannelId` immer mitgeben, sonst nur globale Werte.
- `all()` und `getDomain()` sind `@internal` (nicht in Storefront/Store-API verwenden).
- Uninstall ohne `keepUserData` löscht alle Konfigurationswerte inklusive Overrides.
- Feste Werte pro Umgebung: `shopware.system_config.default` in `config/packages/*.yaml` (im Admin dann nicht änderbar).

## Verweise

- ../../references/19-plugin-konfiguration.md
- ../../references/18-snippets-uebersetzungen.md
- ../../references/03-plugin-lifecycle.md
- Wurzel-Gerüst: ../plugin/README.md; Events: ../event-subscriber/README.md

## Hinweise und Lücken

- Geprüft: `config.xml` validiert mit `xmllint --schema` gegen `core/System/SystemConfig/Schema/config.xsd` (6.7.15.1) und gegen ein Core-Beispiel (`cart.xml`).
- Deprecation: Twig-`config()` mit statischen Keys wie `seo.descriptionMaxLength`, `cms.tosCmsPageId` ist in 6.7 deprecated (Ersatz laut Referenz z. B. `core.basicInformation.tosPage`).
- ⚠ UNGEPRÜFT: Die Auswertung von `min`/`max`, `copyable`, `price` und `datetime` durch die Admin-Komponenten wurde nicht einzeln geprüft (PHP reicht die Optionen nur durch). Die Doku nennt für `name` "4+ Zeichen"; die XSD verlangt dies nicht.
- ⚠ UNGEPRÜFT: Das Verhalten von `<ai-badge>` und `<flag>` ist nur im XSD belegt und nicht in der Vorlage enthalten.
- ⚠ LÜCKE: Eigene Admin-Komponenten für `<component name="...">` (Registrierung im Admin-Plugin-JS) sind nicht Teil dieser Vorlage; siehe ../admin-module/README.md für Admin-Gerüste.
