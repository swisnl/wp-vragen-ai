<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\AttachmentExtractor;
use VragenAI\DocumentSync;
use VragenAI\LanguageResolver;
use VragenAI\Tests\TestCase;

class DocumentSyncAttachmentsPayloadTest extends TestCase
{
    public function test_sync_sends_attachments_as_top_level_document_attribute(): void
    {
        $attachments = [
            ['url' => 'https://example.test/files/report.pdf', 'filename' => 'report.pdf'],
        ];

        Functions\when('get_post')->alias(static function (int $postId) {
            return new \WP_Post([
                'ID' => $postId,
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_title' => 'Annual report',
                'post_content' => 'Hello world',
                'post_date' => '2026-06-03 10:30:43',
                'post_modified' => '2026-06-03 10:30:43',
                'post_author' => 1,
            ]);
        });
        Functions\when('apply_filters')->alias(static fn (...$args) => $args[1] ?? null);
        Functions\when('get_permalink')->justReturn('https://example.test/annual-report/');
        Functions\when('get_userdata')->justReturn((object) ['display_name' => 'Admin']);
        Functions\when('get_post_format')->justReturn(false);
        Functions\when('get_object_taxonomies')->justReturn([]);
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('esc_html')->returnArg(1);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')
            ->with('wp_post_15')
            ->once()
            ->andReturn(['data' => []]);
        $client->shouldReceive('createDocument')
            ->once()
            ->with(Mockery::on(static function (array $attributes) use ($attachments): bool {
                return ($attributes['attachments'] ?? null) === $attachments
                    && ! array_key_exists('attachments', $attributes['meta_data'] ?? []);
            }))
            ->andReturn([]);

        $languageResolver = Mockery::mock(LanguageResolver::class);
        $languageResolver->shouldReceive('getTranslations')->once()->andReturn(['en' => 15]);
        $languageResolver->shouldReceive('getDefaultLanguage')->once()->andReturn('en');
        $languageResolver->shouldReceive('getCanonicalPostId')->once()->andReturn(15);

        $attachmentExtractor = Mockery::mock(AttachmentExtractor::class);
        $attachmentExtractor->shouldReceive('extract')->once()->andReturn($attachments);

        $sync = new DocumentSync($client, $languageResolver, $attachmentExtractor);
        $sync->syncPost(15);
    }
}
