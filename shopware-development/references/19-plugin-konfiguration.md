# Plugin-Konfiguration im Backend (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin im Admin konfigurierbar sein soll (`config.xml`), wenn Konfigurationswerte gelesen/gesetzt werden (PHP, Twig, CLI, API) oder wenn Werte pro Sales-Channel abweichen sollen. Sie enthält die Mechanik der Sales-Channel-Vererbung.

Abgrenzung: Wie Code den aktuellen Sales-Channel erkennt und danach verzweigt: [T-20 Sales-Channel-Unterscheidung](./20-sales-channel-unterscheidung.md). Plugin-Gerüst und Lifecycle: [T-02](./02-plugin-aufbau.md), [T-03](./03-plugin-lifecycle.md). Events allgemein: [T-07](./07-events-subscriber.md). Theme-Konfiguration (`theme.json`): [T-12](./12-theme-config-scss-variablen.md). Gerüst: [../templates/config/README.md](../templates/config/README.md).

## 2. Konzepte

- **Datei:** `src/Resources/config/config.xml`, validiert vom `ConfigReader` gegen `core/System/SystemConfig/Schema/config.xsd`. Weitere Dateien über `getConfigFromBundle($bundle, 'name')` (`Resources/config/name.xml`).
- **Struktur:** `<config>` → `<card>`+ → `<title lang>`, optional `<subtitle>`, `<name>`, `<ai-badge>`, `<flag>` → `<input-field type="…">` oder `<component name="…">`.
- **Erlaubte `type`-Werte (XSD 6.7.15.1):** `text` (Default), `textarea`, `text-editor`, `url`, `password`, `int`, `float`, `bool`, `checkbox`, `datetime`, `date`, `time`, `colorpicker`, `single-select`, `multi-select`, `price`. `sw-entity-single-select` ist **kein** `type`, sondern `<component name="sw-entity-single-select">` mit `<entity>`.
- **Speicherung:** Tabelle `system_config`, Schlüssel `<PluginName>.config.<fieldName>`, Wert als JSON `{"_value": …}`, `sales_channel_id` NULL = global. Plugin-Name = Klassenname des Plugins.
- **Vererbung:** Sales-Channel-Wert überschreibt den globalen. **Leere** SC-Werte (null, `''`, `0`, `[]`; Booleans ausgenommen) überschreiben NICHT (`SystemConfigLoader::getSubArray`).
- **Defaults** (`<defaultValue>`): geschrieben bei Install (override) und Update (nur fehlende Keys); bei Uninstall ohne `keepUserData` inkl. aller SC-Overrides gelöscht.
- **Loader-Kette:** `SystemConfigLoader` (DB) → `CachedSystemConfigLoader` (Key `system-config-<scId>`) → `ConfiguredSystemConfigLoader` (YAML-Overrides) → `MemoizedSystemConfigLoader` (äußerste Schicht, Request-Memo). Reihenfolge ergibt sich aus den Decorator-Prioritäten in `core/System/DependencyInjection/configuration.php` (-1000 / -1500 / -2000; `SystemConfigService` ruft den äußersten Decorator). Nicht aktivierte Plugins werden herausgefiltert.

Verzeichnisbaum:

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Resources/config/config.xml        # Schlüssel: SwagExample.config.*
    ├── Resources/config/services.php
    ├── Service/PriceLabelService.php
    └── Subscriber/ConfigSubscriber.php
```

Entscheidungshilfe: feste, read-only Werte pro Umgebung -> YAML `shopware.system_config`; vom Händler änderbar -> `config.xml`; komplexe Struktur/eigene UI -> eigenes Admin-Modul ([T-24](./24-admin-module.md)).

## 3. Anleitung

1. `src/Resources/config/config.xml` anlegen (mind. eine `card` mit `title` und einem Feld).
2. Installieren: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample`. Nach Änderung der config.xml bei bereits installiertem Plugin: `bin/console plugin:update SwagExample` (schreibt nur **fehlende** Default-Keys; `PluginLifecycleService`: `savePluginConfiguration($plugin, true)` bei Install, `savePluginConfiguration($plugin)` bei Update).
3. Admin: Erweiterungen → Meine Erweiterungen → Plugin → „Konfigurieren" (Route `config/:namespace`, Seite `sw-extension-config`, rendert `sw-system-config`). Oben Sales-Channel-Umschalter („Alle Sales Channels" = global, sonst Override mit Inherit-Icon).
4. Lesen per CLI: `bin/console system:config:get SwagExample.config.badgeText [-s <salesChannelId>]`.
5. Setzen per CLI: `bin/console system:config:set SwagExample.config.maxItems 10 --json [-s <salesChannelId>] [--silent]` (`--json` für bool/int/float).
6. Im Code: `SystemConfigService::get|getString|getInt|getFloat|getBool($key, ?string $salesChannelId = null)`; die ID aus `$salesChannelContext->getSalesChannelId()`.
7. Fester Wert pro Umgebung (read-only im Admin): `config/packages/*.yaml`
   ```yaml
   shopware:
       system_config:
           default:                                # oder Sales-Channel-UUID
               'SwagExample.config.mode': simple
   ```
   Der Admin kann diese Keys nicht ändern (`setMultiple` wirft `systemConfigKeyIsManagedBySystems`, außer Wert identisch).
8. HTTP-API: `GET /api/_action/system-config?domain=SwagExample.config&salesChannelId=…`, `POST /api/_action/system-config`, `POST …/batch`, `GET …/schema`, `GET …/check`.

### Feldoptionen
- Übersetzbar (`lang`, Fallback `en-GB`): `label`, `placeholder`, `helpText`.
- Boolesch: `copyable`, `disabled`, `required`. Auch `options` (nur Selects) und `defaultValue` (typisiert: bool/checkbox→bool, int, float, multi-select→Array, sonst String).
- Weitere Kindelemente (`min`, `max`, `minLength`, `maxLength`, `entity`, …) werden vom PHP-Reader unverändert als `config`-Option durchgereicht (`xs:any lax`); die Auswertung liegt in der Admin-Komponente.
- Attribut `cache-relevant="true"` an `input-field`/`component` (Reader: `cacheRelevant` in der Feld-`config`): Ändert der Admin beim Speichern (`sw-system-config` `saveAll`) mindestens ein so markiertes Feld, sendet er `silent=false` an `/api/_action/system-config/batch`, d. h. `SystemConfigService` invalidiert den Cache (`CacheInvalidationSubscriber`; bei `silent=true` entfällt die Invalidierung). Ohne markierte Änderung sendet er keinen `silent`-Parameter; es gilt der Default (heute `false`, mit `CACHE_REWORK`/ab 6.8 `true`). Die Markierung wirkt nur im Admin-Speichern, nicht bei `set()` per Code. Doku: „ab 6.7.12.0".
- Card: `<flag>` (Feature-Flag), `<ai-badge>` (boolean, nur im XSD belegt).
- Eigene Komponente: `<component name="sw-…">`, Core-Beispiele `sw-entity-single-select`, `sw-entity-multi-id-select`, `sw-cms-page-select`, `sw-system-config-media-upload`. Eigene Komponenten müssen im Admin registriert sein ([T-25](./25-admin-komponenten.md)).
- Feldname: Regex `[a-zA-Z][a-zA-Z0-9]*`, bei `input-field` pro Datei eindeutig.

## 4. Beispiele

`src/Resources/config/config.xml`
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>General</title>
        <title lang="de-DE">Allgemein</title>

        <input-field type="bool">
            <name>enabled</name>
            <label>Enable feature</label>
            <label lang="de-DE">Funktion aktivieren</label>
            <defaultValue>true</defaultValue>
        </input-field>

        <input-field type="text">
            <name>badgeText</name>
            <label>Badge text</label>
            <label lang="de-DE">Badge-Text</label>
            <placeholder>New</placeholder>
            <defaultValue>New</defaultValue>
            <helpText>Shown on product boxes.</helpText>
        </input-field>

        <input-field type="int">
            <name>maxItems</name>
            <label>Max items</label>
            <defaultValue>5</defaultValue>
            <min>1</min>
            <max>20</max>
        </input-field>

        <input-field type="single-select">
            <name>mode</name>
            <label>Mode</label>
            <defaultValue>simple</defaultValue>
            <options>
                <option><id>simple</id><name>Simple</name><name lang="de-DE">Einfach</name></option>
                <option><id>extended</id><name>Extended</name><name lang="de-DE">Erweitert</name></option>
            </options>
        </input-field>

        <input-field type="password">
            <name>apiKey</name>
            <label>API key</label>
        </input-field>

        <component name="sw-entity-single-select">
            <name>defaultCountryId</name>
            <entity>country</entity>
            <label>Country</label>
        </component>
    </card>
</config>
```

`src/Service/PriceLabelService.php`
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

`src/Resources/config/services.php` (`services.xml` ist in 6.7 deprecated, siehe [T-05](./05-services-registrieren.md))
```php
<?php declare(strict_types=1);

use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagExample\Service\PriceLabelService;
use SwagExample\Subscriber\ConfigSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(PriceLabelService::class)
        ->args([service(SystemConfigService::class)]);

    $services->set(ConfigSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```

`src/Subscriber/ConfigSubscriber.php` (Wert vor dem Speichern normalisieren, auf Änderung reagieren)
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConfigSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            BeforeSystemConfigChangedEvent::class => 'normalize',
            SystemConfigChangedEvent::class => 'changed',
        ];
    }

    public function normalize(BeforeSystemConfigChangedEvent $event): void
    {
        if ($event->getKey() === 'SwagExample.config.badgeText' && \is_string($event->getValue())) {
            $event->setValue(trim($event->getValue()));
        }
    }

    public function changed(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() !== 'SwagExample.config.mode') {
            return;
        }
        // $event->getValue(), $event->getSalesChannelId() (null = global); auch beim Löschen (value null)
    }
}
```

Twig (Storefront; Sales-Channel wird aus dem Kontext ermittelt):
```twig
{% if config('SwagExample.config.enabled') %}
    <span class="badge">{{ config('SwagExample.config.badgeText') }}</span>
{% endif %}
```
Setzen per Code: `$this->systemConfigService->set('SwagExample.config.mode', 'extended', $salesChannelId);`

Weitere Events: `BeforeSystemConfigMultipleChangedEvent`, `SystemConfigMultipleChangedEvent`, `SystemConfigDomainLoadedEvent`, Hook `SystemConfigChangedHook` (`app.config.changed`, für Apps/Webhooks).

## 5. Fallstricke

- Schlüssel ohne `.config.` oder falscher Plugin-Name: richtig `SwagExample.config.enabled`.
- `<input-field type="sw-entity-single-select">` -> XSD-Fehler; stattdessen `<component name="…">`.
- Feldname mit Bindestrich/Unterstrich oder Zahl am Anfang -> XSD-Fehler; doppelte `input-field`-Namen verletzen `uniqueInputFieldName`.
- Neues Feld mit Default fehlt nach Update: `plugin:update` ausführen (Defaults nur für nicht existierende Keys).
- Per-SC-Override auf „leer/0/''" erzwingen geht nicht (erbt global). Boolean `false` überschreibt dagegen.
- `get()` liefert `null`, wenn nie gespeichert und ohne `defaultValue`; `getString()`=`''`, `getInt()`=0, `getBool()`=false. Defaults im Code zusätzlich absichern.
- `getString/getInt/…` werfen `SystemConfigException::invalidSettingValueException` bei Array-Werten (multi-select) -> `get()` nutzen.
- `all()` und `getDomain()` sind `@internal`, nicht in Storefront/Store-API verwenden (Cache-Tags).
- `$salesChannelId` vergessen: nur globale Konfiguration, SC-Overrides greifen nicht.
- Twig `config()` ohne SalesChannelContext (z. B. Mail-Templates ohne Kontext) liefert globalen Wert.
- Konfiguration im Admin nicht sichtbar: Plugin nicht aktiv, XML ungültig oder Cache (`bin/console cache:clear`).
- `SystemConfigChangedEvent` hat nur Getter; Änderung vor dem Speichern über `BeforeSystemConfigChangedEvent::setValue()`.
- Uninstall ohne „Daten behalten" löscht Werte inkl. aller SC-Overrides.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `SystemConfigService::set/setMultiple/delete`: optionaler Parameter `bool $silent`, Default heute `false` (Cache-Invalidierung), ab 6.8 / Feature `CACHE_REWORK` `true` (`#[NewOptionalParameter(v6.8.0)]`).
- `SystemConfigService::trace()` / `getTrace()` `@deprecated tag:v6.8.0` (stattdessen intern `CacheTagCollector`).
- Twig `config()`: statische Keys `seo.descriptionMaxLength`, `cms.revocationNoticeCmsPageId`, `cms.taxCmsPageId`, `cms.tosCmsPageId`, `confirm.revocationNotice` deprecated (v6.8.0); Ersatz z. B. `core.basicInformation.revocationPage` / `tosPage`.
- `cache-relevant` laut Doku ab 6.7.12.0; im Code vorhanden (Wirkung siehe Abschnitt Feldoptionen).
- `services.xml` und `routes.xml` in Plugins sind deprecated (Entfernung v6.8.0.0, `Bundle.php`) -> `services.php`/`routes.php`.
- XSD-Typen `price`, `textarea`, `text-editor`, `url`, `datetime`, `time`, `checkbox` und `ai-badge` existieren in 6.7.15.1; Einführungsversion nicht geprüft.
- Admin rendert Config-Felder teils mit Meteor-Komponenten (`isMeteorComponent` in `sw-system-config/index.js`). In 6.5 hieß die Seite `sw-plugin-config`, in 6.7 `sw-extension-config` (6.5-Detail aus Erinnerung, nicht am Code geprüft).

## 6. Checkliste

- [ ] `config.xml` unter `src/Resources/config/` und XSD-konform (Typen aus der Liste, Namen `[a-zA-Z][a-zA-Z0-9]*`).
- [ ] Select-Entities als `<component name="sw-entity-single-select">`, nicht als `type`.
- [ ] Schlüssel überall als `<PluginName>.config.<field>` verwendet.
- [ ] `<defaultValue>` für jedes Feld gesetzt, im Code zusätzlich abgesichert.
- [ ] `$salesChannelId` bei jedem Lesezugriff übergeben (aus Kontext).
- [ ] Array-Werte (multi-select) per `get()` gelesen, nicht `getString()`.
- [ ] Nach config.xml-Änderung `plugin:update` und `cache:clear` ausgeführt.
- [ ] Kein Verlass auf „leer überschreibt global" pro Sales-Channel.
- [ ] Secrets (API-Keys) als `password`-Feld, nicht in Twig/Frontend ausgegeben.
- [ ] Eigene `<component>`-Typen im Admin registriert und gebaut.

## 7. Quellen

- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-plugin-configuration.html
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/use-plugin-configuration.html
- Code (`core/System/SystemConfig/`): `SystemConfigService`, `Util/ConfigReader`, `Schema/config.xsd`, `SystemConfigLoader`, `CachedSystemConfigLoader`, `MemoizedSystemConfigLoader`, `ConfiguredSystemConfigLoader` (Verdrahtung: `core/System/DependencyInjection/configuration.php`), `Event/*`, `Command/ConfigGet`, `ConfigSet`, `Api/SystemConfigController`.
- `core/Framework/Adapter/Twig/Extension/ConfigExtension.php`; `core/Framework/Plugin/PluginLifecycleService.php`; `core/System/Resources/config/cart.xml`, `basicInformation.xml`; `core/Framework/DependencyInjection/Configuration.php` (`system_config`).
- `administration/Resources/app/administration/src/module/sw-settings/component/sw-system-config/index.js`, `sw-extension/page/sw-extension-config/`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele nicht in laufender Installation ausgeführt, nur gegen XSD/Code geprüft.
- ⚠ LÜCKE: Doku nennt `name` mit „4+ Zeichen", XSD nur `[a-zA-Z][a-zA-Z0-9]*`; Code gilt, Mindestlänge im Code nicht gefunden.
- ⚠ LÜCKE: Auswertung von `min`/`max`/`minLength`/`maxLength`/`entity` in den Admin-Komponenten nicht einzeln geprüft; Wirkung von `<ai-badge>` nicht vertieft.
- ⚠ UNGEPRÜFT: Aussagen zu 6.5/6.6 (Seitenname, Einführungsversion der Typen) nicht am Code belegbar (nur 6.7.15.1 installiert).
- ⚠ UNGEPRÜFT: Reihenfolge der Events in `setMultiple` (pro Key `SystemConfigChangedEvent`, danach einmal `SystemConfigMultipleChangedEvent`) aus dem Code abgelesen, nicht zur Laufzeit geprüft.
