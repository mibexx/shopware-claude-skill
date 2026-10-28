# Mail templates and mail data (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin creates its own mail template type including a template, adds variables to mails (events), sends mails from its own code, or needs to clarify the assignment of template, sales channel and language.

Scope: flow actions and triggers (`action.mail.send`, custom flow events) in [T-09 Flow Builder](./09-flow-builder.md); migrations in general in [T-04](./04-database-migrations.md); events/subscribers in [T-07](./07-events-subscriber.md); service decoration in [T-06](./06-service-decoration.md); sales channel distinction in [T-20](./20-sales-channel-distinction.md).

## 2. Concepts

- **Data model** (`core/Content/MailTemplate/`):
  - `mail_template_type` (`technical_name`, `available_entities`, translated name).
  - `mail_template` (`system_default`, FK to the type); translated in `mail_template_translation`: `subject`, `sender_name`, `description`, `content_html`, `content_plain`.
  - `mail_header_footer` belongs to the **sales channel** (`sales_channel.mail_header_footer_id`, field `mailHeaderFooter`), not to the template.
- **Custom type plus template** via a **plugin migration** (`INSERT` into the four tables). Reference: `Migration1763377570CreatePasswordChangeMailTemplate` (transaction, existence check of the type). Plugin migrations live in `src/Migration` (namespace `<PluginNamespace>\Migration`, `Bundle::getMigrationNamespace()`).
- **Enriching mail data** via events:
  - `MailBeforeValidateEvent` (`mail.before.send`): changes `data` and `templateData`; stoppable (prevents sending).
  - `MailTemplateRenderContextEvent`: runs directly before rendering, only `templateData`, knows the sales channel (`getSalesChannel()`).
  - `MailBeforeSentEvent` (`mail.after.create.message`): finished `Email`, e.g. for attachments; stoppable. `MailSentEvent`: after sending.
- **Sending**: `AbstractMailService::send(array $data, Context $context, array $templateData = []): ?Email` (impl. `MailService`). With a stored template: `SendMailTemplate::send(SendMailTemplateParams, Context)`. In the Flow Builder: action `action.mail.send` (`SendMailAction`).
- **Sales channel / language assignment**:
  - There is no longer a `mail_template` <-> sales channel table (`mail_template_sales_channel` deleted in 6.5, `Migration1675082889DropUnusedTables`). The template is chosen **per flow action** via `mailTemplateId`; differentiation via flow rules or multiple flows.
  - The sales channel comes from `data['salesChannelId']` and determines sender, header/footer, domain/snippets.
  - The language comes from `Context::getLanguageId()` and the translation chain (`SendMailTemplate::buildContext()`: `[languageId, parentLanguageId, Defaults::LANGUAGE_SYSTEM]`; in flows `LanguageAware::LANGUAGE_ID`).

Directory tree plugin-relative:

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── Migration/Migration1760000000AddMailTemplate.php
    ├── Subscriber/MailDataSubscriber.php
    └── Resources/config/services.php
```

Core locations: `core/Content/MailTemplate/Service/Event/*`, `core/Content/Mail/Service/{AbstractMailService,MailService,SendMailTemplate,SendMailTemplateParams}.php`, `core/Content/Flow/Dispatching/Action/SendMailAction.php`.

## 3. Instructions

1. Create the plugin: `bin/console plugin:create SwagExample` ([T-02](./02-plugin-structure.md)).
2. Create the migration following example 4.1 (idempotent, no deletion in the plugin).
3. Subscriber and service registration (4.2, 4.3).
4. Install and activate (migrations run during this; alternatively `database:migrate`):
   ```bash
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
   bin/console database:migrate --all SwagExample
   bin/console cache:clear
   ```
5. In the Administration (Settings -> Shop -> Email templates, `sw-mail-template`), check the template and test it via preview (route `api.action.mail_template.preview`).
6. **Define the trigger**: Flow Builder, action "Send email" with the template (`sw-flow-mail-send-modal`), or sending via code (4.4). A template alone sends nothing.
7. **Different per sales channel**: rule (sales channel condition) on the flow action, or a separate flow per sales channel with its own `mailTemplateId`. Choose header/footer in the sales channel.
8. **Custom flow event for sending mail**: must implement `MailAware` (`getMailStruct(): MailRecipientStruct`, `getSalesChannelId()`). Pattern: `CustomerPasswordChangedEvent`; its `EVENT_NAME` is also the `technical_name` of the mail type. Flow setup via migration: `Migration1763377575SendEmailAfterPasswordChangeFlow` (`flow`, `flow_sequence`, `flow_template`). Details: [T-09](./09-flow-builder.md).

### Sequence in `MailService::send()`

1. `MailBeforeValidateEvent`; if propagation is stopped, `null`.
2. `DataValidator` checks `recipients`, `salesChannelId` (EntityExists), `contentHtml`, `contentPlain`, `subject`.
3. Load the sales channel (incl. `mailHeaderFooter`, domain of the context language); translator with sales channel/language/locale for snippets.
4. `createMail()`: adds `templateData['salesChannel']`/`['salesChannelId']`, dispatches `MailTemplateRenderContextEvent`, determines the sender (`senderMail`/`senderEmail` from data, otherwise `core.basicInformation.email`, otherwise `core.mailerSettings.senderAddress`, per sales channel), renders subject, sender name and contents incl. header/footer (`MailTemplateContentBuilder`, `StringTemplateRenderer`). In test mode (`SetupStagingEvent::CONFIG_FLAG` or `testMode`), additional headers.
5. Attachments (`DataPart`), then `MailBeforeSentEvent` (stoppable).
6. Sending via `AbstractMailSender`, then `MailSentEvent`. Errors: `MailErrorEvent` plus log, return `null`.

## 4. Examples

### 4.1 `src/Migration/Migration1760000000AddMailTemplate.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1760000000AddMailTemplate extends MigrationStep
{
    public const TECHNICAL_NAME = 'swag_example_mail';

    public function getCreationTimestamp(): int
    {
        return 1760000000;
    }

    public function update(Connection $connection): void
    {
        if ($connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :name',
            ['name' => self::TECHNICAL_NAME]
        )) {
            return; // idempotent
        }

        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $typeId = Uuid::randomBytes();
        $templateId = Uuid::randomBytes();
        $systemLanguage = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $connection->beginTransaction();

        try {
            $connection->insert('mail_template_type', [
                'id' => $typeId,
                'technical_name' => self::TECHNICAL_NAME,
                'available_entities' => json_encode(['customer' => 'customer']),
                'created_at' => $now,
            ]);
            $connection->insert('mail_template_type_translation', [
                'mail_template_type_id' => $typeId,
                'language_id' => $systemLanguage,
                'name' => 'Swag mail example',
                'created_at' => $now,
            ]);
            $connection->insert('mail_template', [
                'id' => $templateId,
                'mail_template_type_id' => $typeId,
                'system_default' => 0,
                'created_at' => $now,
            ]);
            $connection->insert('mail_template_translation', [
                'mail_template_id' => $templateId,
                'language_id' => $systemLanguage,
                'subject' => 'Hello {{ customer.firstName }}',
                'sender_name' => '{{ salesChannel.translated.name }}',
                'content_html' => '<p>Hello {{ customer.firstName }}, {{ swagGreeting }}</p>',
                'content_plain' => 'Hello {{ customer.firstName }}, {{ swagGreeting }}',
                'created_at' => $now,
            ]);

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }
}
```

More languages: one row per language in both translation tables; `language_id` via `SELECT language.id FROM language INNER JOIN locale ON locale.id = language.translation_code_id WHERE locale.code = :iso` (model `fetchLanguageIdByIso()` in the core migration). Migration scaffold: [../templates/migration/README.md](../templates/migration/README.md).

### 4.2 `src/Subscriber/MailDataSubscriber.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Content\MailTemplate\Service\Event\MailTemplateRenderContextEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MailDataSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            MailBeforeValidateEvent::class => 'onBeforeValidate',
            MailTemplateRenderContextEvent::class => 'onRenderContext',
        ];
    }

    public function onBeforeValidate(MailBeforeValidateEvent $event): void
    {
        // variable for Twig in the template: {{ swagGreeting }}
        $event->addTemplateData('swagGreeting', 'welcome back!');
    }

    public function onRenderContext(MailTemplateRenderContextEvent $event): void
    {
        // runs after salesChannel/salesChannelId have been set in templateData
        $event->addTemplateData('swagShop', $event->getSalesChannel()?->getTranslation('name'));
    }
}
```

### 4.3 `src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Subscriber\MailDataSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(MailDataSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```

(Service registration in general: [T-05](./05-registering-services.md); subscribers: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).)

### 4.4 Sending from your own code

```php
use Shopware\Core\Content\Mail\Service\AbstractMailService;

public function __construct(private readonly AbstractMailService $mailService) {}

$this->mailService->send([
    'recipients' => ['customer@example.com' => 'Max Customer'],
    'salesChannelId' => $salesChannelId,
    'subject' => 'Hello {{ customer.firstName }}',   // rendered with templateData
    'senderName' => '{{ salesChannel.translated.name }}',
    'contentHtml' => '<p>Hi {{ customer.firstName }}</p>',
    'contentPlain' => 'Hi {{ customer.firstName }}',
], $context, ['customer' => $customer]);
```

Registration: there is no service alias for `AbstractMailService` (only the service ID `MailService::class`, `core/Content/DependencyInjection/mail_template.php`), so autowiring to the abstract class fails. Pass the concrete service explicitly:

```php
use Shopware\Core\Content\Mail\Service\MailService;

$services->set(MyMailSender::class)
    ->args([service(MailService::class)]);
```

With a stored template: `SendMailTemplate::send(new SendMailTemplateParams($mailTemplateId, $languageId, [new Address('a@b.de', 'A')], ['customer' => $customer], [], $salesChannelId), $context)`. According to its class header, `SendMailTemplate` is not `@internal` in 6.7.15.1, only its constructor is.

## 5. Pitfalls

- **Variable empty**: `templateData` must be set before rendering (`MailBeforeValidateEvent` or `MailTemplateRenderContextEvent`); in `MailBeforeSentEvent` it is too late.
- **Decoration instead of event**: `->decorate(MailService::class)` works (service ID `MailService::class`, `core/Content/DependencyInjection/mail_template.php`), the docs show `AbstractMailService` as the parent class with `getDecorated()`. For data enrichment, the event subscriber is better.
- **Mail does not arrive, without an error in the caller**: `MailService::send()` does not throw but returns `null` on render/body/sending errors or stopped propagation; `SendMailAction` catches exceptions and only logs. Check `MailErrorEvent` and the log.
- **Sender empty**: neither `core.basicInformation.email` nor `core.mailerSettings.senderAddress` set -> `MailErrorEvent`.
- **Deleting the template on uninstall**: the docs warn (inconsistencies); the core works idempotently.
- **`salesChannelId` invalid**: validation fails (`EntityExists`).
- **Type without a matching flow event**: the type appears in the UI but is never triggered.
- **`available_entities` wrong**: keys must match a provider with tag `shopware.mail.data_provider`, otherwise `MailTemplateException::missingDataProvider` (preview, `MailDataProvider::getTemplateData()`). Core providers (tag key, `core/Content/DependencyInjection/shared.php`): `order`, `customer`, `customer_group`, `product`, `customer_recovery`, `newsletter_recipient`, `order_transaction`, `sales_channel`, `state_machine_state`, `user_recovery`.
- **Header/footer missing**: it is attached to the sales channel; without `mailHeaderFooter` the template is sent unchanged.

### Deprecations and version notes (6.5/6.6 -> 6.7)

- `mail_template_sales_channel` deleted in 6.5; the related events in `MailTemplateEvents` are `@deprecated tag:v6.8.0` without replacement.
- `mail_template_type.template_data`: `Deprecated('v6.7.12.0','v6.8.0.0')` (`MailTemplateTypeDefinition`), as are `MailTemplateTypeEntity::get/setTemplateData`; `SendMailAction::updateMailTemplateType` only writes it when `v6.8.0.0` is inactive.
- API routes `api.action.mail_template.validate` and `.build` deprecated (removal 6.8); replacement `api.action.mail_template.preview` (`MailActionController`).
- `MailSender`: constant for message size `@deprecated tag:v6.8.0` -> `shopware.messenger.message_max_kib_size`. `SalesChannelNotFoundException` `@deprecated tag:v6.8.0`.
- `MailTemplateRenderContextEvent`, `SendMailTemplate`/`SendMailTemplateParams`, `MailTemplateSendService` exist in 6.7.15.1; introduction version not confirmed.
- New mail types in 6.7 include, among others, `order.payment_method.changed`, `revocation_request.merchant/customer`, `customer.password.changed`. The core itself uses `MailBeforeValidateEvent`/`MailBeforeSentEvent` (`GaranLabelMailSubscriber`: data and inline attachments).
- The Admin module `sw-mail-template` has a different route structure under `Feature.isActive('V6_8_0_0')`.
- The docs pages `add-data-to-mails`/`add-mail-template` are reachable, but mention neither `MailTemplateRenderContextEvent` nor the type/flow relationship.

## 6. Checklist

- [ ] Type `technical_name` unique (for a flow trigger = `EVENT_NAME` of the event)
- [ ] Migration idempotent, in a transaction, translations at least for the system language
- [ ] `available_entities` matches registered data providers
- [ ] Additional variables set via event before rendering
- [ ] Trigger present (flow with action "Send email" or sending via code)
- [ ] Sales channel differentiation via flow rules/flows and header/footer checked
- [ ] Sender address configured
- [ ] Preview tested in the Administration, `MailErrorEvent`/log checked
- [ ] The plugin does not delete the template on uninstall

## 7. Sources

Docs (retrieved 2026-10-28):
- https://developer.shopware.com/docs/guides/plugins/plugins/content/mail/add-data-to-mails.html
- https://developer.shopware.com/docs/guides/plugins/plugins/content/mail/add-mail-template.html

Source code (`core/`):
- `Content/Mail/Service/{AbstractMailService,MailService,SendMailTemplate,SendMailTemplateParams}.php`
- `Content/MailTemplate/Service/Event/{MailBeforeValidateEvent,MailTemplateRenderContextEvent,MailBeforeSentEvent,MailSentEvent,MailErrorEvent}.php`
- `Content/MailTemplate/{MailTemplateDefinition,MailTemplateTypes}.php`, `Aggregate/MailTemplateType/MailTemplateTypeDefinition.php`, `Service/{MailTemplateContentBuilder,MailDataProvider,MailTemplateSendService}.php`, `Api/MailActionController.php`
- `Content/Flow/Dispatching/Action/SendMailAction.php`, `Framework/Event/MailAware.php`, `Checkout/Customer/Event/CustomerPasswordChangedEvent.php`
- `Migration/V6_7/{Migration1763377570CreatePasswordChangeMailTemplate,Migration1763377575SendEmailAfterPasswordChangeFlow}.php`, `Migration/Traits/UpdateMailTrait.php`, `Migration/V6_5/Migration1675082889DropUnusedTables.php`
- `Content/DependencyInjection/{mail,mail_template,shared}.php`, `Content/Product/Garan/GaranLabelMailSubscriber.php`, `System/SalesChannel/SalesChannelDefinition.php`, `Framework/Bundle.php` (`getMigrationNamespace`)
- Admin: `module/sw-mail-template/index.js`, `module/sw-flow/component/modals/sw-flow-mail-send-modal/index.js`

### Gaps and uncertainties

- ⚠ UNVERIFIED: Examples (migration, subscriber, sending) and CLI not executed in a running installation; migration written following the core model. The Twig variables `customer`/`salesChannel` require a matching flow event or `templateData` passed when sending directly.
- ⚠ GAP: That the flow trigger event carries the same name as `technical_name` is derived from the core pattern; how the Administration filters templates per event was only partly read. How a custom event appears in the Flow Builder via `BusinessEventCollector` was not explored in depth.
- ⚠ GAP: Introduction version of `MailTemplateRenderContextEvent`, `SendMailTemplate`, `MailTemplateSendService` not confirmed; whether the equivalent exists in 6.6 is unverified.
- ⚠ UNVERIFIED: The effect of `SetupStagingEvent::CONFIG_FLAG` (test mode) only derived from `createMail()`; language detection in flows (`LanguageAware::LANGUAGE_ID`, `LanguageStorer`) not checked in detail.
