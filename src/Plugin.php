<?php

namespace imarc\padstonemason;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\App;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use imarc\padstonemason\models\Settings;
use imarc\padstonemason\services\Assistant;
use imarc\padstonemason\services\ContentDesigner;
use imarc\padstonemason\services\ConversationAssets;
use imarc\padstonemason\services\Conversations;
use imarc\padstonemason\services\Usage;
use imarc\padstonemason\web\assets\assistant\AssistantAsset;
use yii\base\Event;

/**
 * @property ContentDesigner $contentDesigner
 * @property Assistant $assistant
 * @property Conversations $conversations
 * @property ConversationAssets $conversationAssets
 * @property Usage $usage
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION = 'padstoneMason:use';

    public string $schemaVersion = '1.2.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'contentDesigner' => ContentDesigner::class,
            'assistant' => Assistant::class,
            'conversations' => Conversations::class,
            'conversationAssets' => ConversationAssets::class,
            'usage' => Usage::class,
        ]);

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Padstone Mason',
                    'permissions' => [
                        self::PERMISSION => [
                            'label' => 'Use Ask Mason',
                        ],
                    ],
                ];
            },
        );

        Event::on(Entry::class, Element::EVENT_DEFINE_ADDITIONAL_BUTTONS, function(DefineHtmlEvent $event) {
            $entry = $event->sender;
            if (!$entry instanceof Entry) {
                return;
            }
            $html = $this->editorButtonHtml($entry);
            if ($html !== '') {
                $event->html .= $html;
            }
        });

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['padstone-mason'] = 'padstone-mason/chat/index';
                $event->rules['padstone-mason/usage'] = 'padstone-mason/usage/index';
            },
        );

        Craft::$app->onInit(function() {
            if (!$this->isAvailableToCurrentUser()) {
                return;
            }

            $view = Craft::$app->getView();
            if ($view->getTemplateMode() === View::TEMPLATE_MODE_CP) {
                $view->registerAssetBundle(AssistantAsset::class);
            }
        });
    }

    public function getCpNavItem(): ?array
    {
        if (!$this->isAvailableToCurrentUser()) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = 'Padstone Mason';
        $item['url'] = 'padstone-mason';
        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav'] = [
                'chat' => ['label' => 'Ask Mason', 'url' => 'padstone-mason'],
                'usage' => ['label' => 'Usage', 'url' => 'padstone-mason/usage'],
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('padstone-mason/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function editorButtonHtml(Entry $entry): string
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$this->isAvailableToCurrentUser() || !$entry->id || !$user || !Craft::$app->getElements()->canSave($entry, $user)) {
            return '';
        }

        $draftId = ($entry->getIsDraft() && !$entry->isProvisionalDraft) ? $entry->draftId : '';

        return '<button type="button" class="btn" data-padstone-mason'
            . ' data-entry-id="' . (int)$entry->id . '"'
            . ' data-draft-id="' . (int)$draftId . '"'
            . ' data-site-id="' . (int)$entry->siteId . '">Ask Mason</button>';
    }

    public function isAvailableToCurrentUser(): bool
    {
        if (!$this->getSettings()->enabled) {
            return false;
        }

        $user = Craft::$app->getUser()->getIdentity();

        return $user && $user->can(self::PERMISSION);
    }

    public function apiKey(): ?string
    {
        $value = App::env($this->getSettings()->apiKeyEnvName());

        return is_string($value) && $value !== '' ? $value : null;
    }
}
