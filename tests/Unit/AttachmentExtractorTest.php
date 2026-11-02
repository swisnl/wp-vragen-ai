<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use VragenAI\AttachmentExtractor;
use VragenAI\Tests\TestCase;

class AttachmentExtractorTest extends TestCase
{
    public function test_collects_and_deduplicates_direct_pdf_attachments(): void
    {
        Functions\when('get_attached_media')->justReturn([
            new \WP_Post(['ID' => 1]),
            new \WP_Post(['ID' => 2]),
        ]);
        // Both attachments resolve to the same URL -> the second is deduplicated.
        Functions\when('wp_get_attachment_url')->justReturn('https://example.test/doc.pdf');
        Functions\when('get_attached_file')->justReturn('/uploads/doc.pdf');
        Functions\when('get_field_objects')->justReturn([]);

        $result = (new AttachmentExtractor)->extract(new \WP_Post(['ID' => 10]));

        $this->assertSame(
            [['url' => 'https://example.test/doc.pdf', 'filename' => 'doc.pdf']],
            $result
        );
    }

    public function test_skips_a_password_protected_direct_attachment(): void
    {
        Functions\when('get_attached_media')->justReturn([
            new \WP_Post(['ID' => 1, 'post_password' => 'secret']),
            new \WP_Post(['ID' => 2]),
        ]);
        Functions\when('wp_get_attachment_url')->alias(static fn ($id): string => 'https://example.test/'.$id.'.pdf');
        Functions\when('get_attached_file')->alias(static fn ($id): string => '/uploads/'.$id.'.pdf');
        Functions\when('get_field_objects')->justReturn([]);

        $result = (new AttachmentExtractor)->extract(new \WP_Post(['ID' => 10]));

        $this->assertSame(
            [['url' => 'https://example.test/2.pdf', 'filename' => '2.pdf']],
            $result
        );
    }

    public function test_collects_pdf_from_acf_file_field_and_skips_non_pdf(): void
    {
        Functions\when('get_attached_media')->justReturn([]);
        Functions\when('get_field_objects')->justReturn([
            'brochure' => [
                'type' => 'file',
                'value' => [
                    'url' => 'https://example.test/brochure.pdf',
                    'mime_type' => 'application/pdf',
                    'filename' => 'brochure.pdf',
                ],
            ],
            'cover' => [
                'type' => 'file',
                'value' => [
                    'url' => 'https://example.test/cover.png',
                    'mime_type' => 'image/png',
                    'filename' => 'cover.png',
                ],
            ],
        ]);

        $result = (new AttachmentExtractor)->extract(new \WP_Post(['ID' => 11]));

        $this->assertSame(
            [['url' => 'https://example.test/brochure.pdf', 'filename' => 'brochure.pdf']],
            $result
        );
    }

    public function test_skips_a_password_protected_acf_file_field_pdf(): void
    {
        Functions\when('get_attached_media')->justReturn([]);
        Functions\when('get_field_objects')->justReturn([
            'secret' => [
                'type' => 'file',
                'value' => ['ID' => 7, 'url' => 'https://example.test/secret.pdf', 'mime_type' => 'application/pdf', 'filename' => 'secret.pdf'],
            ],
            'public' => [
                'type' => 'file',
                'value' => ['ID' => 8, 'url' => 'https://example.test/public.pdf', 'mime_type' => 'application/pdf', 'filename' => 'public.pdf'],
            ],
        ]);
        Functions\when('get_post')->alias(static fn ($id): \WP_Post => new \WP_Post([
            'ID' => (int) $id,
            'post_password' => (int) $id === 7 ? 'secret' : '',
        ]));

        $result = (new AttachmentExtractor)->extract(new \WP_Post(['ID' => 11]));

        $this->assertSame(
            [['url' => 'https://example.test/public.pdf', 'filename' => 'public.pdf']],
            $result
        );
    }
}
