# Mail-Templates und Mail-Daten (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin einen eigenen Mail-Template-Typ samt Template anlegt, Variablen in Mails ergänzt (Events), Mails aus eigenem Code versendet oder die Zuordnung von Template, Sales Channel und Sprache klären muss.

Abgrenzung: Flow-Aktionen und -Trigger (`action.mail.send`, eigene Flow-Events) in [T-09 Flow Builder](./09-flow-builder.md); Migrationen allgemein in [T-04](./04-datenbank-migrationen.md); Events/Subscriber in [T-07](./07-events-subscriber.md); Service-Dekoration in [T-06](./06-service-dekoration.md); Sales-Channel-Unterscheidung in [T-20](./20-sales-channel-unterscheidung.md).

## 2. Konzepte

- **Datenmodell** (`core/Content/MailTemplate/`):
  - `mail_template_type` (`technical_name`, `available_entities`, übersetzter Name).
  - `mail_template` (`system_default`, FK auf Typ); übersetzt in `mail_template_translation`: `subject`, `sender_name`, `description`, `content_html`, `content_plain`.
  - `mail_header_footer` gehört zum **Sales Channel** (`sales_channel.mail_header_footer_id`, Feld `mailHeaderFooter`), nicht zum Template.
- **Eigener Typ plus Template** per **Plugin-Migration** (`INSERT` in die vier Tabellen). Referenz: `Migration1763377570CreatePasswordChangeMailTemplate` (Transaktion, Existenzprüfung des Typs). Plugin-Migrationen liegen in `src/Migration` (Namespace `<PluginNamespace>\Migration`, `Bundle::getMigrationNamespace()`).
- **Mail-Daten anreichern** über Events:
  - `MailBeforeValidateEvent` (`mail.before.send`): ändert `data` und `templateData`; stoppbar (verhindert Versand).
  - `MailTemplateRenderContextEvent`: läuft direkt vor dem Rendern, nur `templateData`, kennt den Sales Channel (`getSalesChannel()`).
  - `MailBeforeSentEvent` (`mail.after.create.message`): fertige `Email`, z. B. für Anhänge; stoppbar. `MailSentEvent`: nach Versand.
- **Versand**: `AbstractMailService::send(array $data, Context $context, array $templateData = []): ?Email` (Impl. `MailService`). Mit gespeichertem Template: `SendMailTemplate::send(SendMailTemplateParams, Context)`. Im Flow Builder: Aktion `action.mail.send` (`SendMailAction`).
- **Zuordnung Sales Channel / Sprache**:
  - Keine Tabelle `mail_template` <-> Sales Channel mehr (`mail_template_sales_channel` in 6.5 gelöscht, `Migration1675082889DropUnusedTables`). Das Template wird **pro Flow-Aktion** per `mailTemplateId` gewählt; Differenzierung über Flow-Regeln bzw. mehrere Flows.
  - Der Sales Channel kommt aus `data['salesChannelId']` und bestimmt Absender, Header/Footer, Domain/Snippets.
  - Die Sprache kommt aus `Context::getLanguageId()` und der Übersetzungskette (`SendMailTemplate::buildContext()`: `[languageId, parentLanguageId, Defaults::LANGUAGE_SYSTEM]`; in Flows `LanguageAware::LANGUAGE_ID`).

Verzeichnisbaum Plugin-relativ:

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── Migration/Migration1760000000AddMailTemplate.php
    ├── Subscriber/MailDataSubscriber.php
    └── Resources/config/services.php
```

Core-Fundstellen: `core/Content/MailTemplate/Service/Event/*`, `core/Content/Mail/Service/{AbstractMailService,MailService,SendMailTemplate,SendMailTemplateParams}.php`, `core/Content/Flow/Dispatching/Action/SendMailAction.php`.

## 3. Anleitung

1. Plugin anlegen: `bin/console plugin:create SwagExample` ([T-02](./02-plugin-aufbau.md)).
2. Migration nach Beispiel 4.1 anlegen (idempotent, kein Löschen im Plugin).
3. Subscriber und Service-Registrierung (4.2, 4.3).
4. Installieren und aktivieren (Migrationen laufen dabei; ersatzweise `database:migrate`):
   ```bash
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
   bin/console database:migrate --all SwagExample
   bin/console cache:clear
   ```
5. In der Administration (Einstellungen -> Shop -> E-Mail-Templates, `sw-mail-template`) das Template prüfen und per Vorschau testen (Route `api.action.mail_template.preview`).
6. **Auslöser festlegen**: Flow Builder, Aktion "E-Mail senden" mit dem Template (`sw-flow-mail-send-modal`), oder Versand per Code (4.4). Ein Template allein versendet nichts.
7. **Pro Sales Channel unterschiedlich**: Regel (Sales-Channel-Bedingung) an der Flow-Aktion oder je Sales Channel ein eigener Flow mit eigener `mailTemplateId`. Header/Footer im Sales Channel wählen.
8. **Eigenes Flow-Event für den Mailversand**: muss `MailAware` implementieren (`getMailStruct(): MailRecipientStruct`, `getSalesChannelId()`). Muster: `CustomerPasswordChangedEvent`; dessen `EVENT_NAME` ist zugleich `technical_name` des Mail-Typs. Flow-Setup per Migration: `Migration1763377575SendEmailAfterPasswordChangeFlow` (`flow`, `flow_sequence`, `flow_template`). Details: [T-09](./09-flow-builder.md).

### Ablauf in `MailService::send()`

1. `MailBeforeValidateEvent`; bei gestoppter Propagation `null`.
2. `DataValidator` prüft `recipients`, `salesChannelId` (EntityExists), `contentHtml`, `contentPlain`, `subject`.
3. Sales Channel laden (inkl. `mailHeaderFooter`, Domain der Context-Sprache); Translator mit Sales Channel/Sprache/Locale für Snippets.
4. `createMail()`: ergänzt `templateData['salesChannel']`/`['salesChannelId']`, dispatcht `MailTemplateRenderContextEvent`, bestimmt Absender (`senderMail`/`senderEmail` aus Daten, sonst `core.basicInformation.email`, sonst `core.mailerSettings.senderAddress`, je Sales Channel), rendert Subject, Sender-Name und Inhalte inkl. Header/Footer (`MailTemplateContentBuilder`, `StringTemplateRenderer`). Im Testmodus (`SetupStagingEvent::CONFIG_FLAG` bzw. `testMode`) Zusatz-Header.
5. Anhänge (`DataPart`), dann `MailBeforeSentEvent` (stoppbar).
6. Versand über `AbstractMailSender`, danach `MailSentEvent`. Fehler: `MailErrorEvent` plus Log, Rückgabe `null`.

## 4. Beispiele

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

Weitere Sprachen: je Sprache eine Zeile in beide Translation-Tabellen; `language_id` per `SELECT language.id FROM language INNER JOIN locale ON locale.id = language.translation_code_id WHERE locale.code = :iso` (Vorbild `fetchLanguageIdByIso()` in der Core-Migration). Migrations-Gerüst: [../templates/migration/README.md](../templates/migration/README.md).

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
        // Variable fuer Twig im Template: {{ swagGreeting }}
        $event->addTemplateData('swagGreeting', 'welcome back!');
    }

    public function onRenderContext(MailTemplateRenderContextEvent $event): void
    {
        // laeuft nach dem Setzen von salesChannel/salesChannelId in templateData
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

(Service-Registrierung allgemein: [T-05](./05-services-registrieren.md); Subscriber: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).)

### 4.4 Versand aus eigenem Code

```php
use Shopware\Core\Content\Mail\Service\AbstractMailService;

public function __construct(private readonly AbstractMailService $mailService) {}

$this->mailService->send([
    'recipients' => ['kunde@example.com' => 'Max Kunde'],
    'salesChannelId' => $salesChannelId,
    'subject' => 'Hello {{ customer.firstName }}',   // wird mit templateData gerendert
    'senderName' => '{{ salesChannel.translated.name }}',
    'contentHtml' => '<p>Hi {{ customer.firstName }}</p>',
    'contentPlain' => 'Hi {{ customer.firstName }}',
], $context, ['customer' => $customer]);
```

Registrierung: Für `AbstractMailService` existiert kein Service-Alias (nur die Service-ID `MailService::class`, `core/Content/DependencyInjection/mail_template.php`), Autowiring auf die abstrakte Klasse scheitert daher. Konkreten Service explizit übergeben:

```php
use Shopware\Core\Content\Mail\Service\MailService;

$services->set(MyMailSender::class)
    ->args([service(MailService::class)]);
```

Mit gespeichertem Template: `SendMailTemplate::send(new SendMailTemplateParams($mailTemplateId, $languageId, [new Address('a@b.de', 'A')], ['customer' => $customer], [], $salesChannelId), $context)`. `SendMailTemplate` ist in 6.7.15.1 laut Klassenkopf nicht `@internal`, nur der Konstruktor.

## 5. Fallstricke

- **Variable leer**: `templateData` muss vor dem Rendern gesetzt werden (`MailBeforeValidateEvent` oder `MailTemplateRenderContextEvent`), im `MailBeforeSentEvent` ist es zu spät.
- **Decoration statt Event**: `->decorate(MailService::class)` funktioniert (Service-ID `MailService::class`, `core/Content/DependencyInjection/mail_template.php`), die Doku zeigt `AbstractMailService` als Elternklasse mit `getDecorated()`. Für Datenanreicherung ist der Event-Subscriber besser.
- **Mail kommt nicht an, ohne Fehler im Aufrufer**: `MailService::send()` wirft nicht, sondern gibt `null` zurück bei Render-/Body-/Versandfehler, gestoppter Propagation; `SendMailAction` fängt Exceptions und loggt nur. `MailErrorEvent` und Log prüfen.
- **Absender leer**: Weder `core.basicInformation.email` noch `core.mailerSettings.senderAddress` gesetzt -> `MailErrorEvent`.
- **Template beim Deinstallieren löschen**: Doku warnt (Inkonsistenzen); Core arbeitet idempotent.
- **`salesChannelId` ungültig**: Validierung schlägt fehl (`EntityExists`).
- **Typ ohne passendes Flow-Event**: Typ erscheint in der UI, wird aber nie ausgelöst.
- **`available_entities` falsch**: Schlüssel müssen zu einem Provider mit Tag `shopware.mail.data_provider` passen, sonst `MailTemplateException::missingDataProvider` (Vorschau, `MailDataProvider::getTemplateData()`). Core-Provider (Tag-Key, `core/Content/DependencyInjection/shared.php`): `order`, `customer`, `customer_group`, `product`, `customer_recovery`, `newsletter_recipient`, `order_transaction`, `sales_channel`, `state_machine_state`, `user_recovery`.
- **Header/Footer fehlt**: hängt am Sales Channel; ohne `mailHeaderFooter` wird das Template unverändert gesendet.

### Deprecations und Versionshinweise (6.5/6.6 -> 6.7)

- `mail_template_sales_channel` in 6.5 gelöscht; zugehörige Events in `MailTemplateEvents` sind `@deprecated tag:v6.8.0` ohne Ersatz.
- `mail_template_type.template_data`: `Deprecated('v6.7.12.0','v6.8.0.0')` (`MailTemplateTypeDefinition`), ebenso `MailTemplateTypeEntity::get/setTemplateData`; `SendMailAction::updateMailTemplateType` schreibt es nur bei inaktivem `v6.8.0.0`.
- API-Routen `api.action.mail_template.validate` und `.build` deprecated (Entfernung 6.8); Ersatz `api.action.mail_template.preview` (`MailActionController`).
- `MailSender`: Konstante zur Nachrichtengröße `@deprecated tag:v6.8.0` -> `shopware.messenger.message_max_kib_size`. `SalesChannelNotFoundException` `@deprecated tag:v6.8.0`.
- `MailTemplateRenderContextEvent`, `SendMailTemplate`/`SendMailTemplateParams`, `MailTemplateSendService` existieren in 6.7.15.1; Einführungsversion nicht belegt.
- Neue Mail-Typen in 6.7 u. a. `order.payment_method.changed`, `revocation_request.merchant/customer`, `customer.password.changed`. Der Core nutzt `MailBeforeValidateEvent`/`MailBeforeSentEvent` selbst (`GaranLabelMailSubscriber`: Daten und Inline-Anhänge).
- Admin-Modul `sw-mail-template` hat unter `Feature.isActive('V6_8_0_0')` eine andere Routenstruktur.
- Doku-Seiten `add-data-to-mails`/`add-mail-template` sind erreichbar, erwähnen aber weder `MailTemplateRenderContextEvent` noch den Typ-/Flow-Zusammenhang.

## 6. Checkliste

- [ ] Typ `technical_name` eindeutig (bei Flow-Trigger = `EVENT_NAME` des Events)
- [ ] Migration idempotent, in Transaktion, Translations mindestens für Systemsprache
- [ ] `available_entities` passt zu registrierten Data-Providern
- [ ] Zusätzliche Variablen per Event vor dem Rendern gesetzt
- [ ] Auslöser vorhanden (Flow mit Aktion "E-Mail senden" oder Code-Versand)
- [ ] Sales-Channel-Differenzierung über Flow-Regeln/Flows und Header/Footer geprüft
- [ ] Absenderadresse konfiguriert
- [ ] Vorschau in der Administration getestet, `MailErrorEvent`/Log geprüft
- [ ] Template wird im Plugin nicht beim Deinstallieren gelöscht

## 7. Quellen

Doku (2026-10-28 abgerufen):
- https://developer.shopware.com/docs/guides/plugins/plugins/content/mail/add-data-to-mails.html
- https://developer.shopware.com/docs/guides/plugins/plugins/content/mail/add-mail-template.html

Quellcode (`core/`):
- `Content/Mail/Service/{AbstractMailService,MailService,SendMailTemplate,SendMailTemplateParams}.php`
- `Content/MailTemplate/Service/Event/{MailBeforeValidateEvent,MailTemplateRenderContextEvent,MailBeforeSentEvent,MailSentEvent,MailErrorEvent}.php`
- `Content/MailTemplate/{MailTemplateDefinition,MailTemplateTypes}.php`, `Aggregate/MailTemplateType/MailTemplateTypeDefinition.php`, `Service/{MailTemplateContentBuilder,MailDataProvider,MailTemplateSendService}.php`, `Api/MailActionController.php`
- `Content/Flow/Dispatching/Action/SendMailAction.php`, `Framework/Event/MailAware.php`, `Checkout/Customer/Event/CustomerPasswordChangedEvent.php`
- `Migration/V6_7/{Migration1763377570CreatePasswordChangeMailTemplate,Migration1763377575SendEmailAfterPasswordChangeFlow}.php`, `Migration/Traits/UpdateMailTrait.php`, `Migration/V6_5/Migration1675082889DropUnusedTables.php`
- `Content/DependencyInjection/{mail,mail_template,shared}.php`, `Content/Product/Garan/GaranLabelMailSubscriber.php`, `System/SalesChannel/SalesChannelDefinition.php`, `Framework/Bundle.php` (`getMigrationNamespace`)
- Admin: `module/sw-mail-template/index.js`, `module/sw-flow/component/modals/sw-flow-mail-send-modal/index.js`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele (Migration, Subscriber, Versand) und CLI nicht in einer laufenden Installation ausgeführt; Migration nach Core-Vorbild geschrieben. Twig-Variablen `customer`/`salesChannel` setzen ein passendes Flow-Event bzw. beim Direktversand übergebene `templateData` voraus.
- ⚠ LÜCKE: Dass das Flow-Trigger-Event dieselbe Bezeichnung wie `technical_name` trägt, ist aus dem Core-Muster abgeleitet; wie die Administration Templates pro Event filtert, nur in Teilen gelesen. Wie ein eigenes Event über `BusinessEventCollector` im Flow Builder erscheint, nicht vertieft.
- ⚠ LÜCKE: Einführungsversion von `MailTemplateRenderContextEvent`, `SendMailTemplate`, `MailTemplateSendService` nicht belegt; ob das Äquivalent in 6.6 existiert, ungeprüft.
- ⚠ UNGEPRÜFT: Wirkung von `SetupStagingEvent::CONFIG_FLAG` (Testmodus) nur aus `createMail()` abgeleitet; Spracherkennung in Flows (`LanguageAware::LANGUAGE_ID`, `LanguageStorer`) nicht im Detail geprüft.
