<?php

declare(strict_types=1);

namespace LombokClarion\Security;

use LombokClarion\Security\Exceptions\SecurityException;

final class AesGcmEncrypter implements Encrypter
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * @param string $key raw 32-byte key (e.g. from random_bytes(32), stored/rotated externally)
     */
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new SecurityException('AesGcmEncrypter requires a 32-byte key.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new SecurityException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, strict: true);
        if ($raw === false) {
            throw new SecurityException('Ciphertext is not valid base64.');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, 16);
        $encrypted = substr($raw, $ivLength + 16);

        $plaintext = openssl_decrypt($encrypted, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new SecurityException('Decryption failed: ciphertext may be tampered with or the key is wrong.');
        }

        return $plaintext;
    }
}
