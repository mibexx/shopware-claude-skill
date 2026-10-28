# Scheduled Tasks (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin needs recurring background work (cleanup, synchronization, reports) that runs via the Shopware scheduler and message queue. It covers the task and handler classes, registration, CLI commands, intervals and the status model.

Scope: Messenger basics (transports, workers, middleware, custom messages) in [T-34 Message Queue](./34-message-queue.md); service registration in general in [T-05](./05-registering-services.md); plugin lifecycle in [T-03](./03-plugin-lifecycle.md); logging/CLI in [T-43](./43-cli-logging-systemchecks.md); deployment/worker operation in [T-49](./49-build-deployment.md).

## 2. Concepts

- A scheduled task consists of **two classes**: *Task* (message, extends `ScheduledTask`) and *Handler* (extends `ScheduledTaskHandler`, `#[AsMessageHandler(handles: ...)]`, method `run(): void`).
- **Task class** (`core/Framework/MessageQueue/ScheduledTask/ScheduledTask.php`): mandatory `static getTaskName(): string` and `static getDefaultInterval(): int` (seconds). Optional `static shouldRescheduleOnFailure(): bool` (default `false`) and `static shouldRun(ParameterBagInterface $bag): bool` (default `true`). Constants `MINUTELY=60`, `HOURLY=3600`, `DAILY=86400`, `WEEKLY=604800`. The constructor is `final` and empty: no constructor arguments.
- **Registration in the container**:
  - Task: tag `shopware.scheduled.task`. `AutoconfigureCompilerPass` sets it via `registerForAutoconfiguration(ScheduledTask::class)` only for services with autoconfigure enabled. Tag it explicitly in the plugin.
  - Handler: tag `messenger.message_handler` (explicitly or via autoconfigure through `#[AsMessageHandler]`). `MessageHandlerCompilerPass` copies the attribute arguments into the tag.
  - **The handler must be tagged as `messenger.message_handler`**: only then does `ScheduledTaskExecutorCompilerPass` inject the `ScheduledTaskExecutor`, only then does `TaskRunner` find it.
- **Persistence**: `scheduled-task:register` (`TaskRegistry::registerTasks()`) writes tagged tasks to the `scheduled_task` table and deletes entries whose class no longer exists. Automatically on plugin activation, deactivation, update (`PluginLifecycleSubscriber`) and after Shopware updates (`UpdatePostFinishSubscriber`).
- **Execution**: `scheduled-task:run` (long-running process) puts due tasks on the message bus, `messenger:consume` processes them.
- **Intervals**: No YAML configuration per task. Default from `getDefaultInterval()`; at runtime DB columns `run_interval` and `default_run_interval`.
- Directory tree (plugin-relative):

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── ScheduledTask/
    │   ├── CleanupOldRecordsTask.php
    │   └── CleanupOldRecordsTaskHandler.php
    └── Resources/config/services.php
```

Core reference: `core/Framework/MessageQueue/ScheduledTask/` (Task, Handler, Executor, Registry, Scheduler, `README.md`), `core/Framework/MessageQueue/Command/`, `core/Content/Newsletter/ScheduledTask/` (lean model).

## 3. Instructions

1. Create the **task class**: unique `getTaskName()` with vendor prefix (`swag_example.cleanup_old_records`; core e.g. `log_entry.cleanup`), `getDefaultInterval()`. Optionally `shouldRun(ParameterBagInterface $bag)` to switch it off via configuration (core model: `SitemapGenerateTask` with `shopware.sitemap.scheduled_task.enabled`). If `shouldRun()` returns `false`, the task gets status `skipped`. `SystemConfigService` is not available there (static method).
2. **Handler** `extends ScheduledTaskHandler` with `#[AsMessageHandler(handles: <Task>::class)]` and `run(): void`. The parent constructor requires `EntityRepository $scheduledTaskRepository` and `LoggerInterface $exceptionLogger`; add further dependencies and call `parent::__construct(...)`. Common in `run()`: `Context::createCLIContext()`.
3. Register the **services** in `services.php` (task: `shopware.scheduled.task`, handler: `messenger.message_handler`).
4. **Activate and test**:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample   # calls registerTasks() via PluginLifecycleSubscriber
   bin/console scheduled-task:list
   bin/console scheduled-task:run-single swag_example.cleanup_old_records
   ```
5. **For deployments without plugin lifecycle**, run `bin/console scheduled-task:register` yourself. `registerTasks()` creates new tasks (`scheduled`, or `skipped`), updates the status and, if the default changed, `default_run_interval`; a manually changed `run_interval` is preserved (only updated along with it if it equaled the old default, `getUpdatePayload`). Tasks that are no longer tagged are deleted.
6. **Operation**:

| Command | Effect |
|---|---|
| `bin/console scheduled-task:run [-m <mem>] [-t <sec>] [--no-wait]` | Loop: `TaskScheduler::queueScheduledTasks()` sets due tasks to `queued` and dispatches them. `--no-wait` = single run. Reacts to the restart signal. |
| `bin/console messenger:consume async low_priority -vv` | Processes the dispatched tasks (for transport names see gaps). |
| `bin/console scheduled-task:run-single <taskName>` | `TaskRunner::runSingleTask()`: status `queued`, `nextExecutionTime=now`, handler synchronous. Debugging; according to the docs since 6.7.2.0. |
| `bin/console scheduled-task:list` | Lists tasks with status. |
| `bin/console scheduled-task:schedule <taskName> [-f] [-i]` | Status `scheduled`; `-i` = `nextExecutionTime=now`; `-f` overrides `queued`/`running`. |
| `bin/console scheduled-task:deactivate <taskName> [-f]` | Status `inactive`. |

   Alternative to the long-running process: Admin worker (route `POST /api/_action/scheduled-task/run`, ACL `system:queue:process`, `Framework/MessageQueue/Api/ScheduledTaskController.php`; there also `GET /api/_action/scheduled-task/min-run-interval`).
7. **Interval/configuration**:
   - Code: `getDefaultInterval()`.
   - Runtime: field `runInterval` of the entity `scheduled_task` (seconds), via the DAL repository `scheduled_task.repository` (or Admin API `PATCH /api/scheduled-task/{id}`; generic entity route, registered per definition by `Framework/Api/Route/ApiRouteLoader.php`, controller `ApiController::update`).
   - `shopware.yaml`: `shopware.messenger.scheduled_task.requeue_timeout` (default 12 hours; `queued`/`running` tasks hanging longer are requeued), `shopware.sitemap.scheduled_task.enabled`, `shopware.translation.scheduled_task.enabled` (each default true). A generic interval switch per task does not exist.
8. **Dynamic schedule** (docs: since 6.7.13.0): the handler additionally implements `DynamicallyScheduledTaskHandler::getNextExecutionTime(ScheduledTask, ScheduledTaskEntity): ?\DateTimeInterface`. `null` = now + `runInterval`; past = now. Persisted via `ScheduledTaskExecutor::reschedule()`.
9. **Status model** (`ScheduledTaskDefinition`): `scheduled` -> `queued` (TaskScheduler) -> `running` (Executor) -> `scheduled` (success, `lastExecutionTime`/`nextExecutionTime` updated). Exception: `shouldRescheduleOnFailure()=true` -> log in `exceptionLogger`, back to `scheduled`; `false` -> `failed`, the exception is rethrown. Others: `skipped`, `inactive`. Executable according to `ScheduledTaskEntity::isExecutionAllowed()`: `queued`, `failed`, `skipped`, `running`.

## 4. Examples

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
        // true: the error is logged, the task is rescheduled anyway.
        // false (default): status "failed", the exception is rethrown.
        return true;
    }
}
```

`src/ScheduledTask/CleanupOldRecordsTaskHandler.php` (pattern `NewsletterRecipientTaskHandler`)

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
            service('logger'),   // exceptionLogger (parent)
            service('logger'),   // own logger
        ])
        ->tag('messenger.message_handler');
};
```

Dynamic schedule (extend the handler):

```php
use Shopware\Core\Framework\MessageQueue\ScheduledTask\DynamicallyScheduledTaskHandler;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskEntity;

final class CleanupOldRecordsTaskHandler extends ScheduledTaskHandler implements DynamicallyScheduledTaskHandler
{
    // ... constructor and run() as above

    public function getNextExecutionTime(ScheduledTask $task, ScheduledTaskEntity $taskEntity): ?\DateTimeInterface
    {
        return new \DateTimeImmutable('+10 minutes'); // or null -> now + runInterval
    }
}
```

Scaffolding: `bin/console plugin:create SwagExample --create-scheduled-task` (option from `ScheduledTaskGenerator::OPTION_NAME`) generates the task class and the task service entry, but **no handler**. Plugin scaffold: [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Pitfalls

1. **Handler not tagged as `messenger.message_handler`**: the executor is not injected; in 6.7 the handler runs in the deprecated `runLegacy` path with a deprecation, with feature `v6.8.0.0` `__invoke` throws `MessageQueueException::scheduledTaskExecutorNotSet`. `TaskRunner` does not find the handler, `run-single` does nothing.
2. **`#[AsMessageHandler(handles: ...)]` missing**: `TaskRunner::runSingleTask()` skips handlers without the attribute, even with an XML tag.
3. **`scheduled-task:register` forgotten** (deployment without plugin lifecycle): the task is missing from `scheduled_task` and never runs.
4. **Only `scheduled-task:run`, but no `messenger:consume`**: tasks stay `queued`; after `requeue_timeout` (12 h) they are queued again.
5. **Constructor in the task class**: `ScheduledTask::__construct` is `final`, the scheduler does `new $taskClass()`. Dependencies belong in the handler.
6. **Parent constructor not called**/arguments missing: the parent needs `scheduled_task.repository` and a logger.
7. **Changing/duplicating `getTaskName()`**: the name is the identifier (`fetchScheduledTask` filters on it).
8. **`shouldRescheduleOnFailure()=false`**: the task stays `failed` after an error. Countermeasure: `scheduled-task:schedule <name> -f` or return `true`.
9. **Overriding deprecated hooks** (`rescheduleTask`, `markTaskRunning`, `markTaskFailed`, `rescheduleNext`): removed with 6.8; use `DynamicallyScheduledTaskHandler` instead.
10. **Task class deleted, DB entry remains**: `registerTasks()` cleans up; an old worker only logs a warning (`class_exists` failed).
11. **Long-running work in `run()`**: worker timeout and restart signal apply. Split into batches or dispatch your own messages ([T-34](./34-message-queue.md)).
12. **Multiple workers**: the executor also allows status `running` for execution; keep handlers idempotent (comment in `ScheduledTaskEntity::isExecutionAllowed()`).

### Deprecations and version notes (6.5/6.6 -> 6.7)

- 6.7.15.1: orchestration lives in the `ScheduledTaskExecutor` (final, `@internal`), which `ScheduledTaskExecutorCompilerPass` injects into every tagged handler.
- Deprecated (removal `v6.8.0.0`): `ScheduledTaskHandler::rescheduleNext()`, `markTaskRunning()`, `markTaskFailed()`, `rescheduleTask()`, legacy path `runLegacy()`, `TaskScheduler::getNextExecutionTime()`.
- New: `DynamicallyScheduledTaskHandler` (docs: since 6.7.13.0), `scheduled-task:run-single` (docs: since 6.7.2.0). No `@since` in the code.
- A handler via `#[AsMessageHandler(handles: ...)]` is the modern standard; an XML tag with `handles` still works, but `TaskRunner` requires the attribute.
- 6.5/6.6 typically used `services.xml`; in 6.7 `services.php` is the standard, XML is deprecated (`@deprecated tag:v6.8.0`, see [T-05](./05-registering-services.md)).
- New in the code: `ScheduleProvider` (Symfony Scheduler bridge, README: "experimental") and telemetry `scheduled_task.*`; not needed for plugins.

## 6. Checklist

- [ ] Task class without constructor, `getTaskName()` with vendor prefix, `getDefaultInterval()` set
- [ ] Handler with `#[AsMessageHandler(handles: ...)]` and `run()`, parent constructor called
- [ ] Task tagged `shopware.scheduled.task`, handler tagged `messenger.message_handler`
- [ ] `shouldRescheduleOnFailure()` chosen deliberately
- [ ] Plugin installed/activated or `scheduled-task:register` in the deployment
- [ ] `scheduled-task:list` shows the task; `scheduled-task:run-single` works
- [ ] In operation, `scheduled-task:run` and `messenger:consume` are running
- [ ] `run()` is idempotent and split into batches for long runtimes
- [ ] No deprecated handler hooks overridden

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-scheduled-task.html

Source code (`core/`):
- `Framework/MessageQueue/ScheduledTask/{ScheduledTask,ScheduledTaskHandler,ScheduledTaskExecutor,DynamicallyScheduledTaskHandler,ScheduledTaskDefinition,ScheduledTaskEntity}.php`, `README.md`
- `Framework/MessageQueue/ScheduledTask/Registry/TaskRegistry.php`, `Scheduler/{TaskScheduler,TaskRunner}.php`
- `Framework/MessageQueue/Command/{ScheduledTaskRunner,RegisterScheduledTasksCommand,RunSingleScheduledTaskCommand,ListScheduledTaskCommand,ScheduleScheduledTaskCommand,DeactivateScheduledTaskCommand}.php`
- `Framework/MessageQueue/Subscriber/{PluginLifecycleSubscriber,UpdatePostFinishSubscriber}.php`, `Framework/MessageQueue/MessageHandlerCompilerPass.php`
- `Framework/DependencyInjection/CompilerPass/{ScheduledTaskExecutorCompilerPass,AutoconfigureCompilerPass}.php`, `Framework/DependencyInjection/scheduled-task.php`, `Framework/DependencyInjection/Configuration.php`
- `Framework/Plugin/Command/Scaffolding/Generator/ScheduledTaskGenerator.php`
- `Content/Newsletter/ScheduledTask/NewsletterRecipientTask(Handler).php`, `Content/Sitemap/ScheduledTask/SitemapGenerateTask.php`

### Gaps and uncertainties

- ⚠ UNVERIFIED: The examples were not executed; signatures were checked against 6.7.15.1.
- ⚠ UNVERIFIED: Transport names `async`, `low_priority` for `messenger:consume` are the Shopware standard, not checked against `framework.yaml` here (`ScheduledTask` implements `AsyncMessageInterface`, see [T-34](./34-message-queue.md)).
- ⚠ GAP: An Admin UI for changing the interval was not checked. The PATCH route is confirmed (`ApiRouteLoader`: `/api/{entity-name with hyphen}/{path}`, method PATCH, `ApiController::update`), as is the worker route `/api/_action/scheduled-task/run`.
- ⚠ UNVERIFIED: Version details 6.7.2.0 (`run-single`) and 6.7.13.0 (`DynamicallyScheduledTaskHandler`) come from the docs, not from the code. Introduction versions of the config keys not verified.
- ⚠ UNVERIFIED: That `Feature v6.8.0.0` is inactive by default in 6.7.15.1 is an assumption.
- ⚠ GAP: How a handler is otherwise created without a scaffolding stub was not checked.
