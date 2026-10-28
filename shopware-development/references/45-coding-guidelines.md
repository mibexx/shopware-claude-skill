# Coding guidelines and conventions (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies to questions about backward compatibility, `@internal`/`@final`, domain exceptions, feature flags, deprecations, extendability, naming in the plugin, and the `AGENTS.md` files in the source code. It is the basis for deciding which core classes a plugin may safely use.

Scope: decorator mechanics in [T-06 Service decoration](./06-service-decoration.md); events in [T-07](./07-events-subscriber.md) and [T-08 Extension points](./08-extension-points.md); static analysis in [T-48](./48-static-analysis.md); tests in [T-46](./46-tests-phpunit.md); MCP in [T-44](./44-mcp-server.md); plugin structure in [T-02](./02-plugin-structure.md).

## 2. Concepts

- **BC promise** applies to everything without `@internal`: PHP classes/interfaces/public and protected members, DI service IDs/tags, events, Store/Admin API, Twig blocks, JS plugins, admin components, snippet keys, `shopware.yaml` keys, CLI commands. Breaking changes only in the major (6.8) after deprecation in the minor. Constructors of DI-created services are **not** public; constructors of self-instantiated DTOs (e.g. `CalculatedPrice`) are.
- **`@internal`:** no BC guarantee, do not use, do not decorate. **`@final`:** use, do not extend (extension via events/decoration at container level). **Decoratable:** abstract base class with `getDecorated()`, neither `@internal` nor `@final`.
- **Extension order for plugins:** event > extension ([T-08](./08-extension-points.md)) > decoration > service replacement (avoid). Patterns according to the docs: decoration (Store API routes, `Abstract*`), factory, visitor (cart processor/collector), mediator/events (with asynchronous use, IDs instead of objects), adapter.
- **Domain exceptions:** one `FooException extends Shopware\Core\Framework\HttpException` per domain, static factory methods, error codes `DOMAIN__SCREAMING_SNAKE`, placeholders `{{ name }}`. Chain: `HttpException` (Shopware) > `ShopwareHttpException` > Symfony `HttpException`.
- **Feature flags:** flag with four parts `v6.8.0.0` (env `V6_8_0_0=1`), deprecation tag with three parts `tag:v6.8.0`.
- **BC change attributes** (`core/Framework/Deprecation/BCChange/`, `@internal`) replace the `reason:*` deprecations in 6.7: `BecomesAbstract`, `BecomesFinal`, `BecomesInternal`, `BecomesReadonly`, `NewOptionalParameter`, `NewRequiredParameter`, `ReturnTypeNarrowing`, `ParameterTypeNarrowing` among others, each with `public readonly string $version`. Warning sign: anyone who extends e.g. `Feature` (`#[BecomesFinal(version: 'v6.8.0')]`) must refactor before 6.8.
- **Plugin conventions:** `declare(strict_types=1)`, constructor promotion `private readonly`, services as `services.php` (XML deprecated in 6.7), own namespace (`SwagExample\…`, never `Shopware\…`), suffixes following the core pattern (`…Subscriber`, `…Route`/`Abstract…Route`, `…Controller`, `…Definition`/`…Entity`/`…Collection`, `…Exception`, `…Command`, `…Handler`, `…Struct`, `…Event`), table names `snake_case` with vendor prefix (`swag_…`), `ENTITY_NAME` constant in definitions. `Abstract*` only for deliberately decoratable services.
- **Platform:** PHP `~8.2 – ~8.5`, Symfony `~7.4.0` (`core/composer.json`).

Plugin structure illustrating the conventions:

```
custom/plugins/SwagExample/
├── composer.json
├── src/
│   ├── SwagExample.php
│   ├── ExampleException.php            # domain exception (top level)
│   ├── Exception/                      # only subclasses that must be caught
│   ├── Service/{AbstractGreetingService,GreetingService,LoudGreetingService}.php
│   └── Resources/config/services.php
└── tests/Unit/Service/
```

## 3. Instructions

### 3.1 Deprecation and BC
1. As a plugin author, only use non-`@internal` API; check core deprecations in CI with `V6_8_0_0=1` (`V6_8_0_0=1 vendor/bin/phpunit`).
2. Own deprecations (if the plugin itself offers an API): `@deprecated tag:v6.8.0 - reason/replacement` plus `Feature::triggerDeprecationOrThrow('v6.8.0.0', Feature::deprecatedMethodMessage(self::class, __METHOD__, 'v6.8.0.0', 'New::method'))` (`core/Framework/Feature.php`; core example `AppException`: version argument also `'v6.8.0.0'`). The trigger throws `FeatureException` as soon as the major flag is active.
3. Allowed in a minor (core): new classes/constants/events, new public methods on `@final` classes, new non-abstract methods on decorator base classes, new optional parameters; not allowed: removing/renaming public members, type/visibility changes, new interface methods, removing events/Twig blocks/tables/routes.

### 3.2 Domain exception
Base constructor `HttpException(int $statusCode, string $errorCode, string $message, array $parameters = [], ?\Throwable $previous = null)`; methods `getErrorCode()`, `getStatusCode()`, `is(string ...$code)`. Status codes from `Response::HTTP_*`. Model: `core/Content/Product/ProductException.php`. No `\RuntimeException`/`\InvalidArgumentException` in domain code (core PHPStan rules `DomainExceptionRule`, `NoRuntimeExceptionInDomainExceptionsRule`).

### 3.3 Feature flags
- Registration in the core: `core/Framework/Resources/config/packages/feature.yaml` (`shopware.feature.flags`: `name`, `default`, `major`, `toggleable`, `description`). 6.7.15.1: `v6.5.0.0`, `v6.6.0.0`, `v6.7.0.0` (`default: true`), `v6.8.0.0` (`default: false`, `major: true`), plus among others `DISABLE_VUE_COMPAT`, `ACCESSIBILITY_TWEAKS`, `TELEMETRY_METRICS`, `FLOW_EXECUTION_AFTER_BUSINESS_PROCESS`, `DOCUMENT_GENERATION_REWORK`, `PERFORMANCE_TWEAKS`, `TWIG_COMPILE_TIME_OPTIMIZATION`.
- Activation: `.env` `V6_8_0_0=1`, `FEATURE_ALL=1` (all minor flags) or `FEATURE_ALL=major` / `v6.8` (major flags only). Priority: specific env > `FEATURE_ALL` > registry default.
- Access: PHP `Feature::isActive()`, Twig `{% if feature('v6.8.0.0') %}`, Admin `inject: ['feature']` + `feature.isActive(...)`, Storefront JS `import Feature from 'src/helper/feature.helper'`.
- CLI: `bin/console feature:list`, `feature:enable`, `feature:disable`, `feature:dump` (names verified in the source code).
- Plugins: read core flags; own switches via plugin configuration ([T-19](./19-plugin-configuration.md)).

### 3.4 AGENTS.md / CLAUDE.md in the source code
Present in `administration/Resources/app/administration/` (root, `src/core/`, `src/app/`, `src/module/`), `core/Framework/Mcp/` (+ subfolders) and `storefront/Mcp/`. Each `CLAUDE.md` consists only of `@AGENTS.md`.
- **Admin root:** Vue 3, Twig.JS, Pinia, **Vite**, **Jest**; components via component factory (no SFCs, so that plugins can override via Twig block); one Jest test per feature (`.spec.ts` next to the code; from 500 lines a `.spec/` folder); TypeScript for new code. Scripts (platform repo): `composer eslint:admin[:fix]`, `stylelint:admin[:fix]`, `format:admin[:fix]`, `admin:unit`, `build:js:admin`.
- **`src/app/AGENTS.md`:** boot `init-pre/ > init/ > init-post/`; dependencies via `inject`, not via import; components only globally via `Shopware.Component.register`; Pinia via `Shopware.Store.register`; BEM + Meteor tokens; anti-patterns: local component imports, DOM manipulation, mutating props, logic in the template, inline styles, mixins in new code (use composables). Details [T-24](./24-admin-modules.md), [T-25](./25-admin-components.md).
- **`core/Framework/Mcp/AGENTS.md`:** see [T-44](./44-mcp-server.md).
- The admin rules apply only to JS/Vue, not across the board to PHP plugins.

### 3.5 PHP 8.x / Symfony 7 conventions
`strict_types` everywhere; native property/parameter types, generic types via PHPDoc (`list<string>`); enums instead of string constants (where there is no BC constraint); native attributes (`#[Route]`, `#[AsEventListener]`); routes/services as PHP (`routes.php`, `services.php`). Storefront route names start with `frontend.`. In the core: no native time API (`ClockInterface`), no `unserialize`, no `file_exists`, no session access in Core/Store API; namespace layers Core <- Administration/Elasticsearch/Storefront (PHPat `RestrictNamespacesRule`).

### 3.6 ADRs
Core ADRs live in the repo folder `adr/` (`YYYY-MM-DD-title.md`); not included in `vendor/`. Recommended for plugins: `docs/adr/YYYY-MM-DD-title.md` (not required by Shopware).

## 4. Examples

Scaffold references: [Plugin scaffold](../templates/plugin/README.md), [Decorator scaffold](../templates/decorator/README.md).

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

`src/Service/LoudGreetingService.php` (decorator pattern)

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

Feature flag pattern (the core uses it e.g. in `OrderConverter`): `if (Feature::isActive('v6.8.0.0')) { /* new */ } else { /* old */ }`.

⚠ UNVERIFIED: Example plugin not installed/executed.

## 5. Pitfalls

1. `@internal` class extended/decorated: breaks on a minor update without notice.
2. `@final` class extended with `extends` instead of event/decorator.
3. Decorator implements `EventSubscriberInterface` (not allowed according to the docs/rule).
4. Decorated abstract class extended with own public methods (violation of `DecorationPatternRule`, which is only in `core-rules.neon`, not in `rules.neon`; see [T-48](./48-static-analysis.md)); decorators must not define `#[Route]`.
5. `\RuntimeException` instead of a domain exception; error code without domain prefix.
6. Flag/tag confused: tag in `@deprecated tag:` has three parts (`v6.8.0`), flag in `Feature::isActive()` has four parts (`v6.8.0.0`).
7. Branching on `Feature::isActive()` in `services.php`/configuration (forbidden according to the docs).
8. XML configuration (`services.xml`, `routes.xml`) deprecated in 6.7 (`Bundle.php` `triggerXmlConfigDeprecation`), dropped in 6.8.
9. Admin JS: local component imports or mixins in new code.
10. Entities instead of IDs in asynchronously processed events.
11. Adopting docs rules where the code deviates (see gaps).

### Deprecations and version notes (6.5/6.6 → 6.7)

| Topic | 6.5/6.6 | 6.7.15.1 (code) |
|---|---|---|
| PHP/Symfony | 6.5: PHP 8.1+, Symfony 6.x; 6.6: PHP 8.2, Symfony 6.4 (⚠ UNVERIFIED, not confirmed in the code) | PHP `~8.2–8.5`, Symfony `~7.4.0` |
| Flags `v6.5/6.6/6.7.0.0` | major flag of the previous version inactive until the major | all `default: true`, not toggleable; next major `v6.8.0.0` (`default: false`) |
| Deprecation marker | `@deprecated tag:v6.x.0` + `reason:*` | `reason:*` replaced by BC change attributes |
| XML configuration | standard | deprecated (`tag:v6.8.0`) |
| Admin build | Webpack | Vite (see [T-49](./49-build-deployment.md)) |
| MCP | not present | experimental, `stableVersion:v6.8.0` |

## 6. Checklist

- [ ] Only non-`@internal` API used, `@final` classes not extended
- [ ] Own decoratable services: abstract base + `getDecorated()`, no `@final`/`@internal`
- [ ] Errors as domain exception with constant `DOMAIN__CODE` and factory method
- [ ] `declare(strict_types=1)`, native types, `private readonly` promotion
- [ ] Services/routes as PHP, not XML
- [ ] Own namespace and `swag_` table prefix (vendor prefix)
- [ ] CI run with `V6_8_0_0=1` against core deprecations
- [ ] No branches on `Feature::isActive()` in DI configuration
- [ ] Admin code follows `AGENTS.md` (globally registered components, `inject`, no new mixins)

## 7. Sources

Docs (https://developer.shopware.com/docs/resources/guidelines/code/): `core/` (index), `core/backward-compatibility.html`, `core/final-and-internal.html`, `core/domain-exceptions.html`, `core/extendability.html`, `core/decorator-pattern.html`, `core/feature-flags.html`, `core/adr.html`, `core/6.5-new-php-language-features.html` (title "PHP 8.1 & Symfony 6.1", page not updated), `public-apis.html`, `routing.html`, `session-and-state.html`. Other existing pages not evaluated: `core/agent-skills.html`, `core/ci-workflows.html`, `core/unit-tests.html`, `core/writing-code-for-static-analysis.html`, `platform-domains.html`, `store-api.html`, `storefront-controller.html`. The paths listed as "[unverified]", `extendability.html` and `domain-exceptions.html`, are valid.

Source code (under `vendor/shopware/`): `core/Framework/HttpException.php`, `ShopwareHttpException.php`, `ShopwareException.php`, `core/Framework/Feature.php`, `core/Framework/Feature/FeatureException.php`, `core/Framework/Feature/Command/Feature*Command.php`, `core/Framework/Resources/config/packages/feature.yaml`, `core/Framework/Deprecation/BCChange/*`, `core/Framework/Plugin/Exception/DecorationPatternException.php`, `core/Framework/Bundle.php`, `core/Content/Product/ProductException.php`, `core/DevOps/StaticAnalyze/PHPStan/Rules/*`, `administration/Resources/app/administration/AGENTS.md`, `…/src/app/AGENTS.md`, `core/Framework/Mcp/AGENTS.md`.

### Gaps and uncertainties

- ⚠ GAP (docs deviate from the code, the code is authoritative): docs `routing.html` require the annotation `Routing\Annotation\Since`, not present in the code (routes use `#[Route]`); docs `backward-compatibility.html` mention `#[ClassMoved]`, not found in the code (do not use); docs `feature-flags.html` mention the tag `shopware.inactiveFeature`, no hit in vendor.
- ⚠ UNVERIFIED: Docs statement "the core never triggers its own deprecations"; docs content comes from fetch summaries (check examples against the source code).
- ⚠ UNVERIFIED (assumptions/recommendations, not confirmed): `#[Package]` requirement for plugins, own flags in `feature.yaml`, ADR storage in the plugin, `ClockInterface` recommendation.
- ⚠ GAP: ADRs (`adr/…`) and skill `.agents/skills/shopware-admin-js` not included in vendor; 6.5/6.6 column cannot be confirmed against the local code.
