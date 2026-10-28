# Scheduled Tasks (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin wiederkehrende Hintergrundarbeit braucht (Aufräumen, Synchronisieren, Berichte), die über Shopware-Scheduler und Message-Queue läuft. Sie behandelt Task- und Handler-Klasse, Registrierung, CLI-Befehle, Intervalle und das Statusmodell.

Abgrenzung: Messenger-Grundlagen (Transports, Worker, Middleware, eigene Messages) in [T-34 Message Queue](./34-message-queue.md); Service-Registrierung allgemein in [T-05](./05-services-registrieren.md); Plugin-Lifecycle in [T-03](./03-plugin-lifecycle.md); Logging/CLI in [T-43](./43-cli-logging-systemchecks.md); Deployment/Worker-Betrieb in [T-49](./49-build-deployment.md).

## 2. Konzepte

- Ein Scheduled Task besteht aus **zwei Klassen**: *Task* (Message, erbt `ScheduledTask`) und *Handler* (erbt `ScheduledTaskHandler`, `#[AsMessageHandler(handles: ...)]`, Methode `run(): void`).
- **Task-Klasse** (`core/Framework/MessageQueue/ScheduledTask/ScheduledTask.php`): Pflicht `static getTaskName(): string` und `static getDefaultInterval(): int` (Sekunden). Optional `static shouldRescheduleOnFailure(): bool` (Default `false`) und `static shouldRun(ParameterBagInterface $bag): bool` (Default `true`). Konstanten `MINUTELY=60`, `HOURLY=3600`, `DAILY=86400`, `WEEKLY=604800`. Der Konstruktor ist `final` und leer: keine Konstruktor-Argumente.
- **Registrierung im Container**:
  - Task: Tag `shopware.scheduled.task`. `AutoconfigureCompilerPass` setzt ihn per `registerForAutoconfiguration(ScheduledTask::class)` nur für Services mit aktivem Autoconfigure. Im Plugin explizit taggen.
  - Handler: Tag `messenger.message_handler` (explizit oder per Autoconfigure über `#[AsMessageHandler]`). `MessageHandlerCompilerPass` übernimmt die Attribut-Argumente ins Tag.
  - **Der Handler muss als `messenger.message_handler` getaggt sein**: Nur dann injiziert `ScheduledTaskExecutorCompilerPass` den `ScheduledTaskExecutor`, nur dann findet `TaskRunner` ihn.
- **Persistenz**: `scheduled-task:register` (`TaskRegistry::registerTasks()`) schreibt getaggte Tasks in die Tabelle `scheduled_task` und löscht Einträge, deren Klasse nicht mehr existiert. Automatisch bei Plugin-Aktivierung, -Deaktivierung, -Update (`PluginLifecycleSubscriber`) und nach Shopware-Updates (`UpdatePostFinishSubscriber`).
- **Ausführung**: `scheduled-task:run` (Dauerläufer) legt fällige Tasks auf den Message-Bus, `messenger:consume` arbeitet sie ab.
- **Intervalle**: Keine YAML-Konfiguration pro Task. Default aus `getDefaultInterval()`; zur Laufzeit DB-Spalten `run_interval` und `default_run_interval`.
- Verzeichnisbaum (Plugin-relativ):

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── ScheduledTask/
    │   ├── CleanupOldRecordsTask.php
    │   └── CleanupOldRecordsTaskHandler.php
    └── Resources/config/services.php
```

Core-Referenz: `core/Framework/MessageQueue/ScheduledTask/` (Task, Handler, Executor, Registry, Scheduler, `README.md`), `core/Framework/MessageQueue/Command/`, `core/Content/Newsletter/ScheduledTask/` (schlankes Vorbild).

## 3. Anleitung

1. **Task-Klasse** anlegen: eindeutiger `getTaskName()` mit Vendor-Präfix (`swag_example.cleanup_old_records`; Core z. B. `log_entry.cleanup`), `getDefaultInterval()`. Optional `shouldRun(ParameterBagInterface $bag)` zum Abschalten per Konfiguration (Core-Vorbild: `SitemapGenerateTask` mit `shopware.sitemap.scheduled_task.enabled`). Liefert `shouldRun()` `false`, bekommt der Task Status `skipped`. `SystemConfigService` ist dort nicht verfügbar (statische Methode).
2. **Handler** `extends ScheduledTaskHandler` mit `#[AsMessageHandler(handles: <Task>::class)]` und `run(): void`. Parent-Konstruktor verlangt `EntityRepository $scheduledTaskRepository` und `LoggerInterface $exceptionLogger`; weitere Abhängigkeiten ergänzen und `parent::__construct(...)` aufrufen. Üblich im `run()`: `Context::createCLIContext()`.
3. **Services** in `services.php` registrieren (Task: `shopware.scheduled.task`, Handler: `messenger.message_handler`).
4. **Aktivieren und testen**:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample   # ruft registerTasks() über PluginLifecycleSubscriber
   bin/console scheduled-task:list
   bin/console scheduled-task:run-single swag_example.cleanup_old_records
   ```
5. **Bei Deployment ohne Plugin-Lifecycle** selbst `bin/console scheduled-task:register` ausführen. `registerTasks()` legt neue Tasks an (`scheduled`, oder `skipped`), aktualisiert Status und bei geändertem Default `default_run_interval`; ein manuell geändertes `run_interval` bleibt erhalten (nur mitgezogen, wenn es dem alten Default entsprach, `getUpdatePayload`). Nicht mehr getaggte Tasks werden gelöscht.
6. **Betrieb**:

| Befehl | Wirkung |
|---|---|
| `bin/console scheduled-task:run [-m <mem>] [-t <sek>] [--no-wait]` | Schleife: `TaskScheduler::queueScheduledTasks()` setzt fällige Tasks auf `queued` und dispatcht sie. `--no-wait` = einmaliger Lauf. Reagiert auf Restart-Signal. |
| `bin/console messenger:consume async low_priority -vv` | Arbeitet die dispatchten Tasks ab (Transportnamen siehe Lücken). |
| `bin/console scheduled-task:run-single <taskName>` | `TaskRunner::runSingleTask()`: Status `queued`, `nextExecutionTime=now`, Handler synchron. Debugging; laut Doku seit 6.7.2.0. |
| `bin/console scheduled-task:list` | Listet Tasks mit Status. |
| `bin/console scheduled-task:schedule <taskName> [-f] [-i]` | Status `scheduled`; `-i` = `nextExecutionTime=now`; `-f` überschreibt `queued`/`running`. |
| `bin/console scheduled-task:deactivate <taskName> [-f]` | Status `inactive`. |

   Alternative zum Dauerläufer: Admin-Worker (Route `POST /api/_action/scheduled-task/run`, ACL `system:queue:process`, `Framework/MessageQueue/Api/ScheduledTaskController.php`; dort auch `GET /api/_action/scheduled-task/min-run-interval`).
7. **Intervall/Konfiguration**:
   - Code: `getDefaultInterval()`.
   - Laufzeit: Feld `runInterval` der Entity `scheduled_task` (Sekunden), über DAL-Repository `scheduled_task.repository` (oder Admin-API `PATCH /api/scheduled-task/{id}`; generische Entity-Route, pro Definition von `Framework/Api/Route/ApiRouteLoader.php` registriert, Controller `ApiController::update`).
   - `shopware.yaml`: `shopware.messenger.scheduled_task.requeue_timeout` (Default 12 Stunden; länger hängende `queued`/`running`-Tasks werden neu gequeued), `shopware.sitemap.scheduled_task.enabled`, `shopware.translation.scheduled_task.enabled` (je Default true). Ein generischer Intervall-Schalter pro Task existiert nicht.
8. **Dynamischer Zeitplan** (Doku: seit 6.7.13.0): Handler implementiert zusätzlich `DynamicallyScheduledTaskHandler::getNextExecutionTime(ScheduledTask, ScheduledTaskEntity): ?\DateTimeInterface`. `null` = now + `runInterval`; Vergangenheit = now. Persistiert über `ScheduledTaskExecutor::reschedule()`.
9. **Statusmodell** (`ScheduledTaskDefinition`): `scheduled` -> `queued` (TaskScheduler) -> `running` (Executor) -> `scheduled` (Erfolg, `lastExecutionTime`/`nextExecutionTime` aktualisiert). Exception: `shouldRescheduleOnFailure()=true` -> Log in `exceptionLogger`, zurück auf `scheduled`; `false` -> `failed`, Exception wird weitergeworfen. Weitere: `skipped`, `inactive`. Ausführbar laut `ScheduledTaskEntity::isExecutionAllowed()`: `queued`, `failed`, `skipped`, `running`.

## 4. Beispiele

`src/ScheduledTask/CleanupOldRecordsTask.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CleanupOldRecordsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'swag_example.cleanup_old_records';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        // true: Fehler wird geloggt, Task wird trotzdem neu geplant.
        // false (Default): Status "failed", Exception wird weitergeworfen.
        return true;
    }
}
```

`src/ScheduledTask/CleanupOldRecordsTaskHandler.php` (Muster `NewsletterRecipientTaskHandler`)

```php
<?php declare(strict_types=1);

namespace SwagExample\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: CleanupOldRecordsTask::class)]
final class CleanupOldRecordsTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->logger->info('swag_example.cleanup_old_records executed');
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\ScheduledTask\CleanupOldRecordsTask;
use SwagExample\ScheduledTask\CleanupOldRecordsTaskHandler;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(CleanupOldRecordsTask::class)
        ->tag('shopware.scheduled.task');

    $services->set(CleanupOldRecordsTaskHandler::class)
        ->args([
            service('scheduled_task.repository'),
            service('logger'),   // exceptionLogger (Parent)
            service('logger'),   // eigener Logger
        ])
        ->tag('messenger.message_handler');
};
```

Dynamischer Zeitplan (Handler ergänzen):

```php
use Shopware\Core\Framework\MessageQueue\ScheduledTask\DynamicallyScheduledTaskHandler;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskEntity;

final class CleanupOldRecordsTaskHandler extends ScheduledTaskHandler implements DynamicallyScheduledTaskHandler
{
    // ... Konstruktor und run() wie oben

    public function getNextExecutionTime(ScheduledTask $task, ScheduledTaskEntity $taskEntity): ?\DateTimeInterface
    {
        return new \DateTimeImmutable('+10 minutes'); // oder null -> now + runInterval
    }
}
```

Scaffolding: `bin/console plugin:create SwagExample --create-scheduled-task` (Option aus `ScheduledTaskGenerator::OPTION_NAME`) erzeugt Task-Klasse und Task-Service-Eintrag, aber **keinen Handler**. Plugin-Gerüst: [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Fallstricke

1. **Handler nicht als `messenger.message_handler` getaggt**: Executor wird nicht injiziert; in 6.7 läuft der Handler im deprecated `runLegacy`-Pfad mit Deprecation, mit Feature `v6.8.0.0` wirft `__invoke` `MessageQueueException::scheduledTaskExecutorNotSet`. `TaskRunner` findet den Handler nicht, `run-single` tut nichts.
2. **`#[AsMessageHandler(handles: ...)]` fehlt**: `TaskRunner::runSingleTask()` überspringt Handler ohne Attribut, auch bei XML-Tag.
3. **`scheduled-task:register` vergessen** (Deployment ohne Plugin-Lifecycle): Task fehlt in `scheduled_task` und läuft nie.
4. **Nur `scheduled-task:run`, aber kein `messenger:consume`**: Tasks bleiben `queued`; nach `requeue_timeout` (12 h) werden sie erneut gequeued.
5. **Konstruktor in der Task-Klasse**: `ScheduledTask::__construct` ist `final`, der Scheduler macht `new $taskClass()`. Abhängigkeiten gehören in den Handler.
6. **Parent-Konstruktor nicht aufgerufen**/Argumente fehlen: Parent braucht `scheduled_task.repository` und einen Logger.
7. **`getTaskName()` ändern/duplizieren**: Name ist Identifikator (`fetchScheduledTask` filtert darauf).
8. **`shouldRescheduleOnFailure()=false`**: Task bleibt nach Fehler `failed`. Gegenmaßnahme: `scheduled-task:schedule <name> -f` oder `true` zurückgeben.
9. **Deprecated Hooks überschreiben** (`rescheduleTask`, `markTaskRunning`, `markTaskFailed`, `rescheduleNext`): fallen mit 6.8 weg; stattdessen `DynamicallyScheduledTaskHandler`.
10. **Task-Klasse gelöscht, DB-Eintrag bleibt**: `registerTasks()` räumt auf; ein alter Worker loggt nur eine Warnung (`class_exists` fehlgeschlagen).
11. **Langläufer im `run()`**: Worker-Timeout und Restart-Signal gelten. In Batches teilen oder eigene Messages dispatchen ([T-34](./34-message-queue.md)).
12. **Mehrere Worker**: Der Executor lässt auch Status `running` zur Ausführung zu; Handler idempotent halten (Kommentar in `ScheduledTaskEntity::isExecutionAllowed()`).

### Deprecations und Versionshinweise (6.5/6.6 -> 6.7)

- 6.7.15.1: Orchestrierung liegt im `ScheduledTaskExecutor` (final, `@internal`), den `ScheduledTaskExecutorCompilerPass` in jeden getaggten Handler injiziert.
- Deprecated (Entfernung `v6.8.0.0`): `ScheduledTaskHandler::rescheduleNext()`, `markTaskRunning()`, `markTaskFailed()`, `rescheduleTask()`, Legacy-Pfad `runLegacy()`, `TaskScheduler::getNextExecutionTime()`.
- Neu: `DynamicallyScheduledTaskHandler` (Doku: seit 6.7.13.0), `scheduled-task:run-single` (Doku: seit 6.7.2.0). Im Code kein `@since`.
- Handler per `#[AsMessageHandler(handles: ...)]` ist moderner Standard; XML-Tag mit `handles` funktioniert weiter, `TaskRunner` verlangt aber das Attribut.
- 6.5/6.6 nutzten typischerweise `services.xml`; in 6.7 ist `services.php` der Standard, XML ist deprecated (`@deprecated tag:v6.8.0`, siehe [T-05](./05-services-registrieren.md)).
- Neu im Code: `ScheduleProvider` (Symfony-Scheduler-Bridge, README: "experimental") und Telemetrie `scheduled_task.*`; für Plugins nicht nötig.

## 6. Checkliste

- [ ] Task-Klasse ohne Konstruktor, `getTaskName()` mit Vendor-Präfix, `getDefaultInterval()` gesetzt
- [ ] Handler mit `#[AsMessageHandler(handles: ...)]` und `run()`, Parent-Konstruktor aufgerufen
- [ ] Task getaggt `shopware.scheduled.task`, Handler getaggt `messenger.message_handler`
- [ ] `shouldRescheduleOnFailure()` bewusst gewählt
- [ ] Plugin installiert/aktiviert bzw. `scheduled-task:register` im Deployment
- [ ] `scheduled-task:list` zeigt den Task; `scheduled-task:run-single` funktioniert
- [ ] Im Betrieb laufen `scheduled-task:run` und `messenger:consume`
- [ ] `run()` ist idempotent und bei langer Laufzeit in Batches geteilt
- [ ] Keine deprecated Handler-Hooks überschrieben

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-scheduled-task.html

Quellcode (`core/`):
- `Framework/MessageQueue/ScheduledTask/{ScheduledTask,ScheduledTaskHandler,ScheduledTaskExecutor,DynamicallyScheduledTaskHandler,ScheduledTaskDefinition,ScheduledTaskEntity}.php`, `README.md`
- `Framework/MessageQueue/ScheduledTask/Registry/TaskRegistry.php`, `Scheduler/{TaskScheduler,TaskRunner}.php`
- `Framework/MessageQueue/Command/{ScheduledTaskRunner,RegisterScheduledTasksCommand,RunSingleScheduledTaskCommand,ListScheduledTaskCommand,ScheduleScheduledTaskCommand,DeactivateScheduledTaskCommand}.php`
- `Framework/MessageQueue/Subscriber/{PluginLifecycleSubscriber,UpdatePostFinishSubscriber}.php`, `Framework/MessageQueue/MessageHandlerCompilerPass.php`
- `Framework/DependencyInjection/CompilerPass/{ScheduledTaskExecutorCompilerPass,AutoconfigureCompilerPass}.php`, `Framework/DependencyInjection/scheduled-task.php`, `Framework/DependencyInjection/Configuration.php`
- `Framework/Plugin/Command/Scaffolding/Generator/ScheduledTaskGenerator.php`
- `Content/Newsletter/ScheduledTask/NewsletterRecipientTask(Handler).php`, `Content/Sitemap/ScheduledTask/SitemapGenerateTask.php`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele wurden nicht ausgeführt; Signaturen sind gegen 6.7.15.1 geprüft.
- ⚠ UNGEPRÜFT: Transportnamen `async`, `low_priority` für `messenger:consume` sind Shopware-Standard, hier nicht gegen `framework.yaml` geprüft (`ScheduledTask` implementiert `AsyncMessageInterface`, siehe [T-34](./34-message-queue.md)).
- ⚠ LÜCKE: Ein Admin-UI zur Intervalländerung wurde nicht geprüft. Die PATCH-Route ist belegt (`ApiRouteLoader`: `/api/{entity-name mit Bindestrich}/{path}`, Methode PATCH, `ApiController::update`), ebenso die Worker-Route `/api/_action/scheduled-task/run`.
- ⚠ UNGEPRÜFT: Versionsangaben 6.7.2.0 (`run-single`) und 6.7.13.0 (`DynamicallyScheduledTaskHandler`) stammen aus der Doku, nicht aus dem Code. Einführungsversionen der Config-Schlüssel nicht verifiziert.
- ⚠ UNGEPRÜFT: Dass `Feature v6.8.0.0` in 6.7.15.1 standardmäßig inaktiv ist, ist Annahme.
- ⚠ LÜCKE: Wie ein Handler ohne Scaffolding-Stub anderweitig angelegt wird, nicht geprüft.
