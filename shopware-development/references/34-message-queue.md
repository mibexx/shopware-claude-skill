# Message Queue (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin Arbeit asynchron über die Shopware-Message-Queue (Symfony Messenger) auslagern will: eigene Messages und Handler, Routing auf Transports, Middleware, Worker-Betrieb (CLI und Admin-Worker), Retry und Failure-Transport.

Abgrenzung: Scheduled Tasks (wiederkehrende Tasks, `scheduled-task:*`) in [T-33](./33-scheduled-tasks.md); Events/Subscriber in [T-07](./07-events-subscriber.md); Service-Registrierung in [T-05](./05-services-registrieren.md); Worker-/Hosting-Betrieb in [T-49](./49-build-deployment.md); Logging/CLI in [T-43](./43-cli-logging-systemchecks.md).

## 2. Konzepte

- Shopware nutzt **Symfony Messenger** (v7.4.21 laut `installed.json`) ohne eigene Queue-Engine. Message = serialisierbares PHP-Objekt; Versand über `Symfony\Component\Messenger\MessageBusInterface` (Service `messenger.default_bus`, Bus `messenger.bus.default`).
- **Routing per Marker-Interface** (`core/Framework/Resources/config/packages/framework.yaml`, Z. 94-98):
  - `Shopware\Core\Framework\MessageQueue\AsyncMessageInterface` -> Transport `async`
  - `Shopware\Core\Framework\MessageQueue\LowPriorityMessageInterface` -> Transport `low_priority`
  - `Symfony\Component\Mailer\Messenger\SendEmailMessage` -> `async`
  - `Shopware\Core\Framework\Webhook\Message\WebhookEventMessage` -> `webhook` (DSN `shopware-webhook://default`, 0 Retries)
  - Ohne Marker-Interface und ohne eigenes Routing: **synchron** (Symfony-Default, nicht am Shopware-Code getestet).
- **Handler**: Klasse mit `#[AsMessageHandler]` und `__invoke(MyMessage $m)`. Core-Handler sind `final readonly` (PHPStan-Regel `MessageHandlerFinalRule`, für Plugins nur empfohlen).
- **Transports (Default)**: `async` und `low_priority` über Doctrine (`MESSENGER_TRANSPORT_DSN` / `MESSENGER_TRANSPORT_LOW_PRIORITY_DSN`, `auto_setup=false`), Failure-Transport `failed` (`MESSENGER_TRANSPORT_FAILURE_DSN`). Retry: `max_retries: 3`, `delay: 1000`, `multiplier: 2`, `max_delay: 0` (1 s, 2 s, 4 s). Parameter `messenger.default_transport_name` = `v65`. Bus-Middleware: `QueuedTimeMiddleware` (setzt `SentAtStamp`).
- **Middleware**: `MiddlewareInterface` implementieren und unter `framework.messenger.buses.messenger.bus.default.middleware` eintragen (ersetzt die Core-Liste; `QueuedTimeMiddleware` mit aufführen). `MessengerMiddlewareCompilerPass` setzt `RoutingOverwriteMiddleware` automatisch an den **Anfang** der Kette.
- **Routing überschreiben**: `shopware.messenger.routing_overwrite` (Klasse oder Interface -> Transportname(n)).
- **Admin-Worker**: Browser-Worker ruft `POST /api/_action/message-queue/consume` pro Transport (`shopware.admin_worker.transports`).
- **Dedup**: `DeduplicatableMessageInterface` ist nur ein Vertrag (`@experimental stableVersion:v6.8.0 feature:DEDUPLICATABLE_MESSAGES`).

Verzeichnisbaum Plugin-relativ:

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

Core-Referenz: `core/Framework/MessageQueue/` (Marker-Interfaces, `MessageHandlerCompilerPass`, `Middleware/RoutingOverwriteMiddleware`, `Api/ConsumeMessagesController`, `Subscriber/`), `core/Framework/Adapter/Messenger/`, `core/Framework/DependencyInjection/message-queue.php`.

## 3. Anleitung

### 3.1 Message dispatchen

1. Message-Klasse: einfaches serialisierbares Objekt (nur Skalare/Arrays/IDs; keine Entities, Services oder Context). Default-Serializer: JSON (`messenger.transport.symfony_serializer`). Keine PHPStan-Typen in Messages (`MessagesShouldNotUsePHPStanTypes`).
2. Marker wählen: `AsyncMessageInterface` für reguläre Hintergrundarbeit, `LowPriorityMessageInterface` für Massenarbeit (Core: `DispatchEntityMessage`; Doku: ab 6.5.7.0).
3. `MessageBusInterface` injizieren, `dispatch($message)` oder `dispatch(new Envelope($message, [new DelayStamp(ms)]))`.
4. Größenlimit: `shopware.messenger.message_max_kib_size` (Default 1024), erzwungen nur mit `enforce_message_size: true` (`MessageQueueSizeRestrictListener`, `MessageQueueException::maxQueueMessageSizeExceeded`; `queueMessageSizeExceeded` ist `@deprecated tag:v6.8.0`).

### 3.2 Handler

- `#[AsMessageHandler]` an der Klasse, `__invoke(Message $m)`. Mehrere Handler pro Message möglich.
- `MessageHandlerCompilerPass` mergt Attribut-Argumente (`handles`, `method`, `priority`, `fromTransport`, ...) in das Tag `messenger.message_handler` (Tag-Argumente haben Vorrang). Er verarbeitet nur **getaggte** Services: im Plugin Tag explizit setzen.
- Idempotent bauen: Retries und Admin-Worker können dieselbe Message mehrfach ausführen.
- Exception im Handler -> Retry; nach `max_retries` landet die Message im Transport `failed`.

### 3.3 Transports und Routing

Eigenen Transport (z. B. RabbitMQ) im **Projekt** ergänzen:

```yaml
# config/packages/messenger.yaml (Projekt)
framework:
    messenger:
        transports:
            my_transport:
                dsn: '%env(MY_TRANSPORT_DSN)%'
                retry_strategy: { max_retries: 5, delay: 2000, multiplier: 2 }
        routing:
            'SwagExample\Message\HeavyJob': my_transport
```

Auflösung: explizites Symfony-Routing (`framework.messenger.routing`) wirkt zuerst; `routing_overwrite` greift über `RoutingOverwriteMiddleware`: bei empfangenen Messages (`ReceivedStamp`) oder vorhandenem `TransportNamesStamp` passiert nichts; sonst Match per exakter Klasse, danach per `instanceof`. Wert: String oder Liste.

### 3.4 Middleware

1. `handle(Envelope, StackInterface): Envelope` implementieren und immer `$stack->next()->handle($envelope, $stack)` zurückgeben (außer bewusst abbrechen).
2. Als Service registrieren, in `framework.messenger.buses.messenger.bus.default.middleware` eintragen (Doku: `add-middleware.html`). **Achtung**: Die Liste wird nicht mit der Core-Liste gemergt, sondern ersetzt sie (Symfony `performNoDeepMerging`). Der Core trägt dort `QueuedTimeMiddleware` ein (`core/Framework/Resources/config/packages/framework.yaml`); sie muss in der eigenen Liste mit aufgeführt werden, sonst fällt sie weg (kein `SentAtStamp`).
3. Reihenfolge: `RoutingOverwriteMiddleware` (vom `MessengerMiddlewareCompilerPass` vorangestellt, unabhängig von der YAML), danach konfigurierte (`QueuedTimeMiddleware`, eigene), Symfony-Standard (Routing, Handler) am Ende.
4. Dieselbe Middleware läuft beim Dispatch **und** beim Empfang im Worker; mit `ReceivedStamp` unterscheiden.

### 3.5 Worker, Admin-Worker, CLI

Produktion (Doku): CLI-Worker per systemd/supervisor, Admin-Worker abschalten.

```bash
bin/console messenger:consume async low_priority --time-limit=60 --memory-limit=512M
bin/console messenger:stats
bin/console messenger:failed:show
bin/console messenger:failed:retry
bin/console messenger:failed:remove <id>
bin/console messenger:stop-workers      # sendet Restart-Signal
bin/console debug:messenger
```

`messenger:*` stammt aus dem Symfony FrameworkBundle; Shopware liefert unter `core/Framework/MessageQueue/Command/` nur Scheduled-Task-Kommandos.

Admin-Worker (`shopware.yaml`, Core-Default):

```yaml
shopware:
    admin_worker:
        enable_admin_worker: true
        enable_queue_stats_worker: true   # deprecated, entfällt in 6.8.0
        enable_notification_worker: true
        poll_interval: 20                 # muss < PHP max_execution_time sein
        memory_limit: '128M'
        transports: ["webhook", "async", "low_priority"]
```

| Aspekt | `messenger:consume` | Admin-Worker |
|---|---|---|
| Start | CLI-Prozess, dauerhaft | Web Worker im Browser, pro Transport `POST /api/_action/message-queue/consume` (`receiver`, ACL `system:queue:process`) |
| Laufzeit | bis Limit/Signal | max. `poll_interval` s, Memory-Limit aus `admin_worker.memory_limit` |
| Early Return | nein | `EarlyReturnMessagesListener` stoppt, sobald ein Durchlauf nichts verarbeitet hat |
| Parallelität | beliebig | Lock `message_queue_consume_<receiver>`; zweiter Request -> `MessageQueueException::workerIsLocked` |
| Listener | alle Symfony-Standard-Listener | eigener frischer `EventDispatcher` (TimeLimit, `CountHandledMessagesListener`, `MessageQueueStatsSubscriber`, RestartSignal, EarlyReturn, optional MemoryLimit) |
| Betrieb | Server | nur bei eingeloggtem Admin und `enable_admin_worker` |

Folge: Die Retry-/Failure-Transport-Listener von Symfony fehlen im Admin-Worker-Dispatcher; für verlässliches Retry den CLI-Worker nutzen.

### 3.6 Dedup (experimentell)

Vertrag (Docblock): ID identisch für semantisch gleiche Messages, höchstens 64 ASCII-Zeichen, ohne Typ-/Klassennamen, `null` = nicht deduplizierbar; muss "in der Middleware/im Transport" umgesetzt werden. Core-Implementierer des Interfaces u. a.: `EntityIndexingMessage`, `FullEntityIndexerMessage`, `IterateEntityIndexerMessage`, `RotateAppSecretMessage`, `CleanupOldCacheFolders`, `InvalidateCacheTask`, `AdminSearchIndexingMessage`, `ElasticsearchIndexingMessage`.

### 3.7 Retry

Pro Transport `retry_strategy`: Default 3 Versuche, Verzögerung `delay * multiplier^n` ms, `max_delay: 0` = unbegrenzt, danach `failure_transport: failed`. `webhook`: 0 Retries.

## 4. Beispiele

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
        // Fehler als Exception werfen -> Retry/Failure-Transport greifen
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
        ->tag('messenger.message_handler');   // explizit: #[AsMessageHandler] wird nur bei getaggten Services gelesen

    $services->set(NotificationDispatcher::class)
        ->args([service('messenger.default_bus')]);

    $services->set(LoggingMiddleware::class)
        ->args([service('logger')]);
};
```

Middleware und Routing-Overwrite in der **Projekt**-Konfiguration `config/packages/swag_example.yaml`:

```yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    # ersetzt die Core-Liste: QueuedTimeMiddleware aus dem Core mit eintragen
                    - 'Shopware\Core\Framework\Adapter\Messenger\Middleware\QueuedTimeMiddleware'
                    - 'SwagExample\Middleware\LoggingMiddleware'

shopware:
    messenger:
        routing_overwrite:
            'SwagExample\Message\SmsNotification': low_priority
```

Hinweis: `Bundle::buildDefaultConfig()` (lädt `Resources/config/packages/*.yaml`) wird im Core nur von `Framework`, `Profiling`, `Administration`, `Storefront` und `Elasticsearch` aufgerufen, nicht für Plugins. Plugin-eigene `Resources/config/packages/*.yaml` wirken daher nicht automatisch; Projektkonfiguration verwenden.

Test:

```bash
bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
bin/console messenger:consume low_priority async --time-limit=60 -vv
```

Dedup-Message (experimentell):

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
        return $this->productId; // max. 64 ASCII-Zeichen, kein Klassenname
    }
}
```

Plugin-Gerüst: [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Fallstricke

- **Kein Marker-Interface und kein Routing**: Message läuft synchron im Request.
- **Kein Worker läuft**: Messages bleiben in `messenger_messages` (Doctrine). Produktion: CLI-Worker, nicht auf den Admin-Worker verlassen (nur bei eingeloggtem Admin).
- **`low_priority` nicht konsumiert**: Worker muss den Transport nennen; beim Admin-Worker muss er in `admin_worker.transports` stehen.
- **Entities/Context/Services in der Message**: nicht serialisierbar bzw. zu groß; nur IDs und Skalare, im Handler laden.
- **Message ändern, während alte in der Queue liegen**: Konstruktor nur rückwärtskompatibel erweitern (Core-Beispiel `WebhookEventMessage::$partitionKey`).
- **Handler nicht idempotent**: Retries erzeugen doppelte Effekte.
- **Handler-Tag vergessen**: `#[AsMessageHandler]` allein reicht nicht, wenn der Service nicht `messenger.message_handler` trägt.
- **Middleware ohne `$stack->next()->handle(...)`**: Message wird verschluckt.
- **Worker mit altem Code nach Deployment**: `messenger:stop-workers`; `PluginLifecycleSubscriber` sendet bei Plugin-Lifecycle ein Restart-Signal.
- **`poll_interval` >= `max_execution_time`**: Admin-Worker-Request läuft in Timeout.
- **Lange Handler im Admin-Worker** blockieren den Receiver-Lock.
- **Dedup-Interface verlassen**: experimentell, keine belegte Implementierung.

### Deprecations und Versionshinweise (6.5/6.6 -> 6.7)

- `LowPriorityMessageInterface`/`low_priority`: ab 6.5.7.0 (Doku).
- `shopware.admin_worker.enable_queue_stats_worker`: deprecated ab 6.8.0; Ersatz `shopware.messenger.stats.enabled` (+ `time_span`, Default 300).
- `shopware.message_queue.type` (`shopware.yaml`): `@deprecated tag:v6.8.0`.
- `DeduplicatableMessageInterface`: neu, `@experimental`.
- `WebhookEventMessage::$partitionKey`: `@deprecated tag:v6.8.0` (wird non-nullable).
- Neue Optionen in 6.7.15: `shopware.messenger.enforce_message_size`, `message_max_kib_size`, `scheduled_task.requeue_timeout`, `stats.*`, `routing_overwrite`. Ob sie in 6.5/6.6 existierten, nicht geprüft.
- **Doku weicht vom Code ab**: `admin_worker.transports` enthält im 6.7.15-Default zusätzlich `webhook` (Doku nennt nur `["async","low_priority"]`). Code gilt.
- Handler per `#[AsMessageHandler]` ist empfohlener Weg; Tag-Variante weiterhin möglich.

## 6. Checkliste

- [ ] Message: `final readonly`, nur Skalare/IDs, passendes Marker-Interface
- [ ] Handler mit `#[AsMessageHandler]` und Tag `messenger.message_handler` registriert
- [ ] Handler idempotent, Fehler als Exception
- [ ] Routing geprüft (Marker, `routing_overwrite` oder `framework.messenger.routing`)
- [ ] Middleware ruft `$stack->next()->handle()` und ist in der Projektkonfiguration eingetragen
- [ ] Im Betrieb läuft ein CLI-Worker für alle genutzten Transports
- [ ] Nach Deployments `messenger:stop-workers` bzw. Worker-Neustart
- [ ] Retry-/Failure-Verhalten getestet (`messenger:failed:show`)
- [ ] Keine Abhängigkeit auf experimentelle Dedup-Funktion

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/message-queue/add-message-to-queue.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/message-queue/add-message-handler.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/message-queue/add-middleware.html
- https://developer.shopware.com/docs/guides/hosting/infrastructure/message-queue.html

Quellcode (`core/`):
- `Framework/MessageQueue/{AsyncMessageInterface,LowPriorityMessageInterface,DeduplicatableMessageInterface,MessageHandlerCompilerPass,MessageQueueException}.php`
- `Framework/MessageQueue/Middleware/RoutingOverwriteMiddleware.php`, `Api/ConsumeMessagesController.php`, `Subscriber/{EarlyReturnMessagesListener,CountHandledMessagesListener,MessageQueueStatsSubscriber,MessageQueueSizeRestrictListener}.php`
- `Framework/DependencyInjection/CompilerPass/MessengerMiddlewareCompilerPass.php`, `Framework/DependencyInjection/message-queue.php`, `Framework/DependencyInjection/Configuration.php`
- `Framework/Adapter/Messenger/Middleware/QueuedTimeMiddleware.php`, `Stamp/SentAtStamp.php`
- `Framework/Resources/config/packages/{framework,shopware}.yaml`
- `Framework/Webhook/Handler/WebhookEventMessageHandler.php`, `System/UsageData/EntitySync/DispatchEntityMessage.php`
- `Framework/Bundle.php` (`build`, `buildDefaultConfig`), `DevOps/StaticAnalyze/PHPStan/Rules/MessageHandlerFinalRule.php`
- Admin: `core/worker/admin-worker.js`, `app/init-post/worker.init.ts`; `vendor/symfony/messenger/Worker.php`

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Keine Middleware/Transport-Implementierung gefunden, die `DeduplicatableMessageInterface::deduplicationId()` auswertet (weder Core noch Elasticsearch); Verhalten unbelegt, Redis/RabbitMQ nicht verifiziert.
- ⚠ LÜCKE: Retry im Admin-Worker: `ConsumeMessagesController` nutzt einen eigenen `EventDispatcher` ohne Symfony-Retry-/Failure-Listener; laut `Worker.php` wird eine fehlgeschlagene Message ohne diese Listener `reject`ed. Ob Retry anderweitig greift, nicht geprüft.
- Autoconfigure greift in Plugins nur mit `->defaults()->autowire()->autoconfigure()` (live bestätigt für Subscriber/Decorator); ohne `defaults()` nicht aktiv. ⚠ UNGEPRÜFT: `#[AsMessageHandler]` wurde live nicht gezielt getestet (Beispiel setzt das Tag explizit).
- Belegt (Code, nicht zur Laufzeit ausgeführt): `Bundle::build()` und `Plugin` rufen `buildDefaultConfig()` (protected) nicht auf; im Core rufen es `Framework`, `Profiling`, `Administration`, `Storefront` und `Elasticsearch` auf. Plugin-`Resources/config/packages/*.yaml` wirken nur, wenn das Plugin es selbst in `build()` aufruft; sonst Projektkonfiguration `config/packages/*.yaml` verwenden.
- ⚠ UNGEPRÜFT: CLI-Kommandos nicht ausgeführt (Namen aus Symfony/Doku); "synchron ohne Routing" ist Symfony-Wissen; 6.5/6.6-Details nur aus Doku-Hinweisen und Deprecation-Texten.
- ⚠ UNGEPRÜFT: `message_queue.type` und `enable_queue_stats_worker` betreffen den Increment-Stats-Pfad, nicht den Transport (Zuordnung aus Deprecation-Text abgeleitet). Inhalt der Restart-Signal-Datei im `PluginLifecycleSubscriber` nicht im Detail geprüft.
