# Flow Builder: custom actions and triggers (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin should provide its own **flow action** (backend logic plus Admin modal) or its own **flow trigger** (business event) for the Flow Builder, including storer/aware interfaces and transaction behavior.

Scope: regular subscribers on events in [T-07](./07-events-subscriber.md); replacing the flow execution via the extension point `flow.executor` in [T-08](./08-extension-points.md); Admin module/component basics in [T-24](./24-admin-modules.md) and [T-25](./25-admin-components.md); mail templates in [T-35](./35-mail-templates.md); app flow actions (webhook) in [T-10](./10-app-system-scope.md).

## 2. Concepts

- **Action (backend)**: class `extends Shopware\Core\Content\Flow\Dispatching\Action\FlowAction` with `static getName()`, `requirements()` (list of aware interfaces) and `handleFlow(StorableFlow $flow)`. Service tag `flow.action` **with attribute `key` = `getName()`**. There is no autoconfiguration for `FlowAction` (only `FlowStorer` -> `flow.storer`); the tag must be set manually. The `FlowExecutor` indexes the actions by `key` (`core/Content/DependencyInjection/flow.php:164`); a wrong/missing `key` causes the action to be **silently not executed** (`FlowExecutor::callHandle`, lines 218-222).
- **Action (Admin)**: without an Admin component, the action appears as "unknown". Required: modal `sw-flow-<name>-modal` (derived in `FlowBuilderService::getActionModalName()`), icon/label/description/group via `flowBuilderService`.
- **Trigger**: event `extends Symfony\Contracts\EventDispatcher\Event implements FlowEventAware` (+ aware interfaces such as `CustomerAware`, `OrderAware`, `MailAware`, `SalesChannelAware`). `getName()` = event name, `static getAvailableData(): EventDataCollection`. Visible in the Admin via registration in the `BusinessEventRegistry`: `Bundle::getActionEventClasses()` (overridden by the plugin) or a subscriber on `BusinessEventCollectorEvent`. Triggered via `$eventDispatcher->dispatch($event, $event->getName())`; the `FlowDispatcher` (decorator of `event_dispatcher`, priority 1000) recognizes `FlowEventAware`.
- **Aware/storer**: the aware interface carries `#[IsFlowEventAware]` (`BusinessEventCollector::define()` reads it via reflection into the `aware` list). `FlowStorer::store()` serializes at dispatch (only IDs/scalars), `restore()` restores at execution (entities via `lazy()`). Storers are tagged with `flow.storer` via autoconfiguration.
- **Transactions**: `implements TransactionalAction` (marker) -> `handleFlow` runs in `RetryableTransaction::transactional`; every Throwable becomes `FlowException::transactionFailed()` (codes `FLOW_ACTION_TRANSACTION_ABORTED` for `TransactionFailedException`, `..._COMMIT_FAILED` for a DBAL exception, `..._UNCAUGHT_EXCEPTION` otherwise).
- **Buffered flows**: feature flag `FLOW_EXECUTION_AFTER_BUSINESS_PROCESS` (default `false`, standard in 6.8): flows are queued in `BufferedFlowQueue` and executed after `KernelEvents::TERMINATE`, `WorkerMessageHandledEvent`, `ConsoleEvents::TERMINATE` (max. depth 10). `DelayableAction` is only a marker (`delayable=true` in the action definition); no delay logic in the core.
- Actions always run with **system permissions**: `FlowFactory::restore()` builds a context with `SystemSource`.

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php                           # overrides getActionEventClasses()
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
                └── snippet/{de,en}.json
```
Namespaces are free to choose; the structure mirrors the core.

## 3. Instructions

1. Activate the plugin: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample`.
2. Create the event class, aware interface and storer; put data that actions need into the storer.
3. Register the event: `getActionEventClasses()` in the plugin (documented in the Bundle docblock; `Bundle::registerEvents()` hooks in `BusinessEventRegisterCompilerPass`) **or** a subscriber on `BusinessEventCollectorEvent` (docs approach, `$collection->set($def->getName(), $def)`).
4. Action class + service with tag `flow.action` and `key`.
5. `bin/console cache:clear`. Verify: `bin/console debug:business-events` (events incl. `aware`); `GET /api/_info/events.json` and `/api/_info/flow-actions.json` return what the Admin sees.
6. Build the Admin: `bin/console bundle:dump && bin/build-administration.sh`, then `bin/console assets:install` ([T-49](./49-build-deployment.md)).
7. Create a flow: Administration -> Settings -> Shop -> Flow Builder. An action is only selectable if at least one of its `requirements()` appears in `triggerEvent.aware` (`store/flow.store.ts`, getter `availableActions`); actions without requirements are always available.
8. Trigger the event, check `var/log/*.log`: errors in `FlowExecutor::executeFlows()` are logged as `logger->error`, not thrown.
9. Deliberate abort with rollback: `TransactionalAction` + `TransactionFailedException::because($e)`.
10. Suppress flow triggering: `$context->addState(Context::SKIP_TRIGGER_FLOW)` (`FlowDispatcher::dispatch()` then skips execution; `FlowLogEvent` is still dispatched).
11. Test buffered mode: `.env` `FLOW_EXECUTION_AFTER_BUSINESS_PROCESS=1` or `bin/console feature:enable FLOW_EXECUTION_AFTER_BUSINESS_PROCESS` (⚠ UNVERIFIED: activation mechanism).

Flow in the core: `FlowDispatcher::dispatch()` -> `FlowFactory::create()/createBuffered()` (calls all `FlowStorer::store()`) -> `FlowLoader` (`CachedFlowLoader`) -> `FlowExecutor::executeFlows()` -> `executeIf` (rule check) / `executeAction` -> `callHandle` (app actions via `AppFlowActionEvent`/webhook, custom ones via the `flow.action` map).

## 4. Examples

Scenario: event `swag.example.tag_requested` carries a tag ID; action `action.swag.create.tag` creates a tag.

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
Core aware interfaces (e.g. `ProductAware`) do not extend `FlowEventAware`; they only carry the attribute. The docs variant `TagAware extends FlowEventAware` also works; prefer the core style.

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
Pattern based on `ProductStorer`/`CustomerStorer`. For entities, use `$storable->lazy(KEY, $this->lazyLoad(...))` instead of `setData` (resolved in `StorableFlow::getData()` on first access).

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

`src/Resources/config/services.php` (XML is deprecated in 6.7, `Bundle::triggerXmlConfigDeprecation`)
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

    // `AutoconfigureCompilerPass` registers `registerForAutoconfiguration(FlowStorer::class)` with tag `flow.storer`;
    // this only applies to services with `autoconfigure`. Without `defaults()->autoconfigure()` here, tag explicitly.
    $services->set(TagStorer::class)->tag('flow.storer');
};
```

Trigger the event (inject `EventDispatcherInterface`; the container provides the dispatcher decorated by the `FlowDispatcher`):
```php
$this->eventDispatcher->dispatch(new ExampleEvent($context, $tagId), ExampleEvent::EVENT_NAME);
```

### Admin part (6.7, Vue 3)

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
Key derivation: `action.swag.create.tag` -> icon/label key `swagCreateTag` (`FlowBuilderService::getActionTitle`); modal name `sw-flow-swag-create-tag-modal` (`getActionModalName`: `.` -> `-`, `action` -> `sw-flow`, suffix `-modal`). The component must be registered exactly like this.

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

Note: mail templates and snippet mechanics: [T-35](./35-mail-templates.md), [T-18](./18-snippets-translations.md).

## 5. Pitfalls

- `flow.action` tag without `key` or `key` != `getName()`: the action is never executed, no error.
- An aware interface without `#[IsFlowEventAware]` does not appear in `aware`, the action is never available; likewise if `requirements()` names an interface the event does not implement.
- Event not registered (neither `getActionEventClasses()` nor a subscriber): the trigger is missing in the Admin. Empty `getName()`: `define()` returns `null`.
- Non-scalar objects in the storer: store only IDs/scalars (buffered mode caches `BufferedFlow::$stored`), load entities lazily.
- Duplicate storer keys: `ScalarValuesStorer` throws `\RuntimeException('Can not store generic values twice.')`.
- Event data without a `getAvailableData()` entry does not appear in mail template variables.
- `handleFlow` throws: the executor logs, the flow aborts (`ExecuteSequenceException`), other flows continue; with `TransactionalAction` additionally a rollback.
- Wrong modal name: empty modal.
- Docs pattern `Component.override('sw-flow-sequence-action', { computed: { modalName }, methods: { getActionTitle ... } })`: in 6.7, `getActionTitle`/`getActionDescriptions` live in the `flowBuilderService` (TS); the override probably has no effect (⚠ UNVERIFIED, only inferred from the code).
- Infinite loops in buffered mode: abort at depth 10 with `logger->error('Maximum execution depth reached...')`.
- IF sequences: rules only evaluate with `OrderAware::ORDER`/`CustomerAware::CUSTOMER` data; otherwise only context rule IDs.
- `FlowExecutor`, `BufferedFlowExecutor`, `FlowDispatcher`, `BufferedFlowQueue` are `@internal`/`@final`: do not decorate them; use the extension point `FlowExecutorExtension` instead ([T-08](./08-extension-points.md)).
- Actions run with the system context, not in the customer/Admin context.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `FlowAction`/`StorableFlow` (since 6.4.18/6.5) replace the old `FlowActionInterface`/`FlowEvent`; namespace `Shopware\Core\Content\Flow\Dispatching\...` unchanged.
- XML services deprecated in 6.7 (removed in 6.8): `services.php`.
- Buffered flows are new behind `FLOW_EXECUTION_AFTER_BUSINESS_PROCESS` (default `false`, standard in 6.8).
- From 6.8 on (`Feature::isActive('v6.8.0.0')`), `ProductStorer` uses `ProductProvider::getData()`; until then additionally `BeforeLoadStorableFlowDataEvent`.
- Admin 6.7: Vue 3, `$t` instead of `$tc`, `mt-*` components (Meteor), `v-model:value`; docs examples still use `$tc`, `sw-button`, `v-model`.
- `flowBuilderService` (`addActionNames/addIcons/addLabels/addGroups/addActionGroupMapping/addDescriptionCallbacks`) is `@private`.
- In the core, `hasData` after `restore()` is the usual check (the docs check `hasStore`).

## 6. Checklist

- [ ] Event implements `FlowEventAware`, `getName()` not empty, `getAvailableData()` maintained
- [ ] Event registered via `getActionEventClasses()` or `BusinessEventCollectorEvent`
- [ ] Aware interface carries `#[IsFlowEventAware]`; the action's `requirements()` match it
- [ ] Storer stores only IDs/scalars; entities lazy
- [ ] Service tag `flow.action` with `key` = `getName()`; service in PHP format
- [ ] `TransactionalAction` and `TransactionFailedException` for DB writes
- [ ] Admin: modal name derived exactly, icon/label/group registered, snippets present
- [ ] `cache:clear`, Admin build and `assets:install` done
- [ ] Verified with `debug:business-events` and `/api/_info/flow-actions.json`
- [ ] Flow created in the Admin, execution checked in `var/log`

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/flow/add-flow-builder-action.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/flow/add-flow-builder-trigger.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/flow/action-transactions.html

Source code (`vendor/shopware/`):
- `core/Content/Flow/Dispatching/{Action/FlowAction,Action/AddOrderTagAction,Action/SetOrderStateAction,FlowExecutor,FlowDispatcher,BufferedFlowExecutor,BufferedFlowQueue,DelayableAction,TransactionalAction,TransactionFailedException,StorableFlow,FlowFactory}.php`
- `core/Content/Flow/Dispatching/Storer/{FlowStorer,ProductStorer,CustomerStorer,ScalarValuesStorer}.php`
- `core/Framework/Event/{FlowEventAware,IsFlowEventAware,BusinessEventCollector,BusinessEventRegistry,BusinessEventCollectorEvent}.php`, `core/Framework/Event/Command/DebugDumpBusinessEventsCommand.php`
- `core/Framework/Bundle.php` (`getActionEventClasses`, `registerEvents`), `core/Framework/DependencyInjection/CompilerPass/{BusinessEventRegisterCompilerPass,AutoconfigureCompilerPass}.php`
- `core/Content/Flow/{Api/FlowActionCollector,FlowException}.php`, `core/Content/DependencyInjection/flow.php`, `core/Framework/Api/Controller/InfoController.php`, `core/Framework/Resources/config/packages/feature.yaml`
- Admin: `administration/Resources/app/administration/src/module/sw-flow/{service/flow-builder.service.ts,constant/flow.constant.js,store/flow.store.ts,component/sw-flow-sequence-action/,component/modals/sw-flow-grant-download-access-modal/}`

### Gaps and uncertainties

- ⚠ GAP: Delay: no mechanism in the core; `FlowState::$delayed` is never set. Probably a Commercial extension (not confirmed).
- ⚠ UNVERIFIED: Admin examples (modal, `Shopware.Service('flowBuilderService')` at module import time, sufficient effect of `addActionGroupMapping`/`addGroups`, constant `ACTION_GROUP` in `flow.constant.js`) derived from core code, not executed in an installation.
- ⚠ UNVERIFIED: `flowBuilderService` is `@private`; plugin usage is formally not a stable API.
- ⚠ UNVERIFIED: The docs variant `Component.override` for `getActionTitle`/`getActionDescriptions` probably has no effect in 6.7.
- ⚠ UNVERIFIED: Activation of the feature flag via `.env`/`feature:enable` not separately checked against the code.
- `FlowAction` is not tagged via autoconfiguration (not found in `AutoconfigureCompilerPass`): tag manually.
- That the injected `event_dispatcher` is the one decorated by the `FlowDispatcher` follows from `core/Content/DependencyInjection/flow.php:112` (`->decorate('event_dispatcher', null, 1000)`).
- `getActionEventClasses()` is not mentioned in the three docs pages, only in the Bundle docblock.
