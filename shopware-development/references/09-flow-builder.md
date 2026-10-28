# Flow Builder: eigene Actions und Trigger (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin eine eigene **Flow-Action** (Backend-Logik plus Admin-Modal) oder einen eigenen **Flow-Trigger** (Business-Event) für den Flow Builder bereitstellen soll, inklusive Storer/Aware-Interfaces und Transaktionsverhalten.

Abgrenzung: normale Subscriber auf Events in [T-07](./07-events-subscriber.md); Ersetzen der Flow-Ausführung über den Extension Point `flow.executor` in [T-08](./08-extension-points.md); Admin-Modul-/Komponenten-Grundlagen in [T-24](./24-admin-module.md) und [T-25](./25-admin-komponenten.md); Mail-Templates in [T-35](./35-mail-templates.md); App-Flow-Actions (Webhook) in [T-10](./10-app-system-abgrenzung.md).

## 2. Konzepte

- **Action (Backend)**: Klasse `extends Shopware\Core\Content\Flow\Dispatching\Action\FlowAction` mit `static getName()`, `requirements()` (Liste von Aware-Interfaces) und `handleFlow(StorableFlow $flow)`. Service-Tag `flow.action` **mit Attribut `key` = `getName()`**. Es gibt keine Autokonfiguration für `FlowAction` (nur `FlowStorer` -> `flow.storer`); das Tag ist manuell zu setzen. Der `FlowExecutor` indiziert die Actions nach `key` (`core/Content/DependencyInjection/flow.php:164`); ein falscher/fehlender `key` führt dazu, dass die Action **stillschweigend nicht ausgeführt** wird (`FlowExecutor::callHandle`, Z. 218-222).
- **Action (Admin)**: Ohne Admin-Komponente erscheint die Action als "unknown". Nötig: Modal `sw-flow-<name>-modal` (abgeleitet in `FlowBuilderService::getActionModalName()`), Icon/Label/Beschreibung/Gruppe über `flowBuilderService`.
- **Trigger**: Event `extends Symfony\Contracts\EventDispatcher\Event implements FlowEventAware` (+ Aware-Interfaces wie `CustomerAware`, `OrderAware`, `MailAware`, `SalesChannelAware`). `getName()` = Eventname, `static getAvailableData(): EventDataCollection`. Sichtbar im Admin über Registrierung im `BusinessEventRegistry`: `Bundle::getActionEventClasses()` (Plugin überschreibt) oder Subscriber auf `BusinessEventCollectorEvent`. Ausgelöst per `$eventDispatcher->dispatch($event, $event->getName())`; der `FlowDispatcher` (Decorator von `event_dispatcher`, Priorität 1000) erkennt `FlowEventAware`.
- **Aware/Storer**: Aware-Interface trägt `#[IsFlowEventAware]` (`BusinessEventCollector::define()` liest es per Reflection in die `aware`-Liste). `FlowStorer::store()` serialisiert beim Dispatch (nur IDs/Skalare), `restore()` stellt beim Ausführen wieder her (Entities per `lazy()`). Storer werden per Autokonfiguration mit `flow.storer` getaggt.
- **Transaktionen**: `implements TransactionalAction` (Marker) -> `handleFlow` läuft in `RetryableTransaction::transactional`; jede Throwable wird zu `FlowException::transactionFailed()` (Codes `FLOW_ACTION_TRANSACTION_ABORTED` bei `TransactionFailedException`, `..._COMMIT_FAILED` bei DBAL-Exception, `..._UNCAUGHT_EXCEPTION` sonst).
- **Buffered Flows**: Feature-Flag `FLOW_EXECUTION_AFTER_BUSINESS_PROCESS` (Default `false`, in 6.8 Standard): Flows werden in `BufferedFlowQueue` eingereiht und nach `KernelEvents::TERMINATE`, `WorkerMessageHandledEvent`, `ConsoleEvents::TERMINATE` ausgeführt (max. Tiefe 10). `DelayableAction` ist nur ein Marker (`delayable=true` in der Action-Definition); keine Verzögerungslogik im Core.
- Actions laufen immer mit **System-Rechten**: `FlowFactory::restore()` baut einen Context mit `SystemSource`.

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php                           # überschreibt getActionEventClasses()
    ├── Core/Content/Flow/
    │   ├── Dispatching/Action/CreateTagAction.php
    │   ├── Dispatching/Aware/TagAware.php
    │   ├── Dispatching/Storer/TagStorer.php
    │   └── Event/ExampleEvent.php
    └── Resources/
        ├── config/services.php
        └── app/administration/src/
            ├── main.js
            └── module/swag-flow-example/
                ├── index.js
                ├── component/swag-flow-create-tag-modal/{index.js,swag-flow-create-tag-modal.html.twig}
                └── snippet/{de-DE,en-GB}.json
```
Namespaces sind frei; die Struktur spiegelt den Core.

## 3. Anleitung

1. Plugin aktivieren: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample`.
2. Event-Klasse, Aware-Interface und Storer erstellen; Daten, die Actions brauchen, im Storer ablegen.
3. Event registrieren: `getActionEventClasses()` im Plugin (im Bundle-Docblock dokumentiert; `Bundle::registerEvents()` hängt `BusinessEventRegisterCompilerPass` ein) **oder** Subscriber auf `BusinessEventCollectorEvent` (Doku-Weg, `$collection->set($def->getName(), $def)`).
4. Action-Klasse + Service mit Tag `flow.action` und `key`.
5. `bin/console cache:clear`. Prüfen: `bin/console debug:business-events` (Events inkl. `aware`); `GET /api/_info/events.json` und `/api/_info/flow-actions.json` liefern, was der Admin sieht.
6. Admin bauen: `bin/console bundle:dump && bin/build-administration.sh`, dann `bin/console assets:install` ([T-49](./49-build-deployment.md)).
7. Flow anlegen: Administration -> Einstellungen -> Shop -> Flow Builder. Eine Action ist nur wählbar, wenn mindestens eines ihrer `requirements()` in `triggerEvent.aware` vorkommt (`store/flow.store.ts`, Getter `availableActions`); Actions ohne requirements sind immer verfügbar.
8. Event auslösen, `var/log/*.log` prüfen: Fehler in `FlowExecutor::executeFlows()` werden als `logger->error` protokolliert, nicht geworfen.
9. Gezielter Abbruch mit Rollback: `TransactionalAction` + `TransactionFailedException::because($e)`.
10. Flow-Auslösung unterdrücken: `$context->addState(Context::SKIP_TRIGGER_FLOW)` (`FlowDispatcher::dispatch()` überspringt dann die Ausführung; `FlowLogEvent` wird trotzdem dispatcht).
11. Buffered-Modus testen: `.env` `FLOW_EXECUTION_AFTER_BUSINESS_PROCESS=1` oder `bin/console feature:enable FLOW_EXECUTION_AFTER_BUSINESS_PROCESS` (⚠ UNGEPRÜFT: Aktivierungsmechanismus).

Ablauf im Core: `FlowDispatcher::dispatch()` -> `FlowFactory::create()/createBuffered()` (ruft alle `FlowStorer::store()`) -> `FlowLoader` (`CachedFlowLoader`) -> `FlowExecutor::executeFlows()` -> `executeIf` (Regelprüfung) / `executeAction` -> `callHandle` (App-Actions über `AppFlowActionEvent`/Webhook, eigene über die `flow.action`-Map).

## 4. Beispiele

Szenario: Event `swag.example.tag_requested` trägt eine Tag-ID; Action `action.swag.create.tag` legt ein Tag an.

`src/SwagExample.php`
```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;
use SwagExample\Core\Content\Flow\Event\ExampleEvent;

class SwagExample extends Plugin
{
    /** @return array<class-string> */
    protected function getActionEventClasses(): array
    {
        return [ExampleEvent::class];
    }
}
```

`src/Core/Content/Flow/Dispatching/Aware/TagAware.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Flow\Dispatching\Aware;

use Shopware\Core\Framework\Event\IsFlowEventAware;

#[IsFlowEventAware]
interface TagAware
{
    public const TAG_ID = 'swagTagId';
    public const TAG = 'swagTag';

    public function getTagId(): string;
}
```
Core-Aware-Interfaces (z. B. `ProductAware`) erweitern `FlowEventAware` nicht; sie tragen nur das Attribut. Die Doku-Variante `TagAware extends FlowEventAware` funktioniert auch, Core-Stil bevorzugen.

`src/Core/Content/Flow/Event/ExampleEvent.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Flow\Event;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use SwagExample\Core\Content\Flow\Dispatching\Aware\TagAware;
use Symfony\Contracts\EventDispatcher\Event;

class ExampleEvent extends Event implements FlowEventAware, TagAware
{
    final public const EVENT_NAME = 'swag.example.tag_requested';

    public function __construct(
        private readonly Context $context,
        private readonly string $tagId,
    ) {
    }

    public function getName(): string
    {
        return self::EVENT_NAME;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getTagId(): string
    {
        return $this->tagId;
    }

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add(TagAware::TAG_ID, new ScalarValueType(ScalarValueType::TYPE_STRING));
    }
}
```

`src/Core/Content/Flow/Dispatching/Storer/TagStorer.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Flow\Dispatching\Storer;

use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\FlowStorer;
use Shopware\Core\Framework\Event\FlowEventAware;
use SwagExample\Core\Content\Flow\Dispatching\Aware\TagAware;

class TagStorer extends FlowStorer
{
    public function store(FlowEventAware $event, array $stored): array
    {
        if (!$event instanceof TagAware || isset($stored[TagAware::TAG_ID])) {
            return $stored;
        }

        $stored[TagAware::TAG_ID] = $event->getTagId();

        return $stored;
    }

    public function restore(StorableFlow $storable): void
    {
        if (!$storable->hasStore(TagAware::TAG_ID)) {
            return;
        }

        $storable->setData(TagAware::TAG_ID, $storable->getStore(TagAware::TAG_ID));
    }
}
```
Muster nach `ProductStorer`/`CustomerStorer`. Für Entities statt `setData` `$storable->lazy(KEY, $this->lazyLoad(...))` (wird in `StorableFlow::getData()` beim ersten Zugriff aufgelöst).

`src/Core/Content/Flow/Dispatching/Action/CreateTagAction.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Flow\Dispatching\Action;

use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\TransactionalAction;
use Shopware\Core\Content\Flow\Dispatching\TransactionFailedException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use SwagExample\Core\Content\Flow\Dispatching\Aware\TagAware;

class CreateTagAction extends FlowAction implements TransactionalAction
{
    /**
     * @internal
     */
    public function __construct(private readonly EntityRepository $tagRepository)
    {
    }

    public static function getName(): string
    {
        return 'action.swag.create.tag';
    }

    /** @return list<string> */
    public function requirements(): array
    {
        return [TagAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        $name = $flow->getConfig()['name'] ?? null;
        if (!\is_string($name) || $name === '' || !$flow->hasData(TagAware::TAG_ID)) {
            return;
        }

        try {
            $this->tagRepository->create([['id' => $flow->getData(TagAware::TAG_ID), 'name' => $name]], $flow->getContext());
        } catch (\Throwable $e) {
            throw TransactionFailedException::because($e);
        }
    }
}
```

`src/Resources/config/services.php` (XML ist in 6.7 deprecated, `Bundle::triggerXmlConfigDeprecation`)
```php
<?php declare(strict_types=1);

use SwagExample\Core\Content\Flow\Dispatching\Action\CreateTagAction;
use SwagExample\Core\Content\Flow\Dispatching\Storer\TagStorer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(CreateTagAction::class)
        ->args([service('tag.repository')])
        ->tag('flow.action', ['priority' => 600, 'key' => CreateTagAction::getName()]);

    // `AutoconfigureCompilerPass` registriert `registerForAutoconfiguration(FlowStorer::class)` mit Tag `flow.storer`;
    // das greift nur bei Services mit `autoconfigure`. Hier ohne `defaults()->autoconfigure()` daher explizit taggen.
    $services->set(TagStorer::class)->tag('flow.storer');
};
```

Event auslösen (`EventDispatcherInterface` injizieren; der Container liefert den vom `FlowDispatcher` dekorierten Dispatcher):
```php
$this->eventDispatcher->dispatch(new ExampleEvent($context, $tagId), ExampleEvent::EVENT_NAME);
```

### Admin-Teil (6.7, Vue 3)

`src/Resources/app/administration/src/main.js`
```js
import './module/swag-flow-example';
```

`.../module/swag-flow-example/index.js`
```js
import './component/swag-flow-create-tag-modal';
import enGB from './snippet/en.json';
import deDE from './snippet/de.json';

Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('de-DE', deDE);

const flowBuilderService = Shopware.Service('flowBuilderService');
flowBuilderService.addActionNames({ SWAG_CREATE_TAG: 'action.swag.create.tag' });
flowBuilderService.addIcons({ swagCreateTag: 'regular-tag' });
flowBuilderService.addLabels({ swagCreateTag: 'swag-flow-example.createTag.title' });
flowBuilderService.addActionGroupMapping({ 'action.swag.create.tag': 'general' });
```
Schlüsselableitung: `action.swag.create.tag` -> Icon-/Label-Key `swagCreateTag` (`FlowBuilderService::getActionTitle`); Modal-Name `sw-flow-swag-create-tag-modal` (`getActionModalName`: `.` -> `-`, `action` -> `sw-flow`, Suffix `-modal`). Die Komponente muss exakt so registriert sein.

`.../component/swag-flow-create-tag-modal/index.js`
```js
import template from './swag-flow-create-tag-modal.html.twig';

Shopware.Component.register('sw-flow-swag-create-tag-modal', {
    template,
    emits: ['process-finish', 'modal-close'],
    props: { sequence: { type: Object, required: true } },
    data() { return { name: this.sequence.config?.name ?? '' }; },
    methods: {
        onSave() {
            if (!this.name) { return; }
            this.$emit('process-finish', { ...this.sequence, config: { name: this.name } });
            this.$emit('modal-close');
        },
        onClose() { this.$emit('modal-close'); },
    },
});
```

`.../component/swag-flow-create-tag-modal/swag-flow-create-tag-modal.html.twig`
```twig
{% block swag_flow_create_tag_modal %}
<sw-modal class="swag-flow-create-tag-modal" :title="$t('swag-flow-example.createTag.title')" :closable="false" @modal-close="onClose">
    <mt-text-field v-model="name" :label="$t('swag-flow-example.createTag.labelName')" required />
    <template #modal-footer>
        <mt-button size="small" variant="secondary" @click="onClose">{{ $t('global.default.cancel') }}</mt-button>
        <mt-button size="small" variant="primary" @click="onSave">{{ $t('sw-flow.modals.buttonSaveAction') }}</mt-button>
    </template>
</sw-modal>
{% endblock %}
```
`snippet/en.json`: `{"swag-flow-example":{"createTag":{"title":"Create tag","labelName":"Tag name"}}}`

Hinweis: Mail-Templates und Snippet-Mechanik: [T-35](./35-mail-templates.md), [T-18](./18-snippets-uebersetzungen.md).

## 5. Fallstricke

- `flow.action`-Tag ohne `key` oder `key` != `getName()`: Action wird nie ausgeführt, kein Fehler.
- Aware-Interface ohne `#[IsFlowEventAware]` erscheint nicht in `aware`, Action nie verfügbar; ebenso wenn `requirements()` ein Interface nennt, das das Event nicht implementiert.
- Event nicht registriert (weder `getActionEventClasses()` noch Subscriber): Trigger fehlt im Admin. Leeres `getName()`: `define()` liefert `null`.
- Nicht-skalare Objekte im Storer: nur IDs/Skalare speichern (Buffered-Betrieb cached `BufferedFlow::$stored`), Entities lazy laden.
- Doppelte Storer-Keys: `ScalarValuesStorer` wirft `\RuntimeException('Can not store generic values twice.')`.
- Event-Daten ohne `getAvailableData()`-Eintrag erscheinen nicht in Mail-Template-Variablen.
- `handleFlow` wirft: Executor loggt, der Flow bricht ab (`ExecuteSequenceException`), andere Flows laufen weiter; mit `TransactionalAction` zusätzlich Rollback.
- Modal-Name falsch: leeres Modal.
- Doku-Muster `Component.override('sw-flow-sequence-action', { computed: { modalName }, methods: { getActionTitle ... } })`: In 6.7 liegen `getActionTitle`/`getActionDescriptions` im `flowBuilderService` (TS); Override greift vermutlich nicht (⚠ UNGEPRÜFT, nur aus Code geschlossen).
- Endlosschleifen im Buffered-Modus: Abbruch bei Tiefe 10 mit `logger->error('Maximum execution depth reached...')`.
- IF-Sequenzen: Regeln werten nur mit `OrderAware::ORDER`/`CustomerAware::CUSTOMER`-Daten; sonst nur Context-Rule-IDs.
- `FlowExecutor`, `BufferedFlowExecutor`, `FlowDispatcher`, `BufferedFlowQueue` sind `@internal`/`@final`: nicht dekorieren; stattdessen Extension Point `FlowExecutorExtension` ([T-08](./08-extension-points.md)).
- Actions laufen mit System-Context, nicht im Kunden-/Admin-Kontext.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `FlowAction`/`StorableFlow` (seit 6.4.18/6.5) lösen alte `FlowActionInterface`/`FlowEvent` ab; Namespace `Shopware\Core\Content\Flow\Dispatching\...` unverändert.
- XML-Services in 6.7 deprecated (entfällt 6.8): `services.php`.
- Buffered Flows neu hinter `FLOW_EXECUTION_AFTER_BUSINESS_PROCESS` (Default `false`, in 6.8 Standard).
- `ProductStorer` nutzt ab 6.8 (`Feature::isActive('v6.8.0.0')`) `ProductProvider::getData()`; bis dahin zusätzlich `BeforeLoadStorableFlowDataEvent`.
- Admin 6.7: Vue 3, `$t` statt `$tc`, `mt-*`-Komponenten (Meteor), `v-model:value`; Doku-Beispiele nutzen noch `$tc`, `sw-button`, `v-model`.
- `flowBuilderService` (`addActionNames/addIcons/addLabels/addGroups/addActionGroupMapping/addDescriptionCallbacks`) ist `@private`.
- Im Core ist `hasData` nach `restore()` die übliche Prüfung (Doku prüft `hasStore`).

## 6. Checkliste

- [ ] Event implementiert `FlowEventAware`, `getName()` nicht leer, `getAvailableData()` gepflegt
- [ ] Event über `getActionEventClasses()` oder `BusinessEventCollectorEvent` registriert
- [ ] Aware-Interface trägt `#[IsFlowEventAware]`; `requirements()` der Action passt dazu
- [ ] Storer speichert nur IDs/Skalare; Entities lazy
- [ ] Service-Tag `flow.action` mit `key` = `getName()`; Service im PHP-Format
- [ ] `TransactionalAction` und `TransactionFailedException` bei DB-Schreibzugriffen
- [ ] Admin: Modal-Name exakt abgeleitet, Icon/Label/Gruppe registriert, Snippets vorhanden
- [ ] `cache:clear`, Admin-Build und `assets:install` durchgeführt
- [ ] Mit `debug:business-events` und `/api/_info/flow-actions.json` verifiziert
- [ ] Flow im Admin angelegt, Ausführung in `var/log` geprüft

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/flow/add-flow-builder-action.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/flow/add-flow-builder-trigger.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/flow/action-transactions.html

Quellcode (`vendor/shopware/`):
- `core/Content/Flow/Dispatching/{Action/FlowAction,Action/AddOrderTagAction,Action/SetOrderStateAction,FlowExecutor,FlowDispatcher,BufferedFlowExecutor,BufferedFlowQueue,DelayableAction,TransactionalAction,TransactionFailedException,StorableFlow,FlowFactory}.php`
- `core/Content/Flow/Dispatching/Storer/{FlowStorer,ProductStorer,CustomerStorer,ScalarValuesStorer}.php`
- `core/Framework/Event/{FlowEventAware,IsFlowEventAware,BusinessEventCollector,BusinessEventRegistry,BusinessEventCollectorEvent}.php`, `core/Framework/Event/Command/DebugDumpBusinessEventsCommand.php`
- `core/Framework/Bundle.php` (`getActionEventClasses`, `registerEvents`), `core/Framework/DependencyInjection/CompilerPass/{BusinessEventRegisterCompilerPass,AutoconfigureCompilerPass}.php`
- `core/Content/Flow/{Api/FlowActionCollector,FlowException}.php`, `core/Content/DependencyInjection/flow.php`, `core/Framework/Api/Controller/InfoController.php`, `core/Framework/Resources/config/packages/feature.yaml`
- Admin: `administration/Resources/app/administration/src/module/sw-flow/{service/flow-builder.service.ts,constant/flow.constant.js,store/flow.store.ts,component/sw-flow-sequence-action/,component/modals/sw-flow-grant-download-access-modal/}`

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Delay: im Core kein Mechanismus; `FlowState::$delayed` wird nirgends gesetzt. Vermutlich Commercial-Erweiterung (nicht belegt).
- ⚠ UNGEPRÜFT: Admin-Beispiele (Modal, `Shopware.Service('flowBuilderService')` zum Zeitpunkt des Modul-Imports, ausreichende Wirkung von `addActionGroupMapping`/`addGroups`, Konstante `ACTION_GROUP` in `flow.constant.js`) aus Core-Code abgeleitet, nicht in einer Installation ausgeführt.
- ⚠ UNGEPRÜFT: `flowBuilderService` ist `@private`; Plugin-Nutzung formal nicht stabile API.
- ⚠ UNGEPRÜFT: Doku-Variante `Component.override` für `getActionTitle`/`getActionDescriptions` in 6.7 vermutlich wirkungslos.
- ⚠ UNGEPRÜFT: Aktivierung des Feature-Flags per `.env`/`feature:enable` nicht separat am Code geprüft.
- `FlowAction` wird nicht per Autokonfiguration getaggt (im `AutoconfigureCompilerPass` nicht gefunden): Tag manuell.
- Dass der injizierte `event_dispatcher` der vom `FlowDispatcher` dekorierte ist, folgt aus `core/Content/DependencyInjection/flow.php:112` (`->decorate('event_dispatcher', null, 1000)`).
- `getActionEventClasses()` steht nicht in den drei Doku-Seiten, nur im Bundle-Docblock.
