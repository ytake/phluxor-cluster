<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;
use Phluxor\ActorSystem\Context\ContextInterface;
use Phluxor\ActorSystem\Message\ReceiveFunction;
use Phluxor\ActorSystem\Props;
use Phluxor\Cluster\ActivatedKind;
use Phluxor\Cluster\KindRegistry;

final class KindRegistryTest extends TestCase
{
    public function testRegisterAndFind(): void
    {
        $registry = new KindRegistry();
        $activatedKind = new ActivatedKind('UserGrain', $this->dummyProps());

        $registry->register($activatedKind);
        $found = $registry->find('UserGrain');

        self::assertSame($activatedKind, $found);
    }

    public function testFindReturnsNullForUnregistered(): void
    {
        $registry = new KindRegistry();

        self::assertNull($registry->find('UnknownGrain'));
    }

    public function testHas(): void
    {
        $registry = new KindRegistry();
        $registry->register(new ActivatedKind('UserGrain', $this->dummyProps()));

        self::assertTrue($registry->has('UserGrain'));
        self::assertFalse($registry->has('UnknownGrain'));
    }

    public function testAllKindNames(): void
    {
        $registry = new KindRegistry();
        $registry->register(new ActivatedKind('UserGrain', $this->dummyProps()));
        $registry->register(new ActivatedKind('OrderGrain', $this->dummyProps()));

        self::assertSame(['UserGrain', 'OrderGrain'], $registry->allKindNames());
    }

    public function testConstructorWithVariadicKinds(): void
    {
        $userKind = new ActivatedKind('UserGrain', $this->dummyProps());
        $orderKind = new ActivatedKind('OrderGrain', $this->dummyProps());

        $registry = new KindRegistry($userKind, $orderKind);

        self::assertSame($userKind, $registry->find('UserGrain'));
        self::assertSame($orderKind, $registry->find('OrderGrain'));
        self::assertSame(['UserGrain', 'OrderGrain'], $registry->allKindNames());
    }

    private function dummyProps(): Props
    {
        return Props::fromFunction(
            new ReceiveFunction(
                static function (ContextInterface $context): void {
                }
            )
        );
    }
}
