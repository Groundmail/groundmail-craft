<?php

namespace groundmail\craft;

use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use groundmail\craft\mail\GroundmailAdapter;
use yii\base\Event;

/**
 * Adds Groundmail to Settings > Email > Transport Type.
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        Event::on(
            MailerHelper::class,
            MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = GroundmailAdapter::class;
            },
        );
    }
}
