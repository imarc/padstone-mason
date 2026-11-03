<?php

namespace imarc\padstonemason\services\structure;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use imarc\padstonemason\services\FieldSupport;
use Throwable;

/**
 * Shared helpers for structure adapters and ContentDesigner.
 */
class StructureHelpers
{
    /**
     * @return array{ok: false, saved: false, error: string, errors: array}
     */
    public function fail(string $error, array $errors = []): array
    {
        return [
            'ok' => false,
            'saved' => false,
            'error' => $error,
            'errors' => $errors,
        ];
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function mutate(callable $fn): array
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $result = $fn();
            if (empty($result['ok'])) {
                $transaction->rollBack();

                return $result;
            }
            $transaction->commit();

            return $result;
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::error($e->getMessage(), __METHOD__);

            return $this->fail('The change could not be saved.');
        }
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function guardDraftMutation(Entry $owner, ?User $user): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }

        $root = $this->rootEntry($owner);
        if (!$root->getIsDraft()) {
            return $this->fail('Refusing to edit the live entry. Changes belong on a draft.');
        }
        if (!Craft::$app->getElements()->canSave($root, $user)) {
            return $this->fail('You do not have permission to save that entry.');
        }

        return ['ok' => true];
    }

    public function rootEntry(Entry $entry): Entry
    {
        $current = $entry;
        while ($current->getOwner() instanceof Entry) {
            $current = $current->getOwner();
        }

        return $current;
    }

    public function reload(Entry $entry): Entry
    {
        $reloaded = Entry::find()
            ->id($entry->id)
            ->siteId($entry->siteId)
            ->drafts(null)
            ->status(null)
            ->one();

        return $reloaded instanceof Entry ? $reloaded : $entry;
    }

    /**
     * @return array{title: ?string, slug: ?string}
     */
    public function snapshot(Entry $entry): array
    {
        $fresh = $this->reload($entry);

        return [
            'title' => $fresh->title,
            'slug' => $fresh->slug,
        ];
    }

    public function errorText(Element $element): string
    {
        $messages = [];
        foreach ($element->getErrors() as $attribute => $errors) {
            foreach ($errors as $error) {
                $messages[] = $attribute . ': ' . $error;
            }
        }

        return $messages ? implode('; ', $messages) : 'Validation failed.';
    }

    /**
     * @return array{previewUrl: ?string, previewAvailable: bool, editUrl: ?string}
     */
    public function previewPayload(Entry $draft): array
    {
        $editUrl = $draft->getCpEditUrl();
        $targetUrl = null;
        try {
            $targets = $draft->getPreviewTargets();
        } catch (Throwable) {
            $url = $draft->getUrl();
            $targets = $url ? [['url' => $url]] : [];
        }
        foreach ($targets as $target) {
            if (!empty($target['url'])) {
                $targetUrl = $target['url'];
                break;
            }
        }
        if ($targetUrl === null) {
            return [
                'previewUrl' => null,
                'previewAvailable' => false,
                'editUrl' => $editUrl,
            ];
        }

        $token = Craft::$app->getTokens()->createPreviewToken([
            'preview/preview',
            [
                'elementType' => Entry::class,
                'canonicalId' => (int)$draft->getCanonicalId(),
                'siteId' => (int)$draft->siteId,
                'draftId' => $draft->draftId ? (int)$draft->draftId : null,
                'revisionId' => null,
                'userId' => Craft::$app->getUser()->getId(),
            ],
        ]);

        if (!$token) {
            return [
                'previewUrl' => null,
                'previewAvailable' => false,
                'editUrl' => $editUrl,
            ];
        }

        $previewParam = Craft::$app->getSecurity()->hashData(Craft::$app->getSecurity()->generateRandomString());

        return [
            'previewUrl' => UrlHelper::urlWithParams($targetUrl, [
                'token' => $token,
                'x-craft-preview' => $previewParam,
            ]),
            'previewAvailable' => true,
            'editUrl' => $editUrl,
        ];
    }

    /**
     * @return array<int, array{field: FieldInterface, required: bool}>
     */
    public function customFieldsFromLayout(?FieldLayout $layout): array
    {
        if (!$layout) {
            return [];
        }

        $fields = [];
        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (!$element instanceof CustomField) {
                    continue;
                }
                $field = $element->getField();
                if ($field) {
                    $fields[] = [
                        'field' => $field,
                        'required' => (bool)$element->required,
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * @return array<int, array{field: FieldInterface, required: bool}>
     */
    public function customFields(EntryType $entryType): array
    {
        return $this->customFieldsFromLayout($entryType->getFieldLayout());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function describeFields(EntryType $entryType, ?callable $describeNestedStructure = null): array
    {
        return $this->describeFieldsFromLayout($entryType->getFieldLayout(), $describeNestedStructure);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function describeFieldsFromLayout(?FieldLayout $layout, ?callable $describeNestedStructure = null): array
    {
        if (!$layout) {
            return [];
        }

        $fields = [];
        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (!$element instanceof CustomField) {
                    continue;
                }
                $field = $element->getField();
                if (!$field) {
                    continue;
                }
                $row = [
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'required' => (bool)$element->required,
                    'writable' => FieldSupport::isWritable($field) && !FieldSupport::isReadOnly($field) && !FieldSupport::isMatrix($field),
                    'input' => FieldSupport::inputHint($field),
                ];
                if ($field instanceof Matrix && $describeNestedStructure) {
                    $row['minEntries'] = $field->minEntries;
                    $row['maxEntries'] = $field->maxEntries;
                    $row['blockTypes'] = $describeNestedStructure($field);
                }
                $fields[] = $row;
            }
        }

        return $fields;
    }

    /**
     * @return array{ok: bool, fields?: array<string, mixed>, skipped?: array<int, string>, error?: string}
     */
    public function prepareFieldValuesFromLayout(?FieldLayout $layout, array $values, ?User $user): array
    {
        $fields = [];
        $skipped = [];
        $byHandle = [];
        foreach ($this->customFieldsFromLayout($layout) as $custom) {
            $byHandle[$custom['field']->handle] = $custom['field'];
        }

        foreach ($values as $handle => $value) {
            $field = $byHandle[$handle] ?? null;
            if (!$field) {
                return $this->fail("Unknown field: $handle");
            }
            $prepared = FieldSupport::prepare($field, $value, $user);
            if ($prepared['status'] === 'fail') {
                return $this->fail($prepared['message']);
            }
            if ($prepared['status'] === 'skip') {
                $skipped[] = $prepared['message'];
                continue;
            }
            $fields[$handle] = $prepared['value'];
        }

        return ['ok' => true, 'fields' => $fields, 'skipped' => $skipped];
    }

    /**
     * @return array{ok: bool, fields?: array<string, mixed>, skipped?: array<int, string>, error?: string}
     */
    public function prepareFieldValues(EntryType $type, array $values, ?User $user): array
    {
        return $this->prepareFieldValuesFromLayout($type->getFieldLayout(), $values, $user);
    }

    /**
     * @return array{minEntries: ?int, maxEntries: ?int, count: int, atMin: bool, atMax: bool, hint?: string}
     */
    public function countMeta(?int $min, ?int $max, int $count): array
    {
        $atMin = $min !== null && $count <= (int)$min;
        $atMax = $max !== null && $count >= (int)$max;
        $meta = [
            'minEntries' => $min,
            'maxEntries' => $max,
            'count' => $count,
            'atMin' => $atMin,
            'atMax' => $atMax,
        ];
        if ($atMax) {
            $meta['hint'] = 'Field is at maximum. Update an existing block id; do not add another.';
        }

        return $meta;
    }

    public function blockSignal(ElementInterface $block, ?FieldLayout $layout): string
    {
        foreach ($this->customFieldsFromLayout($layout) as $custom) {
            $field = $custom['field'];
            if (
                !($field instanceof PlainText || $field instanceof CkeditorField || FieldSupport::isRegex($field))
                || FieldSupport::isReadOnly($field)
            ) {
                continue;
            }

            try {
                $value = FieldSupport::readValue($field, $block->getFieldValue($field->handle));
            } catch (Throwable) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
            if ($plain === '') {
                continue;
            }

            if (mb_strlen($plain) > 80) {
                return mb_substr($plain, 0, 77) . '...';
            }

            return $plain;
        }

        return '';
    }
}
