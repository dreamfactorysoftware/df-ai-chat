<?php

namespace DreamFactory\Core\AiChat\Tests\Security;

use DreamFactory\Core\AIChat\Services\DataToolClient;
use PHPUnit\Framework\TestCase;

/**
 * Security: DataToolClient must validate $serviceName before interpolating it
 * into URL paths.
 *
 * The April 2026 audit (df-ai-chat FINDING-01) found:
 *
 *     return $this->request('GET', "/{$serviceName}/_schema");
 *     return $this->request('GET', "/{$serviceName}/_schema/" . urlencode($tableName));
 *     ...etc, ~9 sites
 *
 * The table name uses urlencode(), but $serviceName is interpolated raw. A
 * caller passing `serviceName = "evil-svc/_table/secrets%23"` could traverse
 * to a different DreamFactory service or sub-resource, bypassing the
 * authorization the calling role grants for the intended service.
 *
 * After the fix, $serviceName is validated against a strict allowlist
 * pattern (`/^[A-Za-z0-9_-]+$/`) — DF service names are alphanumeric +
 * dash + underscore only.
 */
class ServiceNamePathTraversalTest extends TestCase
{
    /**
     * @dataProvider invalidServiceNameProvider
     */
    public function testValidatorRejectsTraversalPayload(string $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DataToolClient::validateServiceName($payload);
    }

    public static function invalidServiceNameProvider(): array
    {
        return [
            'forward slash'        => ['evil/svc'],
            'parent directory dot' => ['../system'],
            'percent-encoded slash'=> ['evil%2Fsvc'],
            'empty string'         => [''],
            'whitespace'           => ['my svc'],
            'newline injection'    => ["mydb\n_table"],
            'query string'         => ['mydb?cheat=1'],
            'fragment'             => ['mydb#frag'],
            'colon scheme prefix'  => ['http://attacker'],
        ];
    }

    /**
     * @dataProvider validServiceNameProvider
     */
    public function testValidatorAcceptsLegitimateNames(string $name): void
    {
        DataToolClient::validateServiceName($name);
        $this->assertTrue(true, 'Valid name accepted: ' . $name);
    }

    public static function validServiceNameProvider(): array
    {
        return [
            'lowercase'        => ['mydb'],
            'with underscore'  => ['my_db'],
            'with dash'        => ['my-db'],
            'with digits'      => ['mydb2'],
            'single uppercase' => ['A'],
            'leading underscore' => ['_underscore'],
        ];
    }

    public function testSourceCallsValidatorAtEveryEntryMethod(): void
    {
        $sourcePath = __DIR__ . '/../../src/Services/DataToolClient.php';
        $this->assertFileExists($sourcePath);
        $contents = file_get_contents($sourcePath);

        $this->assertMatchesRegularExpression(
            '/(self|static)::validateServiceName\s*\(/',
            $contents,
            'DataToolClient must call validateServiceName() at the entry methods '
            . 'before interpolating $serviceName into a URL path.'
        );
    }
}
