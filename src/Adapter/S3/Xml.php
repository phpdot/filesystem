<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Adapter\S3;

use DOMDocument;
use DOMElement;

/**
 * Minimal reader/writer for the handful of S3 XML payloads we touch.
 *
 * Uses DOMDocument and matches elements by local name, so the S3 default
 * namespace is handled without special-casing.
 */
final class Xml
{
    /**
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

    public function isError(string $body): bool
    {
        $doc = $this->load($body);

        return $doc !== null && $doc->getElementsByTagName('Error')->length > 0;
    }

    public function parseUploadId(string $body): ?string
    {
        $doc = $this->load($body);

        return $doc === null ? null : $this->firstText($doc, 'UploadId');
    }

    /**
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
                    'size' => (int)($this->firstText($node, 'Size') ?? '0'),
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
     * @param array<int,string> $parts partNumber => ETag (any order)
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function toTimestamp(string $iso8601): ?int
    {
        $timestamp = strtotime($iso8601);

        return $timestamp === false ? null : $timestamp;
    }

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

    private function firstText(DOMDocument|DOMElement $node, string $tag): ?string
    {
        return $node->getElementsByTagName($tag)->item(0)?->textContent;
    }
}
