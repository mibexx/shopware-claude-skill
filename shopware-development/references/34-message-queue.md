# Message Queue (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin wants to offload work asynchronously via the Shopware message queue (Symfony Messenger): custom messages and handlers, routing to transports, middleware, worker operation (CLI and Admin worker), retry and failure transport.

Scope: scheduled tasks (recurring tasks, `scheduled-task:*`) in [T-33](./33-scheduled-tasks.md); events/subscribers in [T-07](./07-events-subscriber.md); service registration in [T-05](./05-registering-services.md); worker/hosting operation in [T-49](./49-build-deployment.md); logging/CLI in [T-43](./43-cli-logging-systemchecks.md).

## 2. Concepts

- Shopware uses **Symfony Messenger** (v7.4.21 according to `installed.json`) without its own queue engine. Message = serializable PHP object; sent via `Symfony\Component\Messenger\MessageBusInterface` (service `messenger.default_bus`, bus `messenger.bus.default`).
- **Routing via marker interface** (`core/Framework/Resources/config/packages/framework.yaml`, lines 94-98):
  - `Shopware\Core\Framework\MessageQueue\AsyncMessageInterface` -> transport `async`
  - `Shopware\Core\Framework\MessageQueue\LowPriorityMessageInterface` -> transport `low_priority`
  - `Symfony\Component\Mailer\Messenger\SendEmailMessage` -> `async`
  - `Shopware\Core\Framework\Webhook\Message\WebhookEventMessage` -> `webhook` (DSN `shopware-webhook://default`, 0 retries)
  - Without a marker interface and without custom routing: **synchronous** (Symfony default, not tested against the Shopware code).
- **Handler**: class with `#[AsMessageHandler]` and `__invoke(MyMessage $m)`. Core handlers are `final readonly` (PHPStan rule `MessageHandlerFinalRule`, only recommended for plugins).
- **Transports (default)**: `async` and `low_priority` via Doctrine (`MESSENGER_TRANSPORT_DSN` / `MESSENGER_TRANSPORT_LOW_PRIORITY_DSN`, `auto_setup=false`), failure transport `failed` (`MESSENGER_TRANSPORT_FAILURE_DSN`). Retry: `max_retries: 3`, `delay: 1000`, `multiplier: 2`, `max_delay: 0` (1 s, 2 s, 4 s). Parameter `messenger.default_transport_name` = `v65`. Bus middleware: `QueuedTimeMiddleware` (sets `SentAtStamp`).
- **Middleware**: implement `MiddlewareInterface` and add it under `framework.messenger.buses.messenger.bus.default.middleware` (replaces the core list; include `QueuedTimeMiddleware`). `MessengerMiddlewareCompilerPass` automatically puts `RoutingOverwriteMiddleware` at the **start** of the chain.
- **Overriding routing**: `shopware.messenger.routing_overwrite` (class or interface -> transport name(s)).
- **Admin worker**: the browser worker calls `POST /api/_action/message-queue/consume` per transport (`shopware.admin_worker.transports`).
- **Dedup**: `DeduplicatableMessageInterface` is only a contract (`@experimental stableVersion:v6.8.0 feature:DEDUPLICATABLE_MESSAGES`).

Directory tree plugin-relative:

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── Message/SmsNotification.php
    ├── MessageHandler/SmsNotificationHandler.php
    ├── Middleware/LoggingMiddleware.php
    ├── Service/NotificationDispatcher.php
    └── Resources/config/services.php
```

Core reference: `core/Framework/MessageQueue/` (marker interfaces, `MessageHandlerCompilerPass`, `Middleware/RoutingOverwriteMiddleware`, `Api/ConsumeMessagesController`, `Subscriber/`), `core/Framework/Adapter/Messenger/`, `core/Framework/DependencyInjection/message-queue.php`.

## 3. Instructions

### 3.1 Dispatching a message

1. Message class: simple serializable object (only scalars/arrays/IDs; no entities, services or context). Default serializer: JSON (`messenger.transport.symfony_serializer`). No PHPStan types in messages (`MessagesShouldNotUsePHPStanTypes`).
2. Choose the marker: `AsyncMessageInterface` for regular background work, `LowPriorityMessageInterface` for bulk work (core: `DispatchEntityMessage`; docs: from 6.5.7.0).
3. Inject `MessageBusInterface`, `dispatch($message)` or `dispatch(new Envelope($message, [new DelayStamp(ms)]))`.
4. Size limit: `shopware.messenger.message_max_kib_size` (default 1024), only enforced with `enforce_message_size: true` (`MessageQueueSizeRestrictListener`, `MessageQueueException::maxQueueMessageSizeExceeded`; `queueMessageSizeExceeded` is `@deprecated tag:v6.8.0`).

### 3.2 Handler

- `#[AsMessageHandler]` on the class, `__invoke(Message $m)`. Multiple handlers per message are possible.
- `MessageHandlerCompilerPass` merges attribute arguments (`handles`, `method`, `priority`, `fromTransport`, ...) into the tag `messenger.message_handler` (tag arguments take precedence). It only processes **tagged** services: set the tag explicitly in the plugin.
- Build it idempotent: retries and the Admin worker can execute the same message multiple times.
- Exception in the handler -> retry; after `max_retries` the message ends up in the `failed` transport.

### 3.3 Transports and routing

Add your own transport (e.g. RabbitMQ) in the **project**:

```yaml
# config/packages/messenger.yaml (project)
framework:
    messenger:
        transports:
            my_transport:
                dsn: '%env(MY_TRANSPORT_DSN)%'
                retry_strategy: { max_retries: 5, delay: 2000, multiplier: 2 }
        routing:
            'SwagExample\Message\HeavyJob': my_transport
```

Resolution: explicit Symfony routing (`framework.messenger.routing`) applies first; `routing_overwrite` takes effect via `RoutingOverwriteMiddleware`: for received messages (`ReceivedStamp`) or an existing `TransportNamesStamp` nothing happens; otherwise match by exact class, then by `instanceof`. Value: string or list.

### 3.4 Middleware

1. Implement `handle(Envelope, StackInterface): Envelope` and always return `$stack->next()->handle($envelope, $stack)` (unless deliberately aborting).
2. Register as a service, add to `framework.messenger.buses.messenger.bus.default.middleware` (docs: `add-middleware.html`). **Caution**: the list is not merged with the core list but replaces it (Symfony `performNoDeepMerging`). The core registers `QueuedTimeMiddleware` there (`core/Framework/Resources/config/packages/framework.yaml`); it must be included in your own list, otherwise it is dropped (no `SentAtStamp`).
3. Order: `RoutingOverwriteMiddleware` (prepended by `MessengerMiddlewareCompilerPass`, independent of the YAML), then the configured ones (`QueuedTimeMiddleware`, your own), Symfony standard (routing, handler) at the end.
4. The same middleware runs on dispatch **and** on receipt in the worker; distinguish with `ReceivedStamp`.

### 3.5 Worker, Admin worker, CLI

Production (docs): CLI worker via systemd/supervisor, disable the Admin worker.

```bash
bin/console messenger:consume async low_priority --time-limit=60 --memory-limit=512M
bin/console messenger:stats
bin/console messenger:failed:show
bin/console messenger:failed:retry
bin/console messenger:failed:remove <id>
bin/console messenger:stop-workers      # sends restart signal
bin/console debug:messenger
```

`messenger:*` comes from the Symfony FrameworkBundle; Shopware only ships scheduled task commands under `core/Framework/MessageQueue/Command/`.

Admin worker (`shopware.yaml`, core default):

```yaml
shopware:
    admin_worker:
        enable_admin_worker: true
        enable_queue_stats_worker: true   # deprecated, removed in 6.8.0
        enable_notification_worker: true
        poll_interval: 20                 # must be < PHP max_execution_time
        memory_limit: '128M'
        transports: ["webhook", "async", "low_priority"]
```

| Aspect | `messenger:consume` | Admin worker |
|---|---|---|
| Start | CLI process, permanent | Web Worker in the browser, per transport `POST /api/_action/message-queue/consume` (`receiver`, ACL `system:queue:process`) |
| Runtime | until limit/signal | max. `poll_interval` s, memory limit from `admin_worker.memory_limit` |
| Early return | no | `EarlyReturnMessagesListener` stops as soon as a run has processed nothing |
| Parallelism | any | lock `message_queue_consume_<receiver>`; second request -> `MessageQueueException::workerIsLocked` |
| Listeners | all Symfony standard listeners | its own fresh `EventDispatcher` (TimeLimit, `CountHandledMessagesListener`, `MessageQueueStatsSubscriber`, RestartSignal, EarlyReturn, optionally MemoryLimit) |
| Operation | server | only with a logged-in admin and `enable_admin_worker` |

Consequence: Symfony's retry/failure transport listeners are missing in the Admin worker dispatcher; use the CLI worker for reliable retry.

### 3.6 Dedup (experimental)

Contract (docblock): ID identical for semantically equal messages, at most 64 ASCII characters, without type/class names, `null` = not deduplicatable; must be implemented "in the middleware/in the transport". Core implementers of the interface include, among others: `EntityIndexingMessage`, `FullEntityIndexerMessage`, `IterateEntityIndexerMessage`, `RotateAppSecretMessage`, `CleanupOldCacheFolders`, `InvalidateCacheTask`, `AdminSearchIndexingMessage`, `ElasticsearchIndexingMessage`.

### 3.7 Retry

Per transport `retry_strategy`: default 3 attempts, delay `delay * multiplier^n` ms, `max_delay: 0` = unlimited, then `failure_transport: failed`. `webhook`: 0 retries.

## 4. Examples

`src/Message/SmsNotification.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final readonly class SmsNotification implements AsyncMessageInterface
{
    public function __construct(
        public string $phoneNumber,
        public string $content,
    ) {
    }
}
```

`src/MessageHandler/SmsNotificationHandler.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\MessageHandler;

use Psr\Log\LoggerInterface;
use SwagExample\Message\SmsNotification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SmsNotificationHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(SmsNotification $message): void
    {
        // throw errors as exceptions -> retry/failure transport take effect
        $this->logger->info('SMS to ' . $message->phoneNumber . ': ' . $message->content);
    }
}
```

`src/Service/NotificationDispatcher.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use SwagExample\Message\SmsNotification;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class NotificationDispatcher
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function send(string $phone, string $text, int $delayMs = 0): void
    {
        $message = new SmsNotification($phone, $text);

        $this->bus->dispatch($delayMs > 0 ? new Envelope($message, [new DelayStamp($delayMs)]) : $message);
    }
}
```

`src/Middleware/LoggingMiddleware.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $phase = $envelope->last(ReceivedStamp::class) ? 'received' : 'dispatched';
        $this->logger->debug(\sprintf('%s: %s', $phase, $envelope->getMessage()::class));

        return $stack->next()->handle($envelope, $stack);
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\MessageHandler\SmsNotificationHandler;
use SwagExample\Middleware\LoggingMiddleware;
use SwagExample\Service\NotificationDispatcher;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SmsNotificationHandler::class)
        ->args([service('logger')])
        ->tag('messenger.message_handler');   // explicit: #[AsMessageHandler] is only read for tagged services

    $services->set(NotificationDispatcher::class)
        ->args([service('messenger.default_bus')]);

    $services->set(LoggingMiddleware::class)
        ->args([service('logger')]);
};
```

Middleware and routing overwrite in the **project** configuration `config/packages/swag_example.yaml`:

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    # replaces the core list: also add QueuedTimeMiddleware from the core
                    - 'Shopware\Core\Framework\Adapter\Messenger\Middleware\QueuedTimeMiddleware'
                    - 'SwagExample\Middleware\LoggingMiddleware'

shopware:
    messenger:
        routing_overwrite:
            'SwagExample\Message\SmsNotification': low_priority
```

Note: `Bundle::buildDefaultConfig()` (loads `Resources/config/packages/*.yaml`) is only called in the core by `Framework`, `Profiling`, `Administration`, `Storefront` and `Elasticsearch`, not for plugins. Plugin-owned `Resources/config/packages/*.yaml` therefore do not take effect automatically; use the project configuration.

Test:

```bash
bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
bin/console messenger:consume low_priority async --time-limit=60 -vv
```

Dedup message (experimental):

```php
<?php declare(strict_types=1);

namespace SwagExample\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Shopware\Core\Framework\MessageQueue\DeduplicatableMessageInterface;

final readonly class ReindexProduct implements AsyncMessageInterface, DeduplicatableMessageInterface
{
    public function __construct(public string $productId)
    {
    }

    public function deduplicationId(): ?string
    {
        return $this->productId; // max. 64 ASCII characters, no class name
    }
}
```

Plugin scaffold: [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Pitfalls

- **No marker interface and no routing**: the message runs synchronously in the request.
- **No worker running**: messages stay in `messenger_messages` (Doctrine). Production: CLI worker, do not rely on the Admin worker (only with a logged-in admin).
- **`low_priority` not consumed**: the worker must name the transport; for the Admin worker it must be in `admin_worker.transports`.
- **Entities/context/services in the message**: not serializable or too large; only IDs and scalars, load in the handler.
- **Changing a message while old ones are in the queue**: only extend the constructor backward-compatibly (core example `WebhookEventMessage::$partitionKey`).
- **Handler not idempotent**: retries produce duplicate effects.
- **Handler tag forgotten**: `#[AsMessageHandler]` alone is not enough if the service does not carry `messenger.message_handler`.
- **Middleware without `$stack->next()->handle(...)`**: the message is swallowed.
- **Workers with old code after deployment**: `messenger:stop-workers`; `PluginLifecycleSubscriber` sends a restart signal on plugin lifecycle events.
- **`poll_interval` >= `max_execution_time`**: the Admin worker request runs into a timeout.
- **Long handlers in the Admin worker** block the receiver lock.
- **Relying on the dedup interface**: experimental, no confirmed implementation.

### Deprecations and version notes (6.5/6.6 -> 6.7)

- `LowPriorityMessageInterface`/`low_priority`: from 6.5.7.0 (docs).
- `shopware.admin_worker.enable_queue_stats_worker`: deprecated from 6.8.0; replacement `shopware.messenger.stats.enabled` (+ `time_span`, default 300).
- `shopware.message_queue.type` (`shopware.yaml`): `@deprecated tag:v6.8.0`.
- `DeduplicatableMessageInterface`: new, `@experimental`.
- `WebhookEventMessage::$partitionKey`: `@deprecated tag:v6.8.0` (becomes non-nullable).
- New options in 6.7.15: `shopware.messenger.enforce_message_size`, `message_max_kib_size`, `scheduled_task.requeue_timeout`, `stats.*`, `routing_overwrite`. Whether they existed in 6.5/6.6 was not checked.
- **Docs deviate from the code**: in the 6.7.15 default, `admin_worker.transports` additionally contains `webhook` (the docs only name `["async","low_priority"]`). The code applies.
- A handler via `#[AsMessageHandler]` is the recommended way; the tag variant is still possible.

## 6. Checklist

- [ ] Message: `final readonly`, only scalars/IDs, appropriate marker interface
- [ ] Handler registered with `#[AsMessageHandler]` and tag `messenger.message_handler`
- [ ] Handler idempotent, errors as exceptions
- [ ] Routing checked (marker, `routing_overwrite` or `framework.messenger.routing`)
- [ ] Middleware calls `$stack->next()->handle()` and is registered in the project configuration
- [ ] In operation, a CLI worker runs for all used transports
- [ ] After deployments `messenger:stop-workers` or worker restart
- [ ] Retry/failure behavior tested (`messenger:failed:show`)
- [ ] No dependency on the experimental dedup feature

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/message-queue/add-message-to-queue.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/message-queue/add-message-handler.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/message-queue/add-middleware.html
- https://developer.shopware.com/docs/guides/hosting/infrastructure/message-queue.html

Source code (`core/`):
- `Framework/MessageQueue/{AsyncMessageInterface,LowPriorityMessageInterface,DeduplicatableMessageInterface,MessageHandlerCompilerPass,MessageQueueException}.php`
- `Framework/MessageQueue/Middleware/RoutingOverwriteMiddleware.php`, `Api/ConsumeMessagesController.php`, `Subscriber/{EarlyReturnMessagesListener,CountHandledMessagesListener,MessageQueueStatsSubscriber,MessageQueueSizeRestrictListener}.php`
- `Framework/DependencyInjection/CompilerPass/MessengerMiddlewareCompilerPass.php`, `Framework/DependencyInjection/message-queue.php`, `Framework/DependencyInjection/Configuration.php`
- `Framework/Adapter/Messenger/Middleware/QueuedTimeMiddleware.php`, `Stamp/SentAtStamp.php`
- `Framework/Resources/config/packages/{framework,shopware}.yaml`
- `Framework/Webhook/Handler/WebhookEventMessageHandler.php`, `System/UsageData/EntitySync/DispatchEntityMessage.php`
- `Framework/Bundle.php` (`build`, `buildDefaultConfig`), `DevOps/StaticAnalyze/PHPStan/Rules/MessageHandlerFinalRule.php`
- Admin: `core/worker/admin-worker.js`, `app/init-post/worker.init.ts`; `vendor/symfony/messenger/Worker.php`

### Gaps and uncertainties

- ⚠ GAP: No middleware/transport implementation found that evaluates `DeduplicatableMessageInterface::deduplicationId()` (neither core nor Elasticsearch); behavior unconfirmed, Redis/RabbitMQ not verified.
- ⚠ GAP: Retry in the Admin worker: `ConsumeMessagesController` uses its own `EventDispatcher` without Symfony retry/failure listeners; according to `Worker.php`, a failed message is `reject`ed without these listeners. Whether retry takes effect otherwise was not checked.
- Autoconfigure applies in plugins only with `->defaults()->autowire()->autoconfigure()` (verified live for subscribers/decorators); not active without `defaults()`. ⚠ UNVERIFIED: `#[AsMessageHandler]` was not specifically tested live (the example sets the tag explicitly).
- Confirmed (code, not executed at runtime): `Bundle::build()` and `Plugin` do not call `buildDefaultConfig()` (protected); in the core it is called by `Framework`, `Profiling`, `Administration`, `Storefront` and `Elasticsearch`. Plugin `Resources/config/packages/*.yaml` only take effect if the plugin calls it itself in `build()`; otherwise use the project configuration `config/packages/*.yaml`.
- ⚠ UNVERIFIED: CLI commands not executed (names from Symfony/docs); "synchronous without routing" is Symfony knowledge; 6.5/6.6 details only from docs notes and deprecation texts.
- ⚠ UNVERIFIED: `message_queue.type` and `enable_queue_stats_worker` concern the increment stats path, not the transport (mapping derived from the deprecation text). Content of the restart signal file in `PluginLifecycleSubscriber` not checked in detail.
