<?php

/**
 * @package     Webmarka.Plugin
 * @subpackage  System.contentimageupload
 *
 * @copyright   (C) 2026 Beibit Sakhanov <https://webmarka.kz>
 * @copyright   Based on Content Image Upload 1.6, (C) 2026 Хабар
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Webmarka\Plugin\System\ContentImageUpload\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\MediaHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Registry\Registry;
use Webmarka\Plugin\System\ContentImageUpload\Service\JoomlaCoreImageProcessor;
use Webmarka\Plugin\System\ContentImageUpload\Service\OriginalStore;
use Webmarka\Plugin\System\ContentImageUpload\Service\WatermarkService;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Article images: upload, processing, one image for the intro and full text (or two with sync off)
 * and a reversible watermark.
 *
 * A watermark box shows what the image file really carries. Ticking or unticking it changes
 * the file when the article is saved; an upload with the box ticked is stamped at once.
 *
 * @since  1.0.0
 */
final class ContentImageUpload extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Raster MIME types the plugin can process; the effective list is intersected with com_media.
     *
     * @var    array<string, string[]>
     * @since  1.0.0
     */
    private const SUPPORTED_IMAGE_MIMES = [
        'image/jpeg'     => ['jpg', 'jpeg'],
        'image/png'      => ['png'],
        'image/gif'      => ['gif'],
        'image/webp'     => ['webp'],
        'image/bmp'      => ['bmp'],
        'image/x-ms-bmp' => ['bmp'],
        'image/avif'     => ['avif'],
    ];

    /**
     * Cyrillic letters of Russian and Kazakh in Latin, used before the language transliterator:
     * an English or Kazakh interface language may not transliterate Cyrillic at all.
     *
     * @var    array<string, string>
     * @since  1.0.0
     */
    private const CYRILLIC_TO_LATIN = [
        'а' => 'a', 'ә' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ғ' => 'g', 'д' => 'd', 'е' => 'e',
        'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'і' => 'i', 'к' => 'k', 'қ' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'ң' => 'n', 'о' => 'o', 'ө' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ұ' => 'u', 'ү' => 'u', 'ф' => 'f', 'х' => 'h', 'һ' => 'h',
        'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e',
        'ю' => 'yu', 'я' => 'ya',
    ];

    /**
     * Largest accepted image area in pixels.
     *
     * @var    int
     * @since  1.0.0
     */
    private const MAX_IMAGE_PIXELS = 25_000_000;

    /**
     * Log category and file name.
     *
     * @var    string
     * @since  1.7.0
     */
    private const LOG_CATEGORY = 'plg_system_contentimageupload';

    /**
     * Load the language file on instantiation.
     *
     * @var    bool
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Image fields of the article form being displayed.
     *
     * @var    array<string, mixed>
     * @since  1.7.0
     */
    private array $formImages = [];

    /**
     * Store of originals.
     *
     * @var    OriginalStore|null
     * @since  1.7.0
     */
    private ?OriginalStore $originalStore = null;

    /**
     * Whether the log file has been registered in this request.
     *
     * @var    bool
     * @since  1.7.0
     */
    private static bool $loggerReady = false;

    /**
     * Returns the events this plugin listens to.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeCompileHead'      => 'onBeforeCompileHead',
            'onContentPrepareData'     => 'onContentPrepareData',
            'onContentAfterSave'       => 'onContentAfterSave',
            'onAjaxContentimageupload' => 'onAjaxContentimageupload',
        ];
    }

    /**
     * Adds the editor panel to the article edit screen.
     *
     * @param   BeforeCompileHeadEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        $input = $app->getInput();

        if ($input->getCmd('option') !== 'com_content' || $input->getCmd('view') !== 'article') {
            return;
        }

        $document = $app->getDocument();

        if ($document->getType() !== 'html') {
            return;
        }

        $document->addScriptOptions('plgSystemContentImageUpload', $this->getJsOptions());

        $wa = $document->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('plg_system_contentimageupload');
        $wa->useScript('plg_system_contentimageupload.admin');
        $wa->useStyle('plg_system_contentimageupload.admin');
    }

    /**
     * Remembers the images of the article form, so the panel opens with the real watermark state.
     *
     * @param   PrepareDataEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.7.0
     */
    public function onContentPrepareData(PrepareDataEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article' || !$this->getApplication()->isClient('administrator')) {
            return;
        }

        $data   = $event->getData();
        $images = \is_object($data) ? ($data->images ?? []) : ($data['images'] ?? []);

        $this->formImages = $this->normaliseImages($images);
    }

    /**
     * Brings the watermark of the article image to the state of the box once the article is stored.
     *
     * Runs after the save, so a failed save never touches the file, and a watermark problem
     * becomes a warning instead of «save failed» for an article that is already stored.
     *
     * @param   AfterSaveEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article' || !$this->isWatermarkEnabled()) {
            return;
        }

        $app = $this->getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        // Sent only by the panel: a hidden «0» followed by the box «1». No field — no change.
        $input         = $app->getInput()->post;
        $requestedMain = $input->get('ciu_watermark', null, 'raw');
        $requestedFull = $input->get('ciu_watermark_full', null, 'raw');

        if (($requestedMain === null && $requestedFull === null) || !Session::checkToken('post')) {
            return;
        }

        $images  = $this->normaliseImages($event->getItem()->images ?? '');
        $intro   = $this->getLocalImagePath($images['image_intro'] ?? '');
        $full    = $this->getLocalImagePath($images['image_fulltext'] ?? '');
        $synced  = (string) $input->get('ciu_sync', '1', 'raw') !== '0';
        $targets = [];

        // The panel box governs the panel image, the second box a separate full text image.
        if ($requestedMain !== null) {
            $main = $synced ? $this->resolveTargetPath($images) : $intro;

            if ($main !== '') {
                $targets[$main] = (string) $requestedMain === '1';
            }
        }

        if ($requestedFull !== null && !$synced && $full !== '' && $full !== $intro && !isset($targets[$full])) {
            $targets[$full] = (string) $requestedFull === '1';
        }

        if ($targets === []) {
            return;
        }

        $user = $app->getIdentity();

        if (!$user instanceof User || !$this->canChangeImages($user)) {
            $app->enqueueMessage(
                Text::sprintf(
                    'PLG_SYSTEM_CONTENTIMAGEUPLOAD_MSG_WATERMARK_FAILED',
                    Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_MEDIA_PERMISSION')
                ),
                'warning'
            );

            return;
        }

        foreach ($targets as $path => $wanted) {
            try {
                $applied = $this->getOriginalStore()->has($path);

                if ($wanted && !$applied) {
                    $this->applyWatermark($path);
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_CONTENTIMAGEUPLOAD_MSG_WATERMARK_APPLIED', $path), 'message');
                } elseif (!$wanted && $applied) {
                    $this->removeWatermark($path);
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_CONTENTIMAGEUPLOAD_MSG_WATERMARK_REMOVED', $path), 'message');
                }
            } catch (\Throwable $exception) {
                $this->logThrowable($exception);
                $app->enqueueMessage(
                    Text::sprintf(
                        'PLG_SYSTEM_CONTENTIMAGEUPLOAD_MSG_WATERMARK_FAILED',
                        $path . ' — ' . $this->getPublicMessage($exception)
                    ),
                    'warning'
                );
            }
        }
    }

    /**
     * AJAX entry point: ciuAction=upload (default) or ciuAction=status.
     *
     * @param   AjaxEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAjaxContentimageupload(AjaxEvent $event): void
    {
        $action = $this->getApplication()->getInput()->getCmd('ciuAction', 'upload');

        try {
            $result = $action === 'status' ? $this->ajaxStatus() : $this->ajaxUpload();
        } catch (\RuntimeException $exception) {
            $this->log($exception->getMessage(), Log::WARNING);

            $result = ['success' => false, 'message' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            $this->logThrowable($exception);

            $result = ['success' => false, 'message' => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_GENERIC')];
        }

        $event->addResult($result);
    }

    /**
     * Options for the editor script.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    private function getJsOptions(): array
    {
        $contentParams    = ComponentHelper::getParams('com_content');
        $watermarkEnabled = $this->isWatermarkEnabled();
        $watermarkStates  = [];

        if ($watermarkEnabled) {
            foreach (['image_intro', 'image_fulltext'] as $field) {
                $path = $this->getLocalImagePath($this->formImages[$field] ?? '');

                if ($path === '' || isset($watermarkStates[$path])) {
                    continue;
                }

                try {
                    $state = $this->describeImage($path);
                } catch (\Throwable $exception) {
                    $this->logThrowable($exception);
                    $state = null;
                }

                if ($state !== null) {
                    $watermarkStates[$path] = $state;
                }
            }
        }

        return [
            'ajaxUrl'           => Route::_('index.php?option=com_ajax&group=system&plugin=contentimageupload&format=json', false),
            'rootUrl'           => Uri::root(),
            'token'             => Session::getFormToken(),
            'accept'            => $this->getMediaImageAccept(),
            'articleId'         => $this->getApplication()->getInput()->getInt('id', 0),
            'watermarkEnabled'  => $watermarkEnabled,
            'watermarkStates'   => array_values($watermarkStates),
            'imageSync'         => $this->getImageSyncMode(),
            'globalImageClass'  => [
                'intro'    => (string) $contentParams->get('imgclass_intro', ''),
                'fulltext' => (string) $contentParams->get('imgclass_fulltext', ''),
            ],
            'globalFigureClass' => [
                'intro'    => (string) $contentParams->get('float_intro', ''),
                'fulltext' => (string) $contentParams->get('float_fulltext', ''),
            ],
            'fields'            => [
                'intro'    => '#jform_images_image_intro',
                'fulltext' => '#jform_images_image_fulltext',
                'category' => '#jform_catid',
            ],
            'labels'            => [
                'singleTitle'                 => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_TITLE'),
                'singleButton'                => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_UPLOAD'),
                'uploading'                   => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_UPLOADING'),
                'success'                     => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_SUCCESS'),
                'error'                       => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_UPLOAD'),
                'clear'                       => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_CLEAR'),
                'select'                      => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_SELECT'),
                'noMediaField'                => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_NO_MEDIA_FIELD'),
                'imageOptions'                => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_IMAGE_OPTIONS'),
                'imageAlt'                    => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_IMAGE_ALT'),
                'imageAltEmpty'               => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_IMAGE_ALT_EMPTY'),
                'imageClass'                  => Text::_('COM_CONTENT_FIELD_IMAGE_CLASS_LABEL'),
                'imageClassPlaceholder'       => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_IMAGE_CLASS_PLACEHOLDER'),
                'imageFigureClass'            => Text::_('COM_CONTENT_FIELD_FIGURE_CLASS_LABEL'),
                'imageFigureClassDescription' => Text::_('COM_CONTENT_FIELD_FIGURE_CLASS_DESC'),
                'imageCaption'                => Text::_('COM_CONTENT_FIELD_IMAGE_CAPTION_LABEL'),
                'hideOriginalImageFields'     => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_HIDE_ORIGINAL_IMAGE_FIELDS'),
                'hideLinkFieldsets'           => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_HIDE_LINK_FIELDSETS'),
                'imageCleared'                => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_IMAGE_CLEARED'),
                'imageLabel'                  => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_IMAGE_LABEL'),
                'imagePathPlaceholder'        => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_IMAGE_PATH_PLACEHOLDER'),
                'uploadingFile'               => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_UPLOADING_FILE'),
                'watermark'                   => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK'),
                'watermarkHelp'               => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_HELP'),
                'watermarkOn'                 => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_STATE_ON'),
                'watermarkOff'                => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_STATE_OFF'),
                'watermarkWillApply'          => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_WILL_APPLY'),
                'watermarkWillRemove'         => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_WILL_REMOVE'),
                'watermarkOnUpload'           => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_ON_UPLOAD'),
                'watermarkChecking'           => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_CHECKING'),
                'watermarkUnknown'            => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_UNKNOWN'),
                'syncSwitch'                  => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_SYNC_SWITCH'),
                'syncHelp'                    => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_SYNC_HELP'),
                'dropHint'                    => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_DROP_HINT'),
                'fileRejected'                => Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_FILE_REJECTED'),
            ],
        ];
    }

    /**
     * Answers whether an image carries the watermark.
     *
     * @return  array<string, mixed>
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function ajaxStatus(): array
    {
        $user = $this->assertAjaxRequest();

        if (!$user->authorise('core.manage', 'com_content')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'));
        }

        $path  = $this->extractLocalImagePath($this->getApplication()->getInput()->post->getString('path', ''));
        $state = $this->isWatermarkEnabled() ? $this->describeImage($path) : null;

        if ($state === null) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_FOUND'));
        }

        return ['success' => true] + $state;
    }

    /**
     * Uploads, processes and, when asked, stamps an image.
     *
     * @return  array<string, mixed>
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function ajaxUpload(): array
    {
        $user = $this->assertAjaxRequest();

        if (!$user->authorise('core.create', 'com_media')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'));
        }

        $this->checkArticlePermission($user);

        $input              = $this->getApplication()->getInput();
        $watermarkRequested = $input->post->getInt('watermark', 0) === 1 && $this->isWatermarkEnabled();
        $files              = $input->files->get('image', null, 'array');

        $this->validateUploadStructure($files);
        $this->validateUploadError($files);

        if (!is_uploaded_file($files['tmp_name'])) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_TEMP_FILE'));
        }

        $this->validateFileSize($files);
        $this->validateQuantumSafeFile($files);

        $files['name'] = $this->prepareQuantumFileName((string) $files['name']);

        if ($files['name'] === '' || !(new MediaHelper())->canUpload($files, 'com_media')) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_FILE_REJECTED'));
        }

        [$extension, $imageInfo] = $this->validateImage($files);
        $this->validateImageDimensions($imageInfo);

        $settings       = $this->getProcessingSettings();
        $relativeFolder = $this->buildUploadFolder();
        $absoluteFolder = JPATH_ROOT . '/' . $relativeFolder;

        $this->ensureDirectory($absoluteFolder);

        $baseName    = $this->buildBaseName($settings['namingMode'], (string) $files['name'], $settings['suffix']);
        $destination = $this->storeUpload($files, $extension, $settings, $absoluteFolder, $baseName);

        $this->secureFilePermissions($destination);

        $relativePath = $relativeFolder . '/' . basename($destination);
        $response     = [
            'success'     => true,
            'path'        => $relativePath,
            'name'        => basename($destination),
            'watermarked' => false,
        ];

        if ($watermarkRequested) {
            try {
                $this->applyWatermark($relativePath);
                $response['watermarked'] = true;
            } catch (\Throwable $exception) {
                $this->logThrowable($exception);
                $response['warning'] = Text::sprintf(
                    'PLG_SYSTEM_CONTENTIMAGEUPLOAD_WATERMARK_UPLOAD_FAILED',
                    $this->getPublicMessage($exception)
                );
            }
        }

        clearstatcache(true, $destination);

        $imageSize          = @getimagesize($destination);
        $response['width']  = $imageSize[0] ?? null;
        $response['height'] = $imageSize[1] ?? null;
        $response['url']    = $this->buildImageUrl($relativePath);
        $response['value']  = $this->buildFieldValue($relativePath);

        return $response;
    }

    /**
     * Common checks of an AJAX request; returns the user.
     *
     * @return  User
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function assertAjaxRequest(): User
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator')) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_ADMIN_ONLY'));
        }

        if (strtoupper((string) $app->getInput()->getMethod()) !== 'POST') {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_POST_ONLY'));
        }

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'));
        }

        $user = $app->getIdentity();

        if (!$user instanceof User || $user->guest) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'));
        }

        return $user;
    }

    /**
     * Stamps an image, keeping its original first.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function applyWatermark(string $relativePath): void
    {
        $store = $this->getOriginalStore();

        if ($store->has($relativePath)) {
            return;
        }

        $file           = $this->resolveImageFile($relativePath);
        [$extension]    = $this->validateExistingImage($file);
        $watermark      = $this->createWatermarkService();
        $settings       = $this->getProcessingSettings();
        $originalStored = $store->save($relativePath, $file);

        try {
            $watermark->applyToFile($file, $extension, $settings['quality'], $settings['pngCompression']);
        } catch (\Throwable $exception) {
            if ($originalStored) {
                $store->discard($relativePath);
            }

            throw $exception;
        }

        $this->afterImageChanged($relativePath);
    }

    /**
     * Puts the original back.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function removeWatermark(string $relativePath): void
    {
        $this->getOriginalStore()->restore($relativePath, $this->resolveImageFile($relativePath));
        $this->afterImageChanged($relativePath);
    }

    /**
     * Drops the Media Manager thumbnail of a changed image, so it is drawn again.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  void
     *
     * @since   1.7.0
     */
    private function afterImageChanged(string $relativePath): void
    {
        clearstatcache(true, JPATH_ROOT . '/' . $relativePath);

        $thumbnail = JPATH_ROOT . '/media/cache/com_media/thumbs/' . $relativePath;

        if (is_file($thumbnail)) {
            @unlink($thumbnail);
        }

        $this->purgeThumbnails($relativePath);
    }

    /**
     * Deletes JUImage thumbnails of a changed image.
     *
     * JUImage names a thumbnail after the source file plus a hash of its options and returns an
     * existing thumbnail without looking at the source again, so after a watermark change the
     * template would keep serving the old picture. The name is rebuilt here the way JUImage builds
     * it: cache/WxH/x/<name>-<crc32b>.<ext>.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  void
     *
     * @since   1.9.0
     */
    private function purgeThumbnails(string $relativePath): void
    {
        $name = strtolower(pathinfo($relativePath, PATHINFO_FILENAME));
        $name = (string) preg_replace('#[[:punct:]]#', '', $name);
        $name = (string) preg_replace('#[а-яёєїіЁЄЇІ]#iu', '', $name);
        $name = substr(str_replace([' +', ' '], ['_', ''], $name), 0, 150);

        if ($name === '') {
            return;
        }

        $pattern = '/^' . preg_quote($name, '/') . '-[0-9a-f]{8}\.(?:jpe?g|png|gif|webp|avif)$/';

        foreach ($this->getThumbnailFolders() as $folder) {
            foreach (glob($folder . '/*/*/' . $name . '-*', GLOB_NOSORT) ?: [] as $file) {
                if (is_file($file) && preg_match($pattern, basename($file))) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Thumbnail folders from the settings: inside the site, outside /images.
     *
     * @return  string[]
     *
     * @since   1.9.0
     */
    private function getThumbnailFolders(): array
    {
        $root    = realpath(JPATH_ROOT);
        $images  = realpath(JPATH_ROOT . '/images');
        $folders = [];

        if (!$root) {
            return [];
        }

        foreach (explode(',', (string) $this->params->get('thumb_cache_folders', 'img')) as $entry) {
            $entry = trim(str_replace('\\', '/', $entry), " /\t");

            if ($entry === '' || str_contains($entry, '..') || str_contains($entry, "\0") || str_contains($entry, ':')) {
                continue;
            }

            $path = realpath(JPATH_ROOT . '/' . $entry);

            if (!$path || !is_dir($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if ($images && ($path === $images || str_starts_with($path, $images . DIRECTORY_SEPARATOR))) {
                continue;
            }

            $folders[$path] = $path;
        }

        return array_values($folders);
    }

    /**
     * State of an image for the editor, or null when the file is missing.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  array<string, mixed>|null
     *
     * @since   1.7.0
     */
    private function describeImage(string $relativePath): ?array
    {
        if (!is_file(JPATH_ROOT . '/' . $relativePath)) {
            return null;
        }

        return [
            'path'        => $relativePath,
            'value'       => $this->buildFieldValue($relativePath),
            'watermarked' => $this->getOriginalStore()->has($relativePath),
            'url'         => $this->buildImageUrl($relativePath),
        ];
    }

    /**
     * Value for a media field in the form Joomla writes itself: the path plus #joomlaImage with the size.
     * The layout joomla.html.image turns that size into width, height and loading="lazy" on the site.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  string
     *
     * @since   1.7.1
     */
    private function buildFieldValue(string $relativePath): string
    {
        $file = JPATH_ROOT . '/' . $relativePath;

        clearstatcache(true, $file);

        $size = @getimagesize($file);

        if (!\is_array($size) || empty($size[0]) || empty($size[1])) {
            return $relativePath;
        }

        return $relativePath . '#joomlaImage://local-images/' . substr($relativePath, \strlen('images/'))
            . '?width=' . (int) $size[0] . '&height=' . (int) $size[1];
    }

    /**
     * Image URL with the modification time, so the browser does not show a stale copy.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  string
     *
     * @since   1.7.0
     */
    private function buildImageUrl(string $relativePath): string
    {
        $file = JPATH_ROOT . '/' . $relativePath;

        clearstatcache(true, $file);

        return Uri::root() . implode('/', array_map('rawurlencode', explode('/', $relativePath)))
            . '?v=' . (int) @filemtime($file);
    }

    /**
     * Absolute path of an existing image inside /images.
     *
     * @param   string  $relativePath  Image path relative to the site root.
     *
     * @return  string
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function resolveImageFile(string $relativePath): string
    {
        if (!$this->isSafeImagePath($relativePath)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PATH'));
        }

        $imagesRoot = realpath(JPATH_ROOT . '/images');
        $file       = realpath(JPATH_ROOT . '/' . $relativePath);

        if (!$imagesRoot || !$file || !str_starts_with($file, $imagesRoot . DIRECTORY_SEPARATOR) || !is_file($file)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_FOUND'));
        }

        return $file;
    }

    /**
     * Watermark service built from the plugin settings.
     *
     * @return  WatermarkService
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function createWatermarkService(): WatermarkService
    {
        $options = $this->getWatermarkOptions();

        if ($options['relative_path'] === '') {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_WATERMARK_NOT_FOUND'));
        }

        if (!$this->isSafeImagePath($options['relative_path'])) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_WATERMARK_PATH'));
        }

        try {
            $file = $this->resolveImageFile($options['relative_path']);
        } catch (\RuntimeException) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_WATERMARK_NOT_FOUND'));
        }

        $this->validateExistingImage($file);

        return new WatermarkService(
            $file,
            $options['position'],
            $options['opacity'],
            $options['scale'],
            $options['margin_x'],
            $options['margin_y']
        );
    }

    /**
     * Store of originals.
     *
     * @return  OriginalStore
     *
     * @since   1.7.0
     */
    private function getOriginalStore(): OriginalStore
    {
        return $this->originalStore ??= new OriginalStore(
            JPATH_ROOT,
            (string) $this->getApplication()->get('secret', ''),
            JPATH_ADMINISTRATOR . '/cache/contentimageupload/originals'
        );
    }

    /**
     * Whether the watermark feature is on.
     *
     * @return  bool
     *
     * @since   1.7.0
     */
    private function isWatermarkEnabled(): bool
    {
        return (int) $this->params->get('watermark_enabled', 0) === 1;
    }

    /**
     * Changing a file's pixels needs the right to create or edit media.
     *
     * @param   User  $user  The user.
     *
     * @return  bool
     *
     * @since   1.7.0
     */
    private function canChangeImages(User $user): bool
    {
        return $user->authorise('core.edit', 'com_media') || $user->authorise('core.create', 'com_media');
    }

    /**
     * The images field of an article as an array.
     *
     * @param   mixed  $images  Array, JSON string, Registry or object.
     *
     * @return  array<string, mixed>
     *
     * @since   1.7.0
     */
    private function normaliseImages(mixed $images): array
    {
        if ($images instanceof Registry) {
            return $images->toArray();
        }

        if (\is_string($images)) {
            $decoded = json_decode($images, true);

            return \is_array($decoded) ? $decoded : [];
        }

        if (\is_object($images)) {
            return (array) $images;
        }

        return \is_array($images) ? $images : [];
    }

    /**
     * Local path of a media field value, or an empty string for empty, external or unsafe values.
     *
     * @param   mixed  $value  Field value.
     *
     * @return  string
     *
     * @since   1.8.0
     */
    private function getLocalImagePath(mixed $value): string
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            return '';
        }

        try {
            return $this->extractLocalImagePath($value);
        } catch (\RuntimeException) {
            return '';
        }
    }

    /**
     * Image sync mode: always (no switch), on or off (switch in the panel with that default).
     *
     * @return  string
     *
     * @since   1.8.0
     */
    private function getImageSyncMode(): string
    {
        $mode = (string) $this->params->get('image_sync', 'on');

        return \in_array($mode, ['always', 'on', 'off'], true) ? $mode : 'on';
    }

    /**
     * The image the panel shows: the intro image, or the full text image when there is no intro image.
     *
     * @param   array<string, mixed>  $images  The images field.
     *
     * @return  string  Local path, or an empty string.
     *
     * @since   1.7.0
     */
    private function resolveTargetPath(array $images): string
    {
        foreach (['image_intro', 'image_fulltext'] as $field) {
            $value = trim((string) ($images[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            try {
                return $this->extractLocalImagePath($value);
            } catch (\RuntimeException) {
                return '';
            }
        }

        return '';
    }

    /**
     * Turns a media field value or a site URL into a local path inside /images.
     *
     * @param   string  $value  images/a.jpg#joomlaImage://local-images/a.jpg?width=…, or a URL of this site.
     *
     * @return  string
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function extractLocalImagePath(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_FOUND'));
        }

        if (str_contains($value, '#')) {
            [$localValue, $mediaValue] = explode('#', $value, 2);
            $value                     = trim($localValue);

            if ($value === '' && str_starts_with($mediaValue, 'joomlaImage://local-images/')) {
                $mediaPath = (string) parse_url($mediaValue, PHP_URL_PATH);
                $value     = 'images/' . ltrim(rawurldecode($mediaPath), '/');
            }
        }

        if (preg_match('#^https?://#i', $value)) {
            $url  = Uri::getInstance($value);
            $root = Uri::getInstance(Uri::root());

            if (strtolower((string) $url->getHost()) !== strtolower((string) $root->getHost())) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PATH'));
            }

            $value = substr((string) $url->getPath(), \strlen(rtrim(Uri::root(true), '/')));
        }

        $value = ltrim(str_replace('\\', '/', rawurldecode($value)), '/');

        if (!$this->isSafeImagePath($value)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PATH'));
        }

        return $value;
    }

    /**
     * Writes the uploaded file, processed when processing is on.
     *
     * @param   array<string, mixed>  $file      Upload entry.
     * @param   string                $extension Real extension of the upload.
     * @param   array<string, mixed>  $settings  Processing settings.
     * @param   string                $folder    Absolute target folder.
     * @param   string                $baseName  File name without extension.
     *
     * @return  string  Absolute path of the stored file.
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function storeUpload(array $file, string $extension, array $settings, string $folder, string $baseName): string
    {
        $destination = $this->getUniqueDestination($folder, $baseName, $extension);

        if (!$settings['enabled']) {
            $this->copyUpload($file['tmp_name'], $destination);

            return $destination;
        }

        $processor = new JoomlaCoreImageProcessor();

        if (!$processor->isAvailable()) {
            if ($settings['strictMode']) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PROCESSOR'));
            }

            $this->copyUpload($file['tmp_name'], $destination);

            return $destination;
        }

        $data = file_get_contents($file['tmp_name']);

        if ($data === false) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PROCESSING'));
        }

        try {
            $result = $processor->process($data, $extension, [
                'allow_upscale'    => $settings['allowUpscale'],
                'max_height'       => $settings['maxHeight'],
                'max_width'        => $settings['maxWidth'],
                'mode'             => $settings['resizeMode'],
                'quality'          => $settings['quality'],
                'png_compression'  => $settings['pngCompression'],
                'image_fill_color' => $settings['imageFillColor'],
                'target_format'    => $settings['outputFormat'],
                'source_file'      => $file['tmp_name'],
            ]);
        } catch (\Throwable $exception) {
            if ($settings['strictMode']) {
                throw $exception;
            }

            $this->logThrowable($exception);
            $result = null;
        }

        unset($data);

        if ($result === null) {
            if ($settings['strictMode']) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PROCESSING'));
            }

            $this->copyUpload($file['tmp_name'], $destination);

            return $destination;
        }

        $destination = $this->getUniqueDestination($folder, $baseName, $result['extension']);

        if (!File::write($destination, $result['data'])) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PROCESSING'));
        }

        return $destination;
    }

    /**
     * Copies the uploaded file as it is.
     *
     * @param   string  $source       Temporary upload file.
     * @param   string  $destination  Target file.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.7.0
     */
    private function copyUpload(string $source, string $destination): void
    {
        if (!File::copy($source, $destination)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PROCESSING'));
        }
    }

    /**
     * Checks that the user may edit the article, or create one in the category.
     *
     * @param   User  $user  The user.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function checkArticlePermission(User $user): void
    {
        $input     = $this->getApplication()->getInput();
        $articleId = $input->post->getInt('articleId', 0);

        if ($articleId > 0) {
            $assetName = 'com_content.article.' . $articleId;

            if ($user->authorise('core.edit', $assetName)) {
                return;
            }

            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('created_by'))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $articleId, ParameterType::INTEGER);

            $createdBy = (int) $db->setQuery($query)->loadResult();

            if ($createdBy === (int) $user->id && $user->authorise('core.edit.own', $assetName)) {
                return;
            }

            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'));
        }

        $categoryId = $input->post->getInt('categoryId', 0);
        $assetName  = $categoryId > 0 ? 'com_content.category.' . $categoryId : 'com_content';

        if (!$user->authorise('core.create', $assetName)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'));
        }
    }

    /**
     * @param   mixed  $files  Upload entry.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function validateUploadStructure(mixed $files): void
    {
        if (!\is_array($files)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NO_FILE'));
        }

        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
            if (!\array_key_exists($key, $files) || \is_array($files[$key])) {
                throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NO_FILE'));
            }
        }

        if ((string) $files['tmp_name'] === '' || (string) $files['name'] === '') {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NO_FILE'));
        }
    }

    /**
     * @param   array<string, mixed>  $files  Upload entry.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function validateUploadError(array $files): void
    {
        $error = (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        $messages = [
            UPLOAD_ERR_INI_SIZE  => 'PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_SIZE',
            UPLOAD_ERR_FORM_SIZE => 'PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_SIZE',
            UPLOAD_ERR_PARTIAL   => 'PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PARTIAL',
            UPLOAD_ERR_NO_FILE   => 'PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NO_FILE',
        ];

        throw new \RuntimeException(Text::_($messages[$error] ?? 'PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_UPLOAD'));
    }

    /**
     * @param   array<string, mixed>  $files  Upload entry.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function validateFileSize(array $files): void
    {
        $maxMb    = (float) ComponentHelper::getParams('com_media')->get('upload_maxsize', 0);
        $fileSize = (int) ($files['size'] ?? 0);

        if ($fileSize <= 0) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_SIZE'));
        }

        if ($maxMb > 0 && $fileSize > ($maxMb * 1024 * 1024)) {
            throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_MAX_SIZE', $maxMb));
        }
    }

    /**
     * Image extensions allowed by com_media that this plugin can process.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function getAllowedExtensions(): array
    {
        $params = ComponentHelper::getParams('com_media');
        $parse  = static function (string $value): array {
            $items = array_map('strtolower', array_map('trim', explode(',', str_replace('\\', '', $value))));

            return array_values(array_unique(array_filter($items)));
        };

        $extensions = $parse((string) $params->get('image_extensions', 'bmp,gif,jpg,jpeg,png,webp,avif'));

        if ((int) $params->get('restrict_uploads', 1) === 1) {
            $restricted = $parse((string) $params->get('restrict_uploads_extensions', 'bmp,gif,jpg,jpeg,png,webp,avif'));
            $extensions = array_values(array_intersect($extensions, $restricted));
        }

        return array_values(array_intersect($extensions, array_merge(...array_values(self::SUPPORTED_IMAGE_MIMES))));
    }

    /**
     * Value for the accept attribute of the file input.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getMediaImageAccept(): string
    {
        return implode(',', array_map(static fn (string $extension): string => '.' . $extension, $this->getAllowedExtensions()));
    }

    /**
     * Rejects names with executable parts and files with PHP code inside.
     *
     * @param   array<string, mixed>  $files  Upload entry.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function validateQuantumSafeFile(array $files): void
    {
        $name    = (string) ($files['name'] ?? '');
        $tmpName = (string) ($files['tmp_name'] ?? '');

        if ($name === '' || $tmpName === '' || str_contains($name, "\0")) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_FILE_REJECTED'));
        }

        $forbidden = ['php', 'phps', 'pht', 'phtml', 'php3', 'php4', 'php5', 'php6', 'php7', 'phar',
            'inc', 'pl', 'cgi', 'fcgi', 'java', 'jar', 'py', 'htaccess', 'sh'];
        $parts     = array_map('strtolower', \array_slice(explode('.', $name), 0, -1));

        if (array_intersect($forbidden, $parts) !== []) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_FILE_REJECTED'));
        }

        if (!is_file($tmpName) || !is_readable($tmpName)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_TEMP_FILE'));
        }

        $handle = @fopen($tmpName, 'rb');

        if ($handle === false) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_TEMP_FILE'));
        }

        $tail = '';

        try {
            while (!feof($handle)) {
                $chunk = $tail . (string) fread($handle, 131072);

                if (stripos($chunk, '<?php') !== false) {
                    throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_FILE_REJECTED'));
                }

                $tail = substr($chunk, -10);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Normalises a file name the way Quantum Manager does: transliteration and safe characters.
     *
     * @param   string  $name  Original file name.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function prepareQuantumFileName(string $name): string
    {
        $name      = trim($name);
        $extension = strtolower((string) preg_replace('#[^A-Za-z0-9]#', '', pathinfo($name, PATHINFO_EXTENSION)));
        $baseName  = pathinfo($name, PATHINFO_FILENAME);

        $baseName = preg_replace('#[-]{2,}#u', '-', str_replace(' ', '-', $baseName)) ?? $baseName;
        $baseName = File::makeSafe($this->transliterate($baseName), ['#^\.#', '#\040#']);
        $baseName = trim($baseName, '.-');

        return $baseName === '' || $extension === '' ? '' : $baseName . '.' . $extension;
    }

    /**
     * @param   array<string, mixed>  $files  Upload entry.
     *
     * @return  array{0: string, 1: array}  Real extension and getimagesize() data.
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function validateImage(array $files): array
    {
        $extension = strtolower(pathinfo((string) ($files['name'] ?? ''), PATHINFO_EXTENSION));

        if (!\in_array($extension, $this->getAllowedExtensions(), true)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_FILE_REJECTED'));
        }

        $imageInfo = @getimagesize($files['tmp_name']);

        if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_IMAGE'));
        }

        $supported = self::SUPPORTED_IMAGE_MIMES[strtolower((string) ($imageInfo['mime'] ?? ''))] ?? [];

        if ($supported === []) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_MIME'));
        }

        return [$supported[0], $imageInfo];
    }

    /**
     * @param   string  $file  Absolute path of an image.
     *
     * @return  array{0: string, 1: array}  Real extension and getimagesize() data.
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function validateExistingImage(string $file): array
    {
        $imageInfo = @getimagesize($file);

        if ($imageInfo === false) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_IMAGE'));
        }

        if (!\in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $this->getAllowedExtensions(), true)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_FILE_REJECTED'));
        }

        $supported = self::SUPPORTED_IMAGE_MIMES[strtolower((string) ($imageInfo['mime'] ?? ''))] ?? [];

        if ($supported === []) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_MIME'));
        }

        $this->validateImageDimensions($imageInfo);

        return [$supported[0], $imageInfo];
    }

    /**
     * @param   array  $imageInfo  getimagesize() data.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function validateImageDimensions(array $imageInfo): void
    {
        $width  = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);

        if ($width < 1 || $height < 1) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_IMAGE'));
        }

        if ($width > intdiv(self::MAX_IMAGE_PIXELS, $height)) {
            throw new \RuntimeException(
                Text::sprintf('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_DIMENSIONS', $width, $height, self::MAX_IMAGE_PIXELS)
            );
        }
    }

    /**
     * Upload folder from the «Folder format» setting; dates follow the user's time zone.
     *
     * @return  string
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function buildUploadFolder(): string
    {
        $template = str_replace('\\', '/', trim((string) $this->params->get('folder_format', 'images/{year}/{month}/{day}')));
        $template = $template !== '' ? $template : 'images/{year}/{month}/{day}';

        if (
            str_contains($template, "\0") || str_contains($template, '..') || str_starts_with($template, '/')
            || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $template)
        ) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PATH'));
        }

        $user      = $this->getApplication()->getIdentity();
        $date      = $this->getLocalDate();
        $format    = static fn (string $pattern): string => $date->format($pattern, true, false);
        $variables = [
            '{user_id}'   => (string) ($user instanceof User ? (int) $user->id : 0),
            '{year}'      => $format('Y'),
            '{month}'     => $format('m'),
            '{week_year}' => $format('W'),
            '{week_day}'  => $format('w'),
            '{day_year}'  => $format('z'),
            '{day}'       => $format('d'),
            '{hours}'     => $format('h'),
            '{hours_24}'  => $format('H'),
            '{minutes}'   => $format('i'),
            '{seconds}'   => $format('s'),
            '{unix}'      => (string) $date->toUnix(),
        ];

        $resolved = trim(strtr($template, $variables), '/');

        if (preg_match('/\{[^}]+\}/', $resolved) || !$this->isSafeImagePath($resolved)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PATH'));
        }

        return $resolved;
    }

    /**
     * Current date in the time zone of the user (the site time zone when the user has none).
     *
     * @return  \Joomla\CMS\Date\Date
     *
     * @since   1.7.1
     */
    private function getLocalDate(): \Joomla\CMS\Date\Date
    {
        $user = $this->getApplication()->getIdentity();
        $date = Factory::getDate();

        $date->setTimezone($user instanceof User ? $user->getTimezone() : new \DateTimeZone('UTC'));

        return $date;
    }

    /**
     * A relative path inside /images without empty, dot or control segments.
     *
     * @param   string  $path  Path relative to the site root.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    private function isSafeImagePath(string $path): bool
    {
        $parts = explode('/', $path);

        if ($parts[0] !== 'images') {
            return false;
        }

        foreach ($parts as $part) {
            if (
                $part === '' || $part === '.' || $part === '..' || str_contains($part, '\\') || str_contains($part, ':')
                || preg_match('/[\x00-\x1F\x7F]/', $part)
            ) {
                return false;
            }
        }

        // The store of originals is not a place for article images.
        return !str_starts_with($path . '/', OriginalStore::FOLDER . '/');
    }

    /**
     * @param   string  $directory  Absolute folder path.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_CREATE_FOLDER'));
        }

        $imagesRoot = realpath(JPATH_ROOT . '/images');
        $realFolder = realpath($directory);

        if (!$imagesRoot || !$realFolder || !str_starts_with($realFolder, $imagesRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PATH'));
        }

        if (!is_writable($realFolder)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_NOT_WRITABLE'));
        }
    }

    /**
     * @param   string  $directory  Absolute folder path.
     * @param   string  $baseName   File name without extension.
     * @param   string  $extension  Extension.
     *
     * @return  string
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function getUniqueDestination(string $directory, string $baseName, string $extension): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $destination = $directory . '/' . $baseName . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

            if (!file_exists($destination)) {
                return $destination;
            }
        }

        throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_FILENAME'));
    }

    /**
     * @param   string  $file  Absolute file path.
     *
     * @return  void
     *
     * @throws  \RuntimeException
     *
     * @since   1.0.0
     */
    private function secureFilePermissions(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException(Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_PROCESSING'));
        }

        if (!@chmod($file, 0644)) {
            $this->log('Unable to set permissions on ' . $file, Log::WARNING);
        }
    }

    /**
     * Validated processing settings.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    private function getProcessingSettings(): array
    {
        $pick = function (string $name, string $default, array $allowed): string {
            $value = strtolower((string) $this->params->get($name, $default));

            return \in_array($value, $allowed, true) ? $value : $default;
        };

        return [
            'enabled'        => (int) $this->params->get('processing_enabled', 1) === 1,
            'resizeMode'     => $pick('resize_mode', 'none', ['none', 'fit', 'crop', 'width', 'height']),
            'maxWidth'       => max(0, (int) $this->params->get('max_width', 1920)),
            'maxHeight'      => max(0, (int) $this->params->get('max_height', 1080)),
            'allowUpscale'   => (int) $this->params->get('allow_upscale', 0) === 1,
            'outputFormat'   => $pick('output_format', 'keep', ['keep', 'jpg', 'png', 'gif', 'webp', 'avif', 'bmp']),
            'quality'        => min(100, max(1, (int) $this->params->get('quality', 82))),
            'pngCompression' => min(9, max(0, (int) $this->params->get('png_compression', 6))),
            'imageFillColor' => $this->normaliseFillColor((string) $this->params->get('image_fill_color', '#ffffff')),
            'strictMode'     => (int) $this->params->get('strict_mode', 0) === 1,
            'namingMode'     => $pick('filename_mode', 'k2hash', ['k2hash', 'datehash', 'original']),
            'suffix'         => (string) $this->params->get('filename_suffix', '_XL'),
        ];
    }

    /**
     * Validated watermark settings.
     *
     * @return  array<string, mixed>
     *
     * @since   1.5.1
     */
    private function getWatermarkOptions(): array
    {
        $position = str_replace('-', '_', (string) $this->params->get('watermark_position', 'bottom_right'));
        $value    = trim((string) $this->params->get('watermark_file', ''));
        $margin   = $this->params->get('watermark_margin', 24);

        try {
            $relativePath = $value !== '' ? $this->extractLocalImagePath($value) : '';
        } catch (\RuntimeException) {
            $relativePath = ltrim(str_replace('\\', '/', explode('#', $value, 2)[0]), '/');
        }

        return [
            'relative_path' => $relativePath,
            'position'      => \in_array($position, WatermarkService::POSITIONS, true) ? $position : 'bottom_right',
            'margin_x'      => min(500, max(0, (int) $this->params->get('watermark_margin_x', $margin))),
            'margin_y'      => min(500, max(0, (int) $this->params->get('watermark_margin_y', $margin))),
            'opacity'       => min(100, max(1, (int) $this->params->get('watermark_opacity', 80))),
            'scale'         => min(100, max(1, (int) $this->params->get('watermark_scale', 20))),
        ];
    }

    /**
     * @param   string  $value  Colour from the settings.
     *
     * @return  string
     *
     * @since   1.5.1
     */
    private function normaliseFillColor(string $value): string
    {
        $value = ltrim(trim($value), '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $value) ? '#' . strtolower($value) : '#ffffff';
    }

    /**
     * @param   string  $mode          k2hash, datehash or original.
     * @param   string  $originalName  Uploaded file name.
     * @param   string  $suffix        Suffix for k2hash.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function buildBaseName(string $mode, string $originalName, string $suffix): string
    {
        if ($mode === 'original') {
            return $this->safeLatinName(pathinfo($originalName, PATHINFO_FILENAME));
        }

        if ($mode === 'datehash') {
            return $this->getLocalDate()->format('Ymd_His', true, false) . '_' . bin2hex(random_bytes(8));
        }

        $safeSuffix = $this->safeLatinName($suffix);

        return bin2hex(random_bytes(16)) . ($safeSuffix === 'image' ? '' : '_' . $safeSuffix);
    }

    /**
     * Russian and Kazakh Cyrillic by the table above, everything else by the interface language.
     *
     * @param   string  $value  Any text.
     *
     * @return  string
     *
     * @since   1.7.0
     */
    private function transliterate(string $value): string
    {
        $value = strtr(mb_strtolower($value, 'UTF-8'), self::CYRILLIC_TO_LATIN);

        return $this->getApplication()->getLanguage()->transliterate($value);
    }

    /**
     * Latin letters, digits, hyphen and underscore only.
     *
     * @param   string  $value  Any text.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function safeLatinName(string $value): string
    {
        $value = strtolower($this->transliterate($value));
        $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return trim($value, '-_') ?: 'image';
    }

    /**
     * Message safe to show to the editor.
     *
     * @param   \Throwable  $exception  The error.
     *
     * @return  string
     *
     * @since   1.7.0
     */
    private function getPublicMessage(\Throwable $exception): string
    {
        return $exception instanceof \RuntimeException
            ? $exception->getMessage()
            : Text::_('PLG_SYSTEM_CONTENTIMAGEUPLOAD_ERROR_GENERIC');
    }

    /**
     * Writes to administrator/logs/plg_system_contentimageupload.php.
     *
     * @param   string  $message   Message.
     * @param   int     $priority  Log priority.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function log(string $message, int $priority = Log::ERROR): void
    {
        try {
            if (!self::$loggerReady) {
                Log::addLogger(['text_file' => self::LOG_CATEGORY . '.php'], Log::ALL & ~Log::DEBUG, [self::LOG_CATEGORY]);
                self::$loggerReady = true;
            }

            Log::add($message, $priority, self::LOG_CATEGORY);
        } catch (\Throwable) {
            // Logging must never break the editor.
        }
    }

    /**
     * @param   \Throwable  $exception  The error.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function logThrowable(\Throwable $exception): void
    {
        $this->log(\sprintf(
            '%s: %s (%s:%d)',
            $exception::class,
            $exception->getMessage(),
            basename($exception->getFile()),
            $exception->getLine()
        ));
    }
}
