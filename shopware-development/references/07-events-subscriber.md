# Events und Subscriber (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin auf Ereignisse im Core reagieren (Entities laden/schreiben, Storefront-Seiten, Warenkorb, HTTP) oder eigene Events dispatchen soll. Events dienen zum Anreichern und Beobachten; Logik ersetzen geschieht per Dekoration ([T-06 Service-Dekoration](./06-service-dekoration.md)).

Abgrenzung: typisierte Ersetz-/Nachbearbeitungs-Hooks mit Ergebnis sind Extension Points ([T-08](./08-extension-points.md)); fachliche Events als Flow-Trigger siehe [T-09 Flow Builder](./09-flow-builder.md); Service-Registrierung allgemein in [T-05](./05-services-registrieren.md); DAL-Lese-/Schreib-API in [T-28](./28-dal-lesen-schreiben.md); Script-Hooks sind App-Feature ([T-10](./10-app-system-abgrenzung.md)).

## 2. Konzepte

- Shopware nutzt den Symfony-EventDispatcher. Service `event_dispatcher` ist ein `Shopware\Core\Framework\Event\NestedEventDispatcher` (`core/Framework/DependencyInjection/event.php`: `->decorate('event_dispatcher')`), der den Symfony-`EventDispatcher` (`framework-bundle`, Service `event_dispatcher`) umhüllt. `dispatch($event, $name)` ruft zuerst rekursiv alle verschachtelten Events (`NestedEvent::getEvents()`) auf, bei verschachtelten `GenericEvent` mit deren `getName()` als Name, und delegiert dann unverändert (`$event`, `$name`) an den inneren Dispatcher. Der Symfony-`EventDispatcher` nimmt ohne Namen `$event::class`. Im Debug-Modus kommt zusätzlich `debug.event_dispatcher` (`TraceableEventDispatcher`) als weiterer Decorator hinzu; die Reihenfolge der Decorator-Kette ist nicht geprüft.
- Eventnamen sind **Klassennamen** (`ProductPageLoadedEvent::class`, `EntityWriteEvent::class`, `PreWriteValidationEvent::class`) oder **Strings** (nur DAL-Events wie `'product.loaded'`). Der Dispatch-Name ist `$event::class`, sofern der Dispatcher keinen Namen bekommt; `getName()` oder eine `EVENT_NAME`-Konstante ändern daran nichts. Ein String-Name gilt nur bei verschachtelten `GenericEvent` (`getName()`, z. B. `product.loaded`) oder wenn der Aufrufer ihn explizit mitgibt (`dispatch($event, 'name')`). Beispiel: `CheckoutOrderPlacedEvent` hat `EVENT_NAME = 'checkout.order.placed'`, `CartOrderRoute` dispatcht es aber ohne Namen (`core/Checkout/Cart/SalesChannel/CartOrderRoute.php`, `dispatch($event)`): ein Listener auf den String `'checkout.order.placed'` feuert nie, `CheckoutOrderPlacedEvent::class` verwenden. (Der String dient dem Flow Builder, siehe [T-09](./09-flow-builder.md).)
- Subscriber: Klasse mit `EventSubscriberInterface::getSubscribedEvents()`, Service mit Tag `kernel.event_subscriber`. Format `Event => 'methode'` oder `Event => ['methode', priority]`. Einzelner Listener: Tag `kernel.event_listener` mit `event`, `method`, `priority`.

| Familie | Basis | Beispiel | Zweck |
|---|---|---|---|
| Kernel | Symfony `KernelEvent` | `KernelEvents::REQUEST` | HTTP-Lebenszyklus, Header |
| Page/Storefront | `Storefront\Page\PageLoadedEvent` (`NestedEvent`, `ShopwareSalesChannelEvent`; `getPage()`, `getRequest()`), `StorefrontRenderEvent` | `ProductPageLoadedEvent`, `GenericPageLoadedEvent` | Page-Struct erweitern (Extensions), Template-Parameter, Criteria |
| Business/Flow | `FlowEventAware` | `CheckoutOrderPlacedEvent`, `MailBeforeSentEvent` | fachliche Ereignisse, Flow-Trigger |
| DAL | `NestedEvent`+`GenericEvent` bzw. `Event`+`ShopwareEvent` | `product.written`, `PreWriteValidationEvent` | Persistenz-Lebenszyklus |
| Cart | `core/Checkout/Cart/Event/*` | `BeforeLineItemAddedEvent`, `CartChangedEvent` | Warenkorb/Checkout ([T-36](./36-warenkorb.md)) |

(Die Tabelle stammt aus dem Quellcode, nicht aus der Doku.)

DAL-Events (`core/Framework/DataAbstractionLayer/Event/`):
- `EntityLoadedEvent` (`<entity>.loaded`, `getEntities()`), `PartialEntityLoadedEvent` (`<entity>.partial_loaded`), Sales-Channel-Variante `sales_channel.<entity>.loaded`.
- `EntityWrittenEvent` (`<entity>.written`, `getIds()`, `getWriteResults()`, `getPayloads()`; erst NACH dem Schreiben), `EntityDeletedEvent` (`<entity>.deleted`), `EntityWrittenContainerEvent` (Rückgabe von `create/update/upsert`).
- `EntityWriteEvent` (Klassenname; VOR dem Schreiben eines Command-Batches; `getCommands()`, `getCommandsForEntity()`, `getIds($entity)`, `addSuccess()`/`addError()`), `EntityDeleteEvent` (vor Löschbefehlen). Beide haben private Konstruktoren (`create()` intern): Plugins erzeugen sie nicht.
- `PreWriteValidationEvent` liegt unter `...\DataAbstractionLayer\Write\Validation\` (nicht `Event`); dispatcht innerhalb der Transaktion; `getCommands()`, `getCommandsForEntity()`, `getPrimaryKeys()`, `getExceptions()`.
- Such-Events: `EntitySearchResultLoadedEvent` (`<entity>.search.result.loaded`), `EntityAggregationResultLoadedEvent`, `EntityIdSearchResultLoadedEvent`, `EntitySearchedEvent`.

HTTP-nahe Shopware-Events: `BeforeSendResponseEvent` (auch bei gecachten Antworten), `BeforeSendRedirectResponseEvent`, `Storefront\Event\StorefrontRedirectEvent`. Weitere Storefront-Events in `storefront/Event/` (`StorefrontRenderEvent`, `SwitchBuyBoxVariantEvent`, `ThemeCompilerConcatenatedStylesEvent`, `MaintenanceRedirectEvent`); Seiten-Events in `storefront/Page/<Bereich>/*PageLoadedEvent.php`, davor jeweils ein `*PageCriteriaEvent`.

Verzeichnisbaum:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Event/ExampleEvent.php
    ├── Service/ExampleService.php
    ├── Subscriber/{ProductLoadedSubscriber,ProductWrittenSubscriber,ProductPageSubscriber}.php
    └── Resources/config/services.php
```

## 3. Anleitung

1. **Event finden**: Konstantenklassen `*Events.php` (z. B. `core/Content/Product/ProductEvents.php`), `BusinessEvents.php`; `grep -rn "extends NestedEvent\|extends Event\|implements FlowEventAware" vendor/shopware`; `grep -rn "eventDispatcher->dispatch" ...`. Laufzeit: `bin/console debug:event-dispatcher 'product.loaded'` (Symfony-Standard, siehe Lücken), Symfony-Profiler-Tab "Events". Business-Events: `bin/console debug:business-events`.
2. **Subscriber** schreiben: `implements EventSubscriberInterface`, statisches `getSubscribedEvents()`, Abhängigkeiten per Konstruktor (`private readonly`).
3. **Registrieren** als Service mit Tag `kernel.event_subscriber` (immer explizit, siehe Lücken).
4. **CLI**:
   ```
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/console cache:clear
   bin/console debug:event-dispatcher 'product.loaded'
   ```
   Nach Änderungen an Service-Definition oder `getSubscribedEvents()` den Container-Cache leeren.
5. **Eigenes Event**: `extends Symfony\Contracts\EventDispatcher\Event implements ShopwareEvent` (`getContext(): Context`); bei Sales-Channel-Bezug `ShopwareSalesChannelEvent`; verschachtelt `extends NestedEvent` mit `getEvents()`. `EventDispatcherInterface` (Service `event_dispatcher`) injizieren und `dispatch(new ExampleEvent(...))`, optional mit festem Namen als zweitem Argument.
6. **Flow-fähige Events** (`FlowEventAware`, `#[IsFlowEventAware]` an Aware-Interfaces): siehe [T-09](./09-flow-builder.md). Das Attribut ist Marker für Aware-Interfaces, nicht für Event-Klassen.

## 4. Beispiele

`src/Subscriber/ProductLoadedSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductLoadedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_LOADED_EVENT => 'onProductLoaded',
        ];
    }

    /**
     * @param EntityLoadedEvent<ProductEntity> $event
     */
    public function onProductLoaded(EntityLoadedEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        foreach ($event->getEntities() as $product) {
            $product->addExtension('swagExample', new ArrayStruct(['loaded' => true]));
        }
    }
}
```

`src/Subscriber/ProductWrittenSubscriber.php` (Written + PreWriteValidation)
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductWrittenSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            PreWriteValidationEvent::class => 'onPreWrite',
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        $this->logger->info('Products written', ['ids' => $event->getIds()]);
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommandsForEntity(ProductDefinition::ENTITY_NAME) as $command) {
            if ($command instanceof InsertCommand) {
                // $command->getPayload() prüfen; Fehler per $event->getExceptions()->add(new \RuntimeException(...)) anhängen (WriteException::add(\Throwable), geworfen in EntityWriteGateway::validateCommands)
            }
        }
    }
}
```

`src/Subscriber/ProductPageSubscriber.php` (Page-Event; Twig: `{{ page.extensions.swagHello.text }}`)
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
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $event->getPage()->addExtension('swagHello', new ArrayStruct(['text' => 'Hallo']));
    }
}
```

`src/Event/ExampleEvent.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Event;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Symfony\Contracts\EventDispatcher\Event;

class ExampleEvent extends Event implements ShopwareEvent
{
    // Nur gültig, wenn der Aufrufer ihn beim dispatch() explizit angibt.
    final public const EVENT_NAME = 'swag_example.example';

    public function __construct(
        private readonly string $payload,
        private readonly Context $context
    ) {
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
```

`src/Service/ExampleService.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Context;
use SwagExample\Event\ExampleEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ExampleService
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function run(string $payload, Context $context): void
    {
        $this->eventDispatcher->dispatch(new ExampleEvent($payload, $context));
        // oder mit festem Namen: dispatch($event, ExampleEvent::EVENT_NAME); dann feuern NUR Listener auf diesen String, nicht auf ExampleEvent::class
    }
}
```

`src/Resources/config/services.php` (PHP-Format; XML ist in 6.7 deprecated, siehe Abschnitt 5)
```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SwagExample\Subscriber\ProductLoadedSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(SwagExample\Subscriber\ProductWrittenSubscriber::class)
        ->args([service('logger')])
        ->tag('kernel.event_subscriber');

    $services->set(SwagExample\Subscriber\ProductPageSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(SwagExample\Service\ExampleService::class)
        ->args([service('event_dispatcher')]);
};
```

Gerüst: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md). Plugin-Grundgerüst: [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Fallstricke

- Subscriber ohne Tag `kernel.event_subscriber` wird nie aufgerufen (Core registriert so, z. B. `core/Content/DependencyInjection/product.php`).
- Klassenbasierte Events (`EntityWriteEvent::class`, `PreWriteValidationEvent::class`, Page-Events) nicht mit Strings verwechseln; `PreWriteValidationEvent` liegt unter `Write\Validation`.
- `*.written` für den Vorher-Zustand ist zu spät: dafür `EntityWriteEvent`/`EntityDeleteEvent` mit `addSuccess()`/`addError()`.
- In `PreWriteValidationEvent` keine Repository-Writes (laufende Transaktion, Rekursion/Deadlock-Gefahr); nur validieren. (Empfehlung, nicht am Code belegt.)
- Endlosschleife: Subscriber auf `product.written`, der selbst `product` schreibt, löst sich erneut aus; mit Guard (Context-State, Feldvergleich) absichern.
- `product.loaded` läuft auch im Admin-Kontext, bei Varianten und in Massenladungen: keine DB-Abfrage pro Entity. Für Storefront-Produkte zusätzlich `sales_channel.product.loaded` und `product.partial_loaded` beachten.
- Versionierte Entities: bei Bedarf `$event->getContext()->getVersionId() === Defaults::LIVE_VERSION` prüfen (Doku "listening-to-events"), sonst läuft der Subscriber auch bei Draft-Versionen.
- Logik "ersetzen" per Event geht nicht; Dekoration nutzen ([T-06](./06-service-dekoration.md)) oder Extension Point ([T-08](./08-extension-points.md)).
- Schwere Arbeit (Mail, HTTP) nicht synchron im Subscriber; in Message-Handler auslagern ([T-34](./34-message-queue.md)).
- Container-Cache nach neuem Subscriber nicht geleert.
- Doku nennt `BusinessEventInterface` als Suchbegriff (finding-events): existiert in 6.7.15.1 nicht mehr (kein Treffer in `core`/`storefront`); heute `FlowEventAware`.
- `EntityWriteEvent::create()`/`EntityDeleteEvent::create()` sind interne Factories.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `@deprecated tag:v6.8.0`: `NestedEvent::getFlatEventList()`, Teilmethode in `NestedEventCollection`, `EntityWrittenEvent::$events`/`$existences` samt Gettern (`getEvents()`, `getExistences()`), zugehörige Methode in `EntityWrittenContainerEvent` (Begründung im Code: "unused").
- XML-Service-Definitionen im Plugin sind in 6.7 deprecated (`Bundle::triggerXmlConfigDeprecation`, 6.8 lädt keine XML mehr): `services.php` (alternativ `services.yaml`) verwenden. Geprüft: `Bundle::registerContainerFile` lädt XML, YAML und PHP.
- Neu im Event-Set 6.7 (im Code vorhanden): `DocumentGeneratedEvent`, `DocumentDeletedEvent` (DocumentV2), `CustomerPasswordChangedEvent`, `RevocationRequestEvent`. ⚠ UNGEPRÜFT: ob sie in 6.6 fehlten.

## 6. Checkliste

- [ ] Eventname korrekt (Klasse vs. String; DAL: `<entity>.loaded` usw.)
- [ ] Subscriber als Service mit Tag `kernel.event_subscriber` registriert
- [ ] Service-Datei im PHP-Format (`services.php`), nicht XML
- [ ] Methodensignatur nutzt exakt die Event-Klasse
- [ ] Keine schwere/synchrone Arbeit und keine Einzel-Queries pro Entity in `*.loaded`
- [ ] Selbstauslösende Writes (`*.written` schreibt dieselbe Entity) abgesichert
- [ ] Bei versionierten Entities `LIVE_VERSION` geprüft, falls relevant
- [ ] Vorher-Zustand nur über `EntityWriteEvent`/`EntityDeleteEvent`
- [ ] `plugin:refresh`, `cache:clear` ausgeführt, Listener per `debug:event-dispatcher` kontrolliert
- [ ] Für Logik-Ersatz stattdessen Dekoration/Extension Point erwogen

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/event/listening-to-events.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/event/finding-events.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/event/add-custom-event.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/data-handling/using-database-events.html
- https://developer.shopware.com/docs/guides/plugins/plugins/architecture/events.html

Quellcode (`vendor/shopware/`):
- `core/Framework/DependencyInjection/event.php` (Decorator `NestedEventDispatcher`); `vendor/symfony/event-dispatcher/EventDispatcher.php` (`dispatch`: `$eventName ??= $event::class`)
- `core/Framework/Event/{NestedEventDispatcher,NestedEvent,ShopwareEvent,GenericEvent,ShopwareSalesChannelEvent,FlowEventAware,IsFlowEventAware,BusinessEventRegistry,BeforeSendResponseEvent}.php`
- `core/Framework/DataAbstractionLayer/Event/{EntityLoadedEvent,EntityWrittenEvent,EntityWrittenContainerEvent,EntityWriteEvent,EntityDeleteEvent,EntityDeletedEvent}.php`
- `core/Framework/DataAbstractionLayer/Write/Validation/PreWriteValidationEvent.php`; Dispatch in `core/Framework/DataAbstractionLayer/Dbal/EntityWriteGateway.php` (Z. 109, 134, 140)
- `core/Content/Product/ProductEvents.php`, `core/Content/DependencyInjection/product.php`
- `core/Checkout/Cart/Event/CheckoutOrderPlacedEvent.php`, `core/Framework/Routing/{CoreSubscriber,RouteEventSubscriber}.php`
- `storefront/Page/PageLoadedEvent.php`, `storefront/Page/Product/{ProductPageLoadedEvent,ProductPageLoader}.php`, `storefront/Event/StorefrontRenderEvent.php`
- `core/Framework/Bundle.php` (`registerContainerFile`)

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: `bin/console debug:event-dispatcher` wurde nicht ausgeführt (kein Container-Zugriff); Symfony-Standardbefehl, Annahme.
- Autoconfiguration von `EventSubscriberInterface` greift in Plugins nur mit `->defaults()->autowire()->autoconfigure()` (live bestätigt); ohne `defaults()` nicht aktiv, Tag deshalb im Zweifel explizit setzen.
- Belegt: `PreWriteValidationEvent::getExceptions()` liefert die `WriteException` des `WriteContext`; `add(\Throwable): WriteException` (`WriteException.php`), `tryToThrow()` wirft sie in `EntityWriteGateway::validateCommands()` direkt nach dem Dispatch (Z. 140-146, 833) und bricht die Transaktion ab. Für Feldfehler besser `WriteConstraintViolationException` (`core/Framework/Validation/`) verwenden; nicht ausgeführt.
- ⚠ UNGEPRÜFT: Alle Beispiele nur gegen Quellcode-Signaturen geprüft, nicht in einer laufenden Instanz getestet.
- ⚠ LÜCKE: 6.5/6.6-Verhalten (Entfernung `BusinessEventInterface`, älteres `BeforeDeleteEvent`) nicht ausgewertet.
- Die Doku-Seite "architecture/events" unterscheidet Kernel-/Business-/Page-Events nicht ausdrücklich; Familientabelle stammt aus dem Code.
