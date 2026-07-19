<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use Aws\SesV2\SesV2Client;
use Aws\Sns\SnsClient;

final class DeliveryService
{
    /** @return array{id:string,debug_code?:string} */
    public function sendOtp(string $channel, string $code, string $destination, string $locale): array
    {
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local') {
            return ['id' => 'local-' . bin2hex(random_bytes(8)), 'debug_code' => $code];
        }
        $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
        if (! is_string($region) || $region === '') {
            throw new \RuntimeException('AWS region is not configured.');
        }
        $ids = [];
        if ($channel === 'email') {
            $from = getenv('E7_SES_FROM_EMAIL');
            if (! is_string($from) || ! is_email($from) || ! is_email($destination)) {
                throw new \RuntimeException('SES sender or signer email is invalid.');
            }
            $client = new SesV2Client(['version' => 'latest', 'region' => $region]);
            $result = $client->sendEmail([
                'FromEmailAddress' => $from,
                'Destination' => ['ToAddresses' => [$destination]],
                'Content' => ['Simple' => [
                    'Subject' => ['Data' => $locale === 'pt_BR' ? 'Código de aceite da proposta E7' : 'E7 proposal acceptance code', 'Charset' => 'UTF-8'],
                    'Body' => ['Text' => ['Data' => $locale === 'pt_BR' ? "Seu código é $code. Ele expira em 10 minutos." : "Your code is $code. It expires in 10 minutes.", 'Charset' => 'UTF-8']],
                ]],
            ]);
            $ids[] = (string) ($result->get('MessageId') ?? 'ses');
        }
        if ($channel === 'sms') {
            if ($destination === '') {
                throw new \RuntimeException('Signer phone is required for SMS OTP.');
            }
            $senderId = getenv('E7_SNS_SENDER_ID');
            if (! is_string($senderId) || preg_match('/^[A-Za-z0-9]{3,11}$/', $senderId) !== 1) {
                throw new \RuntimeException('SNS sender ID is not configured.');
            }
            $client = new SnsClient(['version' => 'latest', 'region' => $region]);
            $result = $client->publish(['PhoneNumber' => $destination, 'Message' => "E7: $code", 'MessageAttributes' => [
                'AWS.SNS.SMS.SMSType' => ['DataType' => 'String', 'StringValue' => 'Transactional'],
                'AWS.SNS.SMS.SenderID' => ['DataType' => 'String', 'StringValue' => $senderId],
            ]]);
            $ids[] = (string) ($result->get('MessageId') ?? 'sns');
        }
        return ['id' => implode(',', $ids)];
    }
}
