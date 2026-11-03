<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use PHPUnit\Framework\TestCase;

/** Contract for opt-in local synthetic smoke; never uses real clients. */
final class ProductionSmokeScriptTest extends TestCase
{
    private static function script(): string
    {
        $text = file_get_contents(__DIR__.'/../../../scripts/production-smoke.sh');
        self::assertIsString($text);

        return $text;
    }

    public function test_criterio_principal(): void
    {
        $script = self::script();
        self::assertStringContainsString('"${1:-}" == "--local-tenant"', $script);
        self::assertStringContainsString('NAME_FILE', $script);
        self::assertStringContainsString('local-only origin required', $script);
        self::assertStringContainsString('release_sha', $script);
        self::assertStringContainsString('/organizations/select', $script);
        self::assertStringContainsString('cross-tenant selection not denied', $script);
        self::assertStringContainsString('tenant context changed after forbidden access', $script);
        self::assertStringContainsString('PASS: local synthetic release, login and tenant isolation', $script);
        [$status, $stdout, $stderr] = self::runSyntheticServer('good');
        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('PASS: local synthetic release, login and tenant isolation', $stdout);
        self::assertSame('', trim($stderr), 'Unexpected local smoke diagnostics');

        // The original production smoke remains the default; no new production flag is applied.
        self::assertStringContainsString('E2E_USER_PASSWORD is required', $script);
        self::assertStringContainsString('EXPECTED_RELEASE', $script);
    }

    /**
     * Real HTTP loopback exercise. A disposable fake server sees only fabricated
     * identities. The smoke script's default production path is NEVER invoked.
     * @return array{int,string,string}
     */
    private static function runSyntheticServer(string $mode): array
    {
        $python = <<<'PY_SERVER'
import http.server
import json
import os
import urllib.parse

OWN = '11111111-1111-4111-8111-111111111111'
FOREIGN = '22222222-2222-4222-8222-222222222222'
MODE = os.environ.get('GF_SYNTH_MODE', 'good')
SHA = 'a' * 40

class Fixture(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass  # Never log credentials or tenant fields.

    def send(self, status, body='', content='text/html', location=None, cookie=None):
        self.send_response(status)
        self.send_header('Content-Type', content)
        if location is not None:
            self.send_header('Location', location)
        if cookie is not None:
            self.send_header('Set-Cookie', cookie)
        self.end_headers()
        self.wfile.write(body.encode('utf-8'))

    def do_GET(self):
        if self.path == '/health':
            details = {'status': 'ok', 'version': '0.1.225', 'release_sha': SHA}
            if MODE == 'stale_sha':
                details['release_sha'] = 'b' * 40
            if MODE == 'missing_sha':
                del details['release_sha']
            return self.send(200, json.dumps(details), 'application/json')
        if self.path == '/login':
            return self.send(200, '<form method="post" action="/login"><input name="_csrf_token" value="login-token"></form>')
        if self.path == '/organizations':
            if 'session=yes' not in self.headers.get('Cookie', ''):
                return self.send(401)
            html = '<form method="post" action="/organizations/select"><input name="organization_id" value="'+OWN+'"><input name="_csrf_token" value="selection-token"></form>'
            if MODE == 'foreign_leak':
                html += '<form method="post" action="/organizations/select"><input name="organization_id" value="'+FOREIGN+'"><input name="_csrf_token" value="selection-token"></form>'
            return self.send(200, html)
        if self.path == '/admin':
            if 'tenant=yes' not in self.headers.get('Cookie', ''):
                return self.send(401)
            return self.send(200, '<div data-organization="'+OWN+'"></div>')
        if self.path == '/api/admin/context':
            if 'tenant=yes' not in self.headers.get('Cookie', ''):
                return self.send(401)
            active = FOREIGN if MODE == 'wrong_context' else OWN
            return self.send(200, json.dumps({'data': {'organization': {'id': active}}}), 'application/json')
        return self.send(404)

    def do_POST(self):
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if length > 4096:
                return self.send(413)
            fields = urllib.parse.parse_qs(self.rfile.read(length).decode('utf-8'))
        except (ValueError, UnicodeError):
            return self.send(400)
        if self.path == '/login':
            verified = (fields.get('_csrf_token') == ['login-token'] and
                        fields.get('email') == ['actor@example.test'] and
                        fields.get('password') == ['synthetic-no-secret'] and
                        MODE != 'bad_login')
            return self.send(302, location='/organizations', cookie='session=yes; Path=/') if verified else self.send(401)
        if self.path == '/organizations/select':
            if fields.get('_csrf_token') != ['selection-token']:
                return self.send(403)
            if fields.get('organization_id') == [OWN]:
                return self.send(302, location='/admin', cookie='tenant=yes; Path=/')
            if fields.get('organization_id') == [FOREIGN]:
                if MODE == 'unsafe_foreign':
                    return self.send(302, location='/admin')
                return self.send(403)
            return self.send(404)
        return self.send(404)

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Fixture)
print(server.server_port, flush=True)
server.serve_forever()
PY_SERVER;
        $serverPipes = [];
        $server = proc_open(['python3', '-u', '-c', $python],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $serverPipes, null, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'GF_SYNTH_MODE' => $mode]);
        self::assertIsResource($server);
        fclose($serverPipes[0]);
        stream_set_timeout($serverPipes[1], 5);
        $port = trim((string) fgets($serverPipes[1]));
        $nameFile = tempnam(sys_get_temp_dir(), 'gf-794-synthetic-');
        self::assertNotFalse($nameFile);
        try {
            self::assertMatchesRegularExpression('/^[0-9]{1,5}$/', $port,
                'Disposable server did not expose an ephemeral loopback port');
            self::assertGreaterThan(0, (int) $port);
            self::assertLessThanOrEqual(65535, (int) $port);
            $data = [
                'email' => 'actor@example.test',
                'password' => 'synthetic-no-secret',
                'own_tenant_id' => '11111111-1111-4111-8111-111111111111',
                'foreign_tenant_id' => '22222222-2222-4222-8222-222222222222',
            ];
            self::assertNotFalse(file_put_contents($nameFile, json_encode($data, JSON_THROW_ON_ERROR)));
            self::assertTrue(chmod($nameFile, 0600));
            $pipes = [];
            $command = ['bash', __DIR__.'/../../../scripts/production-smoke.sh', '--local-tenant'];
            $process = proc_open($command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                null, [
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                    'BASE_URL' => 'http://127.0.0.1:'.$port,
                    'EXPECTED_SHA' => str_repeat('a', 40),
                    'EXPECTED_RELEASE' => '0.1.225',
                    'NAME_FILE' => $nameFile,
                ]);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            return [$status, $stdout, $stderr];
        } finally {
            @unlink($nameFile);
            proc_terminate($server);
            fclose($serverPipes[1]);
            fclose($serverPipes[2]);
            proc_close($server);
        }
    }

    public function testRejectsSyntheticTenantFailures(): void
    {
        foreach (['stale_sha', 'missing_sha', 'bad_login', 'foreign_leak', 'wrong_context', 'unsafe_foreign'] as $mode) {
            [$status, $stdout, $stderr] = self::runSyntheticServer($mode);
            self::assertNotSame(0, $status, 'Expected NO-GO for synthetic failure '.$mode);
            self::assertSame('', trim($stdout), 'Failure must not emit a positive result');
            self::assertStringContainsString('NO-GO:', $stderr);
            self::assertStringNotContainsString('synthetic-no-secret', $stderr);
            self::assertStringNotContainsString('actor@example.test', $stderr);
        }
    }

    public function testMissingEvidenceFailsClosed(): void
    {
        $script = __DIR__.'/../../../scripts/production-smoke.sh';
        $command = ['bash', $script, '--local-tenant'];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            null, ['BASE_URL' => 'http://127.0.0.1:12345', 'EXPECTED_SHA' => '', 'EXPECTED_RELEASE' => '0.1.225']);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertNotSame(0, $exit, $output);
        self::assertStringContainsString('NO-GO: exact release identity required', $error);
    }

    public function testRejectsExternalOriginWithoutContact(): void
    {
        $script = __DIR__.'/../../../scripts/production-smoke.sh';
        $pipes = [];
        $process = proc_open(['bash', $script, '--local-tenant'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            null, ['BASE_URL' => 'https://www.grindflow.com.co', 'EXPECTED_SHA' => str_repeat('a', 40),
                'EXPECTED_RELEASE' => '0.1.225']);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertNotSame(0, $exit, $output);
        self::assertStringContainsString('NO-GO: local-only origin required', $error);
    }
}
