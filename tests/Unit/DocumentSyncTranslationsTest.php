<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use VragenAI\ApiClient;
use VragenAI\AttachmentExtractor;
use VragenAI\DocumentSync;
use VragenAI\LanguageResolver;
use VragenAI\Tests\TestCase;
use VragenAI\WeglotLanguageResolver;

class DocumentSyncTranslationsTest extends TestCase
{
    public function test_a_translated_post_is_sent_as_its_own_request_with_content(): void
    {
        $this->stubWordPress();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')->once()->andReturn(['data' => []]);
        $client->shouldReceive('createDocument')
            ->once()
            ->with(Mockery::on(static fn (array $attributes): bool => ! array_key_exists('translations', $attributes)))
            ->andReturn(['data' => ['id' => 'doc-1']]);

        $client->shouldReceive('createTranslation')
            ->once()
            ->with('doc-1', Mockery::on(static function (array $attributes): bool {
                return ($attributes['locale'] ?? null) === 'fr'
                    && ($attributes['title'] ?? null) === 'À propos'
                    && ! empty($attributes['content']);
            }))
            ->andReturn([]);

        $sync = new DocumentSync($client, $this->postBackedResolver(), $this->noAttachments());
        $sync->syncPost(15);
    }

    public function test_an_id_from_the_document_response_updates_instead_of_creating(): void
    {
        $this->stubWordPress();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')->once()->andReturn([
            'data' => [[
                'id' => 'doc-1',
                'attributes' => [
                    'translations' => ['fr' => ['id' => 42, 'title' => 'Ancien titre']],
                ],
            ]],
        ]);
        $client->shouldReceive('updateDocument')->once()->andReturn(['data' => ['id' => 'doc-1']]);

        // The id came back with the document, so no lookup was needed to know this is an update.
        $client->shouldReceive('updateTranslation')
            ->once()
            ->with('42', 'doc-1', Mockery::type('array'))
            ->andReturn([]);
        $client->shouldNotReceive('createTranslation');

        $sync = new DocumentSync($client, $this->postBackedResolver(), $this->noAttachments());
        $sync->syncPost(15);
    }

    public function test_weglot_sends_no_content_so_vragenai_fetches_the_prefixed_url(): void
    {
        $this->stubWordPress();

        $weglot = Mockery::mock(WeglotLanguageResolver::class);
        $weglot->shouldReceive('isActive')->andReturn(true);
        $weglot->shouldReceive('translateTitle')->andReturn('À propos');
        $weglot->shouldReceive('translatedUrl')->andReturn('https://example.test/fr/about/');

        $resolver = Mockery::mock(LanguageResolver::class);
        $resolver->shouldReceive('getTranslations')->andReturn(['en' => 15, 'fr' => 15]);
        $resolver->shouldReceive('getDefaultLanguage')->andReturn('en');
        $resolver->shouldReceive('getCanonicalPostId')->andReturn(15);
        $resolver->shouldReceive('weglot')->andReturn($weglot);

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')->once()->andReturn(['data' => []]);
        $client->shouldReceive('createDocument')->once()->andReturn(['data' => ['id' => 'doc-1']]);

        $client->shouldReceive('createTranslation')
            ->once()
            ->with('doc-1', Mockery::on(static function (array $attributes): bool {
                return ($attributes['locale'] ?? null) === 'fr'
                    && ($attributes['url'] ?? null) === 'https://example.test/fr/about/'
                    && ! array_key_exists('content', $attributes);
            }))
            ->andReturn([]);

        $sync = new DocumentSync($client, $resolver, $this->noAttachments());
        $sync->syncPost(15);
    }

    public function test_removing_a_language_deletes_its_translation(): void
    {
        $this->stubWordPress();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')->once()->andReturn([
            'data' => [[
                'id' => 'doc-1',
                'attributes' => [
                    'meta_data' => ['languages' => ['en', 'fr']],
                    'translations' => ['fr' => ['id' => 42]],
                ],
            ]],
        ]);
        $client->shouldReceive('updateDocument')->once()->andReturn([]);
        $client->shouldReceive('deleteTranslation')->once()->with('42')->andReturn([]);
        $client->shouldNotReceive('deleteDocument');

        $sync = new DocumentSync($client, Mockery::mock(LanguageResolver::class), $this->noAttachments());

        $sync->removeTranslation([
            'external_reference' => 'wp_post_15',
            'language' => 'fr',
            'canonical_language' => 'en',
            'remaining_languages' => ['en'],
        ]);
    }

    public function test_removing_the_canonical_language_deletes_the_whole_document(): void
    {
        $this->stubWordPress();

        $client = Mockery::mock(ApiClient::class);
        $client->shouldReceive('findByExternalReference')->once()->andReturn([
            'data' => [['id' => 'doc-1', 'attributes' => ['translations' => ['fr' => ['id' => 42]]]]],
        ]);
        $client->shouldReceive('deleteDocument')->once()->with('doc-1')->andReturn([]);
        $client->shouldNotReceive('deleteTranslation');

        $sync = new DocumentSync($client, Mockery::mock(LanguageResolver::class), $this->noAttachments());

        $sync->removeTranslation([
            'external_reference' => 'wp_post_15',
            'language' => 'en',
            'canonical_language' => 'en',
            'remaining_languages' => ['fr'],
        ]);
    }

    private function postBackedResolver(): LanguageResolver
    {
        $weglot = Mockery::mock(WeglotLanguageResolver::class);
        $weglot->shouldReceive('isActive')->andReturn(false);

        $resolver = Mockery::mock(LanguageResolver::class);
        $resolver->shouldReceive('getTranslations')->andReturn(['en' => 15, 'fr' => 16]);
        $resolver->shouldReceive('getDefaultLanguage')->andReturn('en');
        $resolver->shouldReceive('getCanonicalPostId')->andReturn(15);
        $resolver->shouldReceive('weglot')->andReturn($weglot);

        return $resolver;
    }

    private function noAttachments(): AttachmentExtractor
    {
        $extractor = Mockery::mock(AttachmentExtractor::class);
        $extractor->shouldReceive('extract')->andReturn([]);

        return $extractor;
    }

    private function stubWordPress(): void
    {
        Functions\when('get_post')->alias(static function (int $postId) {
            return new \WP_Post([
                'ID' => $postId,
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_title' => $postId === 16 ? 'À propos' : 'About us',
                'post_content' => 'Hello world',
                'post_date' => '2026-06-03 10:30:43',
                'post_modified' => '2026-06-03 10:30:43',
                'post_author' => 1,
            ]);
        });
        Functions\when('apply_filters')->alias(static fn (...$args) => $args[1] ?? null);
        Functions\when('get_permalink')->justReturn('https://example.test/about/');
        Functions\when('get_userdata')->justReturn((object) ['display_name' => 'Admin']);
        Functions\when('get_post_format')->justReturn(false);
        Functions\when('get_object_taxonomies')->justReturn([]);
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('esc_html')->returnArg(1);
    }
}
