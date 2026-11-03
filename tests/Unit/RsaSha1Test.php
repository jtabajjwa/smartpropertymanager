<?php

use App\Payments\RsaSha1;

/*
 * RsaSha1 must behave exactly like openssl_sign()/openssl_verify() with
 * SHA-1, which servers with a strict crypto policy (EL9+) refuse to run.
 */

beforeEach(function () {
    $fixtures = dirname(__DIR__).'/Fixtures/payments';
    $this->private = openssl_pkey_get_private(file_get_contents("{$fixtures}/yo-test-private.pem"));
    $this->public = openssl_pkey_get_public(file_get_contents("{$fixtures}/yo-test-public.pem"));
});

test('its signatures are byte-for-byte those of openssl_sign with SHA-1', function () {
    foreach (['', '20261103111330256783086794', sha1('20261103111330256783086794'), str_repeat('Primora ✓ ', 50)] as $data) {
        openssl_sign($data, $expected, $this->private, OPENSSL_ALGO_SHA1);

        expect(RsaSha1::sign($data, $this->private))->toBe($expected);
    }
});

test('it accepts what openssl_sign signed, and nothing else', function () {
    $data = sha1('20261103111330256783086794');
    openssl_sign($data, $signature, $this->private, OPENSSL_ALGO_SHA1);
    openssl_sign($data, $sha256, $this->private, OPENSSL_ALGO_SHA256);
    $tampered = $signature;
    $tampered[10] = chr(ord($tampered[10]) ^ 1);

    expect(RsaSha1::verify($data, $signature, $this->public))->toBeTrue()
        ->and(RsaSha1::verify($data.'x', $signature, $this->public))->toBeFalse()
        ->and(RsaSha1::verify($data, $tampered, $this->public))->toBeFalse()
        ->and(RsaSha1::verify($data, $sha256, $this->public))->toBeFalse()
        ->and(RsaSha1::verify($data, 'short', $this->public))->toBeFalse();
});
