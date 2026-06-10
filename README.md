# Vragen.ai for WordPress

Synchronises published WordPress content with a [vragen.ai](https://vragen.ai) knowledge base so it can be searched and answered through vragen.ai.

Whenever a post is published, updated, trashed or deleted, the plugin queues a background job (via [Action Scheduler](https://actionscheduler.org)) that creates, updates or removes the matching document in vragen.ai.

## Features

- Automatic sync on publish/update/trash/delete for the post types you choose.
- Background processing through Action Scheduler, large sites stay responsive.
- Bulk synchronisation of all published content from the settings screen.
- WP-CLI command (`wp vragenai sync`) for scripted or initial imports.
- PDF attachments (directly attached media and ACF file fields) are passed to vragen.ai for server-side text extraction.
- Multilingual support for WPML and Polylang.
- Embed a vragen.ai deployment (page, popup or popover) with the **Vragen.ai** block, or load one site-wide from the settings screen.
- Filters (`vragenai_should_index_post`, `vragenai_document_attributes`) to customise what is indexed.

## Requirements

- WordPress 6.0+
- PHP 8.1+

## External services
This plugin connects to the vragen.ai knowledge base API. This connection is the sole purpose of the plugin: it is required to index your content so it can be searched and answered through vragen.ai. The service is operated by [SWIS](https://swis.nl) on behalf of [Vragen.ai](https://vragen.ai).

The API is reached at the endpoint configured for your account, `https://{customer}.vragen.ai/api/v1` by default (the root domain can be changed with the `VRAGENAI_API_DOMAIN` constant).

When you publish, update, trash or delete content of an enabled post type and when you run a bulk synchronisation the plugin sends the following to that endpoint:

* the post title, public URL and rendered content;
* metadata: author display name, post type, post format, language(s), publish/modified dates, taxonomy terms and the featured-image URL;
* the URLs and MIME types of attached PDF files, so vragen.ai can fetch and extract their text server-side (the file contents themselves are not uploaded by the plugin);
* your configured customer name and API token (sent as a Bearer authorization header).

In addition, while the settings screen is open the plugin contacts the API at most once per hour to verify the connection.

No data is sent until you enter a customer name and API token. The plugin makes no other external requests.

* Service homepage: https://vragen.ai/
* Privacy Policy: https://vragen.ai/privacy-statement

## Installation

1. Install and activate the plugin.
2. Go to **Settings → Vragen.ai**.
3. Enter your customer name (the `{customer}` in `{customer}.vragen.ai`) and API token.
4. Select the post types to synchronise and save.
5. Optionally run **Bulk synchronisation** to index existing content.

### Keeping secrets out of the database

For production you can define credentials in `wp-config.php` instead of storing them in the database. These constants take precedence over the values stored on the settings screen:

```php
define( 'VRAGENAI_CUSTOMER', 'your-organisation' );
define( 'VRAGENAI_TOKEN', 'your-api-token' );
```

### Pointing at another environment

The API is reached at `{customer}.vragen.ai` by default. For staging or self-hosted instances you can override the root domain (the customer name remains the subdomain) — there is deliberately no admin UI field for this:

```php
define( 'VRAGENAI_API_DOMAIN', 'example.com' );
```

With customer `your-organisation` this targets `https://your-organisation.example.com/api/v1`.

## Multilingual model

With WPML or Polylang active, all translations of a post are treated as **one** piece of content: they are merged into a single vragen.ai document keyed on the default-language (canonical) translation. The document content is taken from that translation, and every available language is listed in its metadata.

Translations are assumed to be semantically equivalent, so only the canonical content is indexed; vragen.ai's semantic layer plus a language filter handle per-language retrieval.

## Embedding Vragen.ai

Besides syncing content, the plugin can embed a Vragen.ai deployment on your site:

- **Vragen.ai embed block** — add the block to any post or page and pick a deployment from the dropdown. The build type (page, popup or popover) comes from the deployment itself. You can place multiple embeds on one page.
- **Site-wide embed** — choose a popup or popover deployment under **Settings → Vragen.ai** to load it on every page.

The embed host is derived from your configured customer and `VRAGENAI_API_DOMAIN`, so you only choose the deployment.

### Content Security Policy

The embed loads a script from your Vragen.ai instance and makes API calls to it. If your site sends a strict CSP, allow your Vragen.ai domain:

```
script-src  'self' https://vragen.ai https://*.vragen.ai;
connect-src 'self' https://vragen.ai https://*.vragen.ai;
img-src     'self' data: https://vragen.ai https://*.vragen.ai;
style-src   'self' 'unsafe-inline';
```

`*.vragen.ai` does not match the bare `vragen.ai`, so include both. If you set `VRAGENAI_API_DOMAIN`, allow that domain instead.

## Development

The plugin uses Composer for both runtime and development dependencies.

```sh
composer install
```

Available scripts:

| Command | Description |
| --- | --- |
| `composer test` | Run the PHPUnit test suite. |
| `composer check-types` | Run PHPStan static analysis. |
| `composer check-style` | Check code style with Laravel Pint. |
| `composer fix-style` | Fix code style with Laravel Pint. |

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
