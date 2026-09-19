<?php

namespace APP\plugins\blocks\socialFeedBlock;

use APP\core\Application;
use APP\plugins\blocks\socialFeedBlock\classes\ApiClient;
use APP\plugins\blocks\socialFeedBlock\classes\BlueskyProvider;
use APP\plugins\blocks\socialFeedBlock\classes\BlueskyRichText;
use APP\plugins\blocks\socialFeedBlock\classes\FeedConfig;
use APP\plugins\blocks\socialFeedBlock\classes\FeedService;
use APP\plugins\blocks\socialFeedBlock\classes\FeedSettings;
use APP\plugins\blocks\socialFeedBlock\classes\HtmlSanitizer;
use APP\plugins\blocks\socialFeedBlock\classes\MastodonProvider;
use APP\plugins\blocks\socialFeedBlock\classes\Post;
use APP\plugins\blocks\socialFeedBlock\classes\Setting;
use Illuminate\Support\Facades\Cache;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\BlockPlugin;
use Throwable;

/**
 * A sidebar block with the latest posts of a Mastodon or Bluesky account.
 *
 * The server fetches and caches the posts and the template renders plain HTML,
 * so a visitor's browser never contacts the social network.
 */
class SocialFeedBlockPlugin extends BlockPlugin
{
    private const STYLESHEET = 'styles/block.css';

    public function getDisplayName(): string
    {
        return __('plugins.blocks.socialFeed.title');
    }

    public function getDescription(): string
    {
        return __('plugins.blocks.socialFeed.desc');
    }

    /**
     * @return array<string, mixed> the settings of the current context by name; ones never saved are null
     */
    public function getFeedSettings(): array
    {
        $contextId = $this->getCurrentContextId();
        $names = Setting::names();

        return array_combine($names, array_map(fn (string $name): mixed => $this->getSetting($contextId, $name), $names));
    }

    /** The one place that wires the providers to the HTTP client, the cache and the log. */
    public function createFeedService(): FeedService
    {
        $api = new ApiClient(Application::get()->getHttpClient());

        return new FeedService(
            [
                FeedConfig::NETWORK_MASTODON => new MastodonProvider($api, new HtmlSanitizer()),
                FeedConfig::NETWORK_BLUESKY => new BlueskyProvider($api, new BlueskyRichText()),
            ],
            Cache::store(),
            static function (string $message): void {
                error_log($message);
            }
        );
    }

    public function getContents($templateMgr, $request = null)
    {
        $request ??= Application::get()->getRequest();
        $settings = $this->getFeedSettings();
        $config = FeedSettings::config($settings);

        $title = trim((string) $settings[Setting::BlockTitle->value]);
        $templateMgr->assign([
            'socialFeedTitle' => $title !== '' ? $title : __('plugins.blocks.socialFeed.defaultTitle'),
            'socialFeedPosts' => $config === null ? [] : $this->loadPosts($config),
            'socialFeedProfileUrl' => $config?->profileUrl(),
            // The sidebar is rendered after the page head, so the stylesheet is linked from the block itself
            'socialFeedStylesheet' => $request->getBaseUrl() . '/' . $this->getPluginPath() . '/' . self::STYLESHEET,
        ]);

        return parent::getContents($templateMgr, $request);
    }

    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $url = $request->getRouter()->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'blocks',
        ]);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));

        return $actions;
    }

    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $form = new SocialFeedBlockPluginSettingsForm($this);
        if (!$request->getUserVar('save')) {
            $form->initData();

            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();

        return new JSONMessage(true);
    }

    /**
     * A social block must never take a journal page down, so whatever goes
     * wrong while loading its posts only means there is nothing to show.
     *
     * @return list<array<string, mixed>> the posts in the form the template reads
     */
    private function loadPosts(FeedConfig $config): array
    {
        try {
            return array_map(
                static fn (Post $post): array => $post->toArray(),
                $this->createFeedService()->getPosts($config)
            );
        } catch (Throwable $exception) {
            error_log('socialFeedBlock: ' . $exception::class . ' - ' . $exception->getMessage());

            return [];
        }
    }
}
