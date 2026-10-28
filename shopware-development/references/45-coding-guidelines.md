# Coding-Guidelines und Konventionen (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift bei Fragen zu Rückwärtskompatibilität, `@internal`/`@final`, Domain-Exceptions, Feature-Flags, Deprecations, Extendability, Namensgebung im Plugin und zu den `AGENTS.md`-Dateien im Quellcode. Sie ist die Entscheidungsgrundlage, welche Core-Klassen ein Plugin gefahrlos nutzen darf.

Abgrenzung: Decorator-Mechanik in [T-06 Service-Dekoration](./06-service-dekoration.md); Events in [T-07](./07-events-subscriber.md) und [T-08 Extension Points](./08-extension-points.md); statische Analyse in [T-48](./48-statische-analyse.md); Tests in [T-46](./46-tests-phpunit.md); MCP in [T-44](./44-mcp-server.md); Plugin-Struktur in [T-02](./02-plugin-aufbau.md).

## 2. Konzepte

- **BC-Versprechen** gilt für alles ohne `@internal`: PHP-Klassen/Interfaces/öffentliche und protected Member, DI-Service-IDs/Tags, Events, Store-/Admin-API, Twig-Blöcke, JS-Plugins, Admin-Komponenten, Snippet-Keys, `shopware.yaml`-Keys, CLI-Commands. Breaking Changes nur im Major (6.8) nach Deprecation in der Minor. Konstruktoren DI-erzeugter Services sind **nicht** öffentlich; Konstruktoren selbst instanziierter DTOs (z. B. `CalculatedPrice`) schon.
- **`@internal`:** keine BC-Garantie, nicht nutzen, nicht dekorieren. **`@final`:** nutzen, nicht erweitern (Erweiterung über Events/Dekoration auf Container-Ebene). **Dekorierbar:** abstrakte Basisklasse mit `getDecorated()`, weder `@internal` noch `@final`.
- **Erweiterungs-Reihenfolge für Plugins:** Event > Extension ([T-08](./08-extension-points.md)) > Dekoration > Service-Replacement (vermeiden). Muster laut Doku: Decoration (Store-API-Routen, `Abstract*`), Factory, Visitor (Cart-Processor/Collector), Mediator/Events (bei asynchroner Nutzung IDs statt Objekte), Adapter.
- **Domain-Exceptions:** pro Domäne eine `FooException extends Shopware\Core\Framework\HttpException`, statische Factory-Methoden, Error-Codes `DOMAIN__SCREAMING_SNAKE`, Platzhalter `{{ name }}`. Kette: `HttpException` (Shopware) > `ShopwareHttpException` > Symfony `HttpException`.
- **Feature-Flags:** Flag vierteilig `v6.8.0.0` (Env `V6_8_0_0=1`), Deprecation-Tag dreiteilig `tag:v6.8.0`.
- **BC-Change-Attribute** (`core/Framework/Deprecation/BCChange/`, `@internal`) ersetzen in 6.7 die `reason:*`-Deprecations: `BecomesAbstract`, `BecomesFinal`, `BecomesInternal`, `BecomesReadonly`, `NewOptionalParameter`, `NewRequiredParameter`, `ReturnTypeNarrowing`, `ParameterTypeNarrowing` u. a., je `public readonly string $version`. Warnsignal: Wer z. B. `Feature` (`#[BecomesFinal(version: 'v6.8.0')]`) erweitert, muss vor 6.8 umbauen.
- **Plugin-Konventionen:** `declare(strict_types=1)`, Konstruktor-Promotion `private readonly`, Services als `services.php` (XML in 6.7 deprecated), eigener Namespace (`SwagExample\…`, nie `Shopware\…`), Suffixe nach Core-Muster (`…Subscriber`, `…Route`/`Abstract…Route`, `…Controller`, `…Definition`/`…Entity`/`…Collection`, `…Exception`, `…Command`, `…Handler`, `…Struct`, `…Event`), Tabellennamen `snake_case` mit Vendor-Präfix (`swag_…`), `ENTITY_NAME`-Konstante in Definitionen. `Abstract*` nur für bewusst dekorierbare Services.
- **Plattform:** PHP `~8.2 – ~8.5`, Symfony `~7.4.0` (`core/composer.json`).

Plugin-Struktur, die die Konventionen zeigt:

```
custom/plugins/SwagExample/
├── composer.json
├── src/
│   ├── SwagExample.php
│   ├── ExampleException.php            # Domain-Exception (Top-Level)
│   ├── Exception/                      # nur Unterklassen, die gefangen werden müssen
│   ├── Service/{AbstractGreetingService,GreetingService,LoudGreetingService}.php
│   └── Resources/config/services.php
└── tests/Unit/Service/
```

## 3. Anleitung

### 3.1 Deprecation und BC
1. Als Plugin-Autor nur Nicht-`@internal`-API nutzen; Core-Deprecations in CI mit `V6_8_0_0=1` prüfen (`V6_8_0_0=1 vendor/bin/phpunit`).
2. Eigene Deprecations (falls Plugin selbst API anbietet): `@deprecated tag:v6.8.0 - Begründung/Ersatz` plus `Feature::triggerDeprecationOrThrow('v6.8.0.0', Feature::deprecatedMethodMessage(self::class, __METHOD__, 'v6.8.0.0', 'Neu::methode'))` (`core/Framework/Feature.php`; Core-Beispiel `AppException`: Versionsargument ebenfalls `'v6.8.0.0'`). Der Trigger wirft `FeatureException`, sobald das Major-Flag aktiv ist.
3. In Minor erlaubt (Core): neue Klassen/Konstanten/Events, neue public Methoden an `@final`-Klassen, neue nicht-abstrakte Methoden an Decorator-Basisklassen, neue optionale Parameter; nicht erlaubt: Entfernen/Umbenennen öffentlicher Member, Typ-/Sichtbarkeitsänderung, neue Interface-Methoden, Entfernen von Events/Twig-Blöcken/Tabellen/Routen.

### 3.2 Domain-Exception
Basis-Konstruktor `HttpException(int $statusCode, string $errorCode, string $message, array $parameters = [], ?\Throwable $previous = null)`; Methoden `getErrorCode()`, `getStatusCode()`, `is(string ...$code)`. Statuscodes aus `Response::HTTP_*`. Vorbild: `core/Content/Product/ProductException.php`. Keine `\RuntimeException`/`\InvalidArgumentException` im Domänencode (Core-PHPStan-Regeln `DomainExceptionRule`, `NoRuntimeExceptionInDomainExceptionsRule`).

### 3.3 Feature-Flags
- Registrierung im Core: `core/Framework/Resources/config/packages/feature.yaml` (`shopware.feature.flags`: `name`, `default`, `major`, `toggleable`, `description`). 6.7.15.1: `v6.5.0.0`, `v6.6.0.0`, `v6.7.0.0` (`default: true`), `v6.8.0.0` (`default: false`, `major: true`), außerdem u. a. `DISABLE_VUE_COMPAT`, `ACCESSIBILITY_TWEAKS`, `TELEMETRY_METRICS`, `FLOW_EXECUTION_AFTER_BUSINESS_PROCESS`, `DOCUMENT_GENERATION_REWORK`, `PERFORMANCE_TWEAKS`, `TWIG_COMPILE_TIME_OPTIMIZATION`.
- Aktivierung: `.env` `V6_8_0_0=1`, `FEATURE_ALL=1` (alle Minor-Flags) bzw. `FEATURE_ALL=major` / `v6.8` (nur Major-Flags). Priorität: konkrete Env > `FEATURE_ALL` > Registry-Default.
- Zugriff: PHP `Feature::isActive()`, Twig `{% if feature('v6.8.0.0') %}`, Admin `inject: ['feature']` + `feature.isActive(...)`, Storefront-JS `import Feature from 'src/helper/feature.helper'`.
- CLI: `bin/console feature:list`, `feature:enable`, `feature:disable`, `feature:dump` (Namen im Quellcode verifiziert).
- Plugins: Core-Flags lesen, eigene Schalter über Plugin-Konfiguration ([T-19](./19-plugin-konfiguration.md)).

### 3.4 AGENTS.md / CLAUDE.md im Quellcode
Vorhanden in `administration/Resources/app/administration/` (Root, `src/core/`, `src/app/`, `src/module/`), `core/Framework/Mcp/` (+ Unterordner) und `storefront/Mcp/`. Jede `CLAUDE.md` besteht nur aus `@AGENTS.md`.
- **Admin-Root:** Vue 3, Twig.JS, Pinia, **Vite**, **Jest**; Komponenten per Component-Factory (keine SFCs, damit Plugins per Twig-Block überschreiben können); Jest-Test pro Feature (`.spec.ts` neben dem Code; ab 500 Zeilen `.spec/`-Ordner); TypeScript für neuen Code. Skripte (Platform-Repo): `composer eslint:admin[:fix]`, `stylelint:admin[:fix]`, `format:admin[:fix]`, `admin:unit`, `build:js:admin`.
- **`src/app/AGENTS.md`:** Boot `init-pre/ > init/ > init-post/`; Abhängigkeiten per `inject`, nicht per Import; Komponenten nur global via `Shopware.Component.register`; Pinia über `Shopware.Store.register`; BEM + Meteor-Tokens; Anti-Patterns: lokale Komponenten-Imports, DOM-Manipulation, Props mutieren, Logik im Template, Inline-Styles, Mixins in neuem Code (Composables nutzen). Details [T-24](./24-admin-module.md), [T-25](./25-admin-komponenten.md).
- **`core/Framework/Mcp/AGENTS.md`:** siehe [T-44](./44-mcp-server.md).
- Die Admin-Regeln gelten nur für JS/Vue, nicht pauschal für PHP-Plugins.

### 3.5 PHP-8.x-/Symfony-7-Konventionen
`strict_types` überall; native Property-/Parametertypen, generische Typen per PHPDoc (`list<string>`); Enums statt String-Konstanten (wo kein BC-Zwang); native Attribute (`#[Route]`, `#[AsEventListener]`); Routen/Services als PHP (`routes.php`, `services.php`). Storefront-Routennamen beginnen mit `frontend.`. Im Core: keine native Zeit-API (`ClockInterface`), kein `unserialize`, kein `file_exists`, kein Session-Zugriff in Core/Store-API; Namespace-Schichten Core <- Administration/Elasticsearch/Storefront (PHPat `RestrictNamespacesRule`).

### 3.6 ADRs
Core-ADRs liegen im Repo-Ordner `adr/` (`YYYY-MM-DD-titel.md`); in `vendor/` nicht enthalten. Für Plugins als Empfehlung: `docs/adr/YYYY-MM-DD-titel.md` (nicht von Shopware verlangt).

## 4. Beispiele

Gerüst-Verweise: [Plugin-Gerüst](../templates/plugin/README.md), [Decorator-Gerüst](../templates/decorator/README.md).

`src/ExampleException.php`

```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

class ExampleException extends HttpException
{
    public const GREETING_NAME_EMPTY = 'SWAG_EXAMPLE__GREETING_NAME_EMPTY';

    public static function greetingNameEmpty(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::GREETING_NAME_EMPTY,
            'The name for the greeting must not be empty.'
        );
    }
}
```

`src/Service/AbstractGreetingService.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

abstract class AbstractGreetingService
{
    abstract public function getDecorated(): AbstractGreetingService;

    abstract public function greet(string $name): string;
}
```

`src/Service/GreetingService.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use SwagExample\ExampleException;

final class GreetingService extends AbstractGreetingService
{
    public function getDecorated(): AbstractGreetingService
    {
        throw new DecorationPatternException(self::class);
    }

    public function greet(string $name): string
    {
        if (trim($name) === '') {
            throw ExampleException::greetingNameEmpty();
        }

        return 'Hello ' . $name;
    }
}
```

`src/Service/LoudGreetingService.php` (Dekorator-Muster)

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

final class LoudGreetingService extends AbstractGreetingService
{
    public function __construct(private readonly AbstractGreetingService $inner)
    {
    }

    public function getDecorated(): AbstractGreetingService
    {
        return $this->inner;
    }

    public function greet(string $name): string
    {
        return strtoupper($this->inner->greet($name));
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Service\AbstractGreetingService;
use SwagExample\Service\GreetingService;
use SwagExample\Service\LoudGreetingService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(GreetingService::class);

    $services->set(LoudGreetingService::class)
        ->decorate(GreetingService::class)
        ->args([service('.inner')]);

    $services->alias(AbstractGreetingService::class, GreetingService::class);
};
```

Feature-Flag-Muster (Core nutzt es z. B. in `OrderConverter`): `if (Feature::isActive('v6.8.0.0')) { /* neu */ } else { /* alt */ }`.

⚠ UNGEPRÜFT: Beispiel-Plugin nicht installiert/ausgeführt.

## 5. Fallstricke

1. `@internal`-Klasse erweitert/dekoriert: bricht bei Minor-Update ohne Ankündigung.
2. `@final`-Klasse mit `extends` erweitert statt Event/Decorator.
3. Dekorator implementiert `EventSubscriberInterface` (nicht erlaubt laut Doku/Regel).
4. Dekorierte Abstract-Klasse um eigene public-Methoden ergänzt (Verstoß gegen `DecorationPatternRule`, die nur in `core-rules.neon` steht, nicht in `rules.neon`; siehe [T-48](./48-statische-analyse.md)); Dekoratoren dürfen keine `#[Route]` definieren.
5. `\RuntimeException` statt Domain-Exception; Error-Code ohne Domänenpräfix.
6. Flag/Tag verwechselt: Tag in `@deprecated tag:` dreiteilig (`v6.8.0`), Flag in `Feature::isActive()` vierteilig (`v6.8.0.0`).
7. `Feature::isActive()` in `services.php`/Konfiguration verzweigen (laut Doku verboten).
8. XML-Konfiguration (`services.xml`, `routes.xml`) in 6.7 deprecated (`Bundle.php` `triggerXmlConfigDeprecation`), entfällt in 6.8.
9. Admin-JS: lokale Komponenten-Imports oder Mixins in neuem Code.
10. Entities statt IDs in asynchron verarbeiteten Events.
11. Doku-Regeln übernehmen, wo der Code abweicht (siehe Lücken).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

| Thema | 6.5/6.6 | 6.7.15.1 (Code) |
|---|---|---|
| PHP/Symfony | 6.5: PHP 8.1+, Symfony 6.x; 6.6: PHP 8.2, Symfony 6.4 (⚠ UNGEPRÜFT, nicht am Code belegt) | PHP `~8.2–8.5`, Symfony `~7.4.0` |
| Flags `v6.5/6.6/6.7.0.0` | Major-Flag der Vorversion inaktiv bis Major | alle `default: true`, nicht toggelbar; nächstes Major `v6.8.0.0` (`default: false`) |
| Deprecation-Marker | `@deprecated tag:v6.x.0` + `reason:*` | `reason:*` ersetzt durch BC-Change-Attribute |
| XML-Konfiguration | Standard | deprecated (`tag:v6.8.0`) |
| Admin-Build | Webpack | Vite (siehe [T-49](./49-build-deployment.md)) |
| MCP | nicht vorhanden | experimentell, `stableVersion:v6.8.0` |

## 6. Checkliste

- [ ] Nur Nicht-`@internal`-API genutzt, `@final`-Klassen nicht erweitert
- [ ] Eigene dekorierbare Services: abstrakte Basis + `getDecorated()`, kein `@final`/`@internal`
- [ ] Fehler als Domain-Exception mit Konstante `DOMAIN__CODE` und Factory-Methode
- [ ] `declare(strict_types=1)`, native Typen, `private readonly` Promotion
- [ ] Services/Routen als PHP, nicht XML
- [ ] Eigener Namespace und `swag_`-Tabellenpräfix (Vendor-Präfix)
- [ ] CI-Lauf mit `V6_8_0_0=1` gegen Core-Deprecations
- [ ] Keine Branches auf `Feature::isActive()` in DI-Konfiguration
- [ ] Admin-Code folgt `AGENTS.md` (global registrierte Komponenten, `inject`, keine Mixins neu)

## 7. Quellen

Doku (https://developer.shopware.com/docs/resources/guidelines/code/): `core/` (Index), `core/backward-compatibility.html`, `core/final-and-internal.html`, `core/domain-exceptions.html`, `core/extendability.html`, `core/decorator-pattern.html`, `core/feature-flags.html`, `core/adr.html`, `core/6.5-new-php-language-features.html` (Titel "PHP 8.1 & Symfony 6.1", Seite nicht aktualisiert), `public-apis.html`, `routing.html`, `session-and-state.html`. Weitere existierende, nicht ausgewertete Seiten: `core/agent-skills.html`, `core/ci-workflows.html`, `core/unit-tests.html`, `core/writing-code-for-static-analysis.html`, `platform-domains.html`, `store-api.html`, `storefront-controller.html`. Die als "[ungeprüft]" geführten Pfade `extendability.html` und `domain-exceptions.html` sind gültig.

Quellcode (unter `vendor/shopware/`): `core/Framework/HttpException.php`, `ShopwareHttpException.php`, `ShopwareException.php`, `core/Framework/Feature.php`, `core/Framework/Feature/FeatureException.php`, `core/Framework/Feature/Command/Feature*Command.php`, `core/Framework/Resources/config/packages/feature.yaml`, `core/Framework/Deprecation/BCChange/*`, `core/Framework/Plugin/Exception/DecorationPatternException.php`, `core/Framework/Bundle.php`, `core/Content/Product/ProductException.php`, `core/DevOps/StaticAnalyze/PHPStan/Rules/*`, `administration/Resources/app/administration/AGENTS.md`, `…/src/app/AGENTS.md`, `core/Framework/Mcp/AGENTS.md`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE (Doku weicht vom Code ab, Code gilt): Doku `routing.html` fordert Annotation `Routing\Annotation\Since`, im Code nicht vorhanden (Routen nutzen `#[Route]`); Doku `backward-compatibility.html` nennt `#[ClassMoved]`, im Code nicht gefunden (nicht verwenden); Doku `feature-flags.html` nennt Tag `shopware.inactiveFeature`, im Vendor kein Treffer.
- ⚠ UNGEPRÜFT: Doku-Aussage "Core triggert nie eigene Deprecations"; Doku-Inhalt stammt aus Abruf-Zusammenfassungen (Beispiele gegen Quellcode prüfen).
- ⚠ UNGEPRÜFT (Annahmen/Empfehlungen, nicht belegt): `#[Package]`-Pflicht für Plugins, eigene Flags in `feature.yaml`, ADR-Ablage im Plugin, `ClockInterface`-Empfehlung.
- ⚠ LÜCKE: ADRs (`adr/…`) und Skill `.agents/skills/shopware-admin-js` im Vendor nicht enthalten; 6.5/6.6-Spalte nicht am lokalen Code belegbar.
