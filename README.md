# Vragen.ai for WordPress

Synchronises published WordPress content with a [vragen.ai](https://vragen.ai) knowledge base so it can be searched and answered through vragen.ai.

Whenever a post is published, updated, trashed or deleted, the plugin queues a background job (via [Action Scheduler](https://actionscheduler.org)) that creates, updates or removes the matching document in vragen.ai.

## Features

- Automatic sync on publish/update/trash/delete for the post types you choose.
- Background processing through Action Scheduler — large sites stay responsive.
- Bulk synchronisation of all published content from the settings screen.
- WP-CLI command (`wp vragenai sync`) for scripted or initial imports.
- PDF attachments (directly attached media and ACF file fields) are passed to vragen.ai for server-side text extraction.
- Multilingual support for WPML and Polylang.
- Filters (`vragenai_should_index_post`, `vragenai_document_attributes`) to customise what is indexed.

## Requirements

- WordPress 6.0+
- PHP 8.1+

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

## Multilingual model

With WPML or Polylang active, all translations of a post are treated as **one** piece of content: they are merged into a single vragen.ai document keyed on the default-language (canonical) translation. The document content is taken from that translation, and every available language is listed in its metadata.

Translations are assumed to be semantically equivalent, so only the canonical content is indexed; vragen.ai's semantic layer plus a language filter handle per-language retrieval.

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
