<?php

namespace VragenAI;

class AttachmentExtractor
{
    private const PDF_MIME = 'application/pdf';

    /** @return list<array{url: string, filename: string}> */
    public function extract(\WP_Post $post): array
    {
        $out = [];
        $seen = [];

        $this->fromDirectAttachments($post, $out, $seen);
        $this->fromAcfFields($post, $out, $seen);

        return $out;
    }

    /**
     * @param  list<array{url: string, filename: string}>  $out
     * @param  array<string, true>  $seen
     */
    private function fromDirectAttachments(\WP_Post $post, array &$out, array &$seen): void
    {
        foreach (get_attached_media(self::PDF_MIME, $post->ID) as $attachment) {
            // A child can carry its own password even when the parent is public;
            // don't hand its text to the knowledge base, mirroring the sync gate.
            if ($attachment->post_password !== '') {
                continue;
            }

            $url = wp_get_attachment_url($attachment->ID);
            if (! $url || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = [
                'url' => $url,
                'filename' => basename(get_attached_file($attachment->ID) ?: $url),
            ];
        }
    }

    /**
     * @param  list<array{url: string, filename: string}>  $out
     * @param  array<string, true>  $seen
     */
    private function fromAcfFields(\WP_Post $post, array &$out, array &$seen): void
    {
        if (! function_exists('get_field_objects')) {
            return;
        }

        foreach (get_field_objects($post->ID) ?: [] as $field) {
            if ($field['type'] !== 'file' || empty($field['value']) || ! is_array($field['value'])) {
                continue;
            }

            $value = $field['value'];
            $url = trim((string) ($value['url'] ?? ''));
            $mime = $value['mime_type'] ?? '';

            if ($mime !== self::PDF_MIME || $url === '' || isset($seen[$url])) {
                continue;
            }

            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            // Same guard as a directly attached PDF: an ACF-referenced file can
            // carry its own password, and its text must not reach the knowledge base.
            $attachmentId = (int) ($value['ID'] ?? 0);
            if ($attachmentId > 0) {
                $attachment = get_post($attachmentId);
                if ($attachment instanceof \WP_Post && $attachment->post_password !== '') {
                    continue;
                }
            }

            $seen[$url] = true;
            $out[] = [
                'url' => $url,
                'filename' => (string) ($value['filename'] ?? basename($url)),
            ];
        }
    }
}
