<?php

namespace Core\Tests;

use PHPUnit\Framework\TestCase;

final class apidocs_test extends TestCase
{
    private const METHODS = ['get' => 1, 'post' => 1, 'put' => 1, 'patch' => 1, 'delete' => 1];

    /** apidocs/openapi.json: openapi 3.x JSON, paths 있음 */
    public function test_openapi_json(): void
    {
        $spec = json_decode(file_get_contents(dirname(__DIR__, 2) . '/apidocs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression('/^3\.\d+\.\d+$/', $spec['openapi']);
        $this->assertNotEmpty($spec['paths']);
    }

    /** apidocs/openapi.json: securitySchemes.bearerAuth 는 http bearer */
    public function test_openapi_bearer_scheme(): void
    {
        $spec = json_decode(file_get_contents(dirname(__DIR__, 2) . '/apidocs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('http', $spec['components']['securitySchemes']['bearerAuth']['type']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
    }

    /** apidocs/openapi.json: paths 의 PHP 파일(? 앞)이 저장소에 있음 */
    public function test_openapi_paths_파일(): void
    {
        $root = dirname(__DIR__, 2);
        $spec = json_decode(file_get_contents($root . '/apidocs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach (array_keys($spec['paths']) as $path) {
            $file = ltrim(explode('?', $path)[0], '/');
            $this->assertStringEndsWith('.php', $file, $path);
            $this->assertFileExists($root . '/' . $file, $path);
        }
    }

    /** apidocs/openapi.json: operation 마다 security 있음 */
    public function test_openapi_security(): void
    {
        $spec = json_decode(file_get_contents(dirname(__DIR__, 2) . '/apidocs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($spec['paths'] as $path => $item) {
            foreach (array_intersect_key($item, self::METHODS) as $method => $op) {
                $this->assertNotEmpty($op['security'] ?? [], "{$method} {$path}");
            }
        }
    }

    /** apidocs/openapi.json: bearerAuth 없는 operation → description 에 '브라우저 로그인 전용', 파일에 require_session_login */
    public function test_openapi_세션_전용(): void
    {
        $root = dirname(__DIR__, 2);
        $spec = json_decode(file_get_contents($root . '/apidocs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        $sessionOnly = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach (array_intersect_key($item, self::METHODS) as $method => $op) {
                if (in_array('bearerAuth', array_merge(...array_map('array_keys', $op['security'])), true)) continue;
                $this->assertStringContainsString('브라우저 로그인 전용', $op['description'], "{$method} {$path}");
                $this->assertStringContainsString('require_session_login()', file_get_contents($root . '/' . ltrim(explode('?', $path)[0], '/')), "{$method} {$path}");
                $sessionOnly[] = "{$method} {$path}";
            }
        }

        $this->assertSame(['put /api/me.php', 'get /api/token.php', 'post /api/token.php', 'delete /api/token.php'], $sessionOnly);
    }

    /** apidocs/openapi.json: $ref 가 모두 components 안에 있음 */
    public function test_openapi_ref(): void
    {
        $spec = json_decode(file_get_contents(dirname(__DIR__, 2) . '/apidocs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        $refs = [];
        array_walk_recursive($spec, function ($value, $key) use (&$refs) {
            if ($key === '$ref') $refs[] = $value;
        });

        $this->assertNotEmpty($refs);
        foreach (array_unique($refs) as $ref) {
            $node = $spec;
            foreach (array_slice(explode('/', $ref), 1) as $part) {
                $this->assertArrayHasKey($part, $node, $ref);
                $node = $node[$part];
            }
        }
    }
}
