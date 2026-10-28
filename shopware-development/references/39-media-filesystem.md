# Media und Filesystem (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin Dateien speichert (Flysystem), Medien hochlädt oder importiert, erlaubte Dateiendungen erweitert, Thumbnails erzeugt/ausgibt oder Medien in der Storefront rendert.

Abgrenzung: Twig-Blöcke allgemein in [T-14 Twig-Templates](./14-storefront-twig-templates.md); Admin-Upload-Komponenten in [T-25 Admin-Komponenten](./25-admin-komponenten.md); Message-Queue für Thumbnails in [T-34 Message Queue](./34-message-queue.md); Service-Registrierung in [T-05](./05-services-registrieren.md); Build/Deployment (S3/CDN-Betrieb) in [T-49](./49-build-deployment.md).

## 2. Konzepte

- **Filesystem = Flysystem** (`League\Flysystem\FilesystemOperator`). Core-Services (öffentlich): `shopware.filesystem.public`, `.private`, `.temp`, `.theme`, `.asset`, `.sitemap` (`core/Framework/DependencyInjection/filesystem.php`), erzeugt von `FilesystemFactory`. Adapter-Typen: `local`, `amazon-s3`, `google-storage` (Tag `shopware.filesystem.factory`, Interface `AdapterFactoryInterface`).
- **Plugin-Filesystems entstehen automatisch**: `Bundle::registerFilesystem()` registriert je Plugin `<snake_case_name>.filesystem.public` und `.private` als `PrefixFilesystem` mit Präfix `plugins/<snake_case_name>` auf dem Core-Filesystem, plus Autowiring-Alias nach dem Muster `$swagExamplePublicFilesystem` / `$swagExamplePrivateFilesystem` (`FilesystemOperator`). Für `SwagExample`: `swag_example.filesystem.public|private`.
- **Upload-APIs (Entscheidungshilfe)**:

| Quelle | API |
|---|---|
| Lokale Datei | `MediaUploadService::uploadFromLocalPath` |
| HTTP-Request (Multipart-Feld `file`) | `MediaUploadService::uploadFromRequest` |
| Fremd-URL laden | `MediaUploadService::uploadFromURL` |
| Nur verlinken, nichts speichern | `MediaUploadService::linkURL` (`mimeType` in `MediaUploadParameters` Pflicht) |
| Blob | `MediaService::saveFile` |
| Kunde in Storefront | `StorefrontMediaUploader::upload` |

  `MediaUploadService` (`core/Content/Media/Upload/`) ist die moderne 6.7-API und `@final`. `MediaService`/`FileSaver::persistFileToMedia()` funktionieren weiter (kein `@deprecated` gefunden), haben aber `@internal`-Konstruktoren: injizieren, nicht instanziieren/erweitern.
- **Erlaubte Endungen**: Config `shopware.filesystem.allowed_extensions` (öffentlich, ersetzt die Standardliste komplett) und `private_allowed_extensions` (private enthält zusätzlich `zip`, `rar`, `xml`); zur Laufzeit erweiterbar über `MediaFileExtensionWhitelistEvent` (wirkt auf öffentliche und private Liste gleichermaßen, keine Unterscheidung im Event). Typ-Erkennung über Tag `shopware.media_type.detector` (`TypeDetectorInterface`).
- **Thumbnails**: `ThumbnailService`, Größen pro Media-Ordner (`MediaFolderConfiguration` + `MediaThumbnailSize`, `thumbnailQuality` Standard 80). Erzeugung beim Upload asynchron über die Message Queue.
- **Pfad/URL**: `AbstractMediaPathStrategy` (Strategien `id`, `filename`, `physical_filename`, `plain`), gewählt über `shopware.cdn.strategy`; `Media.path` und `Media.url` sind Felder der `MediaDefinition`.

Plugin-Baum:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Resources/
    │   ├── config/services.php
    │   └── views/storefront/page/product-detail/buy-widget.html.twig
    ├── Service/MediaImporter.php
    ├── Storage/ReportStorage.php
    └── Subscriber/MediaExtensionSubscriber.php
```

## 3. Anleitung

1. Plugin anlegen ([T-02](./02-plugin-aufbau.md)), `services.php` wie in 4.1, dann `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`.
2. Filesystem-Service-IDs prüfen: `bin/console debug:container --show-hidden | grep filesystem`.
3. Upload-Weg wählen (Tabelle oben). URL-Validierung gegen private IPs steuern `shopware.media.enable_url_validation` und `enable_url_upload_feature`.
4. Endung freigeben (4.4). Eigener Typ: `TypeDetectorInterface::detect(MediaFile $mediaFile, ?MediaType $previouslyDetectedType): ?MediaType`, Tag `shopware.media_type.detector` (Core nutzt Priorität 10 bzw. 0). Bestehende Medien neu klassifizieren: `bin/console media:generate-media-types`.
5. Thumbnails: Größen pro Ordner im Admin (Einstellungen, Medien-Ordner) bzw. Entität `media_thumbnail_size`. Nachträglich:
   - `bin/console media:generate-thumbnails` (u. a. `--batch-size/-b`)
   - `bin/console media:delete-local-thumbnails`
   - `bin/console media:delete-unused`
   Ein laufender Worker (`messenger:consume`) ist Voraussetzung für die asynchrone Erzeugung.
6. Storefront: Association `thumbnails` laden; Ausgabe mit `{% sw_thumbnails %}` (4.5); Original über `media.url` bzw. Filter `sw_encode_media_url`.
7. Admin: `sw-media-upload-v2` (+ `sw-upload-listener`) und `sw-media-preview-v2` laut Doku `handling-media.html` (siehe Lücken).
8. Kunden-Upload in der Storefront: `Shopware\Storefront\Framework\Media\StorefrontMediaUploader::upload(UploadedFile $file, string $folder, string $type, Context $context, bool $isPrivate = false)`; Validierung über `StorefrontMediaValidatorRegistry` und Validatoren mit Tag `storefront.media.upload.validator` (`StorefrontMediaValidatorInterface::getType()/validate()`; Core: `StorefrontMediaImageValidator`, `StorefrontMediaDocumentValidator`). PHP-Dateinamen werden geblockt.
9. Betreiber-Konfiguration (Projekt `config/packages/shopware.yaml`, Defaults in `core/Framework/Resources/config/packages/shopware.yaml`):
```yaml
shopware:
    filesystem:
        public:
            type: "amazon-s3"       # oder local, google-storage
            url: "https://cdn.example.com"
            config:
                bucket: "..."
                region: "..."
        allowed_extensions: [...]   # ersetzt die Standardliste komplett
    media:
        remote_thumbnails:
            enable: false
```

## 4. Beispiele

### 4.1 Services

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use Shopware\Core\Content\Media\Upload\MediaUploadService;
use SwagExample\Service\MediaImporter;
use SwagExample\Storage\ReportStorage;
use SwagExample\Subscriber\MediaExtensionSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Service-ID aus dem Plugin-Namen: SwagExample -> swag_example
    $services->set(ReportStorage::class)
        ->args([
            service('swag_example.filesystem.private'),
            service('swag_example.filesystem.public'),
        ]);

    $services->set(MediaImporter::class)
        ->args([service(MediaUploadService::class)]);

    $services->set(MediaExtensionSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```
XML-Service-Konfiguration ist ab 6.7 deprecated (entfällt mit 6.8), daher `services.php`.

### 4.2 Plugin-Filesystem nutzen

`src/Storage/ReportStorage.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Storage;

use League\Flysystem\FilesystemOperator;

class ReportStorage
{
    public function __construct(
        private readonly FilesystemOperator $privateFilesystem,
        private readonly FilesystemOperator $publicFilesystem,
    ) {
    }

    public function writeReport(string $name, string $content): void
    {
        // landet physisch in files/plugins/swag_example/reports/<name>
        $this->privateFilesystem->write('reports/' . $name, $content);
    }

    public function publicUrl(string $path): string
    {
        return $this->publicFilesystem->publicUrl($path);
    }
}
```
Alternativ per Autowiring: Konstruktor-Parameter `FilesystemOperator $swagExamplePrivateFilesystem` (Alias-Name aus `Bundle::registerFilesystem`).

### 4.3 Media hochladen

`src/Service/MediaImporter.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Content\Media\Upload\MediaUploadParameters;
use Shopware\Core\Content\Media\Upload\MediaUploadService;
use Shopware\Core\Framework\Context;

class MediaImporter
{
    public function __construct(private readonly MediaUploadService $uploadService)
    {
    }

    public function importFromUrl(string $url, string $folderId, Context $context): string
    {
        return $this->uploadService->uploadFromURL(
            $url,
            $context,
            new MediaUploadParameters(
                mediaFolderId: $folderId,
                private: false,
                fileName: 'my-image.jpg',   // MIT Endung
                deduplicate: true,          // Hash-Vergleich, liefert vorhandene ID zurück
            )
        );
    }
}
```
Klassische Variante (Standardordner per Entity-Name, z. B. `product`; `MediaService` injizieren):
```php
$mediaId = $this->mediaService->saveFile(
    $blob, 'png', 'image/png', 'filename-ohne-endung', $context, 'product', null, false
);
// oder: createMediaInFolder('product', $context, false) + FileSaver::persistFileToMedia($mediaFile, $name, $mediaId, $context)
```
`MediaService::createMediaInFolder(..., bool $private = true)`: Default ist `true`, für Storefront-Bilder `false` setzen. Bei `MediaUploadService` ergibt `private: null` im Code `false` (`$params->private ?? false`, am Quellcode verifiziert).

### 4.4 Endung erlauben

`src/Subscriber/MediaExtensionSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Content\Media\Event\MediaFileExtensionWhitelistEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MediaExtensionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [MediaFileExtensionWhitelistEvent::class => 'onWhitelist'];
    }

    public function onWhitelist(MediaFileExtensionWhitelistEvent $event): void
    {
        $whitelist = $event->getWhitelist();
        $whitelist[] = 'stl';
        $event->setWhitelist($whitelist);
    }
}
```
Das Event feuert für öffentliche und private Medien (`MediaFileExtensionListProvider::getAllowedExtensions($private, $context)`), enthält aber kein Public/Private-Kennzeichen: `getContext()` ist nur der Request-Context, der Subscriber kann die beiden Listen nicht unterscheiden. Eine ergänzte Endung gilt daher für beide. Nur für eine Sichtbarkeit: Config `shopware.filesystem.allowed_extensions` bzw. `private_allowed_extensions` setzen (öffentlich ersetzt dabei die Standardliste komplett). Siehe [Event-Subscriber-Gerüst](../templates/event-subscriber/README.md).

### 4.5 Storefront-Thumbnails

`src/Resources/views/storefront/page/product-detail/buy-widget.html.twig` (Einbettung in einen Block des Core-Templates: [T-14](./14-storefront-twig-templates.md))
```twig
{% if product.cover.media %}
    {% sw_thumbnails 'swag-example-thumbnails' with {
        media: product.cover.media,
        sizes: { 'xs': '501px', 'sm': '315px', 'lg': '333px', 'default': '100px' },
        attributes: { 'class': 'img-fluid', 'alt': product.translated.name, 'loading': 'lazy' }
    } %}
{% endif %}
```
Der erste String wird als Variable `name` an `thumbnail.html.twig` übergeben (`ThumbnailTokenParser::parse`). `{% sw_thumbnails %}` ist ein `sw_include` auf `@Storefront/storefront/utilities/thumbnail.html.twig`; der Block `thumbnail_utility_img` ist überschreibbar. Die Doku-Aussage "Lazy Loading standardmäßig aus" stimmt: ohne `loading` setzt das Template `eager`.

## 5. Fallstricke

- **Media bleibt `private`** (Default `true` in `MediaService`): Bild in der Storefront nicht abrufbar, `private: false` übergeben.
- **`fileExtensionNotSupported`**: Endung fehlt in der Liste; zwei getrennte Listen (public/private); Config-Override ersetzt die gesamte Liste, Event-Erweiterung ist additiv.
- **Dateiname mit/ohne Endung**: an `saveFile`/`persistFileToMedia` ohne Endung (kommt aus `MediaFile`); bei `MediaUploadParameters::fileName` mit Endung (wird via `getFileNameWithoutExtension()` zerlegt).
- **Namenskollision**: `FileSaver::ensureFileNameIsUnique` wirft bei gleichem Namen+Endung im selben Pfad eine Exception.
- **Temp-Dateien**: bei `fetchBlob` ohne `cleanUpTempFile` aufräumen (`MediaService::saveFile` macht es selbst).
- **Thumbnails fehlen**: Worker läuft nicht (`messenger:consume`), Ordner-Konfiguration ohne Größen oder `createThumbnails` aus, Association `thumbnails` nicht geladen.
- **Direkter Zugriff auf `public/`-Pfade** statt Flysystem bricht bei S3/CDN.
- **`{% sw_thumbnails %}` mit nur einer Media-ID** statt vollständiger Entität: keine Thumbnails.
- **`@internal`/`@final`**: `MediaService`, `FileSaver`, `FileFetcher` nicht erweitern/instanziieren; `MediaUploadService` ist `@final`.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `MediaUploadService`, `MediaUploadParameters`, `linkURL`, externe Thumbnails (`ExternalThumbnailCollection`), Presigned Uploads (`Upload/PresignedMediaUploadService`, Config `shopware.media.presigned_upload`), `remote_thumbnails`: in 6.7.15.1 vorhanden; Einführungsversion nicht belegt.
- Alte `UrlGenerator`-Klassen (6.5) sind nicht mehr vorhanden; stattdessen `AbstractMediaPathStrategy`.
- Flysystem 3 (`FilesystemOperator`, `publicUrl()`); Plugin-Filesystems als `PrefixFilesystem`.
- `@deprecated tag:v6.8.0`: `MediaFileExtensionWhitelistEvent`-Konstruktor ohne `$context` und `getNullableContext()` (im Subscriber `getContext()` nutzen); `FilesystemFactory::factory()` mit `visibility` in `config.options` (stattdessen `visibility` neben `type`); `ThumbnailService::updateThumbnails()` `$force` wird Pflichtteil der Signatur; `MediaUploadService::validateExternalUrl()` (static), Ersatz `assertValidExternalUrl()` auf injizierter Instanz; XML-Service-/Packages-Konfiguration.
- Unter `feature('v6.8.0.0')` wird `thumbnail.url` in `thumbnail.html.twig` nicht mehr via `sw_encode_url` kodiert.

## 6. Checkliste

- [ ] Plugin-Filesystem per Service-ID `<snake_case>.filesystem.public|private` oder Autowiring-Alias injiziert, keine direkten Dateipfade
- [ ] Passender Upload-Weg gewählt, `MediaUploadService` bevorzugt
- [ ] `private: false` für Storefront-sichtbare Medien gesetzt
- [ ] Dateinamen-Konvention beachtet (mit Endung bei `MediaUploadParameters`, ohne bei `saveFile`)
- [ ] Zusätzliche Endungen über `MediaFileExtensionWhitelistEvent` (nicht per Config-Override) freigegeben
- [ ] Association `thumbnails` geladen, `{% sw_thumbnails %}` mit voller Media-Entität
- [ ] Thumbnail-Größen im Media-Ordner konfiguriert, Worker läuft
- [ ] Deprecated-APIs (`validateExternalUrl`, `getNullableContext`, XML-Services) vermieden
- [ ] Kundenuploads über `StorefrontMediaUploader` mit Validator abgesichert

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/filesystem/filesystem.html
- https://developer.shopware.com/docs/guides/plugins/plugins/content/media/add-custom-file-extension.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/howto/use-media-thumbnails.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/data-handling-processing/handling-media.html
- https://flysystem.thephpleague.com/docs/usage/filesystem-api/

Quellcode (relativ zu `vendor/shopware/`):
- `core/Framework/Adapter/Filesystem/{FilesystemFactory,PrefixFilesystem}.php`, `Adapter/{LocalFactory,AwsS3v3Factory,GoogleStorageFactory,AdapterFactoryInterface}.php`
- `core/Framework/DependencyInjection/filesystem.php`, `core/Framework/Bundle.php` (`registerFilesystem`, `registerContainerFile`)
- `core/Content/Media/MediaService.php`, `File/{FileSaver,FileFetcher,MediaFile}.php`
- `core/Content/Media/Upload/{MediaUploadService,MediaUploadParameters,MediaFileExtensionListProvider}.php`, `Event/MediaFileExtensionWhitelistEvent.php`
- `core/Content/Media/TypeDetector/TypeDetectorInterface.php`, `Thumbnail/ThumbnailService.php`, `Commands/*.php`, `Core/Strategy/*`
- `storefront/Framework/Media/{StorefrontMediaUploader,StorefrontMediaValidatorInterface}.php`
- `storefront/Framework/Twig/TokenParser/ThumbnailTokenParser.php`, `storefront/Resources/views/storefront/utilities/thumbnail.html.twig`
- `core/Framework/Resources/config/packages/shopware.yaml` (Abschnitte `filesystem`, `media`)

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Plugin-API für eigene, vom Prefix-Schema unabhängige Filesystems nicht gefunden; ein eigener `FilesystemOperator`-Service über `FilesystemFactory` wäre nur eine Ableitung (nicht in der Doku, nicht getestet).
- ⚠ UNGEPRÜFT: Beispiele wurden nicht in einer laufenden Installation ausgeführt.
- ⚠ UNGEPRÜFT: Einführungsversionen von `MediaUploadService` und Presigned Upload.
- ⚠ UNGEPRÜFT: Admin-Komponenten `sw-media-upload-v2`/`sw-media-preview-v2` nur aus der Doku, nicht am Admin-Quellcode verifiziert.
- ⚠ LÜCKE: Optionsliste von `media:generate-thumbnails` nicht vollständig ausgelesen. Das Thumbnail-Template kennt weitere undokumentierte Variablen (`load`, `loadOriginalImage`, `autoColumnSizes`, `columns`, `layout`).
