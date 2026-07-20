<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

final class EmailTemplate
{
    private const LOGO_URL = 'https://e7company.com/wp-content/plugins/e7-propostas-core/assets/brand/e7-symbol-transparent.png';

    /** @return array{subject:string,text:string,html:string} */
    public static function otp(string $code, string $locale): array
    {
        $pt = $locale === 'pt_BR';
        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = $pt ? 'Código de aceite da proposta E7' : 'E7 proposal acceptance code';
        $text = $pt
            ? "E7 Company\n\nSeu código de verificação é: $code\n\nEle expira em 10 minutos.\n\nSe você não iniciou este aceite, ignore este e-mail."
            : "E7 Company\n\nYour verification code is: $code\n\nIt expires in 10 minutes.\n\nIf you did not start this acceptance, you can ignore this email.";
        $content = '<p style="margin:0 0 10px;color:#667085;font-size:15px;line-height:1.6;">'
            . ($pt ? 'Use o código abaixo para confirmar sua identidade e concluir o aceite da proposta.' : 'Use the code below to confirm your identity and complete the proposal acceptance.')
            . '</p><div style="margin:24px 0;border-radius:16px;background:#eff6ff;padding:20px;text-align:center;">'
            . '<span style="display:block;color:#1d4ed8;font-size:30px;font-weight:700;letter-spacing:8px;line-height:1.2;">' . $safeCode . '</span>'
            . '<span style="display:block;margin-top:8px;color:#667085;font-size:12px;">' . ($pt ? 'Válido por 10 minutos' : 'Valid for 10 minutes') . '</span></div>'
            . '<p style="margin:0;color:#667085;font-size:13px;line-height:1.6;">' . ($pt ? 'Se você não iniciou este aceite, ignore este e-mail.' : 'If you did not start this acceptance, you can ignore this email.') . '</p>';

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => self::layout($pt ? 'Aceite de proposta' : 'Proposal acceptance', $pt ? 'Seu código de verificação' : 'Your verification code', $content),
        ];
    }

    /** @return array{subject:string,text:string,html:string} */
    public static function finalCopy(string $locale): array
    {
        $pt = $locale === 'pt_BR';
        $subject = $pt ? 'Sua proposta E7 aceita' : 'Your accepted E7 proposal';
        $text = $pt
            ? "E7 Company\n\nSua cópia final está pronta.\n\nA proposta aceita e o registro do aceite seguem anexados a este e-mail. Guarde o arquivo para seus registros."
            : "E7 Company\n\nYour final copy is ready.\n\nThe accepted proposal and its acceptance record are attached to this email. Keep the file for your records.";
        $content = '<p style="margin:0;color:#667085;font-size:15px;line-height:1.6;">'
            . ($pt ? 'A proposta aceita e o registro do aceite seguem anexados a este e-mail. Guarde o arquivo para seus registros.' : 'The accepted proposal and its acceptance record are attached to this email. Keep the file for your records.')
            . '</p><div style="margin-top:24px;border-radius:16px;background:#f6f8fb;padding:16px 18px;color:#344054;font-size:13px;line-height:1.6;">'
            . ($pt ? 'Este e-mail foi enviado automaticamente após a conclusão segura do aceite.' : 'This email was sent automatically after the acceptance was securely completed.')
            . '</div>';

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => self::layout($pt ? 'Proposta aceita' : 'Proposal accepted', $pt ? 'Sua cópia final está pronta' : 'Your final copy is ready', $content),
        ];
    }

    private static function layout(string $eyebrow, string $heading, string $content): string
    {
        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#f6f8fb;color:#0a0a0a;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f6f8fb;"><tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:560px;overflow:hidden;border:1px solid #e5e7eb;border-radius:20px;background:#ffffff;">'
            . '<tr><td align="center" style="background:#172554;padding:24px 32px;">'
            . '<img src="' . self::LOGO_URL . '" width="140" height="96" alt="E7" style="display:block;width:140px;height:96px;margin:0 auto;border:0;">'
            . '</td></tr><tr><td style="padding:36px 40px 32px;">'
            . '<p style="margin:0 0 10px;color:#2563eb;font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">' . htmlspecialchars($eyebrow, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<h1 style="margin:0 0 20px;color:#0a0a0a;font-size:30px;line-height:1.15;letter-spacing:-0.8px;">' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . $content
            . '</td></tr><tr><td style="border-top:1px solid #e5e7eb;padding:20px 40px;color:#667085;font-size:11px;line-height:1.5;">E7 Company Tecnologia LTDA.<br>e7company.com</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
