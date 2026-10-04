<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 6 — TOTP verified against the RFC 6238 test vectors.
 *
 * Since this is a hand-rolled implementation of a security primitive, it is
 * checked against the published vectors rather than only against itself.
 */
class TotpServiceTest extends TestCase
{
    /**
     * RFC 6238 Appendix B uses the ASCII secret "12345678901234567890"
     * (base32: GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ) with SHA1.
     *
     * The published values are 8-digit; we generate 6, so compare the last six.
     */
    public static function rfcVectors(): array
    {
        return [
            // [unix time, expected 8-digit code from the RFC]
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_matches_rfc6238_vectors(int $time, string $expected8): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame(
            substr($expected8, -6),
            (new TotpService)->codeAt($secret, $time),
            "TOTP at T={$time} must match the RFC 6238 vector"
        );
    }

    public function test_current_code_verifies(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();

        $this->assertTrue($totp->verify($secret, $totp->codeAt($secret, time())));
    }

    /** ±1 period of clock drift is tolerated; beyond that is rejected. */
    public function test_drift_window(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();
        $now = time();

        $this->assertTrue($totp->verify($secret, $totp->codeAt($secret, $now, -1), $now));
        $this->assertTrue($totp->verify($secret, $totp->codeAt($secret, $now, 1), $now));
        $this->assertFalse($totp->verify($secret, $totp->codeAt($secret, $now, 5), $now));
    }

    public function test_wrong_code_is_rejected(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();

        $this->assertFalse($totp->verify($secret, '000000', 1111111109));
        $this->assertFalse($totp->verify($secret, 'abcdef'));
        $this->assertFalse($totp->verify($secret, '12345'), 'Wrong length');
    }

    public function test_generated_secret_is_valid_base32(): void
    {
        $secret = (new TotpService)->generateSecret();

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_provisioning_uri_is_scannable(): void
    {
        $uri = (new TotpService)->provisioningUri('ABCDEFGHIJKLMNOP', 'admin@example.com', 'AutoPilot Deploy');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABCDEFGHIJKLMNOP', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
        $this->assertStringContainsString('issuer=AutoPilot%20Deploy', $uri);
    }

    public function test_recovery_codes_are_unique(): void
    {
        $codes = (new TotpService)->generateRecoveryCodes(8);

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
    }
}
