<?php

namespace APP\plugins\blocks\socialFeedBlock;

use APP\notification\NotificationManager;
use APP\plugins\blocks\socialFeedBlock\classes\FeedConfig;
use APP\plugins\blocks\socialFeedBlock\classes\FeedException;
use APP\plugins\blocks\socialFeedBlock\classes\FeedSettings;
use APP\plugins\blocks\socialFeedBlock\classes\Setting;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\notification\Notification;

class SocialFeedBlockPluginSettingsForm extends Form
{
    /** What a block that was never configured starts with. */
    private const DEFAULTS = [
        'network' => FeedConfig::NETWORK_MASTODON,
        'mastodonSource' => FeedConfig::SOURCE_ACCOUNT,
        'blueskySource' => FeedConfig::SOURCE_AUTHOR,
        'postCount' => FeedConfig::DEFAULT_POSTS,
        'cacheTtl' => FeedConfig::DEFAULT_CACHE_MINUTES,
    ];

    public function __construct(private SocialFeedBlockPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /** Settings are stored per context, so every journal can show its own feed. */
    public function initData()
    {
        foreach ($this->plugin->getFeedSettings() as $name => $value) {
            $isUnset = $value === null || $value === '';
            $this->setData($name, $isUnset ? (self::DEFAULTS[$name] ?? $value) : $value);
        }
        parent::initData();
    }

    public function readInputData()
    {
        $this->readUserVars(Setting::names());
        parent::readInputData();
    }

    /**
     * The same rules the block applies: what is accepted here is what the
     * block will show.
     */
    public function validate($callHooks = true)
    {
        $isValid = parent::validate($callHooks);

        foreach (FeedSettings::errors($this->submittedSettings()) as $name => $error) {
            $this->addError($name, __($error['key'], $error['params']));
            $this->addErrorField($name);
            $isValid = false;
        }

        return $isValid;
    }

    public function fetch($request, $template = null, $display = false)
    {
        $range = static fn (int $min, int $max): array => ['min' => $min, 'max' => $max];

        TemplateManager::getManager($request)->assign([
            'pluginName' => $this->plugin->getName(),
            'networkOptions' => [
                FeedConfig::NETWORK_MASTODON => 'plugins.blocks.socialFeed.network.mastodon',
                FeedConfig::NETWORK_BLUESKY => 'plugins.blocks.socialFeed.network.bluesky',
            ],
            'mastodonSourceOptions' => [
                FeedConfig::SOURCE_ACCOUNT => 'plugins.blocks.socialFeed.source.account',
                FeedConfig::SOURCE_HASHTAG => 'plugins.blocks.socialFeed.source.hashtag',
            ],
            'blueskySourceOptions' => [
                FeedConfig::SOURCE_AUTHOR => 'plugins.blocks.socialFeed.source.author',
                FeedConfig::SOURCE_FEED => 'plugins.blocks.socialFeed.source.feed',
            ],
            'postCountHelp' => __('plugins.blocks.socialFeed.settings.postCount.desc', $range(FeedConfig::MIN_POSTS, FeedConfig::MAX_POSTS)),
            'cacheTtlHelp' => __('plugins.blocks.socialFeed.settings.cacheTtl.desc', $range(FeedConfig::MIN_CACHE_MINUTES, FeedConfig::MAX_CACHE_MINUTES)),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * Saves the settings and then loads the feed once, so an administrator
     * learns at once whether the new source works. A source that cannot be
     * reached is still saved: the network may only be down for the moment.
     */
    public function execute(...$functionArgs)
    {
        $config = FeedSettings::config($this->submittedSettings());
        $contextId = $this->plugin->getCurrentContextId();

        foreach (Setting::cases() as $setting) {
            $this->plugin->updateSetting($contextId, $setting->value, $this->valueToStore($setting, $config), $setting->type());
        }

        [$type, $message] = $this->checkFeed($config);
        (new NotificationManager())->createTrivialNotification(
            PKPApplication::get()->getRequest()->getUser()->getId(),
            $type,
            ['contents' => $message]
        );

        return parent::execute(...$functionArgs);
    }

    /** @return array<string, mixed> */
    private function submittedSettings(): array
    {
        return array_combine(Setting::names(), array_map(fn (string $name): mixed => $this->getData($name), Setting::names()));
    }

    /**
     * Numbers and flags are stored as the block reads them, so the form shows
     * the value in effect when it is opened again; text is stored as typed.
     */
    private function valueToStore(Setting $setting, FeedConfig $config): mixed
    {
        return match ($setting) {
            Setting::PostCount => $config->postCount,
            Setting::CacheTtl => $config->cacheMinutes,
            Setting::ExcludeReplies => $config->excludeReplies,
            Setting::ExcludeReposts => $config->excludeReposts,
            default => trim((string) $this->getData($setting->value)),
        };
    }

    /** @return array{0: int, 1: string} the notification type and its text */
    private function checkFeed(FeedConfig $config): array
    {
        try {
            $this->plugin->createFeedService()->refresh($config);
        } catch (FeedException $exception) {
            $reason = __($exception->getError()->localeKey());

            return [Notification::NOTIFICATION_TYPE_WARNING, __('plugins.blocks.socialFeed.saved.warning', ['reason' => $reason])];
        }

        return [Notification::NOTIFICATION_TYPE_SUCCESS, __('common.changesSaved')];
    }
}
