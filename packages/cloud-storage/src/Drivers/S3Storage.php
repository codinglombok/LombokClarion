<?php

declare(strict_types=1);

namespace LombokClarion\CloudStorage\Drivers;

use LombokClarion\CloudStorage\Exceptions\CloudStorageException;
use LombokClarion\Http\UploadedFile;
use LombokClarion\Storage\Storage;

/**
 * AWS S3 (and S3-compatible) storage driver. Uses native curl and AWS
 * Signature V4 — no vendor SDK dependency.
 *
 * Works with: AWS S3, MinIO, DigitalOcean Spaces, Cloudflare R2, Backblaze B2.
 */
final class S3Storage implements Storage
{
    public function __construct(
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $endpoint = '',
        private readonly string $prefix = '',
    ) {
    }

    public function put(string $path, string $contents): void
    {
        $key = $this->keyFor($path);
        $this->request('PUT', $key, $contents, [
            'Content-Type' => $this->guessMimeType($path),
        ]);
    }

    public function get(string $path): string
    {
        $key = $this->keyFor($path);
        $response = $this->request('GET', $key);

        if ($response['status'] === 404) {
            throw new CloudStorageException("File not found in S3: {$path}");
        }

        return $response['body'];
    }

    public function exists(string $path): bool
    {
        $key = $this->keyFor($path);
        $response = $this->request('HEAD', $key);
        return $response['status'] === 200;
    }

    public function delete(string $path): void
    {
        $key = $this->keyFor($path);
        $response = $this->request('DELETE', $key);

        if ($response['status'] !== 204 && $response['status'] !== 200) {
            throw new CloudStorageException("Failed to delete S3 object: {$path} (HTTP {$response['status']})");
        }
    }

    public function store(UploadedFile $file, string $directory, string $extension): string
    {
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = rtrim($directory, '/') . '/' . $name;
        $contents = file_get_contents($file->tempPath());

        if ($contents === false) {
            throw new CloudStorageException("Cannot read uploaded file: {$file->tempPath()}");
        }

        $this->put($path, $contents);

        return $path;
    }

    /**
     * Generate a pre-signed URL for temporary access to a private object.
     */
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        $key = $this->keyFor($path);
        $host = $this->host();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiry = $now->getTimestamp() + $expiresInSeconds;

        $date = $now->format('Ymd');
        $datetime = $now->format('Ymd\THis\Z');
        $scope = "{$date}/{$this->region}/s3/aws4_request";

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $datetime,
            'X-Amz-Expires' => (string) $expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];

        ksort($queryParams);
        $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = "GET\n/{$key}\n{$canonicalQueryString}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
        $stringToSign = "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->signingKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $scheme = $this->endpoint !== '' ? parse_url($this->endpoint, PHP_URL_SCHEME) : 'https';

        return "{$scheme}://{$host}/{$key}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function request(string $method, string $key, string $body = '', array $headers = []): array
    {
        $host = $this->host();
        $url = ($this->endpoint !== '' ? rtrim($this->endpoint, '/') : "https://{$host}") . '/' . $key;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $datetime = $now->format('Ymd\THis\Z');

        $payloadHash = hash('sha256', $body);
        $headers['Host'] = $host;
        $headers['x-amz-date'] = $datetime;
        $headers['x-amz-content-sha256'] = $payloadHash;

        // Canonical headers (sorted, lowercase)
        $signedHeaders = [];
        $canonicalHeaders = '';
        ksort($headers);
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            $signedHeaders[] = $lower;
            $canonicalHeaders .= $lower . ':' . trim($value) . "\n";
        }
        $signedHeadersStr = implode(';', $signedHeaders);

        $canonicalRequest = implode("\n", [
            $method,
            '/' . $key,
            '', // query string
            $canonicalHeaders,
            $signedHeadersStr,
            $payloadHash,
        ]);

        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$datetime}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->signingKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, "
            . "SignedHeaders={$signedHeadersStr}, Signature={$signature}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        });

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new CloudStorageException("S3 request failed: {$error}");
        }

        return ['status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders];
    }

    private function host(): string
    {
        if ($this->endpoint !== '') {
            return (string) parse_url($this->endpoint, PHP_URL_HOST);
        }

        return "{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }

    private function keyFor(string $path): string
    {
        $clean = ltrim($path, '/');
        return $this->prefix !== '' ? rtrim($this->prefix, '/') . '/' . $clean : $clean;
    }

    private function signingKey(string $date): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    private function guessMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'html', 'htm' => 'text/html',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }
}
