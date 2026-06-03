<?php

namespace VragenAI\Tests\Unit;

use Brain\Monkey\Functions;
use VragenAI\AttachmentExtractor;
use VragenAI\Tests\TestCase;

class AttachmentExtractorTest extends TestCase
{
    public function testCollectsAndDeduplicatesDirectPdfAttachments(): void
    {
        Functions\when('get_attached_media')->justReturn([
            (object) ['ID' => 1],
            (object) ['ID' => 2],
        ]);
        // Both attachments resolve to the same URL -> the second is deduplicated.
        Functions\when('wp_get_attachment_url')->justReturn('https://example.test/doc.pdf');
        Functions\when('get_attached_file')->justReturn('/uploads/doc.pdf');

        $result = (new AttachmentExtractor())->extract(new \WP_Post(['ID' => 10]));

        $this->assertSame(
            [['url' => 'https://example.test/doc.pdf', 'filename' => 'doc.pdf']],
            $result
        );
    }

    public function testCollectsPdfFromAcfFileFieldAndSkipsNonPdf(): void
    {
        Functions\when('get_attached_media')->justReturn([]);
        Functions\when('get_field_objects')->justReturn([
            'brochure' => [
                'type'  => 'file',
                'value' => [
                    'url'       => 'https://example.test/brochure.pdf',
                    'mime_type' => 'application/pdf',
                    'filename'  => 'brochure.pdf',
                ],
            ],
            'cover' => [
                'type'  => 'file',
                'value' => [
                    'url'       => 'https://example.test/cover.png',
                    'mime_type' => 'image/png',
                    'filename'  => 'cover.png',
                ],
            ],
        ]);

        $result = (new AttachmentExtractor())->extract(new \WP_Post(['ID' => 11]));

        $this->assertSame(
            [['url' => 'https://example.test/brochure.pdf', 'filename' => 'brochure.pdf']],
            $result
        );
    }
}
