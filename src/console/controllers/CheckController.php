<?php

namespace imarc\padstonemason\console\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use craft\web\View;
use craft\fields\Assets as AssetsField;
use imarc\padstonemason\controllers\ChatController;
use imarc\padstonemason\controllers\UsageController;
use imarc\padstonemason\models\Settings;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\services\ContentDesigner;
use imarc\padstonemason\services\FieldSupport;
use imarc\padstonemason\records\ConversationAssetRecord;
use imarc\padstonemason\records\ConversationRecord;
use imarc\padstonemason\records\MessageRecord;
use imarc\padstonemason\records\UsageRecord;
use imarc\padstonemason\services\providers\AnthropicClient;
use imarc\padstonemason\services\providers\OpenAiClient;
use imarc\padstonemason\services\providers\ProviderClient;
use imarc\padstonemason\services\providers\ProviderResult;
use imarc\padstonemason\services\providers\ToolInput;
use ReflectionMethod;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\web\UploadedFile;

class CheckController extends Controller
{
    /** @var int[] */
    private array $elementIds = [];

    /** @var int[] */
    private array $assetIds = [];

    /** @var int[] */
    private array $conversationIds = [];

    /** @var int[] */
    private array $userIds = [];

    /** @var string[] */
    private array $failures = [];

    public function actionIndex(): int
    {
        $admin = User::find()->admin(true)->one();
        if (!$admin instanceof User) {
            $this->stderr("No admin user found.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        Craft::$app->getUser()->setIdentity($admin);
        $plugin = Plugin::getInstance();
        if (!$plugin) {
            $this->stderr("Padstone Mason plugin is not loaded.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $this->checkSettingsShape();
            $this->checkSettingsSave($plugin);
            $this->checkIdentity($plugin, $admin);
            $this->checkPermissions($plugin, $admin);
            $this->checkProviders($plugin);
            $this->checkEditing($plugin, $admin);
            $this->checkSummarizeAndApply($plugin, $admin);
            $this->checkConversationAssets($plugin, $admin);
            $this->checkMediaAndRelations($plugin, $admin);
            $this->checkUnpublishAndDelete($plugin, $admin);
            $this->checkAssistantLoop($plugin, $admin);
            $this->checkConversations($plugin, $admin);
            $this->checkUsage($plugin, $admin);
            $this->checkSecrets();
        } finally {
            Craft::$app->getUser()->setIdentity($admin);
            $this->cleanup();
        }

        if ($this->failures !== []) {
            foreach ($this->failures as $failure) {
                $this->stderr("FAIL: $failure\n");
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Padstone Mason checks passed.\n");

        return ExitCode::OK;
    }

    private function checkSettingsShape(): void
    {
        $names = array_keys((new Settings())->getAttributes());
        foreach (['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'apiKey', 'openaiApiKey', 'anthropicApiKey'] as $secret) {
            if (in_array($secret, $names, true)) {
                $this->failures[] = "Settings model stores $secret";
            }
        }
    }

    private function checkSettingsSave(Plugin $plugin): void
    {
        $posted = new Settings();
        $posted->setAttributes(['allowedSections' => "pages\nblogArticle"], false);
        if (!$posted->validate() || $posted->allowedSections !== ['pages', 'blogArticle']) {
            $this->failures[] = 'A newline-separated section list was not read as separate handles: ' . json_encode($posted->allowedSections) . ' ' . json_encode($posted->getErrors());
        }

        $settings = $plugin->getSettings();
        $original = $settings->getAttributes();
        $settings->provider = 'anthropic';
        $settings->defaultModel = 'claude-sonnet-4-5';
        $settings->allowedSections = ['pages'];
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->getAttributes())) {
            $this->failures[] = 'Could not save plugin settings: ' . json_encode($settings->getErrors());

            return;
        }

        Craft::$app->getProjectConfig()->flush();
        $yaml = $this->projectConfigText();
        if (!str_contains($yaml, 'claude-sonnet-4-5') || !str_contains($yaml, 'anthropic')) {
            $this->failures[] = 'Saved provider and model were not written to project config.';
        }
        if (str_contains($yaml, 'OPENAI_API_KEY') || str_contains($yaml, 'ANTHROPIC_API_KEY')) {
            $this->failures[] = 'Project config contains an API key setting.';
        }

        $reloaded = Craft::$app->getPlugins()->getPlugin('padstone-mason');
        if (!$reloaded instanceof Plugin || $reloaded->getSettings()->defaultModel !== 'claude-sonnet-4-5') {
            $this->failures[] = 'Saved settings did not survive a plugin reload.';
        }

        $plugin->getSettings()->setAttributes($original, false);
        Craft::$app->getPlugins()->savePluginSettings($plugin, $original);
    }

    private function checkIdentity(Plugin $plugin, User $admin): void
    {
        Craft::$app->getUser()->setIdentity($admin);
        $nav = $plugin->getCpNavItem();
        $button = $plugin->editorButtonHtml($this->samplePage($admin));
        if (($nav['label'] ?? '') !== 'Padstone Mason' || ($nav['subnav']['chat']['label'] ?? '') !== 'Ask Mason' || !str_contains($button, '>Ask Mason</button>') || !str_contains($button, 'data-padstone-mason')) {
            $this->failures[] = 'Padstone Mason is not the nav label, or Ask Mason is not the chat link and entry button.';
        }

        $tables = [
            ConversationRecord::tableName(),
            MessageRecord::tableName(),
            UsageRecord::tableName(),
        ];
        foreach (['padstonemason_conversations', 'padstonemason_messages', 'padstonemason_usage'] as $name) {
            $token = '{{%' . $name . '}}';
            if (!in_array($token, $tables, true) || Craft::$app->getDb()->getTableSchema($token) === null) {
                $this->failures[] = 'Table ' . $name . ' is missing.';
            }
        }
    }

    private function checkPermissions(Plugin $plugin, User $admin): void
    {
        $method = new ReflectionMethod(\craft\controllers\PluginsController::class, 'beforeAction');
        $source = $this->methodSource($method);
        if (!str_contains($source, 'requireAdmin')) {
            $this->failures[] = 'Craft plugin settings are not limited to admins.';
        }

        $viewer = $this->makeUser(false);
        $this->withPermissionChecks(function () use ($plugin, $viewer, $admin): void {
            Craft::$app->getUser()->setIdentity($viewer);
            if ($plugin->getCpNavItem() !== null) {
                $this->failures[] = 'A user without the assistant permission can see the nav item.';
            }
            if ($plugin->editorButtonHtml($this->samplePage($admin)) !== '') {
                $this->failures[] = 'A user without the assistant permission can see the editor button.';
            }
        });

        $plugin->getSettings()->enabled = false;
        Craft::$app->getUser()->setIdentity($admin);
        if ($plugin->isAvailableToCurrentUser() || $plugin->editorButtonHtml($this->samplePage($admin)) !== '') {
            $this->failures[] = 'The assistant stays available while disabled.';
        }
        if ($plugin->assistant->configurationError() !== 'The assistant is disabled.') {
            $this->failures[] = 'A disabled assistant did not refuse the request.';
        }
        $plugin->getSettings()->enabled = true;
        Craft::$app->getUser()->setIdentity($admin);
    }

    private function checkProviders(Plugin $plugin): void
    {
        $plugin->getSettings()->provider = 'openai';
        $plugin->getSettings()->defaultModel = 'gpt-4.1';
        $this->assertClientRequest(
            new OpenAiClient('test-key', $this->mockHttp([
                'choices' => [['message' => ['content' => 'ok']]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3],
            ], $openaiHistory)),
            'api.openai.com',
            'gpt-4.1',
            $openaiHistory,
        );

        $plugin->getSettings()->provider = 'anthropic';
        $plugin->getSettings()->defaultModel = 'claude-sonnet-4-5';
        $this->assertClientRequest(
            new AnthropicClient('test-key', $this->mockHttp([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
            ], $anthropicHistory)),
            'api.anthropic.com',
            'claude-sonnet-4-5',
            $anthropicHistory,
        );

        $plugin->getSettings()->provider = 'openai';
        $plugin->getSettings()->defaultModel = 'gpt-4.1';

        if (ToolInput::json([]) !== '{}' || ToolInput::json(['type' => 'richText']) !== '{"type":"richText"}') {
            $this->failures[] = 'Empty tool arguments were not encoded as an object.';
        }
        $emptyInput = [];
        $this->assertClientRequest(
            new AnthropicClient('test-key', $this->mockHttp([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ], $emptyInput)),
            'api.anthropic.com',
            'claude-sonnet-4-5',
            $emptyInput,
            [
                ['role' => 'user', 'content' => 'Read the page'],
                ['role' => 'assistant', 'toolCalls' => [[
                    'id' => 'toolu_read',
                    'name' => 'read_layout',
                    'arguments' => [],
                ]]],
                ['role' => 'tool', 'toolCallId' => 'toolu_read', 'content' => '{"ok":true}'],
            ],
        );
        $emptyRequest = $emptyInput[0]['request'] ?? null;
        if ($emptyRequest) {
            $emptyRequest->getBody()->rewind();
        }
        $emptyBody = $emptyRequest ? (string)$emptyRequest->getBody() : '';
        if (!str_contains($emptyBody, '"input":{}')) {
            $this->failures[] = 'Anthropic tool input was not sent as an object.';
        }

        $key = 'sk-testkeyvalue12345678';
        try {
            (new OpenAiClient($key, $this->mockHttpStatus(401, [
                'error' => ['message' => "Incorrect API key provided: $key"],
            ])))->complete([['role' => 'user', 'content' => 'hi']], [], 'gpt-4.1', 16);
            $this->failures[] = 'A failed provider response did not throw.';
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if (!str_contains($message, '401') || !str_contains($message, 'Incorrect API key') || str_contains($message, $key)) {
                $this->failures[] = 'Provider error was not a safe status message: ' . $message;
            }
        }
    }

    private function checkEditing(Plugin $plugin, User $admin): void
    {
        Craft::$app->getUser()->setIdentity($admin);

        $matrixAdapter = null;
        foreach ($plugin->structureAdapters->all() as $adapter) {
            if ($adapter instanceof \imarc\padstonemason\services\structure\MatrixStructureAdapter) {
                $matrixAdapter = $adapter;
                break;
            }
        }
        if (!$matrixAdapter) {
            $this->failures[] = 'Structure adapter registry is missing the built-in Matrix adapter.';
        }
        $breadcrumb = Craft::$app->getFields()->getFieldByHandle('breadcrumbTitle');
        if ($breadcrumb && $plugin->structureAdapters->forField($breadcrumb)) {
            $this->failures[] = 'A non-structure field was incorrectly claimed by a structure adapter.';
        }

        $schema = $plugin->contentDesigner->describeSchema('basicPage', $admin);
        if (empty($schema['ok'])) {
            $this->failures[] = 'Schema discovery failed: ' . ($schema['error'] ?? '');

            return;
        }
        $handles = array_column($schema['blockTypes'], 'handle');
        if (!in_array('richText', $handles, true) || !in_array('twoColumnSection', $handles, true)) {
            $this->failures[] = 'Schema is missing registered block types.';
        }
        $layoutHandles = array_column($schema['layoutFields'] ?? [], 'handle');
        $heroField = null;
        foreach ($schema['layoutFields'] ?? [] as $layoutField) {
            if (($layoutField['handle'] ?? '') === 'hero') {
                $heroField = $layoutField;
            }
        }
        $heroTypes = array_column($heroField['blockTypes'] ?? [], 'handle');
        if (!in_array('hero', $layoutHandles, true) || !in_array('breadcrumbTitle', $layoutHandles, true) || !in_array('standardHero', $heroTypes, true) || (int)($heroField['maxEntries'] ?? 0) !== 1) {
            $this->failures[] = 'Schema did not describe the hero layout field.';
        }
        if (in_array('notARealBlock', $handles, true)) {
            $this->failures[] = 'Schema listed an unregistered block type.';
        }
        $twoColumn = $this->fieldOnType($schema['blockTypes'], 'twoColumnSection', 'subBlocks');
        if (!$twoColumn || (int)$twoColumn['minEntries'] !== 2 || (int)$twoColumn['maxEntries'] !== 2) {
            $this->failures[] = 'subBlocks min/max was not 2.';
        }

        $named = $plugin->contentDesigner->describeSchema('Basic Page', $admin);
        if (empty($named['ok']) || ($named['entryType'] ?? '') !== 'basicPage') {
            $this->failures[] = 'The entry type name Basic Page did not resolve to basicPage.';
        }

        $newsSchema = $plugin->contentDesigner->describeSchema('newsPage', $admin);
        $newsHandles = array_column($newsSchema['layoutFields'] ?? [], 'handle');
        if (empty($newsSchema['ok']) || !in_array('hero', $newsHandles, true)) {
            $this->failures[] = 'A layout without Content Designer did not describe its other fields.';
        }

        $unresolvedSchema = $plugin->contentDesigner->describeSchema('page', $admin);
        $catalogHandles = [];
        foreach ($unresolvedSchema['sections'] ?? [] as $section) {
            foreach ($section['entryTypes'] ?? [] as $type) {
                $catalogHandles[] = $type['handle'] ?? '';
            }
        }
        if (!empty($unresolvedSchema['ok']) || !str_contains((string)($unresolvedSchema['error'] ?? ''), 'Ask the admin') || !in_array('basicPage', $catalogHandles, true) || in_array('page', $catalogHandles, true)) {
            $this->failures[] = 'An unknown entry type did not return the section catalog.';
        }

        $plugin->getSettings()->allowedSections = [];
        $blockedSection = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check blocked',
            'section' => 'pages',
            'entryType' => 'basicPage',
        ], $admin);
        if (!empty($blockedSection['ok'])) {
            $this->failures[] = 'Created a page while the section allow-list was empty.';
            $this->elementIds[] = (int)$blockedSection['entryId'];
        }
        $plugin->getSettings()->allowedSections = ['pages'];

        $pageRequest = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check page alias',
            'section' => 'pages',
            'entryType' => 'page',
            'slug' => 'assistant-check-alias-' . uniqid(),
        ], $admin);
        $pageTypes = [];
        foreach ($pageRequest['sections'] ?? [] as $section) {
            if (($section['handle'] ?? '') === 'pages') {
                foreach ($section['entryTypes'] ?? [] as $type) {
                    $pageTypes[] = $type['handle'] ?? '';
                }
            }
        }
        if (!empty($pageRequest['ok']) || !str_contains((string)($pageRequest['error'] ?? ''), 'Ask the admin') || !in_array('basicPage', $pageTypes, true)) {
            $this->failures[] = 'Requesting entry type page created a draft or omitted the pages catalog.';
            if (!empty($pageRequest['entryId'])) {
                $this->elementIds[] = (int)$pageRequest['entryId'];
            }
        }

        $namedDraft = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check named page',
            'section' => 'pages',
            'entryType' => 'Basic Page',
            'slug' => 'assistant-check-named-' . uniqid(),
        ], $admin);
        if (empty($namedDraft['ok'])) {
            $this->failures[] = 'Could not create a page from the entry type name Basic Page: ' . ($namedDraft['error'] ?? '');
        } else {
            $this->elementIds[] = (int)$namedDraft['entryId'];
            $namedEntry = $plugin->contentDesigner->entryFromId((int)$namedDraft['entryId'], (int)$namedDraft['draftId'], null, $admin);
            if (!$namedEntry || $namedEntry->getType()->handle !== 'basicPage') {
                $this->failures[] = 'The name Basic Page did not create a basicPage draft.';
            }
        }

        $news = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check news page',
            'section' => 'pages',
            'entryType' => 'newsPage',
            'slug' => 'assistant-check-news-' . uniqid(),
        ], $admin);
        if (empty($news['ok'])) {
            $this->failures[] = 'Could not create a page whose entry type has no Content Designer field: ' . ($news['error'] ?? '');
        } else {
            $this->elementIds[] = (int)$news['entryId'];
            $newsDraft = $plugin->contentDesigner->entryFromId((int)$news['entryId'], (int)$news['draftId'], null, $admin);
            if (!$newsDraft || $newsDraft->getType()->handle !== 'newsPage') {
                $this->failures[] = 'The news page draft was not a newsPage.';
            } else {
                $newsLayout = $plugin->contentDesigner->readLayout($newsDraft, $admin);
                if (empty($newsLayout['ok']) || !array_key_exists('hero', $newsLayout['matrices'] ?? []) || ($newsLayout['blocks'] ?? null) !== []) {
                    $this->failures[] = 'Reading a layout without Content Designer did not return its other fields.';
                }
                if (!str_contains($plugin->editorButtonHtml($newsDraft), '>Ask Mason</button>')) {
                    $this->failures[] = 'Ask Mason was not offered on an entry without Content Designer.';
                }
                $missingField = $plugin->contentDesigner->addBlock($newsDraft, 'contentDesigner', 'richText', null, [], [], $admin);
                if (!empty($missingField['ok']) || !str_contains((string)($missingField['error'] ?? ''), 'hero')) {
                    $this->failures[] = 'A missing Content Designer field did not name the Matrix fields on the layout.';
                }
                $viewer = $this->viewer($admin);
                $this->withPermissionChecks(function () use ($plugin, $newsDraft, $viewer): void {
                    if (in_array((int)$viewer->id, $this->userIds, true)) {
                        $pages = Craft::$app->getEntries()->getSectionByHandle('pages');
                        Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, [
                            Plugin::PERMISSION,
                            "viewEntries:{$pages->uid}",
                            "viewPeerEntries:{$pages->uid}",
                            "viewPeerEntryDrafts:{$pages->uid}",
                        ]);
                    }
                    Craft::$app->getUser()->setIdentity($viewer);
                    $deniedCreate = $plugin->contentDesigner->createPage([
                        'title' => 'Assistant check denied create',
                        'section' => 'pages',
                        'entryType' => 'basicPage',
                    ], $viewer);
                    if (!empty($deniedCreate['ok'])) {
                        $this->failures[] = 'A view-only user created a page.';
                        $this->elementIds[] = (int)$deniedCreate['entryId'];
                    }
                    if ($plugin->editorButtonHtml($newsDraft) !== '') {
                        $this->failures[] = 'Ask Mason was offered to a user who cannot save the entry.';
                    }
                });
                Craft::$app->getUser()->setIdentity($admin);
            }
        }

        $prompt = (new ReflectionMethod($plugin->assistant, 'systemPrompt'))->invoke(
            $plugin->assistant,
            new ConversationRecord(),
            $admin,
        );
        if (
            !is_string($prompt)
            || !str_contains($prompt, 'ask the admin which to use')
            || !str_contains($prompt, 'Do not say the request is impossible or a project config change')
            || !str_contains($prompt, 'basicPage (Basic Page)')
            || str_contains($prompt, 'There is no entry type named page')
            || !str_contains($prompt, 'rebind_entry')
            || !str_contains($prompt, 'Prefer summarize_page')
            || !str_contains($prompt, 'Prefer apply_blocks')
        ) {
            $this->failures[] = 'The instructions do not list the allowed entry types, summarize/apply preferences, or still alias page to basicPage.';
        }

        $created = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check page',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-' . uniqid(),
        ], $admin);
        if (empty($created['ok']) || empty($created['unpublished'])) {
            $this->failures[] = 'Could not create an unpublished page draft: ' . ($created['error'] ?? '');

            return;
        }
        $this->elementIds[] = (int)$created['entryId'];
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        if (!$draft || Entry::find()->id($draft->id)->one()) {
            $this->failures[] = 'The new page is live before publish.';
        }
        if (empty($created['previewUrl'])) {
            $this->failures[] = 'A pages draft did not return a preview URL.';
        }

        $viewer = $this->viewer($admin);
        $this->withPermissionChecks(function () use ($plugin, $draft, $viewer): void {
            Craft::$app->getUser()->setIdentity($viewer);
            $denied = $plugin->contentDesigner->updateEntry($draft, ['title' => 'Nope'], $viewer);
            if (!empty($denied['ok'])) {
                $this->failures[] = 'A view-only user updated a draft.';
            }
        });
        Craft::$app->getUser()->setIdentity($admin);
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);

        $hero = $plugin->contentDesigner->addBlock($draft, 'hero', 'standardHero', null, [
            'heroEyebrow' => 'About',
            'heroHeadline' => 'About us',
            'heroContent' => '<p>Hello</p>',
            'ctaLink' => 'https://example.com/about',
            'background' => 'accent-primary',
        ], [], $admin);
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $heroLayout = $draft ? $plugin->contentDesigner->readLayout($draft, $admin) : [];
        $heroBlocks = $heroLayout['matrices']['hero']['blocks'] ?? [];
        $secondHero = $draft ? $plugin->contentDesigner->addBlock($draft, 'hero', 'standardHero', null, [
            'heroHeadline' => 'Second',
        ], [], $admin) : ['ok' => true];
        $breadcrumb = $draft ? $plugin->contentDesigner->updateEntry($draft, [
            'values' => ['breadcrumbTitle' => 'About', 'showBreadcrumbs' => false],
        ], $admin) : ['ok' => false];
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $afterHero = $draft ? $plugin->contentDesigner->readLayout($draft, $admin) : [];
        if (
            empty($hero['ok'])
            || ($heroBlocks[0]['fields']['heroHeadline'] ?? null) !== 'About us'
            || !in_array('background is read-only and was skipped.', $hero['skipped'] ?? [], true)
            || !empty($secondHero['ok'])
            || empty($breadcrumb['ok'])
            || ($afterHero['fields']['breadcrumbTitle'] ?? null) !== 'About'
            || ($afterHero['fields']['showBreadcrumbs'] ?? null) !== false
            || empty($heroLayout['matrices']['hero']['atMax'])
            || !str_contains((string)($heroLayout['matrices']['hero']['hint'] ?? ''), 'maximum')
        ) {
            $this->failures[] = 'The hero layout field could not be edited: ' . ($hero['error'] ?? '') . ' ' . json_encode($heroBlocks) . ' ' . json_encode($afterHero['fields'] ?? []) . ' ' . json_encode($heroLayout['matrices']['hero'] ?? []);
        }

        $added = $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'richText', 0, [
            'headline' => 'First',
            'blockContent' => '<p>First</p>',
        ], [], $admin);
        if (empty($added['ok'])) {
            $this->failures[] = 'Could not add a rich text block: ' . ($added['error'] ?? '');

            return;
        }
        $firstId = (int)$added['blockId'];
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $second = $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'richText', null, [
            'headline' => 'Second',
            'blockContent' => '<p>Second</p>',
        ], [], $admin);
        if (empty($second['ok'])) {
            $this->failures[] = 'Could not add a second block: ' . ($second['error'] ?? '');

            return;
        }
        $layout = $plugin->contentDesigner->readLayout($draft, $admin);
        $ids = array_column($layout['blocks'] ?? [], 'id');
        if ($ids !== [$firstId, (int)$second['blockId']]) {
            $this->failures[] = 'Block order after insert was ' . json_encode($ids);
        }

        $block = $plugin->contentDesigner->blockFromId($firstId, $draft->siteId);
        $updated = $plugin->contentDesigner->updateBlock($block, ['headline' => 'Updated'], $admin);
        if (empty($updated['ok'])) {
            $this->failures[] = 'Could not update a headline: ' . ($updated['error'] ?? '');
        } else {
            $block = $plugin->contentDesigner->blockFromId($firstId, $draft->siteId);
            $content = (string)$block->getFieldValue('blockContent');
            if ($block->getFieldValue('headline') !== 'Updated' || !str_contains($content, 'First')) {
                $this->failures[] = 'A partial update changed another field.';
            }
        }

        $badOrder = $plugin->contentDesigner->reorderBlocks($draft, 'contentDesigner', [$firstId], $admin);
        if (!empty($badOrder['ok'])) {
            $this->failures[] = 'An incomplete reorder was accepted.';
        }

        $unknown = $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'notARealBlock', null, [], [], $admin);
        if (!empty($unknown['ok']) || !str_contains((string)($unknown['error'] ?? ''), 'not available')) {
            $this->failures[] = 'An unknown block type was accepted.';
        }

        $beforeUpload = count($plugin->contentDesigner->readLayout($draft, $admin)['blocks'] ?? []);
        $upload = $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'logoGridSection', null, [
            'logos' => ['upload' => ['filename' => 'a.png', 'contents' => 'nope']],
        ], [], $admin);
        $afterUpload = count($plugin->contentDesigner->readLayout($draft, $admin)['blocks'] ?? []);
        if (!empty($upload['ok']) || $afterUpload !== $beforeUpload || !str_contains((string)($upload['error'] ?? ''), 'Uploads')) {
            $this->failures[] = 'An upload request changed the draft.';
        }

        $columns = $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'twoColumnSection', null, [
            'headline' => 'Columns',
        ], [
            'subBlocks' => [
                ['type' => 'richTextNoMedia', 'fields' => ['blockContentNoMedia' => '<p>Left</p>']],
                ['type' => 'richTextNoMedia', 'fields' => ['blockContentNoMedia' => '<p>Right</p>']],
            ],
        ], $admin);
        if (empty($columns['ok'])) {
            $this->failures[] = 'Could not add a two-column section: ' . ($columns['error'] ?? '');
        } else {
            $column = $plugin->contentDesigner->blockFromId((int)$columns['blockId'], $draft->siteId);
            $nested = $column->getFieldValue('subBlocks')->status(null)->all();
            if (count($nested) !== 2) {
                $this->failures[] = 'Two-column section did not save two sub blocks.';
            }
            $removed = $plugin->contentDesigner->removeBlock($nested[0], $admin);
            if (!empty($removed['ok'])) {
                $this->failures[] = 'Removed a sub block below the minimum.';
            }
            $skipped = $plugin->contentDesigner->updateBlock($column, [
                'headline' => 'After skip',
                'background' => 'not-a-pickture',
            ], $admin);
            if (empty($skipped['ok']) || empty($skipped['skipped'])) {
                $this->failures[] = 'A read-only field was not reported as skipped: ' . ($skipped['error'] ?? '');
            } elseif ($plugin->contentDesigner->blockFromId((int)$columns['blockId'], $draft->siteId)->getFieldValue('headline') !== 'After skip') {
                $this->failures[] = 'Supported fields were not saved alongside a skipped field.';
            }
        }

        $short = $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'twoColumnSection', null, [], [
            'subBlocks' => [
                ['type' => 'richTextNoMedia', 'fields' => ['blockContentNoMedia' => '<p>Only</p>']],
            ],
        ], $admin);
        if (!empty($short['ok'])) {
            $this->failures[] = 'Accepted a two-column section with one sub block.';
        }

        $shared = $this->sharedEntry($admin);
        $related = $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'sharedSection', null, [
            'sharedBlocks' => [(int)$shared->id],
        ], [], $admin);
        if (empty($related['ok'])) {
            $this->failures[] = 'Could not relate a shared section: ' . ($related['error'] ?? '');
        } else {
            $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
            $layout = $plugin->contentDesigner->readLayout($draft, $admin);
            $sharedBlocks = array_values(array_filter($layout['blocks'], fn(array $block) => $block['type'] === 'sharedSection'));
            $relatedIds = array_column($sharedBlocks[0]['fields']['sharedBlocks'] ?? [], 'id');
            if (!in_array((int)$shared->id, array_map('intval', $relatedIds), true)) {
                $this->failures[] = 'Shared section did not relate the existing entry.';
            }
        }

        $renamed = $plugin->contentDesigner->updateEntry($draft, ['title' => 'Assistant check renamed'], $admin);
        if (empty($renamed['ok']) || Entry::find()->title('Assistant check renamed')->one()) {
            $this->failures[] = 'Renaming the draft changed or missed the live entry.';
        }

        $noPreview = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check shared',
            'section' => 'sharedBlocks',
            'entryType' => 'sharedSections',
        ], $admin);
        $plugin->getSettings()->allowedSections = ['pages', 'sharedBlocks'];
        $noPreview = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check shared',
            'section' => 'sharedBlocks',
            'entryType' => 'sharedSections',
        ], $admin);
        $plugin->getSettings()->allowedSections = ['pages'];
        if (empty($noPreview['ok'])) {
            $this->failures[] = 'Could not create a shared block draft: ' . ($noPreview['error'] ?? '');
        } elseif (!empty($noPreview['previewAvailable'])) {
            $this->failures[] = 'A section without a preview URL reported a preview.';
        } else {
            $this->elementIds[] = (int)$noPreview['entryId'];
        }

        $this->withPermissionChecks(function () use ($plugin, $draft, $viewer): void {
            Craft::$app->getUser()->setIdentity($viewer);
            $publishDenied = $plugin->contentDesigner->publishDraft($draft, $viewer);
            if (!empty($publishDenied['ok'])) {
                $this->failures[] = 'A view-only user published a draft.';
            }
        });
        Craft::$app->getUser()->setIdentity($admin);
        $published = $plugin->contentDesigner->publishDraft($draft, $admin);
        if (empty($published['ok']) || !Entry::find()->id($draft->getCanonicalId())->one()) {
            $this->failures[] = 'Publishing did not make the entry live: ' . ($published['error'] ?? '');
        }

        $keptPage = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check stay bound',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-stay-bound-' . uniqid(),
        ], $admin);
        if (empty($keptPage['ok'])) {
            $this->failures[] = 'Could not create a page to check the publish binding: ' . ($keptPage['error'] ?? '');

            return;
        }
        $canonicalId = (int)$keptPage['entryId'];
        $this->elementIds[] = $canonicalId;
        $kept = $plugin->conversations->open($admin->id, $canonicalId, (int)$keptPage['draftId'], Craft::$app->getSites()->getPrimarySite()->id);
        $this->conversationIds[] = (int)$kept->id;
        $oldDraftId = (int)$kept->draftId;
        $publishedKeep = $plugin->assistant->publish($kept, $admin);
        if (empty($publishedKeep['ok']) || (int)$kept->entryId !== $canonicalId || $kept->draftId) {
            $this->failures[] = 'Publishing cleared the conversation entry binding.';
        } else {
            $found = $plugin->contentDesigner->entryFromId($canonicalId, $oldDraftId, $kept->siteId ? (int)$kept->siteId : null, $admin);
            if (!$found || $found->getIsDraft()) {
                $this->failures[] = 'A published entry could not be loaded from its old draft id.';
            }
            $continued = $plugin->assistant->reply($kept, 'Rename it', $admin, new ScriptedClient(function(array $messages) {
                $tools = array_values(array_filter($messages, fn(array $message) => $message['role'] === 'tool'));
                if ($tools === []) {
                    return new ProviderResult(toolCalls: [[
                        'id' => 'rename-1',
                        'name' => 'update_entry',
                        'arguments' => ['title' => 'Assistant check stayed bound'],
                    ]]);
                }

                return new ProviderResult(text: 'Renamed the draft.');
            }));
            $kept->refresh();
            $live = Entry::find()->id($canonicalId)->status(null)->one();
            if (empty($continued['ok']) || !$kept->draftId || (int)$kept->entryId !== $canonicalId || !$live || $live->title === 'Assistant check stayed bound') {
                $this->failures[] = 'A later edit did not stay bound to the published entry.';
            }
        }

        $rebindPage = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check rebind',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-rebind-' . uniqid(),
        ], $admin);
        if (empty($rebindPage['ok'])) {
            $this->failures[] = 'Could not create a page to rebind: ' . ($rebindPage['error'] ?? '');

            return;
        }
        $this->elementIds[] = (int)$rebindPage['entryId'];
        $rebindDraft = $plugin->contentDesigner->entryFromId((int)$rebindPage['entryId'], (int)$rebindPage['draftId'], null, $admin);
        $rebindPublished = $rebindDraft ? $plugin->contentDesigner->publishDraft($rebindDraft, $admin) : ['ok' => false];
        $started = $plugin->conversations->start($admin->id, null, $admin, Craft::$app->getSites()->getPrimarySite()->id);
        $chat = $started['conversation'] ?? null;
        if (empty($rebindPublished['ok']) || !$chat instanceof ConversationRecord) {
            $this->failures[] = 'Could not prepare an unbound conversation for rebind.';

            return;
        }
        $this->conversationIds[] = (int)$chat->id;
        $liveId = (int)$rebindPage['entryId'];
        $rebound = $plugin->assistant->execute('rebind_entry', ['entryId' => $liveId], $chat, $admin);
        $chat->refresh();
        $live = Entry::find()->id($liveId)->status(null)->one();
        if (empty($rebound['ok']) || (int)$chat->entryId !== $liveId || !$chat->draftId || !$live || $live->getIsDraft() || $live->title !== 'Assistant check rebind') {
            $this->failures[] = 'Rebinding did not attach the conversation to the existing entry.';
        } else {
            $firstDraftId = (int)$chat->draftId;
            $again = $plugin->assistant->execute('rebind_entry', ['entryId' => $liveId], $chat, $admin);
            $chat->refresh();
            if (empty($again['ok']) || (int)$chat->draftId !== $firstDraftId) {
                $this->failures[] = 'Rebinding the same entry created another draft.';
            }

            $otherPage = $plugin->contentDesigner->createPage([
                'title' => 'Assistant check rebind other',
                'section' => 'pages',
                'entryType' => 'basicPage',
                'slug' => 'assistant-check-rebind-other-' . uniqid(),
            ], $admin);
            if (!empty($otherPage['entryId'])) {
                $this->elementIds[] = (int)$otherPage['entryId'];
            }
            $otherDraft = empty($otherPage['ok']) ? null : $plugin->contentDesigner->entryFromId((int)$otherPage['entryId'], (int)$otherPage['draftId'], null, $admin);
            $otherPublished = $otherDraft ? $plugin->contentDesigner->publishDraft($otherDraft, $admin) : ['ok' => false];
            $switched = empty($otherPublished['ok']) ? ['ok' => false] : $plugin->assistant->execute('rebind_entry', ['entryId' => (int)$otherPage['entryId']], $chat, $admin);
            $chat->refresh();
            $previousDraft = Entry::find()->draftId($firstDraftId)->status(null)->exists();
            if (empty($switched['ok']) || (int)$chat->entryId !== (int)$otherPage['entryId'] || (int)$chat->draftId === $firstDraftId || !$previousDraft) {
                $this->failures[] = 'Rebinding did not move the conversation to the other entry.';
            }
        }

        $missing = $plugin->assistant->execute('rebind_entry', ['entryId' => 2147483646], $chat, $admin);
        if (!empty($missing['ok'])) {
            $this->failures[] = 'Rebinding accepted an entry that does not exist.';
        }
        $this->withPermissionChecks(function () use ($plugin, $chat, $viewer, $liveId): void {
            if (!in_array((int)$viewer->id, $this->userIds, true)) {
                return;
            }
            Craft::$app->getUser()->setIdentity($viewer);
            $denied = $plugin->assistant->execute('rebind_entry', ['entryId' => $liveId], $chat, $viewer);
            if (!empty($denied['ok'])) {
                $this->failures[] = 'A view-only user rebound a conversation.';
            }
        });
        Craft::$app->getUser()->setIdentity($admin);

        $forkHero = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check fork hero',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-fork-hero-' . uniqid(),
        ], $admin);
        if (empty($forkHero['ok'])) {
            $this->failures[] = 'Could not create a page to fork-update hero: ' . ($forkHero['error'] ?? '');

            return;
        }
        $this->elementIds[] = (int)$forkHero['entryId'];
        $forkDraft = $plugin->contentDesigner->entryFromId((int)$forkHero['entryId'], (int)$forkHero['draftId'], null, $admin);
        $heroSeed = $forkDraft ? $plugin->contentDesigner->addBlock($forkDraft, 'hero', 'standardHero', null, [
            'heroEyebrow' => 'Live eyebrow',
            'heroHeadline' => 'Live headline',
            'heroContent' => '<p>Live</p>',
            'ctaLink' => 'https://example.com/fork-hero',
        ], [], $admin) : ['ok' => false];
        $forkDraft = $plugin->contentDesigner->entryFromId((int)$forkHero['entryId'], (int)$forkHero['draftId'], null, $admin);
        $publishedFork = $forkDraft && !empty($heroSeed['ok'])
            ? $plugin->contentDesigner->publishDraft($forkDraft, $admin)
            : ['ok' => false];
        $liveFork = !empty($publishedFork['ok'])
            ? Entry::find()->id((int)$forkHero['entryId'])->status(null)->one()
            : null;
        $forked = $liveFork instanceof Entry
            ? $plugin->contentDesigner->forkAssistantDraft($liveFork, $admin)
            : ['ok' => false];
        /** @var Entry|null $masonDraft */
        $masonDraft = !empty($forked['ok']) && ($forked['entry'] ?? null) instanceof Entry
            ? $forked['entry']
            : null;
        $forkLayout = $masonDraft ? $plugin->contentDesigner->readLayout($masonDraft, $admin) : [];
        $forkHeroId = (int)($forkLayout['matrices']['hero']['blocks'][0]['id'] ?? 0);
        $foundOnDraft = $masonDraft && $forkHeroId
            ? $plugin->contentDesigner->findBlockOnEntry($masonDraft, $forkHeroId)
            : null;
        $forkChat = $masonDraft
            ? $plugin->conversations->open(
                $admin->id,
                (int)$masonDraft->getCanonicalId(),
                $masonDraft->draftId ? (int)$masonDraft->draftId : null,
                (int)$masonDraft->siteId,
            )
            : null;
        if ($forkChat) {
            $this->conversationIds[] = (int)$forkChat->id;
        }
        $forkUpdated = $forkChat && $forkHeroId
            ? $plugin->assistant->execute('update_block', [
                'blockId' => $forkHeroId,
                'values' => ['heroEyebrow' => 'Draft eyebrow'],
            ], $forkChat, $admin)
            : ['ok' => false, 'error' => 'Could not prepare fork hero update.'];
        if (
            empty($heroSeed['ok'])
            || empty($publishedFork['ok'])
            || !$masonDraft
            || $forkHeroId < 1
            || !$foundOnDraft instanceof Entry
            || empty($forkUpdated['ok'])
        ) {
            $this->failures[] = 'Updating a published Matrix block on a Mason draft failed: '
                . ($forkUpdated['error'] ?? '')
                . ' heroId=' . $forkHeroId;
        }
    }

    private function checkAssistantLoop(Plugin $plugin, User $admin): void
    {
        Craft::$app->getUser()->setIdentity($admin);
        $_SERVER['OPENAI_API_KEY'] = 'test-key';
        putenv('OPENAI_API_KEY=test-key');
        $created = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check chat',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-chat-' . uniqid(),
        ], $admin);
        if (empty($created['ok'])) {
            $this->failures[] = 'Could not create a chat draft: ' . ($created['error'] ?? '');

            return;
        }
        $this->elementIds[] = (int)$created['entryId'];
        $conversation = $plugin->conversations->open($admin->id, (int)$created['entryId'], (int)$created['draftId'], Craft::$app->getSites()->getPrimarySite()->id);
        $this->conversationIds[] = (int)$conversation->id;

        $script = new ScriptedClient(function(array $messages) {
            $tools = array_values(array_filter($messages, fn(array $message) => $message['role'] === 'tool'));
            if (count($tools) === 0) {
                return new ProviderResult(toolCalls: [[
                    'id' => 'add-1',
                    'name' => 'add_block',
                    'arguments' => [
                        'type' => 'richText',
                        'values' => ['headline' => 'A', 'blockContent' => '<p>A</p>'],
                    ],
                ]]);
            }
            if (count($tools) === 1) {
                return new ProviderResult(toolCalls: [[
                    'id' => 'add-2',
                    'name' => 'add_block',
                    'arguments' => [
                        'type' => 'richText',
                        'values' => ['headline' => 'B', 'blockContent' => '<p>B</p>'],
                    ],
                ]]);
            }
            if (count($tools) === 2) {
                $first = json_decode($tools[0]['content'], true);
                $second = json_decode($tools[1]['content'], true);

                return new ProviderResult(toolCalls: [[
                    'id' => 'order-1',
                    'name' => 'reorder_blocks',
                    'arguments' => ['orderedIds' => [(int)$second['blockId'], (int)$first['blockId']]],
                ]]);
            }

            return new ProviderResult(text: 'Added two blocks and reordered them.');
        });

        $reply = $plugin->assistant->reply($conversation, 'Add two blocks and swap them', $admin, $script);
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $layout = $plugin->contentDesigner->readLayout($draft, $admin);
        $headlines = array_map(fn(array $block) => $block['fields']['headline'] ?? null, $layout['blocks'] ?? []);
        if ($headlines !== ['B', 'A']) {
            $this->failures[] = 'The tool loop did not add and reorder two blocks: ' . json_encode($headlines) . ' ' . ($reply['reply'] ?? '');
        }
        if (($reply['tools'] ?? null) !== ['add_block', 'add_block', 'reorder_blocks']) {
            $this->failures[] = 'The reply did not list the tools used: ' . json_encode($reply['tools'] ?? null);
        }
        $visibleMessages = $plugin->conversations->messagesForUser($conversation);
        $lastAssistant = null;
        foreach (array_reverse($visibleMessages) as $message) {
            if ($message->role === 'assistant') {
                $lastAssistant = $message;
                break;
            }
        }
        $viewTools = $plugin->conversations->ownedView((int)$conversation->id, $admin)['messages'] ?? [];
        $lastView = $viewTools === [] ? null : $viewTools[array_key_last($viewTools)];
        if (
            !$lastAssistant
            || $plugin->conversations->toolsForMessage($lastAssistant) !== ['add_block', 'add_block', 'reorder_blocks']
            || ($lastView['tools'] ?? null) !== ['add_block', 'add_block', 'reorder_blocks']
        ) {
            $this->failures[] = 'The assistant message did not persist tools for the footer: ' . json_encode($lastView['tools'] ?? null);
        }
        $visible = array_map(
            fn(MessageRecord $message) => trim((string)$message->content),
            $visibleMessages,
        );
        $modelToolTurns = array_filter(
            $plugin->conversations->messagesForModel($conversation),
            fn(array $message) => ($message['role'] ?? '') === 'assistant' && !empty($message['toolCalls']),
        );
        if (in_array('', $visible, true) || !in_array('Added two blocks and reordered them.', $visible, true) || $modelToolTurns === []) {
            $this->failures[] = 'Empty tool-call messages were shown in the transcript.';
        }
        if (!in_array('gpt-4.1', $script->models, true) && $plugin->getSettings()->defaultModel === 'gpt-4.1') {
            $this->failures[] = 'The assistant did not call the provider with the saved model.';
        }

        $before = count($layout['blocks'] ?? []);
        $refused = new ScriptedClient(fn() => new ProviderResult(toolCalls: [[
            'id' => 'bad-1',
            'name' => 'add_block',
            'arguments' => ['type' => 'notARealBlock'],
        ]], text: null));
        $refusedReply = $plugin->assistant->reply($conversation, 'Invent a block type and call MCP', $admin, $refused);
        $after = $plugin->contentDesigner->readLayout($draft, $admin);
        if (count($after['blocks'] ?? []) !== $before || !str_contains((string)$refusedReply['reply'], 'not saved')) {
            $this->failures[] = 'An invented block type changed the draft or was described as saved.';
        }

        $fresh = new ConversationRecord();
        $fresh->userId = $admin->id;
        $fresh->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $fresh->save(false);
        $this->conversationIds[] = (int)$fresh->id;
        $creator = new ScriptedClient(function(array $messages) {
            $tools = array_values(array_filter($messages, fn(array $message) => $message['role'] === 'tool'));
            if ($tools === []) {
                return new ProviderResult(toolCalls: [[
                    'id' => 'create-1',
                    'name' => 'create_page',
                    'arguments' => [
                        'title' => 'Assistant check created',
                        'section' => 'pages',
                        'entryType' => 'basicPage',
                    ],
                ]]);
            }

            return new ProviderResult(text: 'Created the draft.');
        });
        $createdReply = $plugin->assistant->reply($fresh, 'Create a page', $admin, $creator);
        $fresh->refresh();
        if (!$fresh->entryId || empty($createdReply['draftId'])) {
            $this->failures[] = 'create_page did not bind the conversation.';
        } else {
            $this->elementIds[] = (int)$fresh->entryId;
            $page = $plugin->contentDesigner->entryFromId((int)$fresh->entryId, (int)$fresh->draftId, null, $admin);
            if (!$page || !$page->getIsUnpublishedDraft()) {
                $this->failures[] = 'The page created from chat was not an unpublished draft.';
            }
        }

        $other = $this->makeUser(false);
        if ($plugin->conversations->findOwned((int)$conversation->id, $other->id)) {
            $this->failures[] = 'Another user can read the conversation.';
        }

        $failed = new ScriptedClient(function() {
            throw new \RuntimeException('OpenAI returned 401: Incorrect API key provided: sk-testkeyvalue12345678');
        });
        $failedReply = $plugin->assistant->reply($conversation, 'This should fail', $admin, $failed);
        if (!str_contains((string)($failedReply['reply'] ?? ''), '401') || str_contains((string)($failedReply['reply'] ?? ''), 'sk-testkeyvalue12345678')) {
            $this->failures[] = 'A provider failure was not reported safely: ' . ($failedReply['reply'] ?? '');
        }

        $this->withEmptyKey(function() use ($plugin, $admin, $conversation) {
            $beforeDrafts = (int)$conversation->draftId;
            $result = $plugin->assistant->reply($conversation, 'Change the page', $admin, new ScriptedClient(fn() => new ProviderResult(text: 'should not run')));
            if (($result['reply'] ?? '') !== 'The provider is not configured.' || (int)$conversation->draftId !== $beforeDrafts) {
                $this->failures[] = 'A missing API key did not stop the request before editing.';
            }
        });
    }

    private function checkUsage(Plugin $plugin, User $admin): void
    {
        $before = UsageRecord::find()->count();
        $plugin->usage->record($admin->id, 'openai', 'gpt-4.1', null, null);
        if (UsageRecord::find()->count() !== $before) {
            $this->failures[] = 'A request without token counts was recorded.';
        }

        $plugin->usage->record($admin->id, 'openai', 'gpt-4.1', 5, 7);
        $rows = $plugin->usage->totals(date('Y-m-d'), date('Y-m-d'));
        $match = array_values(array_filter($rows, fn(array $row) => $row['userId'] === (int)$admin->id && $row['model'] === 'gpt-4.1'));
        if ($match === [] || $match[0]['promptTokens'] < 5) {
            $this->failures[] = 'Admin usage totals did not include the recorded request.';
        }
        $record = UsageRecord::find()->orderBy(['id' => SORT_DESC])->one();
        $attributes = $record ? $record->getAttributes() : [];
        if (array_key_exists('content', $attributes) || array_key_exists('apiKey', $attributes)) {
            $this->failures[] = 'Usage rows store message text or an API key.';
        }

        $source = $this->methodSource(new ReflectionMethod(UsageController::class, 'actionIndex'));
        if (!str_contains($source, 'requireAdmin')) {
            $this->failures[] = 'The usage page is not limited to admins.';
        }
    }

    private function checkSecrets(): void
    {
        $yaml = $this->projectConfigText();
        foreach (['OPENAI_API_KEY', 'ANTHROPIC_API_KEY'] as $name) {
            if (preg_match('/' . $name . '\s*:/', $yaml)) {
                $this->failures[] = "Project config contains $name.";
            }
        }
    }

    private function samplePage(User $admin): Entry
    {
        $created = Plugin::getInstance()->contentDesigner->createPage([
            'title' => 'Assistant check button',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-button-' . uniqid(),
        ], $admin);
        if (empty($created['ok'])) {
            throw new \RuntimeException(($created['error'] ?? 'Could not create a sample page.') . ' ' . json_encode($created['errors'] ?? []));
        }
        $this->elementIds[] = (int)$created['entryId'];
        $entry = Plugin::getInstance()->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        if (!$entry) {
            throw new \RuntimeException($created['error'] ?? 'Could not create a sample page.');
        }

        return $entry;
    }

    private function viewer(User $admin): User
    {
        $user = $this->makeUser(false);
        if (!in_array((int)$user->id, $this->userIds, true)) {
            return $user;
        }
        $section = Craft::$app->getEntries()->getSectionByHandle('pages');
        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, [
            "viewEntries:$section->uid",
            "viewPeerEntries:$section->uid",
        ]);

        return $user;
    }

    private function sharedEntry(User $admin): Entry
    {
        $previous = Plugin::getInstance()->getSettings()->allowedSections;
        Plugin::getInstance()->getSettings()->allowedSections = ['pages', 'sharedBlocks'];
        $created = Plugin::getInstance()->contentDesigner->createPage([
            'title' => 'Assistant check shared source',
            'section' => 'sharedBlocks',
            'entryType' => 'sharedSections',
        ], $admin);
        Plugin::getInstance()->getSettings()->allowedSections = $previous;
        if (empty($created['ok'])) {
            throw new \RuntimeException($created['error'] ?? 'Could not create a shared entry.');
        }
        $this->elementIds[] = (int)$created['entryId'];
        $entry = Plugin::getInstance()->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $published = Plugin::getInstance()->contentDesigner->publishDraft($entry, $admin);
        if (empty($published['ok'])) {
            throw new \RuntimeException($published['error'] ?? 'Could not publish the shared entry.');
        }
        $live = Entry::find()->id((int)$created['entryId'])->status(null)->one();
        if (!$live instanceof Entry || $live->getIsDraft()) {
            throw new \RuntimeException('The shared entry is still a draft after publish.');
        }

        return $live;
    }

    private function makeUser(bool $admin): User
    {
        $user = new User();
        $user->username = 'assistantcheck' . uniqid();
        $user->email = $user->username . '@example.com';
        $user->admin = $admin;
        $user->active = true;
        if (!Craft::$app->getUsers()->canCreateUsers()) {
            $user->id = random_int(1_000_000_000, 2_000_000_000);

            return $user;
        }
        if (!Craft::$app->getElements()->saveElement($user)) {
            throw new \RuntimeException(json_encode($user->getErrors()) ?: 'Could not save the check user.');
        }
        $this->userIds[] = (int)$user->id;

        return $user;
    }

    /**
     * Solo grants every permission to every user, so denial checks run as Pro.
     */
    private function withPermissionChecks(callable $fn): void
    {
        $edition = Craft::$app->edition;
        if ($edition === CmsEdition::Solo) {
            Craft::$app->edition = CmsEdition::Pro;
        }
        try {
            $fn();
        } finally {
            Craft::$app->edition = $edition;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $history
     */
    private function mockHttp(array $payload, ?array &$history): Client
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($payload)),
        ]));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mockHttpStatus(int $status, array $payload): Client
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response($status, ['Content-Type' => 'application/json'], json_encode($payload)),
        ]));

        return new Client(['handler' => $stack]);
    }

    /**
     * @param array<int, array<string, mixed>> $history
     */
    private function assertClientRequest(OpenAiClient|AnthropicClient $client, string $host, string $model, array &$history, ?array $messages = null): void
    {
        $client->complete($messages ?? [['role' => 'user', 'content' => 'hi']], [], $model, 128);
        $request = $history[0]['request'] ?? null;
        $body = $request ? json_decode((string)$request->getBody(), true) : [];
        if (!$request || !str_contains((string)$request->getUri(), $host) || ($body['model'] ?? null) !== $model) {
            $this->failures[] = "Provider client did not target $host with $model.";
        }
    }

    private function withEmptyKey(callable $fn): void
    {
        $name = Plugin::getInstance()->getSettings()->apiKeyEnvName();
        $server = $_SERVER[$name] ?? null;
        $env = getenv($name);
        unset($_SERVER[$name]);
        putenv($name);
        try {
            $fn();
        } finally {
            if (is_string($server)) {
                $_SERVER[$name] = $server;
            }
            if ($env !== false) {
                putenv($name . '=' . $env);
            }
        }
    }

    private function projectConfigText(): string
    {
        $path = Craft::getAlias('@config/project');
        $text = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.yaml')) {
                $text .= file_get_contents($file->getPathname());
            }
        }

        return $text;
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /**
     * @param array<int, array<string, mixed>> $types
     */
    private function fieldOnType(array $types, string $typeHandle, string $fieldHandle): ?array
    {
        foreach ($types as $type) {
            if ($type['handle'] !== $typeHandle) {
                continue;
            }
            foreach ($type['fields'] as $field) {
                if ($field['handle'] === $fieldHandle) {
                    return $field;
                }
            }
        }

        return null;
    }

    private function checkConversations(Plugin $plugin, User $admin): void
    {
        $column = Craft::$app->getDb()->getTableSchema(ConversationRecord::tableName())?->getColumn('title');
        if (!$column || $column->allowNull) {
            $this->failures[] = 'Conversations are missing a required title column.';

            return;
        }

        $index = $this->methodSource(new ReflectionMethod(ChatController::class, 'actionIndex'));
        $panel = $this->methodSource(new ReflectionMethod(ChatController::class, 'actionPanel'));
        if (str_contains($index, '->open(') || str_contains($panel, '->open(')) {
            $this->failures[] = 'Opening Chat or the slideout still creates or reuses one conversation.';
        }

        $ghostId = random_int(1_900_000_000, 2_000_000_000);
        $beforeGhost = (int)ConversationRecord::find()->where(['userId' => $ghostId])->count();
        $plugin->conversations->listForUser($ghostId, null, null);
        if ($beforeGhost !== 0 || (int)ConversationRecord::find()->where(['userId' => $ghostId])->count() !== 0) {
            $this->failures[] = 'Listing conversations inserted a row.';
        }

        $alpha = $this->publishedPage($admin, 'Assistant multi chat alpha', 'assistant-multi-alpha-');
        $beta = $this->publishedPage($admin, 'Assistant multi chat beta', 'assistant-multi-beta-');
        $siteId = (int)$alpha->siteId;
        $first = $this->startedConversation($plugin, $admin, $alpha);
        $second = $this->startedConversation($plugin, $admin, $alpha);
        $otherEntry = $this->startedConversation($plugin, $admin, $beta);
        $unbound = $this->startedConversation($plugin, $admin, null);
        if (!$first || !$second || !$otherEntry || !$unbound) {
            return;
        }

        if ((int)$first->draftId === (int)$second->draftId) {
            $this->failures[] = 'A new chat on an entry reused the other chat draft.';
        }
        $firstDraft = Entry::find()->draftId((int)$first->draftId)->status(null)->siteId($siteId)->one();
        $secondDraft = Entry::find()->draftId((int)$second->draftId)->status(null)->siteId($siteId)->one();
        if (!$firstDraft || !$secondDraft || $firstDraft->draftName !== 'Mason' || $secondDraft->draftName !== 'Mason 2') {
            $this->failures[] = 'Entry chats did not get separate Mason draft names.';
        }
        if ($first->title !== 'Assistant multi chat alpha' || $otherEntry->title !== 'Assistant multi chat beta') {
            $this->failures[] = 'A bound conversation did not use the entry title.';
        }

        $plugin->conversations->addMessage($unbound, 'user', 'Assistant multi chat unbound message');
        $unbound->refresh();
        if ($unbound->title !== 'Assistant multi chat unbound message') {
            $this->failures[] = 'An unbound conversation did not use its first message as the title.';
        }

        $long = $this->startedConversation($plugin, $admin, null);
        if ($long) {
            $plugin->conversations->addMessage($long, 'user', str_repeat('m', 90));
            $long->refresh();
            if ($long->title !== str_repeat('m', 80)) {
                $this->failures[] = 'A long conversation title was not shortened to 80 characters.';
            }
        }

        $stored = new ConversationRecord();
        $stored->userId = $admin->id;
        $stored->entryId = $alpha->id;
        $stored->siteId = $siteId;
        $stored->title = 'New chat';
        $stored->save(false);
        $this->conversationIds[] = (int)$stored->id;
        $plugin->conversations->applyDerivedTitle($stored);
        $stored->refresh();
        $empty = new ConversationRecord();
        $empty->userId = $admin->id;
        $empty->siteId = $siteId;
        $empty->title = 'New chat';
        $empty->save(false);
        $this->conversationIds[] = (int)$empty->id;
        $plugin->conversations->applyDerivedTitle($empty);
        $empty->refresh();
        if ($stored->title !== 'Assistant multi chat alpha' || $empty->title !== 'New chat') {
            $this->failures[] = 'Existing conversations did not backfill to the entry title or New chat.';
        }

        $plugin->conversations->addMessage($first, 'user', 'alpha transcript');
        $plugin->conversations->addMessage($second, 'user', 'beta transcript');
        $entryList = $plugin->conversations->listForUser($admin->id, (int)$alpha->id, $siteId);
        $entryIds = array_map(fn(ConversationRecord $record) => (int)$record->id, $entryList);
        if (!in_array((int)$first->id, $entryIds, true) || !in_array((int)$second->id, $entryIds, true)) {
            $this->failures[] = 'Listing an entry collapsed its conversations.';
        }
        if (in_array((int)$otherEntry->id, $entryIds, true) || in_array((int)$unbound->id, $entryIds, true)) {
            $this->failures[] = 'An entry list included another entry or an unbound chat.';
        }
        $beforeOpen = count($entryIds);
        $opened = $plugin->conversations->open($admin->id, (int)$alpha->id, null, $siteId);
        $afterOpen = count($plugin->conversations->listForUser($admin->id, (int)$alpha->id, $siteId));
        if ($beforeOpen < 2 || $afterOpen !== $beforeOpen || !in_array((int)$opened->id, $entryIds, true)) {
            $this->failures[] = 'open() did not leave the separate entry conversations in place.';
        }

        $allIds = array_map(
            fn(ConversationRecord $record) => (int)$record->id,
            $plugin->conversations->listForUser($admin->id, null, null),
        );
        foreach ([(int)$first->id, (int)$otherEntry->id, (int)$unbound->id] as $id) {
            if (!in_array($id, $allIds, true)) {
                $this->failures[] = 'Chat did not list every conversation owned by the user.';
                break;
            }
        }

        $third = $this->startedConversation($plugin, $admin, null);
        if ($third && ($plugin->conversations->messagesForUser($third) !== [] || $third->entryId)) {
            $this->failures[] = 'A new chat reused another transcript or bound an entry.';
        }
        if ($plugin->conversations->messagesForUser($first)[0]->content !== 'alpha transcript') {
            $this->failures[] = 'Starting a new chat changed an older transcript.';
        }

        $firstView = $plugin->conversations->ownedView((int)$first->id, $admin);
        $secondView = $plugin->conversations->ownedView((int)$second->id, $admin);
        $back = $plugin->conversations->ownedView((int)$first->id, $admin);
        if (
            !$firstView || !$secondView || !$back
            || ($firstView['messages'][0]['content'] ?? '') !== 'alpha transcript'
            || ($secondView['messages'][0]['content'] ?? '') !== 'beta transcript'
            || ($back['messages'][0]['content'] ?? '') !== 'alpha transcript'
            || (int)$firstView['draftId'] === (int)$secondView['draftId']
            || !array_key_exists('previewUrl', $firstView)
            || !array_key_exists('previewAvailable', $firstView)
            || empty($firstView['editUrl'])
            || $firstView['editUrl'] === ($secondView['editUrl'] ?? null)
        ) {
            $this->failures[] = 'Switching conversations did not restore that chat’s transcript, preview, and edit link.';
        }

        $other = $this->makeUser(false);
        $foreignIds = array_map(
            fn(ConversationRecord $record) => (int)$record->id,
            $plugin->conversations->listForUser((int)$other->id, null, null),
        );
        if (
            in_array((int)$first->id, $foreignIds, true)
            || $plugin->conversations->ownedView((int)$first->id, $other) !== null
            || $plugin->conversations->deleteOwned((int)$second->id, (int)$other->id)
            || !ConversationRecord::findOne((int)$second->id)
        ) {
            $this->failures[] = 'Another user can list, read, or delete the conversation.';
        }

        $draftId = (int)$second->draftId;
        $messageCount = (int)MessageRecord::find()->where(['conversationId' => (int)$second->id])->count();
        if ($messageCount < 1 || !$plugin->conversations->deleteOwned((int)$second->id, (int)$admin->id)) {
            $this->failures[] = 'The owner could not delete a conversation.';
        } elseif (ConversationRecord::findOne((int)$second->id) || MessageRecord::find()->where(['conversationId' => (int)$second->id])->exists()) {
            $this->failures[] = 'Deleting a conversation left the conversation or its messages.';
        } elseif (!Entry::find()->draftId($draftId)->status(null)->siteId($siteId)->exists()) {
            $this->failures[] = 'Deleting a conversation also deleted its draft.';
        }

        $js = (string)file_get_contents(Plugin::getInstance()->getBasePath() . '/web/assets/assistant/dist/assistant.js');
        $send = $this->methodSource(new ReflectionMethod(ChatController::class, 'actionSend'));
        if (!str_contains($js, 'padstone-mason/chat/conversation') || !str_contains($js, 'params: { conversationId: id }') || !str_contains($js, 'setConversationId') || !str_contains($send, 'startFromRequest')) {
            $this->failures[] = 'The panel script does not load the selected conversation.';
        }
        if (!str_contains($js, 'setPending') || !str_contains($js, 'Mason is writing')) {
            $this->failures[] = 'The panel script does not show a waiting indicator while the model replies.';
        }
        if (
            !str_contains($js, 'data-assistant-file')
            || !str_contains($js, 'images[]')
            || !str_contains($js, 'FormData')
            || !str_contains($js, 'data-assistant-attach')
            || !str_contains($send, 'uploadedImages')
            || !str_contains($send, 'importUrlsFromText')
        ) {
            $this->failures[] = 'The panel does not upload or import conversation images.';
        }
        $rendered = \craft\helpers\Markdown::process("**Bold**\n\n- one", 'gfm');
        if (!str_contains($js, "event.key !== 'Enter'") || !str_contains($js, 'event.shiftKey') || !str_contains($js, 'event.metaKey') || !str_contains($js, "setRangeText('\\n'") || !str_contains($js, 'sendMessage') || !str_contains($js, 'data-assistant-send')) {
            $this->failures[] = 'Enter does not send the prompt.';
        }
        if (!str_contains($rendered, '<strong>Bold</strong>') || !str_contains($rendered, '<li>one</li>') || !str_contains($send, 'replyHtml') || !str_contains($js, 'data.messages')) {
            $this->failures[] = 'Live replies are not rendered as Markdown.';
        }
        $css = (string)file_get_contents(Plugin::getInstance()->getBasePath() . '/web/assets/assistant/dist/assistant.css');
        $panel = (string)file_get_contents(Plugin::getInstance()->getBasePath() . '/templates/_panel.twig');
        if (
            !str_contains($js, 'padstone-mason__message-tools')
            || !str_contains($css, 'padstone-mason__message-tools')
            || !str_contains($panel, 'padstone-mason__message-tools')
            || !str_contains($js, 'message.tools.join')
        ) {
            $this->failures[] = 'Assistant replies do not show a tools footer.';
        }
        $panelSource = $this->methodSource(new ReflectionMethod(ChatController::class, 'actionPanel'));
        $selectedSource = $this->methodSource(new ReflectionMethod(ChatController::class, 'selectedConversation'));
        if (
            !str_contains($js, 'syncEditorToBoundDraft')
            || !str_contains($js, 'padstone-mason-reopen')
            || !str_contains($js, 'openMasonSlideout')
            || !str_contains($js, 'consumeReopen')
            || !str_contains($js, 'scheduleConsumeReopen')
            || !str_contains($js, 'editorMatchesReopen')
            || !str_contains($js, 'data-bound-draft-id')
            || !str_contains($selectedSource, 'conversationMatchesScope')
            || !str_contains($panelSource, 'selectedConversation')
        ) {
            $this->failures[] = 'Entry-scoped Mason does not sync the edit screen to the bound draft.';
        }

        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            $allHtml = $view->renderTemplate('padstone-mason/_panel', $this->panelRender(
                $plugin->conversations->summaries($admin->id, null, null),
                $unbound,
                null,
                null,
                'all',
            ));
            $entryHtml = $view->renderTemplate('padstone-mason/_panel', $this->panelRender(
                $plugin->conversations->summaries($admin->id, (int)$alpha->id, $siteId),
                $first,
                (int)$alpha->id,
                $siteId,
                'entry',
            ));
            if (
                !str_contains($allHtml, 'Assistant multi chat alpha')
                || !str_contains($allHtml, 'Assistant multi chat beta')
                || !str_contains($allHtml, 'Assistant multi chat unbound message')
                || !str_contains($allHtml, 'New chat')
                || !str_contains($allHtml, 'data-assistant-delete')
                || !str_contains($allHtml, 'data-assistant-attach')
                || !str_contains($allHtml, 'data-assistant-file')
                || !str_contains($allHtml, 'Attach image')
                || !str_contains($entryHtml, 'Assistant multi chat alpha')
                || str_contains($entryHtml, 'Assistant multi chat beta')
                || str_contains($entryHtml, 'Assistant multi chat unbound message')
                || ($first->draftId && !str_contains($entryHtml, 'data-bound-draft-id="' . (int)$first->draftId . '"'))
            ) {
                $this->failures[] = 'Chat and the entry slideout did not list the conversations for their scope.';
            }
        } catch (\Throwable $e) {
            $this->failures[] = 'Could not render the conversation list: ' . $e->getMessage();
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    private function checkConversationAssets(Plugin $plugin, User $admin): void
    {
        Craft::$app->getUser()->setIdentity($admin);
        $settings = $plugin->getSettings();
        if ($settings->assetVolumeUid === '' || !$settings->assetVolume()) {
            $this->failures[] = 'The image upload volume setting is missing.';

            return;
        }

        $names = array_column($plugin->assistant->tools(), 'name');
        if (!in_array('import_asset', $names, true) || in_array('upload_asset', $names, true)) {
            $this->failures[] = 'import_asset is not available to the assistant.';
        }

        $chat = $this->startedConversation($plugin, $admin, null);
        if (!$chat) {
            return;
        }

        $media = $plugin->conversationAssets;
        $urls = $media->extractImageUrls('See https://example.com/hero.png and https://example.com/doc.pdf and https://cdn.example.com/x?fm=webp');
        if ($urls !== ['https://example.com/hero.png', 'https://cdn.example.com/x?fm=webp']) {
            $this->failures[] = 'Image URL extraction was wrong: ' . json_encode($urls);
        }

        $bad = $media->importFromUrl('http://127.0.0.1/secret.png', $admin);
        if (!empty($bad['ok'])) {
            $this->failures[] = 'A private image URL was imported.';
        }

        $toolBad = $plugin->assistant->execute('import_asset', ['url' => 'not-a-url'], $chat, $admin);
        if (!empty($toolBad['ok'])) {
            $this->failures[] = 'import_asset accepted an invalid URL.';
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $temp = Craft::$app->getPath()->getTempPath() . '/padstone-mason-check-' . uniqid() . '.png';
        file_put_contents($temp, $png);
        $uploaded = $media->uploadFromRequest(new UploadedFile([
            'name' => 'mason-check.png',
            'tempName' => $temp,
            'type' => 'image/png',
            'size' => filesize($temp),
            'error' => UPLOAD_ERR_OK,
        ]), $admin);
        if (empty($uploaded['ok'])) {
            $this->failures[] = 'Could not upload a conversation image: ' . ($uploaded['error'] ?? '');

            return;
        }
        $this->assetIds[] = (int)$uploaded['assetId'];
        $attached = $media->attach($chat, [(int)$uploaded['assetId']], $admin);
        if (empty($attached['ok']) || !ConversationAssetRecord::find()->where([
            'conversationId' => (int)$chat->id,
            'assetId' => (int)$uploaded['assetId'],
        ])->exists()) {
            $this->failures[] = 'Uploaded image was not linked to the conversation.';

            return;
        }

        $prompt = (new ReflectionMethod($plugin->assistant, 'systemPrompt'))->invoke($plugin->assistant, $chat, $admin);
        if (!is_string($prompt) || !str_contains($prompt, '#' . (int)$uploaded['assetId']) || !str_contains($prompt, 'import_asset')) {
            $this->failures[] = 'Conversation assets are not listed in the assistant instructions.';
        }

        $created = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check assets page',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-assets-' . uniqid(),
        ], $admin);
        if (empty($created['ok'])) {
            $this->failures[] = 'Could not create a page for the asset check: ' . ($created['error'] ?? '');

            return;
        }
        $this->elementIds[] = (int)$created['entryId'];
        $plugin->conversations->bind($chat, (int)$created['entryId'], (int)$created['draftId'], Craft::$app->getSites()->getPrimarySite()->id);
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $block = $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'logoGridSection', null, [
            'logos' => [(int)$uploaded['assetId']],
        ], [], $admin);
        if (empty($block['ok'])) {
            $this->failures[] = 'Could not relate a conversation asset in a block: ' . ($block['error'] ?? '');
        }

        $plugin->conversations->addMessage($chat, 'user', 'Use the attached logo', metadata: [
            'assetIds' => [(int)$uploaded['assetId']],
        ]);
        $modelMessages = $plugin->conversations->messagesForModel($chat);
        $lastUser = null;
        foreach ($modelMessages as $message) {
            if (($message['role'] ?? '') === 'user') {
                $lastUser = $message;
            }
        }
        if (!$lastUser || !str_contains((string)$lastUser['content'], '#' . (int)$uploaded['assetId'])) {
            $this->failures[] = 'Message asset metadata was not included for the model.';
        }

        $view = $plugin->conversations->ownedView((int)$chat->id, $admin);
        if (!$view || empty($view['assets']) || empty($view['messages'])) {
            $this->failures[] = 'ownedView did not include conversation assets.';
        }
    }

    private function checkSummarizeAndApply(Plugin $plugin, User $admin): void
    {
        Craft::$app->getUser()->setIdentity($admin);

        $tools = $plugin->assistant->tools();
        $names = array_column($tools, 'name');
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool['name']] = $tool;
        }
        if (
            !in_array('summarize_page', $names, true)
            || !in_array('apply_blocks', $names, true)
            || !str_contains((string)($byName['summarize_page']['description'] ?? ''), 'orientation')
            || !str_contains((string)($byName['apply_blocks']['description'] ?? ''), 'Stops on the first failure')
            || !str_contains((string)($byName['read_layout']['description'] ?? ''), 'summarize_page')
            || !str_contains((string)($byName['add_block']['description'] ?? ''), 'apply_blocks')
            || !str_contains((string)($byName['update_block']['description'] ?? ''), 'apply_blocks')
        ) {
            $this->failures[] = 'summarize_page / apply_blocks tools or descriptions are missing.';
        }

        $created = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check summarize',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-summarize-' . uniqid(),
        ], $admin);
        if (empty($created['ok'])) {
            $this->failures[] = 'Could not create a page for summarize/apply checks: ' . ($created['error'] ?? '');

            return;
        }
        $this->elementIds[] = (int)$created['entryId'];

        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        if (!$draft instanceof Entry) {
            $this->failures[] = 'Could not load the summarize/apply draft.';

            return;
        }

        $hero = $plugin->contentDesigner->addBlock($draft, 'hero', 'standardHero', null, [
            'heroEyebrow' => 'Sum',
            'heroHeadline' => 'Summarize hero',
            'heroContent' => '<p>Hero body</p>',
            'ctaLink' => 'https://example.com/summarize',
        ], [], $admin);
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $seed = $draft ? $plugin->contentDesigner->addBlock($draft, 'contentDesigner', 'richText', null, [
            'headline' => 'Seed block',
            'blockContent' => '<p>Seed content</p>',
        ], [], $admin) : ['ok' => false];
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        if (empty($hero['ok']) || empty($seed['ok']) || !$draft instanceof Entry) {
            $this->failures[] = 'Could not seed summarize/apply draft: ' . ($hero['error'] ?? '') . ' ' . ($seed['error'] ?? '');

            return;
        }

        $summary = $plugin->contentDesigner->summarizePage($draft, $admin);
        $heroSummary = $summary['matrices']['hero'] ?? [];
        $contentSummary = $summary['matrices']['contentDesigner'] ?? [];
        $seedSignal = (string)($contentSummary['blocks'][0]['signal'] ?? '');
        if (
            empty($summary['ok'])
            || ($summary['title'] ?? null) !== 'Assistant check summarize'
            || empty($heroSummary['atMax'])
            || ($heroSummary['blocks'][0]['type'] ?? null) !== 'standardHero'
            || !str_contains((string)($heroSummary['blocks'][0]['signal'] ?? ''), 'Sum')
            || ($contentSummary['blocks'][0]['type'] ?? null) !== 'richText'
            || $seedSignal === ''
            || !str_contains($seedSignal, 'Seed')
        ) {
            $this->failures[] = 'summarizePage shape was wrong: ' . json_encode($summary);
        }

        $chat = $plugin->conversations->open(
            $admin->id,
            (int)$created['entryId'],
            (int)$created['draftId'],
            Craft::$app->getSites()->getPrimarySite()->id,
        );
        $this->conversationIds[] = (int)$chat->id;

        $toolSummary = $plugin->assistant->execute('summarize_page', [], $chat, $admin);
        if (empty($toolSummary['ok']) || empty($toolSummary['matrices']['hero']['atMax'])) {
            $this->failures[] = 'summarize_page tool failed: ' . json_encode($toolSummary);
        }

        $batch = $plugin->assistant->execute('apply_blocks', [
            'operations' => [
                [
                    'op' => 'add',
                    'fieldHandle' => 'contentDesigner',
                    'type' => 'richText',
                    'values' => ['headline' => 'Batch A', 'blockContent' => '<p>A</p>'],
                ],
                [
                    'op' => 'add',
                    'fieldHandle' => 'contentDesigner',
                    'type' => 'richText',
                    'values' => ['headline' => 'Batch B', 'blockContent' => '<p>B</p>'],
                ],
            ],
        ], $chat, $admin);
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $afterBatch = $draft ? $plugin->contentDesigner->summarizePage($draft, $admin) : [];
        $contentSignals = array_column($afterBatch['matrices']['contentDesigner']['blocks'] ?? [], 'signal');
        if (
            empty($batch['ok'])
            || empty($batch['saved'])
            || count($batch['steps'] ?? []) !== 2
            || !array_key_exists('previewUrl', $batch)
            || !array_key_exists('previewAvailable', $batch)
            || !array_key_exists('editUrl', $batch)
            || !in_array('Batch A', $contentSignals, true)
            || !in_array('Batch B', $contentSignals, true)
        ) {
            $this->failures[] = 'Successful apply_blocks failed: ' . json_encode($batch) . ' ' . json_encode($afterBatch);
        }

        $beforePartial = count($afterBatch['matrices']['contentDesigner']['blocks'] ?? []);
        $partial = $plugin->assistant->execute('apply_blocks', [
            'operations' => [
                [
                    'op' => 'add',
                    'fieldHandle' => 'contentDesigner',
                    'type' => 'richText',
                    'values' => ['headline' => 'Partial keep', 'blockContent' => '<p>Keep</p>'],
                ],
                [
                    'op' => 'add',
                    'fieldHandle' => 'contentDesigner',
                    'type' => 'notARealBlock',
                    'values' => ['headline' => 'Should fail'],
                ],
            ],
        ], $chat, $admin);
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $afterPartial = $draft ? $plugin->contentDesigner->summarizePage($draft, $admin) : [];
        $partialSignals = array_column($afterPartial['matrices']['contentDesigner']['blocks'] ?? [], 'signal');
        if (
            !empty($partial['ok'])
            || empty($partial['saved'])
            || ($partial['stoppedAt'] ?? null) !== 1
            || count($afterPartial['matrices']['contentDesigner']['blocks'] ?? []) !== $beforePartial + 1
            || !in_array('Partial keep', $partialSignals, true)
        ) {
            $this->failures[] = 'Partial apply_blocks behavior was wrong: ' . json_encode($partial) . ' ' . json_encode($afterPartial);
        }

        $heroId = (int)($afterPartial['matrices']['hero']['blocks'][0]['id'] ?? 0);
        $updateMax = $plugin->assistant->execute('apply_blocks', [
            'operations' => [
                [
                    'op' => 'update',
                    'blockId' => $heroId,
                    'values' => ['heroHeadline' => 'Updated via batch'],
                ],
            ],
        ], $chat, $admin);
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $afterUpdate = $draft ? $plugin->contentDesigner->readLayout($draft, $admin) : [];
        if (
            empty($updateMax['ok'])
            || empty($updateMax['saved'])
            || ($afterUpdate['matrices']['hero']['blocks'][0]['fields']['heroHeadline'] ?? null) !== 'Updated via batch'
            || empty($afterUpdate['matrices']['hero']['atMax'])
        ) {
            $this->failures[] = 'apply_blocks update-at-max failed: ' . json_encode($updateMax) . ' ' . json_encode($afterUpdate['matrices']['hero'] ?? []);
        }
    }

    private function checkMediaAndRelations(Plugin $plugin, User $admin): void
    {
        Craft::$app->getUser()->setIdentity($admin);

        $tools = $plugin->assistant->tools();
        $names = array_column($tools, 'name');
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool['name']] = $tool;
        }
        $prompt = (new ReflectionMethod($plugin->assistant, 'systemPrompt'))->invoke(
            $plugin->assistant,
            new ConversationRecord(),
            $admin,
        );
        if (
            !in_array('list_conversation_assets', $names, true)
            || !in_array('update_asset_meta', $names, true)
            || !is_string($prompt)
            || !str_contains($prompt, 'list_conversation_assets')
            || !str_contains($prompt, 'update_asset_meta')
            || !str_contains($prompt, 'find_elements with filters')
            || !str_contains((string)($byName['find_elements']['description'] ?? ''), 'section')
            || !str_contains((string)($byName['list_conversation_assets']['description'] ?? ''), 'linked to this conversation')
        ) {
            $this->failures[] = 'Media/relation tools or prompt preferences are missing.';
        }
        if (ContentDesigner::FIND_LIMIT < 1 || ContentDesigner::FIND_LIMIT > 50) {
            $this->failures[] = 'findElements limit is not a modest positive page size.';
        }

        try {
            $shared = $this->sharedEntry($admin);
        } catch (\Throwable $e) {
            $this->failures[] = $e->getMessage();

            return;
        }
        $foundShared = $plugin->contentDesigner->findElements('entry', (string)$shared->title, $admin, [
            'section' => 'sharedBlocks',
            'status' => 'any',
        ]);
        $sharedIds = array_column($foundShared['elements'] ?? [], 'id');
        if (empty($foundShared['ok']) || !in_array((int)$shared->id, $sharedIds, true)) {
            $this->failures[] = 'Filtered find_elements did not return the shared block: ' . json_encode($foundShared);
        }
        $row = null;
        foreach ($foundShared['elements'] ?? [] as $element) {
            if ((int)($element['id'] ?? 0) === (int)$shared->id) {
                $row = $element;
                break;
            }
        }
        if (!$row || ($row['section'] ?? null) !== 'sharedBlocks' || empty($row['slug'])) {
            $this->failures[] = 'find_elements entry rows are missing section/slug: ' . json_encode($row);
        }

        $missingVolume = $plugin->contentDesigner->findElements('asset', 'anything', $admin, [
            'volume' => 'not-a-real-volume-handle',
        ]);
        if (empty($missingVolume['ok']) || ($missingVolume['elements'] ?? null) !== []) {
            $this->failures[] = 'Unknown volume search should return no assets: ' . json_encode($missingVolume);
        }

        $settings = $plugin->getSettings();
        $volume = $settings->assetVolume();
        if (!$volume) {
            $this->failures[] = 'No asset volume for media checks.';

            return;
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $temp = Craft::$app->getPath()->getTempPath() . '/padstone-mason-media-' . uniqid() . '.png';
        file_put_contents($temp, $png);
        $uploaded = $plugin->conversationAssets->uploadFromRequest(new UploadedFile([
            'name' => 'mason-media-check.png',
            'tempName' => $temp,
            'type' => 'image/png',
            'size' => filesize($temp),
            'error' => UPLOAD_ERR_OK,
        ]), $admin);
        if (empty($uploaded['ok'])) {
            $this->failures[] = 'Could not upload media-check image: ' . ($uploaded['error'] ?? '');

            return;
        }
        $assetId = (int)$uploaded['assetId'];
        $this->assetIds[] = $assetId;

        $foundAsset = $plugin->contentDesigner->findElements('asset', 'mason-media-check', $admin, [
            'volume' => $volume->handle,
        ]);
        $assetIds = array_column($foundAsset['elements'] ?? [], 'id');
        if (empty($foundAsset['ok']) || !in_array($assetId, $assetIds, true)) {
            $this->failures[] = 'Filtered asset find did not return the uploaded file: ' . json_encode($foundAsset);
        }

        $chat = $this->startedConversation($plugin, $admin, null);
        if (!$chat) {
            return;
        }
        $attached = $plugin->conversationAssets->attach($chat, [$assetId], $admin);
        if (empty($attached['ok'])) {
            $this->failures[] = 'Could not attach media-check asset: ' . ($attached['error'] ?? '');

            return;
        }

        $listed = $plugin->assistant->execute('list_conversation_assets', [], $chat, $admin);
        $listedIds = array_column($listed['assets'] ?? [], 'id');
        if (empty($listed['ok']) || !in_array($assetId, $listedIds, true)) {
            $this->failures[] = 'list_conversation_assets missed the linked asset: ' . json_encode($listed);
        }

        $meta = $plugin->assistant->execute('update_asset_meta', [
            'assetId' => $assetId,
            'alt' => 'Mason media alt',
            'title' => 'Mason media title',
        ], $chat, $admin);
        $asset = Asset::find()->id($assetId)->status(null)->one();
        if (
            empty($meta['ok'])
            || empty($meta['saved'])
            || !$asset instanceof Asset
            || (string)$asset->alt !== 'Mason media alt'
            || (string)$asset->title !== 'Mason media title'
        ) {
            $this->failures[] = 'update_asset_meta failed: ' . json_encode($meta);
        }

        $viewer = $this->viewer($admin);
        $this->withPermissionChecks(function() use ($plugin, $viewer, $chat, $assetId): void {
            if (!in_array((int)$viewer->id, $this->userIds, true)) {
                return;
            }
            Craft::$app->getUser()->setIdentity($viewer);
            $denied = $plugin->assistant->execute('update_asset_meta', [
                'assetId' => $assetId,
                'alt' => 'Should not save',
            ], $chat, $viewer);
            if (!empty($denied['ok'])) {
                $this->failures[] = 'A view-only user updated asset metadata.';
            }
        });
        Craft::$app->getUser()->setIdentity($admin);

        $allowedField = new AssetsField();
        $allowedField->handle = 'logosAllowed';
        $allowedField->sources = ['volume:' . $volume->uid];
        $okRelation = FieldSupport::prepare($allowedField, [$assetId], $admin);
        if (($okRelation['status'] ?? null) !== 'ok') {
            $this->failures[] = 'In-source asset relation was refused: ' . json_encode($okRelation);
        }

        $otherUid = '00000000-0000-0000-0000-000000000000';
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $candidate) {
            if ($candidate->uid !== $volume->uid) {
                $otherUid = $candidate->uid;
                break;
            }
        }
        $deniedField = new AssetsField();
        $deniedField->handle = 'logosDenied';
        $deniedField->sources = ['volume:' . $otherUid];
        $badRelation = FieldSupport::prepare($deniedField, [$assetId], $admin);
        if (($badRelation['status'] ?? null) !== 'fail' || !str_contains((string)($badRelation['message'] ?? ''), 'sources')) {
            $this->failures[] = 'Out-of-source asset relation was not refused: ' . json_encode($badRelation);
        }

        $created = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check media relations',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-media-rel-' . uniqid(),
        ], $admin);
        if (empty($created['ok'])) {
            $this->failures[] = 'Could not create page for link checks: ' . ($created['error'] ?? '');

            return;
        }
        $this->elementIds[] = (int)$created['entryId'];
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $linkTarget = Entry::find()->section('pages')->status(null)->id(['not', (int)$created['entryId']])->one();
        if (!$linkTarget instanceof Entry) {
            $linkTarget = $draft;
        }
        $hero = $draft ? $plugin->contentDesigner->addBlock($draft, 'hero', 'standardHero', null, [
            'heroEyebrow' => 'Link',
            'heroHeadline' => 'Link check',
            'heroContent' => '<p>Link body</p>',
            'ctaLink' => [
                'type' => 'entry',
                'value' => (string)$linkTarget->id,
                'label' => 'Target',
            ],
        ], [], $admin) : ['ok' => false];
        $draft = $plugin->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $layout = $draft ? $plugin->contentDesigner->readLayout($draft, $admin) : [];
        $cta = $layout['matrices']['hero']['blocks'][0]['fields']['ctaLink'] ?? null;
        // LinkData::getValue() for entry links surfaces the resolved URL; type stays "entry".
        if (
            empty($hero['ok'])
            || !is_array($cta)
            || ($cta['type'] ?? null) !== 'entry'
            || trim((string)($cta['value'] ?? '')) === ''
            || ($cta['label'] ?? null) !== 'Target'
        ) {
            $this->failures[] = 'Entry-typed Link write failed: ' . json_encode($hero) . ' ' . json_encode($cta);
        }

        $heroBlockId = (int)($layout['matrices']['hero']['blocks'][0]['id'] ?? 0);
        $heroBlock = $draft && $heroBlockId
            ? $plugin->contentDesigner->findBlockOnEntry($draft, $heroBlockId)
            : null;
        $badLink = $heroBlock
            ? $plugin->contentDesigner->updateBlock(
                $heroBlock,
                ['ctaLink' => ['type' => 'entry', 'value' => '999999999']],
                $admin,
            )
            : ['ok' => true];
        if (!empty($badLink['ok'])) {
            $this->failures[] = 'Invalid entry Link target was accepted: ' . json_encode($badLink);
        }

        $entriesField = new \craft\fields\Entries();
        $entriesField->handle = 'tmpEntriesRead';
        $entriesField->sources = '*';
        $read = FieldSupport::readValue($entriesField, [$shared]);
        if (($read[0]['section'] ?? null) !== 'sharedBlocks' || ($read[0]['id'] ?? null) != $shared->id) {
            $this->failures[] = 'Relation readback missing section: ' . json_encode($read);
        }
    }

    private function checkUnpublishAndDelete(Plugin $plugin, User $admin): void
    {
        Craft::$app->getUser()->setIdentity($admin);
        $names = array_column($plugin->assistant->tools(), 'name');
        $prompt = (new ReflectionMethod($plugin->assistant, 'systemPrompt'))->invoke(
            $plugin->assistant,
            new ConversationRecord(),
            $admin,
        );
        if (!in_array('unpublish_entry', $names, true) || !in_array('delete_entry', $names, true) || !is_string($prompt) || !str_contains($prompt, 'only when the user explicitly asks to unpublish or delete')) {
            $this->failures[] = 'Unpublish and delete are not limited to an explicit request.';
        }

        $viewer = $this->viewer($admin);
        try {
            $page = $this->publishedPage($admin, 'Assistant check unpublish', 'assistant-check-unpublish-');
        } catch (\Throwable $e) {
            $this->failures[] = $e->getMessage();

            return;
        }
        $chat = $this->startedConversation($plugin, $admin, $page);
        if (!$chat) {
            return;
        }

        $this->withPermissionChecks(function () use ($plugin, $viewer, $page): void {
            if (!in_array((int)$viewer->id, $this->userIds, true)) {
                return;
            }
            Craft::$app->getUser()->setIdentity($viewer);
            $denied = $plugin->contentDesigner->unpublishEntry($page, $viewer);
            if (!empty($denied['ok'])) {
                $this->failures[] = 'A view-only user unpublished an entry.';
            }
        });
        Craft::$app->getUser()->setIdentity($admin);
        $page = Entry::find()->id((int)$page->id)->status(null)->one();
        if (!$page instanceof Entry || !$page->enabled) {
            $this->failures[] = 'A denied unpublish changed the entry.';

            return;
        }

        $unpublished = $plugin->assistant->execute('unpublish_entry', [], $chat, $admin);
        $chat->refresh();
        $page = Entry::find()->id((int)$page->id)->status(null)->one();
        if (empty($unpublished['ok']) || !$page instanceof Entry || $page->enabled || (int)$chat->entryId !== (int)$page->id) {
            $this->failures[] = 'Unpublishing did not disable the live entry: ' . ($unpublished['error'] ?? '');

            return;
        }
        $again = $plugin->assistant->execute('unpublish_entry', [], $chat, $admin);
        if (empty($again['ok']) || empty($again['alreadyUnpublished'])) {
            $this->failures[] = 'Unpublishing an offline entry was treated as a new change.';
        }

        try {
            $doomed = $this->publishedPage($admin, 'Assistant check delete', 'assistant-check-delete-');
        } catch (\Throwable $e) {
            $this->failures[] = $e->getMessage();

            return;
        }
        $deleteChat = $this->startedConversation($plugin, $admin, $doomed);
        if (!$deleteChat) {
            return;
        }
        $this->withPermissionChecks(function () use ($plugin, $viewer, $doomed): void {
            if (!in_array((int)$viewer->id, $this->userIds, true)) {
                return;
            }
            Craft::$app->getUser()->setIdentity($viewer);
            $denied = $plugin->contentDesigner->deleteEntry($doomed, $viewer);
            if (!empty($denied['ok'])) {
                $this->failures[] = 'A view-only user deleted an entry.';
            }
        });
        Craft::$app->getUser()->setIdentity($admin);
        if (!Entry::find()->id((int)$doomed->id)->status(null)->exists()) {
            $this->failures[] = 'A denied delete removed the entry.';

            return;
        }

        $deleted = $plugin->assistant->execute('delete_entry', [], $deleteChat, $admin);
        $deleteChat->refresh();
        $live = Entry::find()->id((int)$doomed->id)->status(null)->one();
        $trashed = Entry::find()->id((int)$doomed->id)->status(null)->trashed(true)->one();
        if (empty($deleted['ok']) || $deleteChat->entryId || $deleteChat->draftId || $live || !$trashed) {
            $this->failures[] = 'Deleting did not move the entry to the trash and clear the binding: ' . ($deleted['error'] ?? '');
        }

        $draft = $plugin->contentDesigner->createPage([
            'title' => 'Assistant check unpublished draft',
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => 'assistant-check-unpublished-draft-' . uniqid(),
        ], $admin);
        if (empty($draft['ok'])) {
            $this->failures[] = 'Could not create an unpublished draft for the unpublish check: ' . ($draft['error'] ?? '');

            return;
        }
        $this->elementIds[] = (int)$draft['entryId'];
        $draftChat = $plugin->conversations->open($admin->id, (int)$draft['entryId'], (int)$draft['draftId'], Craft::$app->getSites()->getPrimarySite()->id);
        $this->conversationIds[] = (int)$draftChat->id;
        $notLive = $plugin->assistant->execute('unpublish_entry', [], $draftChat, $admin);
        if (!empty($notLive['ok'])) {
            $this->failures[] = 'Unpublish accepted an entry that was never published.';
        }

        $empty = $this->startedConversation($plugin, $admin, null);
        if ($empty && (!empty($plugin->assistant->execute('unpublish_entry', [], $empty, $admin)['ok']) || !empty($plugin->assistant->execute('delete_entry', [], $empty, $admin)['ok']))) {
            $this->failures[] = 'Unpublish or delete ran without a bound entry.';
        }
    }

    private function publishedPage(User $admin, string $title, string $slugPrefix): Entry
    {
        $created = Plugin::getInstance()->contentDesigner->createPage([
            'title' => $title,
            'section' => 'pages',
            'entryType' => 'basicPage',
            'slug' => $slugPrefix . uniqid(),
        ], $admin);
        if (empty($created['ok'])) {
            throw new \RuntimeException($created['error'] ?? 'Could not create a page for the conversation check.');
        }
        $this->elementIds[] = (int)$created['entryId'];
        $draft = Plugin::getInstance()->contentDesigner->entryFromId((int)$created['entryId'], (int)$created['draftId'], null, $admin);
        $published = Plugin::getInstance()->contentDesigner->publishDraft($draft, $admin);
        if (empty($published['ok'])) {
            throw new \RuntimeException($published['error'] ?? 'Could not publish a page for the conversation check.');
        }
        $live = Entry::find()->id((int)$created['entryId'])->status(null)->one();
        if (!$live instanceof Entry || $live->getIsDraft()) {
            throw new \RuntimeException('The conversation check page was not published.');
        }

        return $live;
    }

    private function startedConversation(Plugin $plugin, User $admin, ?Entry $entry): ?ConversationRecord
    {
        $started = $plugin->conversations->start($admin->id, $entry, $admin, $entry?->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
        if (empty($started['ok']) || !$started['conversation'] instanceof ConversationRecord) {
            $this->failures[] = 'Could not start a chat: ' . ($started['error'] ?? '');

            return null;
        }
        $this->conversationIds[] = (int)$started['conversation']->id;

        return $started['conversation'];
    }

    /**
     * @param array<int, array{id: int, title: string, entryId: ?int, draftId: ?int}> $conversations
     * @return array<string, mixed>
     */
    private function panelRender(array $conversations, ConversationRecord $conversation, ?int $entryId, ?int $siteId, string $scope): array
    {
        $boundDraftId = $conversation->draftId ? (int)$conversation->draftId : null;

        return [
            'conversation' => $conversation,
            'conversations' => $conversations,
            'messages' => [],
            'assets' => [],
            'previewUrl' => null,
            'editUrl' => null,
            'boundTitle' => null,
            'boundIsDraft' => (bool)$boundDraftId,
            'boundDraftId' => $boundDraftId,
            'draftNote' => 'Ask for a change. It stays on a draft until you publish it.',
            'entryId' => $entryId,
            'siteId' => $siteId,
            'scope' => $scope,
        ];
    }

    private function cleanup(): void
    {
        foreach ($this->conversationIds as $id) {
            $conversation = ConversationRecord::findOne($id);
            if ($conversation) {
                $conversation->delete();
            }
        }
        foreach ($this->assetIds as $id) {
            if (!$id) {
                continue;
            }
            $assets = Asset::find()->id($id)->status(null)->trashed(null)->all();
            foreach ($assets as $asset) {
                Craft::$app->getElements()->deleteElement($asset, true);
            }
        }
        foreach ($this->elementIds as $id) {
            if (!$id) {
                continue;
            }
            $entries = Entry::find()->id($id)->drafts(null)->status(null)->trashed(null)->site('*')->all();
            foreach ($entries as $entry) {
                Craft::$app->getElements()->deleteElement($entry, true);
            }
        }
        foreach ($this->userIds as $id) {
            $user = User::find()->id($id)->status(null)->one();
            if ($user) {
                Craft::$app->getElements()->deleteElement($user, true);
            }
        }
    }
}

class ScriptedClient implements ProviderClient
{
    /** @var string[] */
    public array $models = [];

    public function __construct(private \Closure $handler)
    {
    }

    public function complete(array $messages, array $tools, string $model, int $maxTokens): ProviderResult
    {
        $this->models[] = $model;

        return ($this->handler)($messages, $tools, $model, $maxTokens);
    }
}
