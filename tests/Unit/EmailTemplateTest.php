<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Infrastructure\EmailTemplate;
use PHPUnit\Framework\TestCase;

final class EmailTemplateTest extends TestCase
{
    public function test_otp_email_uses_the_public_e7_symbol_on_the_dark_brand_background(): void
    {
        $portuguese = EmailTemplate::otp('407736', 'pt_BR');
        $english = EmailTemplate::otp('407736', 'en_IE');

        foreach ([$portuguese, $english] as $email) {
            self::assertStringContainsString('https://e7company.com/wp-content/plugins/e7-propostas-core/assets/brand/e7-symbol-transparent.png', $email['html']);
            self::assertStringContainsString('background:#172554', $email['html']);
            self::assertStringContainsString('alt="E7"', $email['html']);
            self::assertStringNotContainsString('e7-company-logo-transparent.png', $email['html']);
            self::assertStringContainsString('407736', $email['html']);
            self::assertStringContainsString('407736', $email['text']);
            self::assertStringNotContainsString('.local', $email['html']);
        }

        self::assertSame('Código de aceite da proposta E7', $portuguese['subject']);
        self::assertSame('E7 proposal acceptance code', $english['subject']);
        self::assertStringContainsString('Seu código de verificação', $portuguese['html']);
        self::assertStringContainsString('Your verification code', $english['html']);
    }

    public function test_final_copy_email_reuses_the_same_bilingual_brand_template(): void
    {
        $portuguese = EmailTemplate::finalCopy('pt_BR');
        $english = EmailTemplate::finalCopy('en_IE');

        foreach ([$portuguese, $english] as $email) {
            self::assertStringContainsString('e7-symbol-transparent.png', $email['html']);
            self::assertStringNotContainsString('e7-company-logo-transparent.png', $email['html']);
            self::assertStringContainsString('background:#172554', $email['html']);
        }

        self::assertFileExists(dirname(__DIR__, 2) . '/assets/brand/e7-symbol-transparent.png');

        self::assertSame('Sua proposta E7 aceita', $portuguese['subject']);
        self::assertSame('Your accepted E7 proposal', $english['subject']);
        self::assertStringContainsString('Sua cópia final está pronta', $portuguese['html']);
        self::assertStringContainsString('Your final copy is ready', $english['html']);
    }
}
