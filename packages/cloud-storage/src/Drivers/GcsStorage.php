<?php

declare(strict_types=1);

namespace LombokClarion\CloudStorage\Drivers;

use LombokClarion\CloudStorage\Exceptions\CloudStorageException;
use LombokClarion\Http\UploadedFile;
use LombokClarion\Storage\Storage;

/**
 * Google Cloud Storage driver. Uses the JSON API with service-account
 * credentials — no vendor SDK dependency.
 */
final class GcsStorage implements Storage
{
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;

    /**
     * @param string $bucket         GCS bucket name
     * @param string $credentialsPath path to service-account JSON key file
     * @param string $prefix         optional key prefix
     */
    public function __construct(
        private readonly string $bucket,
        private readonly string $credentialsPath,
        private readonly string $prefix = '',
    ) {
    }

    public function put(string $path, string $contents): void
    {
        $key = $this->keyFor($path);
        $url = "https://storage.googleapis.com/upload/storage/v1/b/{$this->bucket}/o"
             . '?uploadType=media&name=' . urlencode($key);

        $response = $this->curlRequest('POST', $url, $contents, [
            'Content-Type: application/octet-stream',
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new CloudStorageException("GCS upload failed: HTTP {$response['status']}");
        }
    }

    public function get(string $path): string
    {
        $key = $this->keyFor($path);
        $url = "https://storage.googleapis.com/storage/v1/b/{$this->bucket}/o/"
             . urlencode($key) . '?alt=media';

        $response = $this->curlRequest('GET', $url);

        if ($response['status'] === 404) {
            throw new CloudStorageException("File not found in GCS: {$path}");
        }

        return $response['body'];
    }

    public function exists(string $path): bool
    {
        $key = $this->keyFor($path);
        $url = "https://storage.googleapis.com/storage/v1/b/{$this->bucket}/o/"
             . urlencode($key);

        $response = $this->curlRequest('GET', $url);
        return $response['status'] === 200;
    }

    public function delete(string $path): void
    {
        $key = $this->keyFor($path);
        $url = "https://storage.googleapis.com/storage/v1/b/{$this->bucket}/o/"
             . urlencode($key);

        $response = $this->curlRequest('DELETE', $url);

        if ($response['status'] !== 204 && $response['status'] !== 200 && $response['status'] !== 404) {
            throw new CloudStorageException("GCS delete failed: HTTP {$response['status']}");
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
     * Generate a signed URL for temporary access.
     */
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        $key = $this->keyFor($path);
        $expiry = time() + $expiresInSeconds;

        $credentials = $this->loadCredentials();
        $stringToSign = "GET\n\n\n{$expiry}\n/{$this->bucket}/{$key}";

        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if ($privateKey === false) {
            throw new CloudStorageException('Invalid GCS private key.');
        }

        openssl_sign($stringToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $encodedSignature = urlencode(base64_encode($signature));

        return "https://storage.googleapis.com/{$this->bucket}/{$key}"
             . "?GoogleAccessId=" . urlencode($credentials['client_email'])
             . "&Expires={$expiry}"
             . "&Signature={$encodedSignature}";
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        $credentials = $this->loadCredentials();
        $now = time();

        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = base64_encode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/devstorage.full_control',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        openssl_sign("{$header}.{$claim}", $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $jwt = "{$header}.{$claim}." . base64_encode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $body = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($body, true);
        if (!isset($data['access_token'])) {
            throw new CloudStorageException('Failed to obtain GCS access token.');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = $now + (int) ($data['expires_in'] ?? 3600) - 60;

        return $this->accessToken;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function curlRequest(string $method, string $url, string $body = '', array $extraHeaders = []): array
    {
        $token = $this->getAccessToken();

        $headers = array_merge([
            'Authorization: Bearer ' . $token,
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /** @return array<string, string> */
    private function loadCredentials(): array
    {
        if (!is_file($this->credentialsPath)) {
            throw new CloudStorageException("GCS credentials file not found: {$this->credentialsPath}");
        }

        $data = json_decode(file_get_contents($this->credentialsPath), true);
        if (!isset($data['client_email'], $data['private_key'])) {
            throw new CloudStorageException('Invalid GCS service account credentials.');
        }

        return $data;
    }

    private function keyFor(string $path): string
    {
        $clean = ltrim($path, '/');
        return $this->prefix !== '' ? rtrim($this->prefix, '/') . '/' . $clean : $clean;
    }
}
