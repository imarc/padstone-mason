<?php

namespace imarc\padstonemason\services;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets;
use craft\fields\Dropdown;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\data\LinkData;
use Craft;

class FieldSupport
{
    public const CONTENT_DESIGNER = 'contentDesigner';

    public static function isMatrix(FieldInterface $field): bool
    {
        return $field instanceof Matrix;
    }

    public static function isReadOnly(FieldInterface $field): bool
    {
        $class = strtolower($field::class);

        return in_array($class, [
            'nystudio107\\seomatic\\fields\\seosettings',
            'nystudio107\\codefield\\fields\\code',
            'imarc\\pickture\\fields\\pickturefield',
        ], true);
    }

    public static function isWritable(FieldInterface $field): bool
    {
        return $field instanceof PlainText
            || $field instanceof CkeditorField
            || $field instanceof Lightswitch
            || $field instanceof Dropdown
            || $field instanceof Link
            || self::isRegex($field)
            || self::isRelation($field);
    }

    public static function isRegex(FieldInterface $field): bool
    {
        return strtolower($field::class) === 'imarc\\regexfield\\fields\\regexfield';
    }

    public static function inputHint(FieldInterface $field): string
    {
        if ($field instanceof Matrix) {
            return 'Use summarize_page for orientation, apply_blocks for multi-block recipes, or add_block / update_block / reorder_blocks / remove_block for single edits. min=' . ($field->minEntries ?? 'none') . ' max=' . ($field->maxEntries ?? 'none');
        }
        if (self::isReadOnly($field)) {
            return 'Read-only. Writes are skipped.';
        }
        if ($field instanceof Lightswitch) {
            return 'Boolean.';
        }
        if ($field instanceof Dropdown) {
            $values = array_values(array_filter(array_map(
                fn(array $option) => $option['value'] ?? null,
                $field->options,
            )));

            return 'One of: ' . implode(', ', $values);
        }
        if ($field instanceof Link) {
            $types = implode(', ', $field->types);

            return 'URL string, or object {type, value, label?} where type is one of: ' . $types . '. For entry/asset types, value is an element id. Use find_elements when the user names a page or file without an id.';
        }
        if (self::isRelation($field)) {
            return 'Existing element id, or a list of ids allowed by this field\'s sources. Raw uploads in field values are refused; use list_conversation_assets or import_asset, then pass those ids.';
        }
        if ($field instanceof CkeditorField) {
            return 'HTML string.';
        }

        return 'String.';
    }

    /**
     * @return array{status: string, value?: mixed, message?: string}
     */
    public static function prepare(FieldInterface $field, mixed $value, ?User $user): array
    {
        if (self::isMatrix($field)) {
            return [
                'status' => 'fail',
                'message' => $field->handle . ' is a nested Matrix field. Use the block tools instead of setting it as a value.',
            ];
        }

        if (self::isReadOnly($field)) {
            return [
                'status' => 'skip',
                'message' => $field->handle . ' is read-only and was skipped.',
            ];
        }

        if (self::looksLikeUpload($value)) {
            return [
                'status' => 'fail',
                'message' => 'Uploads are not supported.',
            ];
        }

        if (self::isRelation($field)) {
            return self::prepareRelation($field, $value, $user);
        }

        if ($field instanceof Lightswitch) {
            return ['status' => 'ok', 'value' => filter_var($value, FILTER_VALIDATE_BOOLEAN)];
        }

        if ($field instanceof Dropdown) {
            $allowed = array_map(fn(array $option) => (string)($option['value'] ?? ''), $field->options);
            if (!in_array((string)$value, $allowed, true)) {
                return ['status' => 'fail', 'message' => $field->handle . ' does not allow that option.'];
            }

            return ['status' => 'ok', 'value' => (string)$value];
        }

        if ($field instanceof Link) {
            return self::prepareLink($field, $value, $user);
        }

        if ($field instanceof PlainText || $field instanceof CkeditorField || self::isRegex($field)) {
            if (is_array($value) || is_object($value)) {
                return ['status' => 'fail', 'message' => $field->handle . ' expects text.'];
            }

            return ['status' => 'ok', 'value' => (string)$value];
        }

        return [
            'status' => 'skip',
            'message' => $field->handle . ' is not writable and was skipped.',
        ];
    }

    public static function readValue(FieldInterface $field, mixed $value): mixed
    {
        if ($field instanceof Matrix) {
            return null;
        }

        if ($field instanceof Link || $value instanceof LinkData) {
            if ($value instanceof LinkData) {
                return [
                    'type' => $value->getType(),
                    'value' => $value->getValue(),
                    'label' => $value->getLabel(),
                ];
            }

            return $value;
        }

        if (self::isRelation($field)) {
            if ($value instanceof \craft\elements\db\ElementQuery) {
                $elements = $value->status(null)->all();
            } elseif (is_array($value)) {
                $elements = $value;
            } else {
                return [];
            }

            return array_map(function(ElementInterface $element) {
                $row = [
                    'id' => $element->id,
                    'title' => $element->title ?? (string)$element,
                ];
                if ($element instanceof Entry) {
                    $row['section'] = $element->getSection()?->handle;
                }

                return $row;
            }, $elements);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return null;
    }

    public static function isRelation(FieldInterface $field): bool
    {
        return $field instanceof Assets || $field instanceof EntriesField;
    }

    public static function looksLikeUpload(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $keys = ['tmp_name', 'base64', 'file', 'upload', 'contents', 'data'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && !in_array($key, ['value', 'label', 'type'], true)) {
                if ($key === 'value' || $key === 'label' || $key === 'type') {
                    continue;
                }
                if (in_array($key, ['tmp_name', 'base64', 'file', 'upload', 'contents'], true)) {
                    return true;
                }
            }
        }

        foreach ($value as $item) {
            if (is_array($item) && (isset($item['tmp_name']) || isset($item['base64']) || (isset($item['filename']) && isset($item['contents'])))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status: string, value?: int[], message?: string}
     */
    private static function prepareRelation(FieldInterface $field, mixed $value, ?User $user): array
    {
        $ids = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($ids as $id) {
            if (is_array($id) && isset($id['id'])) {
                $id = $id['id'];
            }
            if (!is_numeric($id)) {
                return ['status' => 'fail', 'message' => $field->handle . ' only accepts existing element ids.'];
            }

            $element = $field instanceof Assets
                ? Asset::find()->id((int)$id)->status(null)->one()
                : Craft::$app->getElements()->getElementById((int)$id);

            if (!$element) {
                return ['status' => 'fail', 'message' => $field->handle . " element $id was not found."];
            }

            if ($user && !Craft::$app->getElements()->canView($element, $user)) {
                return ['status' => 'fail', 'message' => 'You cannot view that related element.'];
            }

            if (!self::relationAllowedBySources($field, $element)) {
                return [
                    'status' => 'fail',
                    'message' => $field->handle . " element $id is not allowed by that field's sources.",
                ];
            }

            $normalized[] = (int)$element->id;
        }

        return ['status' => 'ok', 'value' => $normalized];
    }

    private static function relationAllowedBySources(FieldInterface $field, ElementInterface $element): bool
    {
        // Use the configured sources property — Assets::getInputSources() can touch the
        // web session, which is unavailable in console checks.
        if ($field instanceof Assets && $element instanceof Asset) {
            $sources = $field->sources;
            if ($sources === '*' || $sources === null) {
                return true;
            }
            if (!is_array($sources) || $sources === []) {
                return false;
            }

            $volume = $element->getVolume();
            $volumeKey = $volume ? 'volume:' . $volume->uid : null;
            $folder = $element->getFolder();
            $folderUid = $folder?->uid;

            foreach ($sources as $source) {
                if (!is_string($source)) {
                    continue;
                }
                if ($source === '*') {
                    return true;
                }
                if ($volumeKey && $source === $volumeKey) {
                    return true;
                }
                if ($folderUid && str_starts_with($source, 'folder:') && $source === 'folder:' . $folderUid) {
                    return true;
                }
                if ($folder && $folderUid && str_starts_with($source, 'folder:')) {
                    $sourceFolder = Craft::$app->getAssets()->getFolderByUid(substr($source, strlen('folder:')));
                    if ($sourceFolder && self::folderIsUnder($folder, $sourceFolder)) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($field instanceof EntriesField && $element instanceof Entry) {
            $sources = $field->sources;
            if ($sources === '*' || $sources === null) {
                return true;
            }
            if (!is_array($sources) || $sources === []) {
                return false;
            }

            $section = $element->getSection();
            $sectionKey = $section ? 'section:' . $section->uid : null;
            foreach ($sources as $source) {
                if (!is_string($source)) {
                    continue;
                }
                if ($source === '*') {
                    return true;
                }
                if ($sectionKey && $source === $sectionKey) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private static function folderIsUnder(\craft\models\VolumeFolder $folder, \craft\models\VolumeFolder $ancestor): bool
    {
        if ((int)$folder->id === (int)$ancestor->id) {
            return true;
        }
        $path = (string)$folder->path;
        $ancestorPath = (string)$ancestor->path;
        if ($ancestorPath === '') {
            return (int)$folder->volumeId === (int)$ancestor->volumeId;
        }

        return (int)$folder->volumeId === (int)$ancestor->volumeId
            && ($path === $ancestorPath || str_starts_with($path, rtrim($ancestorPath, '/') . '/'));
    }

    /**
     * @return array{status: string, value?: array<string, mixed>, message?: string}
     */
    private static function prepareLink(Link $field, mixed $value, ?User $user): array
    {
        if (is_string($value)) {
            if (!in_array('url', $field->types, true)) {
                return ['status' => 'fail', 'message' => $field->handle . ' does not allow url links.'];
            }

            return ['status' => 'ok', 'value' => ['type' => 'url', 'value' => $value]];
        }

        if (!is_array($value)) {
            return ['status' => 'fail', 'message' => $field->handle . ' expects a URL string or link object.'];
        }

        $type = (string)($value['type'] ?? '');
        if ($type === '' || !in_array($type, $field->types, true)) {
            return [
                'status' => 'fail',
                'message' => $field->handle . ' does not allow that link type. Allowed: ' . implode(', ', $field->types) . '.',
            ];
        }

        $payload = [
            'type' => $type,
            'value' => $value['value'] ?? '',
        ];
        if (array_key_exists('label', $value)) {
            $payload['label'] = $value['label'];
        }

        if (in_array($type, ['entry', 'asset'], true)) {
            $id = $payload['value'];
            if (is_array($id) && isset($id['id'])) {
                $id = $id['id'];
            }
            // Craft stores element links as {entry:ID@siteId:url} / {asset:ID@siteId:url}.
            if (is_string($id) && preg_match('/^\{(entry|asset):(\d+)(?:@(\d+))?:url\}$/', $id, $match)) {
                $id = (int)$match[2];
            }
            if (!is_numeric($id)) {
                return ['status' => 'fail', 'message' => $field->handle . " $type links require an element id."];
            }
            $element = $type === 'asset'
                ? Asset::find()->id((int)$id)->status(null)->one()
                : Entry::find()->id((int)$id)->status(null)->site('*')->one();
            if (!$element) {
                return ['status' => 'fail', 'message' => $field->handle . " $type target $id was not found."];
            }
            if ($user && !Craft::$app->getElements()->canView($element, $user)) {
                return ['status' => 'fail', 'message' => 'You cannot view that link target.'];
            }
            $refHandle = $type === 'asset' ? 'asset' : 'entry';
            $payload['value'] = sprintf('{%s:%d@%d:url}', $refHandle, (int)$element->id, (int)$element->siteId);
        } elseif (in_array($type, ['url', 'email'], true)) {
            $payload['value'] = (string)$payload['value'];
            if (trim($payload['value']) === '') {
                return ['status' => 'fail', 'message' => $field->handle . " $type links require a value."];
            }
        }

        return ['status' => 'ok', 'value' => $payload];
    }
}
