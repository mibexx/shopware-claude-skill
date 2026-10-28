# Media and filesystem (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin stores files (Flysystem), uploads or imports media, extends the allowed file extensions, generates/outputs thumbnails, or renders media in the storefront.

Scope: Twig blocks in general in [T-14 Twig templates](./14-storefront-twig-templates.md); admin upload components in [T-25 Admin components](./25-admin-components.md); message queue for thumbnails in [T-34 Message queue](./34-message-queue.md); service registration in [T-05](./05-registering-services.md); build/deployment (S3/CDN operation) in [T-49](./49-build-deployment.md).

## 2. Concepts

- **Filesystem = Flysystem** (`League\Flysystem\FilesystemOperator`). Core services (public): `shopware.filesystem.public`, `.private`, `.temp`, `.theme`, `.asset`, `.sitemap` (`core/Framework/DependencyInjection/filesystem.php`), created by `FilesystemFactory`. Adapter types: `local`, `amazon-s3`, `google-storage` (tag `shopware.filesystem.factory`, interface `AdapterFactoryInterface`).
- **Plugin filesystems are created automatically**: `Bundle::registerFilesystem()` registers `<snake_case_name>.filesystem.public` and `.private` per plugin as `PrefixFilesystem` with prefix `plugins/<snake_case_name>` on the core filesystem, plus an autowiring alias following the pattern `$swagExamplePublicFilesystem` / `$swagExamplePrivateFilesystem` (`FilesystemOperator`). For `SwagExample`: `swag_example.filesystem.public|private`.
- **Upload APIs (decision guide)**:

| Source | API |
|---|---|
| Local file | `MediaUploadService::uploadFromLocalPath` |
| HTTP request (multipart field `file`) | `MediaUploadService::uploadFromRequest` |
| Load an external URL | `MediaUploadService::uploadFromURL` |
| Only link, store nothing | `MediaUploadService::linkURL` (`mimeType` in `MediaUploadParameters` required) |
| Blob | `MediaService::saveFile` |
| Customer in storefront | `StorefrontMediaUploader::upload` |

  `MediaUploadService` (`core/Content/Media/Upload/`) is the modern 6.7 API and `@final`. `MediaService`/`FileSaver::persistFileToMedia()` continue to work (no `@deprecated` found), but have `@internal` constructors: inject them, do not instantiate/extend them.
- **Allowed extensions**: config `shopware.filesystem.allowed_extensions` (public, replaces the default list completely) and `private_allowed_extensions` (private additionally contains `zip`, `rar`, `xml`); extensible at runtime via `MediaFileExtensionWhitelistEvent` (affects the public and private list equally, no distinction in the event). Type detection via tag `shopware.media_type.detector` (`TypeDetectorInterface`).
- **Thumbnails**: `ThumbnailService`, sizes per media folder (`MediaFolderConfiguration` + `MediaThumbnailSize`, `thumbnailQuality` default 80). Generated asynchronously via the message queue on upload.
- **Path/URL**: `AbstractMediaPathStrategy` (strategies `id`, `filename`, `physical_filename`, `plain`), selected via `shopware.cdn.strategy`; `Media.path` and `Media.url` are fields of the `MediaDefinition`.

Plugin tree:
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

## 3. Instructions

1. Create the plugin ([T-02](./02-plugin-structure.md)), `services.php` as in 4.1, then `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`.
2. Check the filesystem service IDs: `bin/console debug:container --show-hidden | grep filesystem`.
3. Choose the upload path (table above). URL validation against private IPs is controlled by `shopware.media.enable_url_validation` and `enable_url_upload_feature`.
4. Allow the extension (4.4). Custom type: `TypeDetectorInterface::detect(MediaFile $mediaFile, ?MediaType $previouslyDetectedType): ?MediaType`, tag `shopware.media_type.detector` (core uses priority 10 or 0, respectively). Reclassify existing media: `bin/console media:generate-media-types`.
5. Thumbnails: sizes per folder in the Admin (Settings, media folders) or entity `media_thumbnail_size`. Afterwards:
   - `bin/console media:generate-thumbnails` (among others `--batch-size/-b`)
   - `bin/console media:delete-local-thumbnails`
   - `bin/console media:delete-unused`
   A running worker (`messenger:consume`) is a prerequisite for asynchronous generation.
6. Storefront: load the `thumbnails` association; output with `{% sw_thumbnails %}` (4.5); original via `media.url` or the filter `sw_encode_media_url`.
7. Admin: `sw-media-upload-v2` (+ `sw-upload-listener`) and `sw-media-preview-v2` according to the docs `handling-media.html` (see gaps).
8. Customer upload in the storefront: `Shopware\Storefront\Framework\Media\StorefrontMediaUploader::upload(UploadedFile $file, string $folder, string $type, Context $context, bool $isPrivate = false)`; validation via `StorefrontMediaValidatorRegistry` and validators with tag `storefront.media.upload.validator` (`StorefrontMediaValidatorInterface::getType()/validate()`; core: `StorefrontMediaImageValidator`, `StorefrontMediaDocumentValidator`). PHP file names are blocked.
9. Operator configuration (project `config/packages/shopware.yaml`, defaults in `core/Framework/Resources/config/packages/shopware.yaml`):
```yaml
shopware:
    filesystem:
        public:
            type: "amazon-s3"       # or local, google-storage
            url: "https://cdn.example.com"
            config:
                bucket: "..."
                region: "..."
        allowed_extensions: [...]   # replaces the default list completely
    media:
        remote_thumbnails:
            enable: false
```

## 4. Examples

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

    // Service ID from the plugin name: SwagExample -> swag_example
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
XML service configuration is deprecated as of 6.7 (removed in 6.8), hence `services.php`.

### 4.2 Using the plugin filesystem

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
        // physically ends up in files/plugins/swag_example/reports/<name>
        $this->privateFilesystem->write('reports/' . $name, $content);
    }

    public function publicUrl(string $path): string
    {
        return $this->publicFilesystem->publicUrl($path);
    }
}
```
Alternatively via autowiring: constructor parameter `FilesystemOperator $swagExamplePrivateFilesystem` (alias name from `Bundle::registerFilesystem`).

### 4.3 Uploading media

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
                fileName: 'my-image.jpg',   // WITH extension
                deduplicate: true,          // hash comparison, returns the existing ID
            )
        );
    }
}
```
Classic variant (default folder by entity name, e.g. `product`; inject `MediaService`):
```php
$mediaId = $this->mediaService->saveFile(
    $blob, 'png', 'image/png', 'filename-without-extension', $context, 'product', null, false
);
// or: createMediaInFolder('product', $context, false) + FileSaver::persistFileToMedia($mediaFile, $name, $mediaId, $context)
```
`MediaService::createMediaInFolder(..., bool $private = true)`: the default is `true`, set `false` for storefront images. With `MediaUploadService`, `private: null` results in `false` in the code (`$params->private ?? false`, verified against the source code).

### 4.4 Allowing an extension

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
The event fires for public and private media (`MediaFileExtensionListProvider::getAllowedExtensions($private, $context)`), but contains no public/private flag: `getContext()` is only the request context, the subscriber cannot distinguish the two lists. An added extension therefore applies to both. For one visibility only: set the config `shopware.filesystem.allowed_extensions` or `private_allowed_extensions` (public replaces the default list completely). See [event subscriber scaffold](../templates/event-subscriber/README.md).

### 4.5 Storefront thumbnails

`src/Resources/views/storefront/page/product-detail/buy-widget.html.twig` (embedding into a block of the core template: [T-14](./14-storefront-twig-templates.md))
```twig
{% if product.cover.media %}
    {% sw_thumbnails 'swag-example-thumbnails' with {
        media: product.cover.media,
        sizes: { 'xs': '501px', 'sm': '315px', 'lg': '333px', 'default': '100px' },
        attributes: { 'class': 'img-fluid', 'alt': product.translated.name, 'loading': 'lazy' }
    } %}
{% endif %}
```
The first string is passed to `thumbnail.html.twig` as the variable `name` (`ThumbnailTokenParser::parse`). `{% sw_thumbnails %}` is an `sw_include` of `@Storefront/storefront/utilities/thumbnail.html.twig`; the block `thumbnail_utility_img` can be overridden. The docs statement "lazy loading off by default" is correct: without `loading`, the template sets `eager`.

## 5. Pitfalls

- **Media stays `private`** (default `true` in `MediaService`): image not retrievable in the storefront, pass `private: false`.
- **`fileExtensionNotSupported`**: extension missing from the list; two separate lists (public/private); a config override replaces the entire list, the event extension is additive.
- **File name with/without extension**: to `saveFile`/`persistFileToMedia` without extension (comes from `MediaFile`); for `MediaUploadParameters::fileName` with extension (split via `getFileNameWithoutExtension()`).
- **Name collision**: `FileSaver::ensureFileNameIsUnique` throws an exception for the same name+extension in the same path.
- **Temp files**: clean up with `fetchBlob` without `cleanUpTempFile` (`MediaService::saveFile` does it itself).
- **Thumbnails missing**: worker not running (`messenger:consume`), folder configuration without sizes or `createThumbnails` off, association `thumbnails` not loaded.
- **Direct access to `public/` paths** instead of Flysystem breaks with S3/CDN.
- **`{% sw_thumbnails %}` with only a media ID** instead of the full entity: no thumbnails.
- **`@internal`/`@final`**: do not extend/instantiate `MediaService`, `FileSaver`, `FileFetcher`; `MediaUploadService` is `@final`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `MediaUploadService`, `MediaUploadParameters`, `linkURL`, external thumbnails (`ExternalThumbnailCollection`), presigned uploads (`Upload/PresignedMediaUploadService`, config `shopware.media.presigned_upload`), `remote_thumbnails`: present in 6.7.15.1; version of introduction not documented.
- The old `UrlGenerator` classes (6.5) no longer exist; use `AbstractMediaPathStrategy` instead.
- Flysystem 3 (`FilesystemOperator`, `publicUrl()`); plugin filesystems as `PrefixFilesystem`.
- `@deprecated tag:v6.8.0`: `MediaFileExtensionWhitelistEvent` constructor without `$context` and `getNullableContext()` (use `getContext()` in the subscriber); `FilesystemFactory::factory()` with `visibility` in `config.options` (instead `visibility` next to `type`); `ThumbnailService::updateThumbnails()` `$force` becomes a mandatory part of the signature; `MediaUploadService::validateExternalUrl()` (static), replacement `assertValidExternalUrl()` on an injected instance; XML service/packages configuration.
- Under `feature('v6.8.0.0')`, `thumbnail.url` in `thumbnail.html.twig` is no longer encoded via `sw_encode_url`.

## 6. Checklist

- [ ] Plugin filesystem injected via service ID `<snake_case>.filesystem.public|private` or autowiring alias, no direct file paths
- [ ] Appropriate upload path chosen, `MediaUploadService` preferred
- [ ] `private: false` set for storefront-visible media
- [ ] File name convention observed (with extension for `MediaUploadParameters`, without for `saveFile`)
- [ ] Additional extensions allowed via `MediaFileExtensionWhitelistEvent` (not via config override)
- [ ] Association `thumbnails` loaded, `{% sw_thumbnails %}` with the full media entity
- [ ] Thumbnail sizes configured in the media folder, worker running
- [ ] Deprecated APIs (`validateExternalUrl`, `getNullableContext`, XML services) avoided
- [ ] Customer uploads secured via `StorefrontMediaUploader` with a validator

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/filesystem/filesystem.html
- https://developer.shopware.com/docs/guides/plugins/plugins/content/media/add-custom-file-extension.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/howto/use-media-thumbnails.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/data-handling-processing/handling-media.html
- https://flysystem.thephpleague.com/docs/usage/filesystem-api/

Source code (relative to `vendor/shopware/`):
- `core/Framework/Adapter/Filesystem/{FilesystemFactory,PrefixFilesystem}.php`, `Adapter/{LocalFactory,AwsS3v3Factory,GoogleStorageFactory,AdapterFactoryInterface}.php`
- `core/Framework/DependencyInjection/filesystem.php`, `core/Framework/Bundle.php` (`registerFilesystem`, `registerContainerFile`)
- `core/Content/Media/MediaService.php`, `File/{FileSaver,FileFetcher,MediaFile}.php`
- `core/Content/Media/Upload/{MediaUploadService,MediaUploadParameters,MediaFileExtensionListProvider}.php`, `Event/MediaFileExtensionWhitelistEvent.php`
- `core/Content/Media/TypeDetector/TypeDetectorInterface.php`, `Thumbnail/ThumbnailService.php`, `Commands/*.php`, `Core/Strategy/*`
- `storefront/Framework/Media/{StorefrontMediaUploader,StorefrontMediaValidatorInterface}.php`
- `storefront/Framework/Twig/TokenParser/ThumbnailTokenParser.php`, `storefront/Resources/views/storefront/utilities/thumbnail.html.twig`
- `core/Framework/Resources/config/packages/shopware.yaml` (sections `filesystem`, `media`)

### Gaps and uncertainties

- ⚠ GAP: No plugin API found for custom filesystems independent of the prefix scheme; a custom `FilesystemOperator` service via `FilesystemFactory` would only be a derivation (not in the docs, not tested).
- ⚠ UNVERIFIED: Examples were not executed in a running installation.
- ⚠ UNVERIFIED: Versions of introduction of `MediaUploadService` and presigned upload.
- ⚠ UNVERIFIED: Admin components `sw-media-upload-v2`/`sw-media-preview-v2` only from the docs, not verified against the admin source code.
- ⚠ GAP: Option list of `media:generate-thumbnails` not read out completely. The thumbnail template knows further undocumented variables (`load`, `loadOriginalImage`, `autoColumnSizes`, `columns`, `layout`).
