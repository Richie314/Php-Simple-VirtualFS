<?php

declare(strict_types=1);

namespace Richie314\SimpleVirtualFS;

use InvalidArgumentException;
use RuntimeException;

/** @internal */
final class ContentEncryption
{
    private const HEADER = "SimpleVirtualFS\0\x01";

    /** Reject keys that are not the required 32 raw bytes. */
    public static function validateKey(#[\SensitiveParameter] string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('Encryption keys must contain 32 raw bytes.');
        }
    }

    /** Encode encrypted bytes with a fresh nonce and bind authentication to the canonical URI. */
    public static function encode(
        #[\SensitiveParameter] string $content,
        string $uri,
        #[\SensitiveParameter] ?string $key,
    ): string {
        if ($key === null) {
            if (str_starts_with($content, self::HEADER)) {
                throw new InvalidArgumentException('The plaintext starts with a reserved encryption header.');
            }

            return $content;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return self::HEADER . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $content,
            self::HEADER . $uri,
            $nonce,
            $key,
        );
    }

    /** Authenticate encrypted bytes against the canonical URI and recover the plaintext. */
    public static function decode(
        #[\SensitiveParameter] string $content,
        string $uri,
        #[\SensitiveParameter] ?string $key,
    ): string {
        $encrypted = str_starts_with($content, self::HEADER);
        if (!$encrypted && $key === null) {
            return $content;
        }
        if (!$encrypted || $key === null) {
            throw new RuntimeException("Encryption configuration does not match the stored file at $uri.");
        }

        $offset = strlen(self::HEADER);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($content) < $offset + $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new RuntimeException("Truncated encrypted file at $uri.");
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($content, $offset + $nonceLength),
            self::HEADER . $uri,
            substr($content, $offset, $nonceLength),
            $key,
        );
        if ($plaintext === false) {
            throw new RuntimeException("Could not authenticate encrypted file at $uri.");
        }

        return $plaintext;
    }
}
