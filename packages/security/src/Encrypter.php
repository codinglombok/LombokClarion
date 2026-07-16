<?php

declare(strict_types=1);

namespace LombokClarion\Security;

interface Encrypter
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}
