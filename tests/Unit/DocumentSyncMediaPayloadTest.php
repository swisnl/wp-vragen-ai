<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\AttachmentExtractor;
use VragenAI\DocumentSync;
use VragenAI\LanguageResolver;
use VragenAI\Tests\TestCase;

class DocumentSyncMediaPayloadTest extends TestCase
{
    public function test_sync_sends_attachment_posts_as_file_documents_without_content(): void
    {
        Functions\when('get_post')->alias(static function (int $postId) {
            return new \WP_Post([
                'ID' => $postId,
                'post_status' => 'inherit',
                'post_type' => 'attachment',
                'post_title' => 'Annual report PDF',
                'post_content' => '',
                'post_date' => '2026-06-03 10:30:43',
                'post_modified' => '2026-06-03 10:30:43',
                'post_author' => 1,
            ]);
        });
        Functions\when('apply_filters')->alias(static fn (...$args) => $args[1] ?? null);
        Functions\when('get_userdata')->justReturn((object) ['display_name' => 'Admin']);
        Functions\when('get_post_format')->justReturn(false);
        Functions\when('get_object_taxonomies')->justReturn([]);
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('wp_get_attachment_url')->justReturn('https://example.test/uploads/annual-report.pdf');
        Functions\when('get_post_mime_type')->justReturn('application/pdf');
        Functions\when('is_wp_error')->justReturn(false);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_42')
            ->once()
            ->andReturn(['data' => []]);
        $client->shouldReceive('createDocument')
            ->once()
            ->with(Mockery::on(static function (array $attributes): bool {
                return ($attributes['external_reference'] ?? null) === 'wp_post_42'
                    && ($attributes['url'] ?? null) === 'https://example.test/uploads/annual-report.pdf'
                    && ($attributes['mime_type'] ?? null) === 'application/pdf'
                    && ! array_key_exists('content', $attributes)
                    && ($attributes['attachments'] ?? null) === []
                    && ($attributes['meta_data']['canonical_language'] ?? null) === 'nl'
                    && ($attributes['meta_data']['languages'] ?? null) === ['nl']
                    && ($attributes['meta_data']['post_type'] ?? null) === 'attachment';
            }))
            ->andReturn([]);

        $languageResolver = Mockery::mock(LanguageResolver::class);
        $languageResolver->shouldReceive('getDefaultLanguage')->once()->andReturn('nl');
        $languageResolver->shouldNotReceive('getTranslations');
        $languageResolver->shouldNotReceive('getCanonicalPostId');

        $attachmentExtractor = Mockery::mock(AttachmentExtractor::class);
        $attachmentExtractor->shouldNotReceive('extract');

        $sync = new DocumentSync($client, $languageResolver, $attachmentExtractor);
        $sync->syncPost(42);
    }
}
