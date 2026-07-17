<?php

declare(strict_types=1);

/**
 * Minimal reader/writer for the handful of S3 XML payloads we touch.
 *
 * Uses DOMDocument and matches elements by local name, so the S3 default
 * namespace is handled without special-casing.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Adapter\S3;

use DOMDocument;
use DOMElement;

final class Xml
{
    /**
     * Parse an S3 error XML response into its code and message.
     *
     * @param string $body
     *
     * @return array{code: string, message: string}
     */
    public function parseError(string $body): array
    {
        $doc = $this->load($body);

        if ($doc === null) {
            return ['code' => '', 'message' => ''];
        }

        return [
            'code' => $this->firstText($doc, 'Code') ?? '',
            'message' => $this->firstText($doc, 'Message') ?? '',
        ];
    }

    /**
     * Is error.
     *
     * @param string $body
     *
     * @return bool
     */
    public function isError(string $body): bool
    {
        $doc = $this->load($body);

        return $doc !== null && $doc->getElementsByTagName('Error')->length > 0;
    }

    /**
     * Parse upload id.
     *
     * @param string $body
     *
     * @return ?string
     */
    public function parseUploadId(string $body): ?string
    {
        $doc = $this->load($body);

        return $doc === null ? null : $this->firstText($doc, 'UploadId');
    }

    /**
     * Parse a ListObjectsV2 XML response into keys and continuation state.
     *
     * @param string $body
     *
     * @return array{
     *     objects: list<array{key: string, size: int, lastModified: ?int, etag: string}>,
     *     prefixes: list<string>,
     *     isTruncated: bool,
     *     nextContinuationToken: ?string,
     * }
     */
    public function parseListObjectsV2(string $body): array
    {
        $doc = $this->load($body);

        $objects = [];
        $prefixes = [];
        $isTruncated = false;
        $nextContinuationToken = null;

        if ($doc !== null) {
            foreach ($doc->getElementsByTagName('Contents') as $node) {
                $lastModified = $this->firstText($node, 'LastModified');

                $objects[] = [
                    'key' => $this->firstText($node, 'Key') ?? '',
                    'size' => (int) ($this->firstText($node, 'Size') ?? '0'),
                    'lastModified' => $lastModified === null ? null : $this->toTimestamp($lastModified),
                    'etag' => trim($this->firstText($node, 'ETag') ?? '', '"'),
                ];
            }

            foreach ($doc->getElementsByTagName('CommonPrefixes') as $node) {
                $prefix = $this->firstText($node, 'Prefix');
                if ($prefix !== null && $prefix !== '') {
                    $prefixes[] = $prefix;
                }
            }

            $isTruncated = ($this->firstText($doc, 'IsTruncated') ?? 'false') === 'true';
            $nextContinuationToken = $this->firstText($doc, 'NextContinuationToken');
        }

        return [
            'objects' => $objects,
            'prefixes' => $prefixes,
            'isTruncated' => $isTruncated,
            'nextContinuationToken' => $nextContinuationToken,
        ];
    }

    /**
     * Build the CompleteMultipartUpload XML request body.
     *
     * @param array<int,string> $parts partNumber => ETag (any order)
     *
     * @return string
     */
    public function buildCompleteMultipartBody(array $parts): string
    {
        ksort($parts);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';

        foreach ($parts as $number => $etag) {
            $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>' . $this->escape($etag) . '</ETag></Part>';
        }

        return $xml . '</CompleteMultipartUpload>';
    }

    /**
     * Escape.
     *
     * @param string $value
     *
     * @return string
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * To timestamp.
     *
     * @param string $iso8601
     *
     * @return ?int
     */
    private function toTimestamp(string $iso8601): ?int
    {
        $timestamp = strtotime($iso8601);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Load.
     *
     * @param string $body
     *
     * @return ?DOMDocument
     */
    private function load(string $body): ?DOMDocument
    {
        if (trim($body) === '') {
            return null;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $doc : null;
    }

    /**
     * First text.
     *
     * @param DOMDocument|DOMElement $node
     * @param string $tag
     *
     * @return ?string
     */
    private function firstText(DOMDocument|DOMElement $node, string $tag): ?string
    {
        return $node->getElementsByTagName($tag)->item(0)?->textContent;
    }
}
