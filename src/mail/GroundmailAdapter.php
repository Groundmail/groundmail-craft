<?php

namespace groundmail\craft\mail;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use Groundmail\Client;
use Groundmail\Symfony\Transport\GroundmailTransport;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use yii\base\InvalidConfigException;

/**
 * The Groundmail transport type in Settings > Email.
 *
 * Every field accepts an environment variable ($GROUNDMAIL_API_KEY) or an alias.
 */
class GroundmailAdapter extends BaseTransportAdapter
{
    public const DEFAULT_BASE_URL = 'https://api.groundmail.dev';

    public const CATEGORIES = ['transactional', 'product'];

    /** Your Groundmail API key (gm_…), or an environment variable that holds it. */
    public ?string $apiKey = null;

    /**
     * The stream Craft's email goes out on: `transactional` (account email, password resets, order
     * and form notifications) or `product`. A message can choose its own with an X-Groundmail-Category header.
     */
    public ?string $category = 'transactional';

    /** API base URL. Not shown in the control panel; set it in the project config if you need to. */
    public ?string $baseUrl = null;

    public static function displayName(): string
    {
        return 'Groundmail';
    }

    public function attributeLabels(): array
    {
        return [
            'apiKey' => Craft::t('groundmail', 'API Key'),
            'category' => Craft::t('groundmail', 'Email category'),
        ];
    }

    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiKey', 'baseUrl'],
            ],
        ];
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['apiKey'], 'trim'];
        $rules[] = [['apiKey'], 'required'];
        // Craft swaps environment variables for their values before validating.
        $rules[] = [['apiKey'], function (string $attribute): void {
            if (is_string($this->$attribute) && !str_starts_with($this->$attribute, 'gm_')) {
                $this->addError($attribute, Craft::t('groundmail', 'Groundmail API keys start with gm_. SMTP passwords (gmsmtp_…) do not work here.'));
            }
        }];
        $rules[] = [['category'], 'in', 'range' => self::CATEGORIES];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return $this->settingsHtml(false);
    }

    public function getReadOnlySettingsHtml(): ?string
    {
        return $this->settingsHtml(true);
    }

    public function defineTransport(): array|AbstractTransport
    {
        $apiKey = trim((string) App::parseEnv($this->apiKey));
        $baseUrl = trim((string) App::parseEnv($this->baseUrl));
        if (!str_starts_with($apiKey, 'gm_')) {
            throw new InvalidConfigException('Groundmail needs an API key that starts with gm_. Set it in Settings > Email, or in the environment variable the setting names.');
        }

        return new GroundmailTransport(
            new Client($apiKey, $baseUrl !== '' ? rtrim($baseUrl, '/') : self::DEFAULT_BASE_URL),
            new CraftMessageMapper(in_array($this->category, self::CATEGORIES, true) ? $this->category : 'transactional'),
        );
    }

    private function settingsHtml(bool $readOnly): string
    {
        return Craft::$app->getView()->renderTemplate('groundmail/settings.twig', [
            'adapter' => $this,
            'readOnly' => $readOnly,
        ]);
    }
}
