# Template: event-subscriber (Subscriber und eigenes Event)

Shopware 6.7.15.1. Drei Subscriber (DAL-Loaded, DAL-Written plus `PreWriteValidationEvent`, Storefront-Page-Event) sowie ein eigenes Event mit Service, der es dispatcht.

## Dateibaum

```
SwagExample/
└── src/
    ├── Subscriber/
    │   ├── ProductLoadedSubscriber.php    # product.loaded (EntityLoadedEvent)
    │   ├── ProductWrittenSubscriber.php   # product.written + PreWriteValidationEvent
    │   └── ProductPageSubscriber.php      # ProductPageLoadedEvent (Storefront)
    ├── Event/
    │   └── ExampleEvent.php               # eigenes Event (ShopwareEvent)
    └── Service/
        └── ExampleService.php             # dispatcht ExampleEvent
```

Wurzel-Gerüst: ../plugin/README.md

## Platzhalter

| Platzhalter | Bedeutung | Ersetzen durch |
|---|---|---|
| `SwagExample\` | Plugin-Namespace | eigener Namespace |
| `swag_example.example` | `ExampleEvent::EVENT_NAME` (gilt nur, weil `ExampleService` ihn beim `dispatch()` explizit übergibt) | `<prefix>.<name>` |
| `swagExample`, `swagHello` | Namen der Extensions an Produkt bzw. Page | eigene Namen |
| `Product*` / Event-Konstanten | Beispiel-Events | gewünschte Events |

## Registrierung (Ausschnitt für `src/Resources/config/services.php`)

Der Subscriber ist erst aktiv, wenn der Service das Tag `kernel.event_subscriber` trägt. Die Vorlagen-Konvention ist, das Tag immer explizit zu setzen (Autoconfigure greift in Plugins nur mit `->defaults()->autowire()->autoconfigure()`, live bestätigt in Variante B der Plugin-Vorlage; ohne `defaults()` bleibt es nicht aktiv).

```php
use SwagExample\Service\ExampleService;
use SwagExample\Subscriber\ProductLoadedSubscriber;
use SwagExample\Subscriber\ProductPageSubscriber;
use SwagExample\Subscriber\ProductWrittenSubscriber;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

$services->set(ProductLoadedSubscriber::class)
    ->tag('kernel.event_subscriber');

$services->set(ProductWrittenSubscriber::class)
    ->args([service('logger')])
    ->tag('kernel.event_subscriber');

$services->set(ProductPageSubscriber::class)
    ->tag('kernel.event_subscriber');

$services->set(ExampleService::class)
    ->args([service('event_dispatcher')]);
```

Einzelner Listener statt Subscriber: `->tag('kernel.event_listener', ['event' => '...', 'method' => '...', 'priority' => 0])` (Core-Beispiel: `storefront/DependencyInjection/system.php`). XML-Variante (deprecated): `<tag name="kernel.event_subscriber"/>`, siehe ../plugin/README.md.

`ProductPageSubscriber` benötigt `shopware/storefront`. Wenn das Plugin auch ohne Storefront laufen soll, die Klasse samt Registrierung weglassen oder `shopware/storefront` in `composer.json` unter `require` ergänzen.

## Einbindung / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate -c SwagExample
bin/console cache:clear                              # nach neuem Subscriber/Tag oder geaenderten getSubscribedEvents()
bin/console debug:event-dispatcher 'product.loaded'  # Listener je Event anzeigen
```

## Regeln und Fallstricke

- Eventnamen: Der Dispatch-Name ist `$event::class`, außer der Dispatcher bekommt einen Namen. Eine `EVENT_NAME`-Konstante allein genügt nicht (`CheckoutOrderPlacedEvent` wird ohne Namen dispatcht: Listener auf `CheckoutOrderPlacedEvent::class`, nicht auf `'checkout.order.placed'`). Klassenname (`PreWriteValidationEvent::class`, `ProductPageLoadedEvent::class`, `EntityWriteEvent::class`) oder String (`'product.loaded'`, `ProductEvents::PRODUCT_LOADED_EVENT`). Nicht verwechseln; `PreWriteValidationEvent` liegt unter `...\Write\Validation\`.
- `*.written` ist zu spät für den Vorher-Zustand: dafür `EntityWriteEvent`/`EntityDeleteEvent` (`addSuccess()`/`addError()`); deren Factory `create()` nie selbst aufrufen.
- `product.loaded` ist ein Massen-Event: keine DB-Abfragen pro Entity. Storefront-Produkte erscheinen zusätzlich unter `sales_channel.product.loaded`.
- Subscriber auf `product.written`, der `product` schreibt, löst sich selbst aus: Guard einbauen. Versionierte Entities ggf. gegen `Defaults::LIVE_VERSION` prüfen.
- Events dienen zum Anreichern/Beobachten. Logik ersetzen: Dekoration, siehe ../decorator/README.md. Schwere Arbeit (Mails, HTTP) in Message-Handler auslagern.
- Eigene Events: `extends Symfony\Contracts\EventDispatcher\Event`, `implements ShopwareEvent` (`getContext()`); mit Sales-Channel-Bezug `ShopwareSalesChannelEvent`; verschachtelt `NestedEvent`. Für den Flow Builder `FlowEventAware` (nicht in der Vorlage).
- Es gibt keine String-Konstanten für Plugin-Lifecycle-Events: `PluginPostActivateEvent::class` u. a. (`core/Framework/Plugin/Event/`), siehe ../plugin/README.md.

## Verweise

- ../../references/07-events-subscriber.md
- ../../references/08-extension-points.md
- ../../references/05-services-registrieren.md
- ../../references/03-plugin-lifecycle.md
- Vorlagen: ../plugin/README.md, ../decorator/README.md, ../config/README.md

## Hinweise und Lücken

- Deprecations (Entfernung 6.8.0): `NestedEvent::getFlatEventList()`, `EntityWrittenEvent::getEvents()`/`getExistences()`; nicht verwenden. `BusinessEventInterface` existiert nicht mehr (heute `FlowEventAware`).
- Geprüft gegen den Quellcode: `ProductEvents`-Konstanten, `EntityLoadedEvent`, `EntityWrittenEvent`, `PreWriteValidationEvent` (Methoden `getCommandsForEntity`, `getExceptions`), `NestedEventDispatcher::dispatch(object, ?string)`, `ShopwareEvent`.
- Live bestätigt (Review-Runden 1–3): Die Subscriber sind registriert (`bin/console debug:event-dispatcher product.loaded`). Belegt am Code: `WriteException::add(\Throwable)` und `tryToThrow()` in `EntityWriteGateway::validateCommands()` nach dem Dispatch.
- ⚠ UNGEPRÜFT: Doppelte Registrierung bei Autoconfigure plus manuellem Tag (Symfony-Verhalten; Subscriber mit `defaults()` laufen live, ein Doppelaufruf wurde nicht gezielt geprüft).
- ⚠ LÜCKE: Business-/Flow-Events (`FlowEventAware`, `BusinessEventCollectorEvent`), Kernel-Events, Cart-Events und `#[AsEventListener]` sind nur in der Referenz beschrieben, nicht als Vorlage enthalten.
