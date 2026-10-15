<?php

namespace imarc\padstonemason\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    public bool $enabled = true;

    public string $provider = 'openai';

    public string $defaultModel = 'gpt-4.1';

    public string $extraInstruction = '';

    public int $maxOutputTokens = 4096;

    /** @var string[] */
    public array $allowedSections = ['pages'];

    public function rules(): array
    {
        return [
            [['enabled'], 'boolean'],
            [['provider'], 'in', 'range' => ['openai', 'anthropic']],
            [['defaultModel', 'extraInstruction'], 'string'],
            [['defaultModel'], 'required'],
            [['maxOutputTokens'], 'integer', 'min' => 1, 'max' => 32000],
            [['allowedSections'], 'each', 'rule' => ['string']],
        ];
    }

    public function beforeValidate(): bool
    {
        if (is_string($this->allowedSections)) {
            $this->allowedSections = array_values(array_filter(array_map(
                'trim',
                preg_split('/\R|,/', $this->allowedSections) ?: [],
            )));
        }

        if ($this->allowedSections === null) {
            $this->allowedSections = [];
        }

        $this->maxOutputTokens = (int)$this->maxOutputTokens;
        $this->enabled = filter_var($this->enabled, FILTER_VALIDATE_BOOLEAN);

        return parent::beforeValidate();
    }

    public function afterValidate(): void
    {
        $known = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $known[] = $section->handle;
        }

        foreach ($this->allowedSections as $handle) {
            if (!in_array($handle, $known, true)) {
                $this->addError('allowedSections', "Unknown section handle: $handle");
            }
        }

        parent::afterValidate();
    }

    public function fields(): array
    {
        return [
            'enabled',
            'provider',
            'defaultModel',
            'extraInstruction',
            'maxOutputTokens',
            'allowedSections',
        ];
    }

    public function allowedSectionsText(): string
    {
        return implode("\n", $this->allowedSections);
    }

    public function sectionIsAllowed(string $handle): bool
    {
        return in_array($handle, $this->allowedSections, true);
    }

    public function apiKeyEnvName(): string
    {
        return $this->provider === 'anthropic' ? 'ANTHROPIC_API_KEY' : 'OPENAI_API_KEY';
    }
}
