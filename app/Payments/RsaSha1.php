<?php

namespace App\Payments;

use OpenSSLAsymmetricKey;

/**
 * SHA1-with-RSA (PKCS#1 v1.5) signatures, as Yo! uses for IPNs, USSD
 * call-outs and withdrawal requests.
 *
 * openssl_sign()/openssl_verify() with OPENSSL_ALGO_SHA1 fail on servers
 * whose crypto policy forbids SHA-1 signatures (the default on RHEL/EL 9 and
 * later, including Primora's EL10 VPS). This builds and checks the same
 * PKCS#1 v1.5 block with the raw RSA operation instead, so the signatures
 * are byte-for-byte those of openssl_sign() and the check is as strict.
 */
class RsaSha1
{
    /** DER prefix of a DigestInfo holding a SHA-1 digest (RFC 8017, section 9.2). */
    public const DIGEST_INFO_PREFIX = '3021300906052b0e03021a05000414';

    public static function sign(string $data, OpenSSLAsymmetricKey $privateKey): ?string
    {
        return openssl_private_encrypt(self::digestInfo($data), $signature, $privateKey, OPENSSL_PKCS1_PADDING) ? $signature : null;
    }

    public static function verify(string $data, string $signature, OpenSSLAsymmetricKey $publicKey): bool
    {
        return openssl_public_decrypt($signature, $block, $publicKey, OPENSSL_PKCS1_PADDING)
            && hash_equals(self::digestInfo($data), $block);
    }

    protected static function digestInfo(string $data): string
    {
        return hex2bin(self::DIGEST_INFO_PREFIX).sha1($data, true);
    }
}
