<?php

namespace VragenAI;

class AttachmentExtractor
{
    private const PDF_MIME = 'application/pdf';

    /** @return list<array{url: string, filename: string}> */
    public function extract(\WP_Post $post): array
    {
        $out  = [];
        $seen = [];

        $this->fromDirectAttachments($post, $out, $seen);
        $this->fromAcfFields($post, $out, $seen);

        return $out;
    }

    private function fromDirectAttachments(\WP_Post $post, array &$out, array &$seen): void
    {
        foreach (get_attached_media(self::PDF_MIME, $post->ID) as $attachment) {
            $url = wp_get_attachment_url($attachment->ID);
            if (!$url || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = [
                'url'      => $url,
                'filename' => basename(get_attached_file($attachment->ID) ?: $url),
            ];
        }
    }

    private function fromAcfFields(\WP_Post $post, array &$out, array &$seen): void
    {
        if (!function_exists('get_field_objects')) {
            return;
        }

        foreach (get_field_objects($post->ID) ?: [] as $field) {
            if ($field['type'] !== 'file' || empty($field['value']) || !is_array($field['value'])) {
                continue;
            }

            $value = $field['value'];
            $url   = trim((string) ($value['url'] ?? ''));
            $mime  = $value['mime_type'] ?? '';

            if ($mime !== self::PDF_MIME || $url === '' || isset($seen[$url])) {
                continue;
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $seen[$url] = true;
            $out[] = [
                'url'      => $url,
                'filename' => $value['filename'] ?? basename($url),
            ];
        }
    }
}
