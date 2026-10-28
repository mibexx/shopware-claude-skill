# Storefront controllers, pages and pagelets (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when you create a custom storefront route/page, provide page data via a PageLoader/pagelet, enrich existing pages, build AJAX endpoints or integrate captchas.
Scope: Twig templates see [T-14](./14-storefront-twig-templates.md), JS/AJAX client see [T-15](./15-storefront-js-plugins.md), Store API routes (data source for loaders) see [T-30](./30-store-api-routes.md), events/subscribers in general see [T-07](./07-events-subscriber.md), services see [T-05](./05-registering-services.md), HTTP cache see [T-41](./41-caching.md), login/context distinction see [T-20](./20-sales-channel-distinction.md).

## 2. Concepts

- **Controller:** extends `Shopware\Storefront\Controller\StorefrontController` (abstract, extends Symfony `AbstractController`). On the class: `#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]` (`_routeScope` = `storefront`, possible on the class or method `#[Route]`). Without a scope, `RouteScopeListener::checkScope` aborts with `RoutingException::invalidRouteScope`.
- **Registration:** The controller must be a **public service** with `->call('setContainer', [service('service_container')])` (core: `storefront/DependencyInjection/controller.php` sets `defaults()->public()` and `setContainer`). Routes are discovered via attribute import in `Resources/config/routes.php` (core: `$routes->import('../../Controller/**/*Controller.php', 'attribute')`).
- **Rendering:** `$this->renderStorefront('@Namespace/storefront/page/....html.twig', [...])` dispatches `StorefrontRenderEvent`, replaces SEO/media placeholders and sets `Content-Type: text/html`. Other helpers: `renderView`, `forwardToRoute`, `createActionResponse`, `redirectToRoute`, `hook`, `isHeadRequest`, `trans`.
- **Page** = struct that extends `Shopware\Storefront\Page\Page` (`metaInformation`). A **PageLoader** builds it: `GenericPageLoaderInterface::load()` returns the base `Page`, `XyzPage::createFrom($page)` (from `CreateFromTrait` in `Struct`) extends it, then an `XyzPageLoadedEvent` (extends `PageLoadedEvent`, abstract `getPage()`) is dispatched. **Pagelet** = `Pagelet extends Struct` with its own `PageletLoader` and `PageletLoadedEvent` (core: header, footer, menu, newsletter, wishlist, country, captcha).
- **Enriching a page:** subscriber on `XyzPageLoadedEvent` (e.g. `ProductPageLoadedEvent`) or `GenericPageLoadedEvent`, then `$event->getPage()->addExtension('name', $struct)`. In Twig: `page.extensions.name`.
- **AJAX:** route with `defaults: ['XmlHttpRequest' => true]`. Without the flag, `StorefrontSubscriber::preventPageLoadingFromXmlHttpRequest` throws `RoutingException::accessDeniedForXmlHttpRequest` for XHR on storefront-scope routes. Response: `JsonResponse` or a rendered partial.
- **Route names:** The storefront `Router` treats routes with the prefix `frontend.`, `widgets.`, `payment.` as storefront routes (`isStorefrontRoute`). Other names only if they are listed in the parameter `storefront.router.allowed_routes` (`Configuration.php`).
- **PlatformRequest constants** (`core/PlatformRequest.php`): `ATTRIBUTE_ROUTE_SCOPE` (`_routeScope`), `ATTRIBUTE_CAPTCHA` (`_captcha`), `ATTRIBUTE_HTTP_CACHE` (`_httpCache`), `ATTRIBUTE_NO_STORE` (`_noStore`), `ATTRIBUTE_LOGIN_REQUIRED` (`_loginRequired`), `ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST`.
- **Captcha:** route default `_captcha => true` activates `CaptchaRouteListener::validateCaptcha`. It iterates over all captchas tagged with `shopware.storefront.captcha` (`supports()`, then `validate()`). On failure: non-XHR and `shouldBreak()` = true → `CaptchaException::invalid`, otherwise `ErrorController::onCaptchaFailure` replaces the controller (JSON with errors). Core priorities: Honeypot 400, BasicCaptcha 300, reCaptcha v2 200, v3 100.
- **Guardrail (comment in `CaptchaController`, `FooterPageletLoader`, `WishlistPageLoader`):** "Do not use direct or indirect repository calls in a controller / PageLoader / PageletLoader. Always use a store-api route."

Directory tree:

```
custom/plugins/SwagExample/src/
├── Controller/DemoController.php
├── Page/Demo/{DemoPage,DemoPageLoader,DemoPageLoadedEvent}.php
├── Pagelet/Hello/{HelloPagelet,HelloPageletLoader,HelloPageletLoadedEvent}.php
├── Subscriber/ProductPageSubscriber.php
├── Framework/Captcha/MyCaptcha.php
└── Resources/
    ├── config/{services.php,routes.php}
    └── views/storefront/{page/demo/index.html.twig,component/hello/hello.html.twig}
```

Naming convention (core): classes end in `Controller`, `Page`, `PageLoader`, `PageLoadedEvent`, `Pagelet`, `PageletLoader`, `PageletLoadedEvent`.

## 3. Instructions

1. Create the controller under `src/Controller`, `extends StorefrontController`, class-level `#[Route(defaults: [_routeScope => [storefront]])]`, constructor injection (no `$this->container->get()`).
2. Register it in `services.php` with `->public()` and `->call('setContainer', [service('service_container')])`. Set up the attribute import in `routes.php`.
3. Start route names with `frontend.`/`widgets.`/`payment.` (otherwise `storefront.router.allowed_routes`).
4. Create Page, PageLoadedEvent, PageLoader following the pattern. Meta data is only present if the page is built via `GenericPageLoader`.
5. Pagelet analogously, but without `GenericPageLoader` (typical for parts loaded later).
6. Captcha: include `{% sw_include '@Storefront/storefront/component/captcha/base.html.twig' %}` in the form (model: `page/account/convert.html.twig`, `contact-form.html.twig`). `base.html.twig` iterates `config('core.basicInformation.activeCaptchasV2')` and includes `captcha/<key>.html.twig`. The route gets `PlatformRequest::ATTRIBUTE_CAPTCHA => true`.
7. AJAX: a JS plugin calls the route (see [T-15](./15-storefront-js-plugins.md)), the route carries `XmlHttpRequest => true` (not for normal page routes). Core pattern for POST forms: `FormController::sendContactForm` returns a `JsonResponse` with entries `['type' => 'success|danger|info', 'alert' => '<html>']`.
8. Install and verify:
```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console cache:clear
bin/console debug:router | grep swag
bin/console debug:container SwagExample\\Controller\\DemoController
```

## 4. Examples

`src/Resources/config/routes.php`
```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Controller/**/*Controller.php', 'attribute');
};
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use Shopware\Storefront\Page\GenericPageLoader;
use SwagExample\Controller\DemoController;
use SwagExample\Framework\Captcha\MyCaptcha;
use SwagExample\Page\Demo\DemoPageLoader;
use SwagExample\Pagelet\Hello\HelloPageletLoader;
use SwagExample\Subscriber\ProductPageSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(DemoPageLoader::class)
        ->args([service(GenericPageLoader::class), service('event_dispatcher')]);

    $services->set(HelloPageletLoader::class)
        ->args([service('event_dispatcher')]);

    $services->set(DemoController::class)
        ->public()
        ->args([service(DemoPageLoader::class), service(HelloPageletLoader::class)])
        ->call('setContainer', [service('service_container')]);

    $services->set(ProductPageSubscriber::class)->tag('kernel.event_subscriber');

    $services->set(MyCaptcha::class)->tag('shopware.storefront.captcha', ['priority' => 50]);
};
```
`GenericPageLoaderInterface` has no container alias in the core (only `GenericPageLoader::class` is referenced), so inject the class directly.

`src/Page/Demo/DemoPage.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Page\Demo;

use Shopware\Storefront\Page\Page;

class DemoPage extends Page
{
    protected string $headline = '';

    public function getHeadline(): string
    {
        return $this->headline;
    }

    public function setHeadline(string $headline): void
    {
        $this->headline = $headline;
    }
}
```

`src/Page/Demo/DemoPageLoadedEvent.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Page\Demo;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

class DemoPageLoadedEvent extends PageLoadedEvent
{
    public function __construct(
        protected DemoPage $page,
        SalesChannelContext $salesChannelContext,
        Request $request,
    ) {
        parent::__construct($salesChannelContext, $request);
    }

    public function getPage(): DemoPage
    {
        return $this->page;
    }
}
```

`src/Page/Demo/DemoPageLoader.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Page\Demo;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class DemoPageLoader
{
    public function __construct(
        private readonly GenericPageLoaderInterface $genericLoader,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function load(Request $request, SalesChannelContext $context): DemoPage
    {
        // Load data only via Store API routes, not via repositories.
        $page = DemoPage::createFrom($this->genericLoader->load($request, $context));
        $page->setHeadline('Hello Storefront');

        $this->eventDispatcher->dispatch(new DemoPageLoadedEvent($page, $context, $request));

        return $page;
    }
}
```

Pagelet (pattern `storefront/Pagelet/Footer/*`): `src/Pagelet/Hello/HelloPagelet.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Pagelet\Hello;

use Shopware\Storefront\Pagelet\Pagelet;

class HelloPagelet extends Pagelet
{
    public function __construct(protected string $message)
    {
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
```

`src/Pagelet/Hello/HelloPageletLoadedEvent.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Pagelet\Hello;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Pagelet\PageletLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

class HelloPageletLoadedEvent extends PageletLoadedEvent
{
    public function __construct(
        protected HelloPagelet $pagelet,
        SalesChannelContext $salesChannelContext,
        Request $request,
    ) {
        parent::__construct($salesChannelContext, $request);
    }

    public function getPagelet(): HelloPagelet
    {
        return $this->pagelet;
    }
}
```

`src/Pagelet/Hello/HelloPageletLoader.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Pagelet\Hello;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class HelloPageletLoader
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function load(Request $request, SalesChannelContext $context): HelloPagelet
    {
        $pagelet = new HelloPagelet('Hello from the pagelet');

        $this->eventDispatcher->dispatch(new HelloPageletLoadedEvent($pagelet, $context, $request));

        return $pagelet;
    }
}
```

`src/Controller/DemoController.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Controller;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use SwagExample\Page\Demo\DemoPageLoader;
use SwagExample\Pagelet\Hello\HelloPageletLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class DemoController extends StorefrontController
{
    public function __construct(
        private readonly DemoPageLoader $pageLoader,
        private readonly HelloPageletLoader $pageletLoader,
    ) {
    }

    #[Route(path: '/demo', name: 'frontend.swag.demo.page', defaults: [PlatformRequest::ATTRIBUTE_HTTP_CACHE => true], methods: ['GET'])]
    public function page(Request $request, SalesChannelContext $context): Response
    {
        $page = $this->pageLoader->load($request, $context);

        return $this->renderStorefront('@SwagExample/storefront/page/demo/index.html.twig', ['page' => $page]);
    }

    #[Route(path: '/widgets/swag/hello', name: 'widgets.swag.hello', defaults: ['XmlHttpRequest' => true], methods: ['GET'])]
    public function hello(Request $request, SalesChannelContext $context): Response
    {
        $pagelet = $this->pageletLoader->load($request, $context);

        return $this->renderStorefront('@SwagExample/storefront/component/hello/hello.html.twig', ['pagelet' => $pagelet]);
    }

    #[Route(path: '/swag/demo/ping', name: 'frontend.swag.demo.ping', defaults: ['XmlHttpRequest' => true, PlatformRequest::ATTRIBUTE_CAPTCHA => true], methods: ['POST'])]
    public function ping(): JsonResponse
    {
        return new JsonResponse([['type' => 'success', 'alert' => $this->trans('swagExample.pingOk')]]);
    }
}
```

`src/Resources/views/storefront/page/demo/index.html.twig`
```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{% block base_content %}
    <h1>{{ page.headline }}</h1>
    {% if page.extensions.swagDemo is defined %}{{ page.extensions.swagDemo.get('text') }}{% endif %}
{% endblock %}
```

`src/Resources/views/storefront/component/hello/hello.html.twig`
```twig
<p>{{ pagelet.message }}</p>
```

Enriching a page: `src/Subscriber/ProductPageSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPageLoaded'];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $event->getPage()->addExtension('swagDemo', new ArrayStruct(['text' => 'Extra data']));
    }
}
```
Twig: `{{ page.extensions.swagDemo.get('text') }}` in the product detail template (block selection see [T-14](./14-storefront-twig-templates.md)).

Captcha (validation part only): `src/Framework/Captcha/MyCaptcha.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Framework\Captcha;

use Shopware\Storefront\Framework\Captcha\AbstractCaptcha;
use Shopware\Storefront\Framework\Captcha\CaptchaException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class MyCaptcha extends AbstractCaptcha
{
    final public const CAPTCHA_NAME = 'swagMyCaptcha';

    public function validate(Request $request, array $captchaConfig): ConstraintViolationList
    {
        if ($request->request->getString('my_captcha_token') === 'expected') {
            return new ConstraintViolationList();
        }

        return new ConstraintViolationList([
            new ConstraintViolation('', '', [], '', '', '', null, CaptchaException::INVALID_CAPTCHA_ERROR),
        ]);
    }

    public function getName(): string
    {
        return self::CAPTCHA_NAME;
    }
}
```
`AbstractCaptcha::supports()` only returns `true` for POST, a non-empty `$captchaConfig` and `isActive` set (config `core.basicInformation.activeCaptchasV2[<name>]`). Without a config entry the captcha is never checked.

Further scaffolds: [../templates/plugin/README.md](../templates/plugin/README.md), [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

## 5. Pitfalls

- Missing `_routeScope`: `RoutingException::invalidRouteScope`.
- XHR without `XmlHttpRequest => true`: `accessDeniedForXmlHttpRequest` (`StorefrontSubscriber::preventPageLoadingFromXmlHttpRequest`). Set the flag only on partial/JSON routes, not on page routes.
- Controller not public or `setContainer` forgotten: service cannot be resolved, or `renderStorefront` fails (`$this->container` null).
- Repository in the controller/PageLoader instead of a Store API route: violates the core rule, bypasses caching and context rules.
- Route name without `frontend.`/`widgets.`/`payment.`: URL generation does not treat the route as a storefront route unless it is in `allowed_routes`.
- Custom page without `GenericPageLoader`: meta data (robots, title) is missing in the template.
- Mail confirmation links via GET: HEAD requests are routed as GET, use `isHeadRequest()`.
- Custom captchas without a config entry: `supports()` requires `isActive` in `activeCaptchasV2`. The captcha names are returned by `/api/_action/captcha_list` (`Api/CaptchaController`).
- XML config (`services.xml`/`routes.xml`) triggers a deprecation in 6.7 (`Bundle::triggerXmlConfigDeprecation`).
- The captcha docs page still shows `isValid()`. The code (6.7) is authoritative: implement `validate()`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `@Route`/`@RouteScope` annotations are gone. 6.7 uses `Symfony\Component\Routing\Attribute\Route` with `defaults: ['_routeScope' => [...]]`.
- `services.php`/`routes.php` preferred. XML is deprecated in 6.7, removal in 6.8 (`core/Framework/Bundle.php`). Routes: `Bundle::configureRoutes` loads, among others, `Resources/config/routes.php` and `routes/*.php`.
- Captcha: `AbstractCaptcha::validate()` is the new way. `isValid()` and `getViolations()` are `@deprecated tag:v6.8.0`. The config field is called `activeCaptchasV2`.
- `storefront.router.allowed_routes`: according to the docs since 6.7.2.0.
- Controllers dispatch `*PageLoadedHook` for app scripts (`$this->hook(...)`).
- The core folder `core/Framework/Routing/Annotation/` still exists; its content was not checked.

## 6. Checklist

- [ ] Controller extends `StorefrontController` and carries `_routeScope = storefront` on the class.
- [ ] Controller is registered `public()` with `setContainer`, `routes.php` imports the controllers.
- [ ] Route names have the prefix `frontend.`/`widgets.`/`payment.`.
- [ ] AJAX/partial routes carry `XmlHttpRequest => true`, page routes do not.
- [ ] PageLoader builds on `GenericPageLoader` and dispatches a `...PageLoadedEvent`.
- [ ] No repository access in controllers/loaders, data via Store API routes.
- [ ] Captcha routes have `_captcha => true`, the captcha implements `validate()` and is tagged.
- [ ] Existing pages are enriched via `addExtension` in the `...PageLoadedEvent`.
- [ ] `bin/console debug:router` shows the routes, cache cleared.

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/controllers/add-custom-controller.html (retrieved)
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/howto/add-custom-captcha.html (retrieved, still shows `isValid`)

Source code (relative to `vendor/shopware/`, samples for this reference confirmed against the code): `storefront/Controller/StorefrontController.php` (`renderStorefront`, `trans`, `hook`, `isHeadRequest`), `CaptchaController.php`, `FormController.php`, `ProductController.php`, `Api/CaptchaController.php`, `storefront/DependencyInjection/{controller,captcha}.php`, `Configuration.php`, `storefront/Resources/config/routes.php`, `storefront/Page/{Page,PageLoadedEvent,GenericPageLoader,GenericPageLoaderInterface}.php`, `storefront/Page/Product/ProductPageLoadedEvent.php`, `storefront/Pagelet/{Pagelet,PageletLoadedEvent}.php`, `Pagelet/Footer/FooterPageletLoader.php`, `storefront/Framework/Routing/{StorefrontRouteScope,StorefrontSubscriber,Router}.php`, `storefront/Framework/Captcha/{AbstractCaptcha,CaptchaRouteListener}.php`, `storefront/Resources/views/storefront/component/captcha/base.html.twig`, `core/Framework/Struct/CreateFromTrait.php`, `core/Framework/Routing/RouteScopeListener.php`, `core/PlatformRequest.php`, `core/Framework/Bundle.php`.

### Gaps and uncertainties

Confirmed against the code (sample): `_routeScope` attribute and `StorefrontRouteScope::ID`, `PlatformRequest` constants, `#[Route]` from `Symfony\Component\Routing\Attribute`, `CaptchaController` as a controller pattern with class route, `setContainer`/public in `controller.php`, `Page`/`PageLoadedEvent`/`Pagelet`/`PageletLoadedEvent` signatures, `createFrom` (`CreateFromTrait`), `XmlHttpRequest` check, `isStorefrontRoute` prefixes, `allowed_routes` config, `AbstractCaptcha::supports/validate/isValid`, captcha priorities and tag name, `renderStorefront` flow, no-repository comment in controller/pagelet loader.

Remaining gaps:
- ⚠ UNVERIFIED: The docs pages add-custom-page, add-custom-pagelet, add-data-to-storefront-page, add-dynamic-content-via-ajax-calls, `architecture/pageloader.html` and the guideline `/docs/resources/guidelines/code/storefront-controller.html` were not retrieved; content derived from the code.
- ⚠ UNVERIFIED: The example was not run in a live installation. Plugin-specific, unverified: that plugin controllers work with only `public()` + `setContainer` and no further tags (e.g. `controller.service_arguments`) is based on the docs and the core pattern (only the core registration was checked).
- ⚠ UNVERIFIED: The Twig namespace `@SwagExample` follows the plugin naming convention (see T-02).
- ⚠ GAP: How a plugin cleanly adds an additional captcha entry including template key in `core.basicInformation.activeCaptchasV2` and `captcha/<key>.html.twig` was not verified. `MyCaptcha` is only the validation part.
- ⚠ UNVERIFIED: File name/format of the `allowed_routes` config (presumably `Resources/config/packages/storefront.yaml`) only derived from the docs/config schema. Confirmed: plugin `Resources/config/packages/*.yaml` files are not loaded (`Bundle::build()` does not call `buildDefaultConfig()`); therefore set `allowed_routes` in the project config `config/packages/`.
- ⚠ UNVERIFIED: Classification of the 6.5 state (annotation namespaces) from memory, not checked against the 6.5 code.
- ⚠ GAP: The effect of `PlatformRequest::ATTRIBUTE_HTTP_CACHE` depends on the active HTTP cache (see T-41).
