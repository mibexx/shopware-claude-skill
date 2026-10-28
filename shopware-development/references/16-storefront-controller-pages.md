# Storefront-Controller, Pages und Pagelets (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eine eigene Storefront-Route/-Seite entsteht, Page-Daten per PageLoader/Pagelet bereitgestellt, bestehende Seiten angereichert, AJAX-Endpunkte gebaut oder Captchas eingebunden werden.
Abgrenzung: Twig-Templates siehe [T-14](./14-storefront-twig-templates.md), JS/AJAX-Client siehe [T-15](./15-storefront-js-plugins.md), Store-API-Routen (Datenquelle für Loader) siehe [T-30](./30-store-api-routen.md), Events/Subscriber allgemein siehe [T-07](./07-events-subscriber.md), Services siehe [T-05](./05-services-registrieren.md), HTTP-Cache siehe [T-41](./41-caching.md), Login-/Kontext-Unterscheidung siehe [T-20](./20-sales-channel-unterscheidung.md).

## 2. Konzepte

- **Controller:** erbt von `Shopware\Storefront\Controller\StorefrontController` (abstract, erbt Symfony `AbstractController`). Am Klassenkopf: `#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]` (`_routeScope` = `storefront`, am Klassen- oder Methoden-`#[Route]` möglich). Ohne Scope bricht `RouteScopeListener::checkScope` mit `RoutingException::invalidRouteScope` ab.
- **Registrierung:** Der Controller muss ein **public Service** mit `->call('setContainer', [service('service_container')])` sein (Core: `storefront/DependencyInjection/controller.php` setzt `defaults()->public()` und `setContainer`). Routen werden per Attribut-Import in `Resources/config/routes.php` gefunden (Core: `$routes->import('../../Controller/**/*Controller.php', 'attribute')`).
- **Rendern:** `$this->renderStorefront('@Namespace/storefront/page/....html.twig', [...])` dispatcht `StorefrontRenderEvent`, ersetzt SEO-/Media-Platzhalter und setzt `Content-Type: text/html`. Weitere Helfer: `renderView`, `forwardToRoute`, `createActionResponse`, `redirectToRoute`, `hook`, `isHeadRequest`, `trans`.
- **Page** = Struct, das `Shopware\Storefront\Page\Page` erweitert (`metaInformation`). **PageLoader** baut sie: `GenericPageLoaderInterface::load()` liefert die Basis-`Page`, `XyzPage::createFrom($page)` (aus `CreateFromTrait` in `Struct`) erweitert sie, danach wird ein `XyzPageLoadedEvent` (erweitert `PageLoadedEvent`, abstract `getPage()`) dispatcht. **Pagelet** = `Pagelet extends Struct` mit eigenem `PageletLoader` und `PageletLoadedEvent` (Core: Header, Footer, Menu, Newsletter, Wishlist, Country, Captcha).
- **Page anreichern:** Subscriber auf `XyzPageLoadedEvent` (z. B. `ProductPageLoadedEvent`) oder `GenericPageLoadedEvent`, dann `$event->getPage()->addExtension('name', $struct)`. In Twig: `page.extensions.name`.
- **AJAX:** Route mit `defaults: ['XmlHttpRequest' => true]`. Ohne Flag wirft `StorefrontSubscriber::preventPageLoadingFromXmlHttpRequest` bei XHR auf Storefront-Scope-Routen `RoutingException::accessDeniedForXmlHttpRequest`. Antwort: `JsonResponse` oder gerendertes Partial.
- **Routen-Namen:** Der Storefront-`Router` behandelt Routen mit Präfix `frontend.`, `widgets.`, `payment.` als Storefront-Routen (`isStorefrontRoute`). Andere Namen nur, wenn sie im Parameter `storefront.router.allowed_routes` stehen (`Configuration.php`).
- **PlatformRequest-Konstanten** (`core/PlatformRequest.php`): `ATTRIBUTE_ROUTE_SCOPE` (`_routeScope`), `ATTRIBUTE_CAPTCHA` (`_captcha`), `ATTRIBUTE_HTTP_CACHE` (`_httpCache`), `ATTRIBUTE_NO_STORE` (`_noStore`), `ATTRIBUTE_LOGIN_REQUIRED` (`_loginRequired`), `ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST`.
- **Captcha:** Route-Default `_captcha => true` aktiviert `CaptchaRouteListener::validateCaptcha`. Es geht alle mit `shopware.storefront.captcha` getaggten Captchas durch (`supports()` dann `validate()`). Bei Fehler: Non-XHR und `shouldBreak()` = true → `CaptchaException::invalid`, sonst ersetzt `ErrorController::onCaptchaFailure` den Controller (JSON mit Fehlern). Core-Prioritäten: Honeypot 400, BasicCaptcha 300, reCaptcha v2 200, v3 100.
- **Leitplanke (Kommentar in `CaptchaController`, `FooterPageletLoader`, `WishlistPageLoader`):** „Do not use direct or indirect repository calls in a controller / PageLoader / PageletLoader. Always use a store-api route.“

Verzeichnisbaum:

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

Namenskonvention (Core): Klassen enden auf `Controller`, `Page`, `PageLoader`, `PageLoadedEvent`, `Pagelet`, `PageletLoader`, `PageletLoadedEvent`.

## 3. Anleitung

1. Controller unter `src/Controller` anlegen, `extends StorefrontController`, Klassen-`#[Route(defaults: [_routeScope => [storefront]])]`, Konstruktor-Injection (kein `$this->container->get()`).
2. In `services.php` mit `->public()` und `->call('setContainer', [service('service_container')])` registrieren. In `routes.php` den Attribut-Import setzen.
3. Routen-Namen mit `frontend.`/`widgets.`/`payment.` beginnen lassen (sonst `storefront.router.allowed_routes`).
4. Page, PageLoadedEvent, PageLoader nach Muster anlegen. Meta-Daten kommen nur, wenn die Page über `GenericPageLoader` aufgebaut wird.
5. Pagelet analog, aber ohne `GenericPageLoader` (typisch für nachladbare Teile).
6. Captcha: im Formular `{% sw_include '@Storefront/storefront/component/captcha/base.html.twig' %}` einbinden (Vorbild `page/account/convert.html.twig`, `contact-form.html.twig`). `base.html.twig` iteriert `config('core.basicInformation.activeCaptchasV2')` und inkludiert `captcha/<key>.html.twig`. Die Route bekommt `PlatformRequest::ATTRIBUTE_CAPTCHA => true`.
7. AJAX: JS-Plugin ruft die Route auf (siehe [T-15](./15-storefront-js-plugins.md)), Route trägt `XmlHttpRequest => true` (nicht für normale Seiten-Routen). Core-Muster für POST-Formulare: `FormController::sendContactForm` liefert `JsonResponse` mit Einträgen `['type' => 'success|danger|info', 'alert' => '<html>']`.
8. Installieren und prüfen:
```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console cache:clear
bin/console debug:router | grep swag
bin/console debug:container SwagExample\\Controller\\DemoController
```

## 4. Beispiele

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
`GenericPageLoaderInterface` hat im Core keinen Container-Alias (nur `GenericPageLoader::class` wird referenziert), daher die Klasse direkt injizieren.

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
        // Daten nur über Store-API-Routen laden, nicht über Repositories.
        $page = DemoPage::createFrom($this->genericLoader->load($request, $context));
        $page->setHeadline('Hello Storefront');

        $this->eventDispatcher->dispatch(new DemoPageLoadedEvent($page, $context, $request));

        return $page;
    }
}
```

Pagelet (Muster `storefront/Pagelet/Footer/*`): `src/Pagelet/Hello/HelloPagelet.php`
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
        $pagelet = new HelloPagelet('Hallo vom Pagelet');

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

Seite anreichern: `src/Subscriber/ProductPageSubscriber.php`
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
        $event->getPage()->addExtension('swagDemo', new ArrayStruct(['text' => 'Extra-Daten']));
    }
}
```
Twig: `{{ page.extensions.swagDemo.get('text') }}` im Produktdetail-Template (Block-Auswahl siehe [T-14](./14-storefront-twig-templates.md)).

Captcha (nur Validierungsteil): `src/Framework/Captcha/MyCaptcha.php`
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
`AbstractCaptcha::supports()` liefert nur `true` bei POST, nicht leerer `$captchaConfig` und gesetztem `isActive` (Config `core.basicInformation.activeCaptchasV2[<name>]`). Ohne Config-Eintrag wird das Captcha nie geprüft.

Weitere Gerüste: [../templates/plugin/README.md](../templates/plugin/README.md), [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

## 5. Fallstricke

- Fehlender `_routeScope`: `RoutingException::invalidRouteScope`.
- XHR ohne `XmlHttpRequest => true`: `accessDeniedForXmlHttpRequest` (`StorefrontSubscriber::preventPageLoadingFromXmlHttpRequest`). Das Flag nur auf Partial-/JSON-Routen setzen, nicht auf Seiten-Routen.
- Controller nicht public oder `setContainer` vergessen: Service nicht auflösbar bzw. `renderStorefront` scheitert (`$this->container` null).
- Repository im Controller/PageLoader statt Store-API-Route: verstößt gegen die Core-Vorgabe, umgeht Caching- und Kontext-Regeln.
- Routenname ohne `frontend.`/`widgets.`/`payment.`: URL-Generierung behandelt die Route nicht als Storefront-Route, sofern nicht in `allowed_routes`.
- Eigene Page ohne `GenericPageLoader`: Meta-Daten (Robots, Titel) fehlen im Template.
- Mail-Bestätigungslinks per GET: HEAD-Requests werden als GET geroutet, `isHeadRequest()` nutzen.
- Eigene Captchas ohne Config-Eintrag: `supports()` verlangt `isActive` in `activeCaptchasV2`. Namen der Captchas liefert `/api/_action/captcha_list` (`Api/CaptchaController`).
- XML-Konfig (`services.xml`/`routes.xml`) löst in 6.7 eine Deprecation aus (`Bundle::triggerXmlConfigDeprecation`).
- Doku-Seite Captcha zeigt noch `isValid()`. Der Code (6.7) gilt: `validate()` implementieren.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `@Route`/`@RouteScope`-Annotationen sind weg. 6.7 nutzt `Symfony\Component\Routing\Attribute\Route` mit `defaults: ['_routeScope' => [...]]`.
- `services.php`/`routes.php` bevorzugt. XML ist in 6.7 deprecated, Entfernung 6.8 (`core/Framework/Bundle.php`). Routen: `Bundle::configureRoutes` lädt u. a. `Resources/config/routes.php` und `routes/*.php`.
- Captcha: `AbstractCaptcha::validate()` ist der neue Weg. `isValid()` und `getViolations()` sind `@deprecated tag:v6.8.0`. Das Config-Feld heißt `activeCaptchasV2`.
- `storefront.router.allowed_routes`: laut Doku ab 6.7.2.0.
- Controller dispatchen `*PageLoadedHook` für App-Scripts (`$this->hook(...)`).
- Der Core-Ordner `core/Framework/Routing/Annotation/` existiert noch, wurde inhaltlich nicht geprüft.

## 6. Checkliste

- [ ] Controller erbt `StorefrontController` und trägt `_routeScope = storefront` am Klassenkopf.
- [ ] Controller ist `public()` mit `setContainer` registriert, `routes.php` importiert die Controller.
- [ ] Routen-Namen haben Präfix `frontend.`/`widgets.`/`payment.`.
- [ ] AJAX-/Partial-Routen tragen `XmlHttpRequest => true`, Seiten-Routen nicht.
- [ ] PageLoader baut auf `GenericPageLoader` auf und dispatcht ein `...PageLoadedEvent`.
- [ ] Keine Repository-Zugriffe in Controller/Loadern, Daten über Store-API-Routen.
- [ ] Captcha-Routen haben `_captcha => true`, Captcha implementiert `validate()` und ist getaggt.
- [ ] Anreicherung bestehender Seiten über `addExtension` im `...PageLoadedEvent`.
- [ ] `bin/console debug:router` zeigt die Routen, Cache geleert.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/controllers/add-custom-controller.html (abgerufen)
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/howto/add-custom-captcha.html (abgerufen, zeigt noch `isValid`)

Quellcode (relativ zu `vendor/shopware/`, Stichproben für diese Referenz am Code bestätigt): `storefront/Controller/StorefrontController.php` (`renderStorefront`, `trans`, `hook`, `isHeadRequest`), `CaptchaController.php`, `FormController.php`, `ProductController.php`, `Api/CaptchaController.php`, `storefront/DependencyInjection/{controller,captcha}.php`, `Configuration.php`, `storefront/Resources/config/routes.php`, `storefront/Page/{Page,PageLoadedEvent,GenericPageLoader,GenericPageLoaderInterface}.php`, `storefront/Page/Product/ProductPageLoadedEvent.php`, `storefront/Pagelet/{Pagelet,PageletLoadedEvent}.php`, `Pagelet/Footer/FooterPageletLoader.php`, `storefront/Framework/Routing/{StorefrontRouteScope,StorefrontSubscriber,Router}.php`, `storefront/Framework/Captcha/{AbstractCaptcha,CaptchaRouteListener}.php`, `storefront/Resources/views/storefront/component/captcha/base.html.twig`, `core/Framework/Struct/CreateFromTrait.php`, `core/Framework/Routing/RouteScopeListener.php`, `core/PlatformRequest.php`, `core/Framework/Bundle.php`.

### Lücken und Unsicherheiten

Am Code bestätigt (Stichprobe): `_routeScope`-Attribut und `StorefrontRouteScope::ID`, `PlatformRequest`-Konstanten, `#[Route]` aus `Symfony\Component\Routing\Attribute`, `CaptchaController` als Controller-Muster mit Klassen-Route, `setContainer`/public in `controller.php`, `Page`/`PageLoadedEvent`/`Pagelet`/`PageletLoadedEvent`-Signaturen, `createFrom` (`CreateFromTrait`), `XmlHttpRequest`-Prüfung, `isStorefrontRoute`-Präfixe, `allowed_routes`-Config, `AbstractCaptcha::supports/validate/isValid`, Captcha-Prioritäten und Tag-Name, `renderStorefront`-Ablauf, Kein-Repository-Kommentar in Controller/Pagelet-Loader.

Verbleibende Lücken:
- ⚠ UNGEPRÜFT: Doku-Seiten add-custom-page, add-custom-pagelet, add-data-to-storefront-page, add-dynamic-content-via-ajax-calls, `architecture/pageloader.html` und die Guideline `/docs/resources/guidelines/code/storefront-controller.html` wurden nicht abgerufen, Inhalt aus dem Code abgeleitet.
- ⚠ UNGEPRÜFT: Das Beispiel wurde nicht in einer laufenden Installation ausgeführt. Plugin-spezifisch ungeprüft: Dass Plugin-Controller ohne weitere Tags (z. B. `controller.service_arguments`) allein mit `public()` + `setContainer` funktionieren, stützt sich auf Doku und Core-Muster (nur Core-Registrierung geprüft).
- ⚠ UNGEPRÜFT: Twig-Namespace `@SwagExample` folgt der Plugin-Namenskonvention (siehe T-02).
- ⚠ LÜCKE: Wie ein Plugin einen zusätzlichen Captcha-Eintrag samt Template-Key in `core.basicInformation.activeCaptchasV2` und `captcha/<key>.html.twig` sauber ergänzt, wurde nicht verifiziert. `MyCaptcha` ist nur der Validierungsteil.
- ⚠ UNGEPRÜFT: Dateiname/Format der `allowed_routes`-Config (vermutlich `Resources/config/packages/storefront.yaml`) nur aus Doku/Config-Schema abgeleitet. Belegt: Plugin-`Resources/config/packages/*.yaml` werden nicht geladen (`Bundle::build()` ruft `buildDefaultConfig()` nicht auf); `allowed_routes` daher in der Projekt-Config `config/packages/` setzen.
- ⚠ UNGEPRÜFT: Einordnung der 6.5-Stände (Annotation-Namespaces) aus Erinnerung, nicht am 6.5-Code geprüft.
- ⚠ LÜCKE: Die `PlatformRequest::ATTRIBUTE_HTTP_CACHE`-Wirkung hängt am aktiven HTTP-Cache (siehe T-41).
