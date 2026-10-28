# Bestellungen, State Machine und Dokumente (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn Zustände von Bestellung, Zahlung oder Lieferung gewechselt oder überwacht werden, eigene State Machines/Zustände entstehen, Bestellungen erweitert werden oder ein eigener Dokumenttyp (Rechnung, Lieferschein, Angebot) gebraucht wird.

Abgrenzung: Events allgemein in [T-07 Events/Subscriber](./07-events-subscriber.md); Flow-Builder-Aktionen in [T-09 Flow Builder](./09-flow-builder.md); Mails zu Zustandswechseln in [T-35 Mail-Templates](./35-mail-templates.md); Entity-Definitionen in [T-27 DAL-Entities](./27-dal-entities.md); Migrationen in [T-04 Migrationen](./04-datenbank-migrationen.md); Zahlungsarten in [T-37 Payment](./37-payment-integration.md); Dokumente über Apps nur in [T-10 App-System](./10-app-system-abgrenzung.md).

## 2. Konzepte

- **Transitions nur über `StateMachineRegistry::transition(new Transition($entity, $id, $actionName, 'stateId'), $context)`.** Die Registry sperrt (`StateMachineLocker`), prüft den Übergang, schreibt `state_machine_history` und das Entity-Feld in einer Transaktion und dispatcht danach Events. Nie `stateId` direkt per Repository schreiben.
- **Events nach einem Wechsel** (nur wenn wirklich gewechselt wurde, `hasTransitioned`):
  1. `StateMachineTransitionEvent` (Klassenname = Event-Name).
  2. `StateMachineStateChangeEvent`, Name `state_machine.<technicalName>_changed`, je einmal mit Seite `state_leave` und `state_enter`. `getStateEventName()` liefert z. B. `state_enter.order.state.completed`.
  3. Für Bestellungen übersetzt `Checkout/Order/Listener/OrderStateChangeEventListener` (hört auf `state_machine.order.state_changed`, `…order_delivery…`, `…order_transaction…`) in `OrderStateMachineStateChangeEvent` unter dem Namen `<side>.<machine>.<state>` (z. B. `state_enter.order_delivery.state.shipped`). Diese Namen werden in `onAddStateEvents` als Flow-Builder-Events registriert; das Event trägt die geladene `OrderEntity`.
- **Zustände/Übergänge sind Datenbankzeilen** (`state_machine`, `state_machine_state`, `state_machine_transition` plus Translation-Tabellen), angelegt per Migration. Anbindung an eine Entity über `StateMachineStateField`.
- **Dokumente in 6.7.15.1: zwei Systeme.**

| | Legacy (aktiv, Standard) | DocumentV2 |
|---|---|---|
| Namespace | `Checkout/Document` | `Checkout/DocumentV2` |
| Aktivierung | immer | Feature-Flag `DOCUMENT_GENERATION_REWORK` (`default: false`), alles `@experimental stableVersion:v6.8.0` |
| Typ-Baustein | `AbstractDocumentRenderer` (Tag `document.renderer`) | Type-Klasse (Tag `shopware.document_v2.type`), Data Provider (Tag `shopware.document_v2.provider`), Render-Data-DTO, Twig `@Framework/documents/<technicalName>.html.twig`, Nummernkreis-Typ `document_<technicalName>`, Zeile in `document_type` |
| Ausblick | `#[ExperimentalReplacement(version: 'v6.9.0', …)]`: wird mit 6.9 ersetzt | Ziel-System |

- **Bestellungen erweitern** über Events (`CartConvertedEvent`, `OrderConvertedEvent`, `CheckoutOrderPlacedEvent`, `OrderEvents::*`), Entity Extension und Custom Fields ([T-29](./29-entity-extensions-custom-fields.md)). `OrderConverter` nicht ersetzen, sondern Events nutzen oder dekorieren ([T-06 Dekoration](./06-service-dekoration.md)).

Plugin-Baum (`SwagExample`):

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Resources/
    │   ├── config/services.php
    │   └── views/documents/swag_offer.html.twig     # DocumentV2-Template
    ├── Migration/
    │   ├── Migration1730000000SwagReviewStateMachine.php
    │   └── Migration1730000001SwagOfferDocumentType.php
    ├── StateMachine/OrderShippedSubscriber.php
    ├── Order/{OrderProcessor,OrderPlacedSubscriber}.php
    └── Document/{SwagOfferDocumentType,SwagOfferRenderData,SwagOfferDataProvider}.php
```

## 3. Anleitung

**A. Zustandswechsel**
1. `StateMachineRegistry` per Konstruktor injizieren (Autowiring).
2. Aktionsnamen (`action_name`) kennen: Bestellung `reopen, process, cancel, complete`; Zahlung u. a. `paid, paid_partially, refund, refund_partially, fail, cancel, authorize, remind, chargeback`; Lieferung `ship, ship_partially, retour, retour_partially, cancel, reopen` (Doku). Vorhandene Übergänge per API/CLI verifizieren (`bin/console state-machine:dump order.state`, Graphviz-Dump).
3. `transition()` aufrufen; Rückgabe: `StateMachineStateCollection` mit Schlüsseln `fromPlace` und `toPlace`.
4. Nicht erlaubter Übergang wirft `StateMachineException::illegalStateTransition` (nennt die erlaubten Aktionen). Ist der Zielzustand bereits erreicht, passiert nichts: kein Fehler, keine Events.
5. Testbar über die Admin-API: `GET /api/_action/state-machine/{entityName}/{entityId}/state` und `POST /api/_action/state-machine/{entityName}/{entityId}/state/{transition}` (`StateMachineActionController`, prüft ACL über `validatePrivilege`; die Registry selbst nicht).
6. Für Zahlungen kapselt `Checkout/Order/Aggregate/OrderTransaction/OrderTransactionStateHandler` die Registry (`paid()`, `process()`, `refund()`, `cancel()`, `authorize()`, `chargeback()` u. a., je `(string $transactionId, Context $context)`).

**B. Eigene Zustände/Übergänge**
1. Migration anlegen (Beispiel 4.3). Neue Übergänge in Core-Maschinen (`order.state`, `order_transaction.state`, `order_delivery.state`): Maschine und State per `technical_name` nachschlagen und `state_machine_transition` einfügen, vorher auf Existenz prüfen (Muster: `core/Migration/V6_3/Migration1600769357AddTransition.php`).
2. Je Zustand eine Translation-Zeile, sonst fehlen Namen im Admin.
3. Danach Cache leeren (Registry cached Maschinen pro Prozess).

**C. Dokumenttyp V2** (nur mit Flag)
1. `bin/console feature:enable DOCUMENT_GENERATION_REWORK`.
2. Type, DTO, Provider, Template, Migration wie in 4.6. Jeder Provider braucht einen eindeutigen `getKey()` (sonst `duplicateProviderKey`).
3. Formate: nur `html`, `pdf`, `zugferd_xml`, `zugferd_embedded_pdf` (`DocumentFormat`). Ein eigener `AbstractDocumentRenderer` (Tag `shopware.document_v2.renderer`) ist nur für ein neues Format nötig; bei mehreren Renderern pro Format gewinnt die höchste Priorität.
4. Erzeugen über `DocumentGenerator` (`Checkout/DocumentV2/Generation`) mit `new DocumentGenerationRequest($orderId, 'swag_offer', ['pdf'])`. Die Nummer vergibt `DocumentNumberGenerator`, falls `documentNumber` null ist.

**D. Dokumenttyp Legacy**
1. Von `Checkout/Document/Renderer/AbstractDocumentRenderer` ableiten (`supports()` liefert den Typ, `render()` liefert `RendererResult`, `getDecorated()` wirft `DecorationPatternException`).
2. Tag `document.renderer`; `DocumentRendererRegistry` nimmt den ersten passenden Renderer.
3. `document_type`, `document_base_config` und Nummernkreis `document_<typ>` per Migration. Vorbild `InvoiceRenderer` (Template `@Framework/documents/invoice.html.twig`, Nummer über `NumberRangeValueGeneratorInterface::getValue('document_' . TYPE, ...)`; in neuem Code `AbstractNumberRangeValueGenerator` injizieren).

**CLI** (im Web-Container):
```
bin/console state-machine:dump order.state
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console database:migrate --all SwagExample
bin/console feature:enable DOCUMENT_GENERATION_REWORK   # nur für V2
bin/console cache:clear
```

## 4. Beispiele

### 4.1 Transition auslösen und verfügbare Übergänge lesen

`src/Order/OrderProcessor.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Order;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class OrderProcessor
{
    public function __construct(private readonly StateMachineRegistry $stateMachineRegistry)
    {
    }

    public function process(string $orderId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(OrderDefinition::ENTITY_NAME, $orderId, 'process', 'stateId', 'Interner Kommentar'),
            $context
        );
    }

    /**
     * @return list<string>
     */
    public function availableActions(string $orderId, Context $context): array
    {
        $transitions = $this->stateMachineRegistry->getAvailableTransitions(
            OrderDefinition::ENTITY_NAME,
            $orderId,
            'stateId',
            $context
        );

        return array_values(array_map(static fn ($t) => $t->getActionName(), $transitions));
    }
}
```

### 4.2 Auf Zustandswechsel reagieren

`src/StateMachine/OrderShippedSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\StateMachine;

use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderShippedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // generisch, alle Entities/Maschinen
            StateMachineTransitionEvent::class => 'onTransition',
            // Name = state_machine.<technicalName>_changed
            'state_machine.order_delivery.state_changed' => 'onDeliveryChanged',
            // Flow-Event der Bestellung, Name = <side>.<machine>.<state>
            'state_enter.order_delivery.state.shipped' => 'onShipped',
        ];
    }

    public function onTransition(StateMachineTransitionEvent $event): void
    {
        // $event->getEntityName(), getEntityId(), getFromPlace()/getToPlace()->getTechnicalName()
    }

    public function onDeliveryChanged(StateMachineStateChangeEvent $event): void
    {
        // $event->getTransitionSide() (state_enter|state_leave), getStateName(), getTransition()->getEntityId()
    }

    public function onShipped(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $event->getOrder();
    }
}
```
Registrierung (`src/Resources/config/services.php`, vollständig für alle Beispiele siehe 4.6):
```php
$services->set(\SwagExample\StateMachine\OrderShippedSubscriber::class)->tag('kernel.event_subscriber');
```

### 4.3 Eigene State Machine per Migration

`src/Migration/Migration1730000000SwagReviewStateMachine.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1730000000SwagReviewStateMachine extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        if ($connection->fetchOne('SELECT 1 FROM state_machine WHERE technical_name = :n', ['n' => 'swag_review.state'])) {
            return;
        }

        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $machineId = Uuid::randomBytes();
        $pending = Uuid::randomBytes();
        $approved = Uuid::randomBytes();

        $connection->insert('state_machine', ['id' => $machineId, 'technical_name' => 'swag_review.state', 'created_at' => $now]);
        $connection->insert('state_machine_translation', [
            'state_machine_id' => $machineId,
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'name' => 'Review',
            'created_at' => $now,
        ]);

        foreach ([[$pending, 'pending', 'Pending'], [$approved, 'approved', 'Approved']] as [$id, $name, $label]) {
            $connection->insert('state_machine_state', ['id' => $id, 'technical_name' => $name, 'state_machine_id' => $machineId, 'created_at' => $now]);
            $connection->insert('state_machine_state_translation', [
                'state_machine_state_id' => $id,
                'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'name' => $label,
                'created_at' => $now,
            ]);
        }

        $connection->insert('state_machine_transition', [
            'id' => Uuid::randomBytes(),
            'action_name' => 'approve',
            'state_machine_id' => $machineId,
            'from_state_id' => $pending,
            'to_state_id' => $approved,
            'created_at' => $now,
        ]);

        $connection->update('state_machine', ['initial_state_id' => $pending], ['id' => $machineId]);
    }
}
```
Entity-Feld dazu (in der `EntityDefinition`, siehe [T-27](./27-dal-entities.md)); Vorbild `OrderDefinition` Z. 125 (`OrderStates::STATE_MACHINE = 'order.state'`):
```php
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StateMachineStateField;

(new StateMachineStateField('state_id', 'stateId', 'swag_review.state'))->addFlags(new Required()),
```
Die Spalte `state_id` (FK auf `state_machine_state`) muss in der Entity-Migration existieren. Den Initialzustand setzt das DAL aus `state_machine.initial_state_id` (`InitialStateIdLoader`).

### 4.4 Bestellung erweitern

`src/Order/OrderPlacedSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Order;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $data = $event->getConvertedCart();
        $data['customFields'] = ($data['customFields'] ?? []) + ['swag_source' => 'shop'];
        $event->setConvertedCart($data);
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        // nach dem Persistieren: ERP-Export anstoßen o. Ä.
    }
}
```
`CartConvertedEvent` wird in `OrderConverter::convertToOrder` (ca. Z. 252) dispatcht (Order-Datenarray); `OrderConvertedEvent` (Order zu Cart, `convertToCart`, ca. Z. 303) trägt ebenfalls `getConvertedCart()/setConvertedCart()`, hier aber vom Typ `Cart` (bei `CartConvertedEvent` ein `array`; `getOriginalConvertedCart()` liefert dort das unveränderte Array). `assembleSalesChannelContext` löst `BeforeSalesChannelContextAssembledEvent`/`SalesChannelContextAssembledEvent` aus.

### 4.5 Eigener Dokumenttyp V2 (nur mit aktivem Flag)

`src/Document/SwagOfferDocumentType.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Document;

use Shopware\Core\Checkout\DocumentV2\DocumentFormat;
use Shopware\Core\Checkout\DocumentV2\Type\AbstractDocumentType;

readonly class SwagOfferDocumentType extends AbstractDocumentType
{
    final public const TECHNICAL_NAME = 'swag_offer';

    public function getTechnicalName(): string
    {
        return self::TECHNICAL_NAME;
    }

    public function getSupportedFormats(): array
    {
        return [DocumentFormat::HTML->value, DocumentFormat::PDF->value];
    }
}
```
`src/Document/SwagOfferRenderData.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Document;

use Shopware\Core\Checkout\DocumentV2\Struct\AbstractRenderData;

final readonly class SwagOfferRenderData extends AbstractRenderData
{
    public function __construct(public string $offerNumber, public string $validUntil)
    {
    }
}
```
`src/Document/SwagOfferDataProvider.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Document;

use Shopware\Core\Checkout\DocumentV2\Provider\AbstractDocumentDataProvider;
use Shopware\Core\Checkout\DocumentV2\Struct\AbstractRenderData;
use Shopware\Core\Checkout\DocumentV2\Struct\ProviderInput;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final readonly class SwagOfferDataProvider extends AbstractDocumentDataProvider
{
    public function getKey(): string
    {
        return 'swag_offer';
    }

    public function supports(string $documentType): bool
    {
        return $documentType === SwagOfferDocumentType::TECHNICAL_NAME;
    }

    public function enrichOrderCriteria(Criteria $criteria): void
    {
        $criteria->addAssociations(['currency', 'language.locale', 'addresses.country', 'lineItems', 'orderCustomer']);
    }

    public function provideRenderingData(ProviderInput $input, Context $context): AbstractRenderData
    {
        return new SwagOfferRenderData(
            $input->generationRequest->documentNumber ?? '',
            (new \DateTimeImmutable($input->generationRequest->documentDate))->modify('+30 days')->format('Y-m-d'),
        );
    }
}
```

### 4.6 Services, Template, Migration (V2)

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\Document\SwagOfferDataProvider;
use SwagExample\Document\SwagOfferDocumentType;
use SwagExample\Order\OrderPlacedSubscriber;
use SwagExample\Order\OrderProcessor;
use SwagExample\StateMachine\OrderShippedSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire();

    $services->set(OrderProcessor::class);
    $services->set(OrderShippedSubscriber::class)->tag('kernel.event_subscriber');
    $services->set(OrderPlacedSubscriber::class)->tag('kernel.event_subscriber');

    $services->set(SwagOfferDocumentType::class)->tag('shopware.document_v2.type');
    $services->set(SwagOfferDataProvider::class)->tag('shopware.document_v2.provider');
};
```

`src/Resources/views/documents/swag_offer.html.twig` (Dateiname = technischer Name; `HtmlRenderer::TEMPLATE_PATTERN = '@Framework/documents/%s.html.twig'`)
```twig
{% sw_extends '@Framework/documents/base.html.twig' %}

{% block document_body %}
    <h1>Angebot {{ config.offerNumber }}</h1>
    <p>Gültig bis {{ config.validUntil }}</p>
{% endblock %}
```
Die DTO-Properties erscheinen flach unter `config.<property>` (`Template/TemplateContext`). Kollidiert ein Name mit Meta-Feldern oder dem DTO eines anderen Providers, wirft `TemplateContext` `templateContextPropertyCollision`. Der Core-Block `document_body` existiert in `core/Framework/Resources/views/documents/base.html.twig` (am Quellcode verifiziert; der in der Recherche genannte Block `document_content` existiert dort NICHT). Weitere Blöcke dort: `document_base`, `document_head`, `document_title_tag`, `document_font_links`; im Legacy-`invoice.html.twig` zusätzlich `document_headline`. Twig-Blockdetails: [T-14](./14-storefront-twig-templates.md).

`src/Migration/Migration1730000001SwagOfferDocumentType.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1730000001SwagOfferDocumentType extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000001;
    }

    public function update(Connection $connection): void
    {
        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $en = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $typeId = Uuid::randomBytes();
        $connection->insert('document_type', ['id' => $typeId, 'technical_name' => 'swag_offer', 'created_at' => $now]);
        $connection->insert('document_type_translation', ['document_type_id' => $typeId, 'language_id' => $en, 'name' => 'Offer', 'created_at' => $now]);

        $numberRangeTypeId = Uuid::randomBytes();
        $connection->insert('number_range_type', ['id' => $numberRangeTypeId, 'global' => 0, 'technical_name' => 'document_swag_offer', 'created_at' => $now]);
        $connection->insert('number_range_type_translation', ['number_range_type_id' => $numberRangeTypeId, 'language_id' => $en, 'type_name' => 'Offer', 'created_at' => $now]);

        $numberRangeId = Uuid::randomBytes();
        $connection->insert('number_range', ['id' => $numberRangeId, 'type_id' => $numberRangeTypeId, 'global' => 1, 'pattern' => 'A{n}', 'start' => 1000, 'created_at' => $now]);
        $connection->insert('number_range_translation', ['number_range_id' => $numberRangeId, 'language_id' => $en, 'name' => 'Offers', 'created_at' => $now]);
    }
}
```
Nummernkreis-Typ = `document_` + technischer Name (`DocumentNumberGenerator::NUMBER_RANGE_DOCUMENT_TYPE_PREFIX`). Spalten nach `Migration1536233560BasicData` (ca. Z. 2006 und 2036).

Weitere Gerüste: [Migration](../templates/migration/README.md), [Event-Subscriber](../templates/event-subscriber/README.md), [Entity](../templates/entity/README.md).

## 5. Fallstricke

1. **`stateId` direkt per Repository setzen**: umgeht History, Events, Flows, Mails. Immer `transition()`.
2. **Event-Typen verwechseln**: `state_enter.*`/`state_leave.*` liefern `OrderStateMachineStateChangeEvent` (Flow-Event), `state_machine.<name>_changed` ein `StateMachineStateChangeEvent`.
3. **No-op-Übergang erwartet Events**: ist der Zielzustand erreicht, passiert nichts (`hasTransitioned = false`).
4. **Gleiche Aktion mit gleichem Quellzustand mehrfach**: nicht erlaubt (Unique-Key); `StateMachineRegistry::getTransitionDestinationById` löst in 6.7 eine Deprecation aus, ab 6.8 ein Fehler.
5. **Alten Zustand nach dem Wechsel aus geladener Entity lesen**: Entity neu laden.
6. **Rechte**: Die Registry wechselt selbst in `Context::SYSTEM_SCOPE`; ACL prüft nur der `StateMachineActionController`.
7. **Translation-Zeilen vergessen**: Zustände ohne Namen im Admin.
8. **V2-Typ ohne `document_type`-Zeile oder ohne Nummernkreis-Typ `document_<name>`**: Generierung scheitert bzw. keine Nummer.
9. **Technischer Name mit Großbuchstaben/Bindestrich**: `RenderInput` verlangt `^[a-z0-9_]+$` (`invalidDocumentType`).
10. **Doppelte DTO-Property-Namen**: `templateContextPropertyCollision`.
11. **`OrderConverter` überschreiben**: stattdessen Events, Dekoration oder Entity Extension.
12. **`@internal`-Klassen** (`DocumentTypeRegistry`, `DocumentRendererRegistry`, die meisten V2-Klassen): nur über die dokumentierten Abstracts und Tags erweitern.
13. **Falscher Twig-Block** im Dokument-Template (`document_content` gibt es nicht): `document_body` verwenden.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Stabil in 6.7.15.1: `StateMachineRegistry::transition()`, `Transition`, `getAvailableTransitions()`, Event-Struktur. Ergänzungen: `StateMachineLocker`, History mit Quelle des Wechsels (`sourceType`).
- 6.7: `Migration1742302302RenamePaidTransitionActions` benennt Aktionen der Zahlungs-State-Machine um; alte Aktionsnamen im eigenen Code gegen die DB prüfen.
- `@deprecated tag:v6.8.0`: `StateMachineTransitionValidator`; `NumberRangeValueGeneratorInterface` (Ersatz `AbstractNumberRangeValueGenerator`), `previewPattern` (Ersatz `previewPatternByNumberRangeId`); mehrdeutige Ziele einer Aktion; `DocumentTypeNameSyncSubscriber` (`@deprecated tag:v6.9.0`).
- XML-Service-Konfiguration ist in 6.7 deprecated (`services.php` verwenden).
- Order-Felder: Code nutzt überall `Feature::isActive('v6.8.0.0') ? getPrimaryOrderDelivery() : getDeliveries()->first()`. Neuer Code lädt die `primaryOrderDelivery`/`primaryOrderTransaction`-Assoziationen.
- Apps können eigene Dokumenttypen liefern (`DocumentV2/App/AppDocumentTypeConfig`); der Name `app_provided` ist reserviert.
- In 6.5/6.6 existierte nur das Legacy-System; Umstellungsversionen nicht belegt.

## 6. Checkliste

- [ ] Zustandswechsel ausschließlich über `StateMachineRegistry::transition()` bzw. `OrderTransactionStateHandler`
- [ ] Aktionsname gegen tatsächliche Übergänge geprüft (`state-machine:dump`)
- [ ] Event-Name korrekt gewählt (`state_enter.<machine>.state.<state>` für Flow-Event, `state_machine.<name>_changed` für Struct-Event)
- [ ] Eigene Zustände/Übergänge per idempotenter Migration inkl. Translation-Zeilen und `initial_state_id`
- [ ] `StateMachineStateField` mit passender `state_id`-Spalte in der Entity-Migration
- [ ] Bestellungserweiterung über Events/Extension statt Überschreiben des `OrderConverter`
- [ ] Dokumentsystem entschieden (Legacy Standard vs. V2 hinter `DOCUMENT_GENERATION_REWORK`)
- [ ] V2: Tags, `document_type`-Zeile, Nummernkreis `document_<name>`, Template `<name>.html.twig`, eindeutiger `getKey()`
- [ ] Core-Twig-Blockname im Dokument-Template am Core-Template geprüft
- [ ] Neue Order-Zugriffe nutzen `primaryOrderDelivery`/`primaryOrderTransaction`
- [ ] Siehe [Review-Checkliste](../checklists/review-checklist.md)

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/checkout/order/using-the-state-machine.html (stimmt mit Code überein)
- https://developer.shopware.com/docs/guides/plugins/plugins/checkout/documents/v2/add-a-document-type.html (Template-Pfad, Tags, `document_type` + Nummernkreis)

Quellcode (relativ zu `vendor/shopware/core/`):
- `System/StateMachine/{StateMachineRegistry,Transition,StateMachineLocker,StateMachineException,StateMachineTransitionValidator}.php`, `Event/{StateMachineTransitionEvent,StateMachineStateChangeEvent}.php`, `Loader/InitialStateIdLoader.php`, `Api/StateMachineActionController.php`, `Command/WorkflowDumpCommand.php`
- `Migration/V6_3/{Migration1536232760StateMachine,Migration1600769357AddTransition}.php`, `Migration/V6_7/Migration1742302302RenamePaidTransitionActions.php`
- `Checkout/Order/{OrderStates,OrderDefinition,OrderEvents}.php`, `Order/Event/OrderStateMachineStateChangeEvent.php`, `Order/Listener/OrderStateChangeEventListener.php`, `Order/Aggregate/OrderTransaction/OrderTransactionStateHandler.php`
- `Checkout/Cart/Order/{OrderConverter,CartConvertedEvent,OrderConvertedEvent}.php`, `Checkout/Cart/Event/CheckoutOrderPlacedEvent.php`
- `Checkout/Document/Renderer/{AbstractDocumentRenderer,InvoiceRenderer,DocumentRendererRegistry}.php`, `Checkout/DependencyInjection/document.php`
- `Checkout/DocumentV2/` (`Type/`, `Provider/`, `Renderer/`, `Struct/`, `Template/TemplateContext.php`, `Config/DocumentNumberGenerator.php`, `Generation/`, `DependencyInjection/documentV2.php`)
- `Framework/Resources/config/packages/feature.yaml` (Flag `DOCUMENT_GENERATION_REWORK`), `Framework/Resources/views/documents/{base,invoice}.html.twig`
- `System/NumberRange/ValueGenerator/`, `Migration/V6_3/Migration1536233560BasicData.php`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Keines der Beispiele wurde ausgeführt (nur aus Klassen und Migrationen des installierten Codes abgeleitet).
- ⚠ UNGEPRÜFT: Spaltennamen der Tabellen `state_machine_state_translation` (`state_machine_state_id`, `language_id`, `name`) aus dem Muster anderer Translation-Tabellen angenommen.
- ⚠ UNGEPRÜFT: `number_range_type.global = 0` angenommen; Verkaufskanal-Zuordnung (`number_range_sales_channel`) fehlt im Beispiel.
- ⚠ UNGEPRÜFT: Ob ein Plugin-Template im Namespace `@Framework` für ein neues (nicht überschreibendes) Template vom `TemplateFinder` aufgelöst wird, und ob `base.html.twig` (nutzt Legacy-Variablen wie `nestedLineItems`) mit V2-Render-Daten zusammenspielt; nicht geprüft.
- ⚠ UNGEPRÜFT: V2-Generierungsfluss (`DocumentGenerator`) nur in Konstruktion/Registrierung gelesen; die Doku verlangt `document_type`-Zeile und Nummernkreis.
- ⚠ LÜCKE: Legacy-Dokument-Doku nicht abgerufen; Legacy-Beschreibung stammt aus dem Code. Welche Aktionen `Migration1742302302` umbenennt, nicht ausgewertet.
- ⚠ LÜCKE: Versionsangaben zu 6.5/6.6 nicht aus dem Code belegbar. Weitere Order-Hooks (`OrderCriteriaEvent`, `OrderStateChangeCriteriaEvent`, `OrderAddressService`, `RecalculationService`) nicht ausgewertet.
- ⚠ UNGEPRÜFT: Signaturen von `feature:enable`, `database:migrate`, `plugin:*` nicht erneut am Code geprüft.
