<?php

declare(strict_types=1);

namespace DJGear\Core\Tests\Unit;

use DJGear\Core\Providers\Email\EmailMessage;
use DJGear\Core\Providers\Email\EmailProviderInterface;
use DJGear\Core\Providers\Payment\PaymentProviderInterface;
use DJGear\Core\Providers\Payment\PaymentRequest;
use DJGear\Core\Providers\Payment\PaymentVerification;
use DJGear\Core\Providers\ProviderRegistry;
use DJGear\Core\Providers\ProviderResult;
use DJGear\Core\Providers\Sms\SmsMessage;
use DJGear\Core\Providers\Sms\SmsProviderInterface;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function testRegistersAndResolvesEmailSmsAndPaymentContracts(): void
    {
        $registry = new ProviderRegistry();

        $email = new class () implements EmailProviderInterface {
            public function id(): string { return 'test-mail'; }
            public function name(): string { return 'Test Mail'; }
            public function isAvailable(): bool { return true; }
            public function send(EmailMessage $message): ProviderResult {
                return ProviderResult::ok($this->id(), 'mail-1');
            }
        };

        $sms = new class () implements SmsProviderInterface {
            public function id(): string { return 'test-sms'; }
            public function name(): string { return 'Test SMS'; }
            public function isAvailable(): bool { return true; }
            public function send(SmsMessage $message): ProviderResult {
                return ProviderResult::ok($this->id(), 'sms-1');
            }
        };

        $payment = new class () implements PaymentProviderInterface {
            public function id(): string { return 'test-pay'; }
            public function name(): string { return 'Test Pay'; }
            public function isSandbox(): bool { return true; }
            public function initiate(PaymentRequest $request): ProviderResult {
                return ProviderResult::ok($this->id(), 'auth-100');
            }
            public function verify(PaymentVerification $verification): ProviderResult {
                return ProviderResult::ok($this->id(), $verification->referenceId);
            }
        };

        $registry->registerEmailProvider($email);
        $registry->registerSmsProvider($sms);
        $registry->registerPaymentProvider($payment);

        self::assertSame($email, $registry->getEmailProvider('test-mail'));
        self::assertSame($sms, $registry->getSmsProvider('test-sms'));
        self::assertSame($payment, $registry->getPaymentProvider('test-pay'));
        self::assertNull($registry->getSmsProvider('nonexistent'));

        self::assertSame([
            'email'   => ['test-mail'],
            'sms'     => ['test-sms'],
            'payment' => ['test-pay'],
        ], $registry->summary());

        $mailRes = $email->send(new EmailMessage(['qa@djgear.local'], 'Hi', 'Body'));
        self::assertTrue($mailRes->success);
        self::assertSame('mail-1', $mailRes->referenceId);

        $payRes = $payment->initiate(new PaymentRequest(42, 500000, 'IRR', 'http://localhost:8080/cb'));
        self::assertTrue($payRes->success);
        self::assertTrue($payment->isSandbox());
    }
}
