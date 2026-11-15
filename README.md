<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Groundmail icon"></p>

<h1 align="center">Groundmail for Craft CMS</h1>

Send Craft's email through the [Groundmail](https://groundmail.dev?utm_source=github&utm_medium=readme&utm_campaign=groundmail-craft) API over HTTPS: account activation, password resets, Craft Commerce order email, form notifications and anything else your site sends with Craft's mailer.

## Requirements

Craft CMS 5.0 or later and PHP 8.2 or later.

## Installation

```bash
composer config repositories.groundmail-craft vcs https://github.com/Groundmail/groundmail-craft
composer require groundmail/craft-plugin
php craft plugin/install groundmail
```

## Setup

1. In Groundmail, [verify your sending domain](https://docs.groundmail.dev/domains?utm_source=github&utm_medium=readme&utm_campaign=groundmail-craft) and create an API key under **API Keys**. A send-only key is enough.
2. Add the key to your `.env` file:

   ```bash
   GROUNDMAIL_API_KEY=gm_your_key
   ```

3. In Craft, go to **Settings > Email**. Set **System Email Address** to an address on your verified domain.
4. Change **Transport Type** to **Groundmail**, and set **API Key** to `$GROUNDMAIL_API_KEY`. Using the environment variable keeps the key out of your project config.
5. Click **Test** to send a test email, then **Save**. To check the connection without emailing a real inbox, send the test to `delivered@simulator.groundmail.dev`:

   ```bash
   php craft mailer/test --to=delivered@simulator.groundmail.dev
   ```

## Email category

Groundmail sends transactional, product and newsletter email from separate IPs, so other mail never slows down your password resets. **Email category** sets the stream for the email Craft sends:

- **Transactional** (default): account email, password resets, order and form notifications.
- **Product**: email about your product to its users, such as onboarding tips. Recipients can opt out of product email and keep getting transactional email.

A single message can choose its own category with an `X-Groundmail-Category` header (`transactional`, `product`, or `newsletter` once your account is approved for newsletters) and add tags with `X-Groundmail-Tag: name=value`:

```php
Craft::$app->getMailer()->compose()
    ->setTo($user->email)
    ->setSubject('Your order has shipped')
    ->setHtmlBody($html)
    ->addHeader('X-Groundmail-Tag', 'order=' . $order->number)
    ->send();
```

Groundmail sends email to people who asked for it. Newsletters need your account approved (email support@groundmail.dev) and an opt-in list. Don't use Groundmail for cold email or for purchased, rented or scraped lists.

## What the plugin sends

Only to `https://api.groundmail.dev`: each email Craft sends (sender, recipients, subject, content, attachments, custom headers) with its category. See the [terms](https://groundmail.dev/legal/terms/?utm_source=github&utm_medium=readme&utm_campaign=groundmail-craft) and [privacy policy](https://groundmail.dev/legal/privacy/?utm_source=github&utm_medium=readme&utm_campaign=groundmail-craft).

## Development

```bash
composer install
vendor/bin/phpunit
```

`tests/e2e/run.py` runs the plugin in a real Craft site, against the Groundmail API or a local mock of it; see the top of that file.

## Support

[Open an issue](https://github.com/Groundmail/groundmail-craft/issues) or email support@groundmail.dev. [Docs](https://docs.groundmail.dev/integrations/craft?utm_source=github&utm_medium=readme&utm_campaign=groundmail-craft) · [Sign up free](https://app.groundmail.dev/signup?utm_source=github&utm_medium=readme&utm_campaign=groundmail-craft)
