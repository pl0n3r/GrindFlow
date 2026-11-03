<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Offline acceptance for issue #800. Reads only the claimed documentation path.
 * The canonical coordination entrypoint is test_criterio_principal.
 */
final class OnboardingPilotDocsTest extends TestCase
{
    private static function document(): string
    {
        $path = dirname(__DIR__).'/docs/launch/onboarding-pilot-five.md';
        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function test_criterio_principal(): void
    {
        $doc = self::document();
        foreach ([
            'Mensaje de bienvenida', 'Formulario de alta', 'Checklist individual',
            'consentimiento', 'NO-GO', 'PRE-LIVE', 'asesoría jurídica',
            '[COMPLETAR POR EL DUEÑO]', 'tenant', 'soporte',
            'casilla inicialmente desmarcada', 'retirada de consentimiento',
        ] as $required) {
            self::assertStringContainsString($required, $doc, 'Falta control: '.$required);
        }

        preg_match_all('/^\\| PILOTO-0([1-5]) \\| NO \\| PENDIENTE \\| PENDIENTE \\| PENDIENTE \\| NO-GO \\|$/m', $doc, $matches);
        self::assertSame(['1', '2', '3', '4', '5'], $matches[1], 'Deben existir exactamente cinco plazas sin activar');

        self::assertStringContainsString('**Estado de envío:** BLOQUEADO', $doc);
        self::assertStringContainsString('no está publicado ni recolecta información', $doc);
        self::assertStringContainsString('ninguna casilla premarcada', $doc);
        self::assertStringContainsString('solo ve datos propios', $doc);
    }

    public function test_documents_never_embed_live_secrets_or_personal_contacts(): void
    {
        $doc = self::document();
        foreach ([
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i',
            '/\\bgh[pousr]_[A-Za-z0-9_]{20,}\\b/',
            '/\\b(?:sk_live_|pk_live_|AKIA)[A-Za-z0-9]{12,}\\b/',
            '/\\b(?:Bearer\\s+)[A-Za-z0-9._-]{20,}\\b/i',
            '/\\b[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}\\b/i',
        ] as $pattern) {
            self::assertSame(0, preg_match($pattern, $doc), 'Documento incluye credencial o contacto identificable');
        }
    }

    public function test_negative_controls_reject_missing_or_activated_slots(): void
    {
        $doc = self::document();
        $activated = str_replace('| PILOTO-03 | NO |', '| PILOTO-03 | SI |', $doc);
        self::assertNotSame($doc, $activated);
        self::assertSame(0, preg_match('/^\\| PILOTO-03 \\| NO \\|/m', $activated));
        $missing = str_replace('| PILOTO-05 | NO |', '| PLAZA-05 | NO |', $doc);
        self::assertSame(0, preg_match('/^\\| PILOTO-05 \\| NO \\|/m', $missing));
        $premarked = str_replace('casilla inicialmente desmarcada', 'casilla premarcada', $doc);
        self::assertStringNotContainsString('casilla inicialmente desmarcada', $premarked);
    }
}
