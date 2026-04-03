<?php

declare(strict_types=1);

namespace DreamFactory\Core\AIChat\Tests\Security;

use DreamFactory\Core\AIChat\Services\DataToolClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the service-name path-traversal fix in DataToolClient.
 *
 * DataToolClient builds URL paths like:
 *   /{$serviceName}/_schema
 *   /{$serviceName}/_table/{tableName}
 *
 * Without validation, an attacker-controlled $serviceName could escape the
 * intended path segment:
 *   "../admin"           -> /admin/_schema   (one segment up)
 *   "foo/../../bar"      -> /bar/_schema     (multiple traversals)
 *   "%2F..%2F"           -> URL-encoded slash traversal
 *
 * The fix: preg_match('/^[a-zA-Z0-9_-]+$/', $serviceName) — reject anything
 * that is not strictly alphanumeric / underscore / hyphen.
 *
 * Because validateServiceName() is private we access it via ReflectionMethod.
 * This tests the real production code, not a copy.
 */
class ServiceNameValidationTest extends TestCase
{
    private ReflectionMethod $method;
    private DataToolClient $client;

    protected function setUp(): void
    {
        // The df-ai-chat package may not be registered in the parent app's
        // composer autoloader. Ensure the class file is loaded directly.
        $classFile = __DIR__ . '/../../src/Services/DataToolClient.php';
        if (!class_exists(DataToolClient::class, false)) {
            require_once $classFile;
        }

        // DataToolClient constructor calls config() and url() which are Laravel
        // helpers. We bypass the constructor entirely so the test has zero
        // framework dependencies.
        $this->client = (new \ReflectionClass(DataToolClient::class))
            ->newInstanceWithoutConstructor();

        $this->method = new ReflectionMethod(DataToolClient::class, 'validateServiceName');
        $this->method->setAccessible(true);
    }

    // -----------------------------------------------------------------------
    // Happy-path: names that must pass validation
    // -----------------------------------------------------------------------

    /** @dataProvider validServiceNames */
    public function testValidServiceNamePasses(string $name): void
    {
        // Should not throw
        $this->method->invoke($this->client, $name);
        $this->addToAssertionCount(1); // mark that we exercised an assertion
    }

    public static function validServiceNames(): array
    {
        return [
            'simple lowercase'            => ['mydb'],
            'uppercase'                   => ['MyDB'],
            'underscore'                  => ['my_database'],
            'hyphen'                      => ['my-database'],
            'alphanumeric mix'            => ['db123'],
            'leading digit'               => ['1db'],
            'underscores and hyphens'     => ['my_db-service'],
            'all caps'                    => ['PRODUCTION'],
            'single character'            => ['x'],
        ];
    }

    // -----------------------------------------------------------------------
    // Path traversal attacks — must all be rejected
    // -----------------------------------------------------------------------

    /** @dataProvider pathTraversalNames */
    public function testPathTraversalIsRejected(string $name, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->method->invoke($this->client, $name);
    }

    public static function pathTraversalNames(): array
    {
        return [
            'dot-dot-slash'                         => ['../admin',          'classic traversal'],
            'slash-dot-dot'                         => ['/admin',            'leading slash'],
            'multiple traversal segments'           => ['foo/../../bar',     'multi-segment traversal'],
            'absolute path'                         => ['/etc/passwd',       'absolute path injection'],
            'url-encoded slash (%2F)'               => ['foo%2F..%2Fbar',    'URL-encoded slash'],
            'url-encoded dot (%2E)'                 => ['%2E%2E/admin',      'URL-encoded dot traversal'],
            'double url-encoded slash (%252F)'      => ['foo%252Fbar',       'double-encoded slash'],
            'backslash traversal'                   => ['..\\admin',         'Windows-style traversal'],
            'embedded slash'                        => ['foo/bar',           'embedded forward slash'],
            'null byte'                             => ["foo\0bar",          'null byte injection'],
            'space'                                 => ['my service',        'space in name'],
            'at sign'                               => ['user@host',         'at sign'],
            'colon'                                 => ['host:port',         'colon'],
            'question mark'                         => ['db?query',          'query string separator'],
            'hash'                                  => ['db#fragment',       'fragment separator'],
            'ampersand'                             => ['db&other',          'query param separator'],
            'empty string'                          => ['',                  'empty name'],
            'only dots'                             => ['...',               'only dots'],
            'dot'                                   => ['.',                 'single dot'],
        ];
    }

    // -----------------------------------------------------------------------
    // Exception message quality
    // -----------------------------------------------------------------------

    public function testExceptionMessageIncludesOffendingName(): void
    {
        $badName = '../admin';
        try {
            $this->method->invoke($this->client, $badName);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(
                $badName,
                $e->getMessage(),
                'The exception message should include the offending service name for diagnostics.',
            );
        }
    }
}
