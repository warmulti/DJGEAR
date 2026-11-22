<?php

declare(strict_types=1);

namespace DJGear\Core\Tests\Unit;

use DJGear\Core\Container\Container;
use DJGear\Core\Container\ContainerInterface;
use DJGear\Core\I18n\Direction;
use DJGear\Core\I18n\Translator;
use DJGear\Core\Lifecycle\Activator;
use DJGear\Core\Lifecycle\Deactivator;
use DJGear\Core\Lifecycle\MigrationInterface;
use DJGear\Core\Lifecycle\MigrationRunner;
use DJGear\Core\Lifecycle\Migrations\CreateAuditLogTable;
use DJGear\Core\Logging\AuditEvent;
use DJGear\Core\Logging\AuditLogger;
use DJGear\Core\Logging\AuditLoggerInterface;
use DJGear\Core\Logging\LoggerInterface;
use DJGear\Core\Logging\LogLevel;
use DJGear\Core\Logging\StructuredLogger;
use DJGear\Core\Modules\AbstractModule;
use DJGear\Core\Modules\ModuleDescriptor;
use DJGear\Core\Modules\ModuleInterface;
use DJGear\Core\Modules\ModuleRegistry;
use DJGear\Core\Plugin;
use DJGear\Core\Providers\Email\EmailMessage;
use DJGear\Core\Providers\Email\EmailProviderInterface;
use DJGear\Core\Providers\Payment\PaymentProviderInterface;
use DJGear\Core\Providers\Payment\PaymentRequest;
use DJGear\Core\Providers\Payment\PaymentVerification;
use DJGear\Core\Providers\ProviderRegistry;
use DJGear\Core\Providers\ProviderResult;
use DJGear\Core\Providers\Sms\SmsMessage;
use DJGear\Core\Providers\Sms\SmsProviderInterface;
use DJGear\Core\Rest\Controllers\AuditLogController;
use DJGear\Core\Rest\Controllers\HealthController;
use DJGear\Core\Rest\Controllers\ModulesController;
use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RestKernel;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\Core\Security\Capabilities;
use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function testPsr4AutoloadingResolvesAllCoreSymbols(): void
    {
        $classes = [
            Plugin::class,
            Container::class,
            Activator::class,
            Deactivator::class,
            MigrationRunner::class,
            CreateAuditLogTable::class,
            AbstractModule::class,
            ModuleDescriptor::class,
            ModuleRegistry::class,
            Translator::class,
            Capabilities::class,
            Authorizer::class,
            StructuredLogger::class,
            AuditEvent::class,
            AuditLogger::class,
            ProviderResult::class,
            ProviderRegistry::class,
            EmailMessage::class,
            SmsMessage::class,
            PaymentRequest::class,
            PaymentVerification::class,
            Response::class,
            RestKernel::class,
            HealthController::class,
            ModulesController::class,
            AuditLogController::class,
        ];

        foreach ($classes as $class) {
            self::assertTrue(class_exists($class), "Expected class {$class} to autoload.");
        }

        $interfaces = [
            ContainerInterface::class,
            MigrationInterface::class,
            ModuleInterface::class,
            LoggerInterface::class,
            AuditLoggerInterface::class,
            EmailProviderInterface::class,
            SmsProviderInterface::class,
            PaymentProviderInterface::class,
            RouteInterface::class,
        ];

        foreach ($interfaces as $interface) {
            self::assertTrue(interface_exists($interface), "Expected interface {$interface} to autoload.");
        }

        self::assertTrue(enum_exists(Direction::class));
        self::assertTrue(enum_exists(LogLevel::class));
    }
}
