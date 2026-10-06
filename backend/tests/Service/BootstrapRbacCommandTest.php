<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Command\BootstrapRbacCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BootstrapRbacCommandTest extends TestCase
{
    public function testExistingAccountIsNotResetOrReactivated(): void
    {
        $user = (new User())->setEmail('admin@example.test')->setPassword('existing-hash')->setFirstName('Existing')->setActive(false);
        [$tester, $hasher] = $this->fixture($user);
        $hasher->expects(self::never())->method('hashPassword');
        self::assertSame(Command::SUCCESS, $tester->execute(['--admin-email' => 'admin@example.test'], ['interactive' => false]));
        self::assertSame('existing-hash', $user->getPassword());
        self::assertSame('Existing', $user->getFirstName());
        self::assertFalse($user->isActive());
        self::assertStringNotContainsString('existing-hash', $tester->getDisplay());
    }

    public function testMissingEmailIsRejected(): void
    {
        [$tester, $hasher] = $this->fixture();
        $hasher->expects(self::never())->method('hashPassword');
        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false]));
    }

    public function testNonInteractiveCreationRequiresExplicitSecretInput(): void
    {
        [$tester, $hasher] = $this->fixture();
        $hasher->expects(self::never())->method('hashPassword');
        self::assertSame(Command::INVALID, $tester->execute(['--admin-email' => 'admin@example.test'], ['interactive' => false]));
    }

    public function testPasswordFromStdinIsNeverPrinted(): void
    {
        [$tester, $hasher] = $this->fixture();
        $password = 'test-only-long-password';
        $hasher->expects(self::once())->method('hashPassword')->with(self::isInstanceOf(User::class), $password)->willReturn('new-hash');
        $tester->setInputs([$password]);
        self::assertSame(Command::SUCCESS, $tester->execute(['--admin-email' => 'admin@example.test', '--admin-password-stdin' => true], ['interactive' => false]));
        self::assertStringNotContainsString($password, $tester->getDisplay());
        self::assertStringNotContainsString('new-hash', $tester->getDisplay());
    }

    private function fixture(?User $user = null): array
    {
        $users = $this->createStub(EntityRepository::class);
        $users->method('findOneBy')->willReturn($user);
        $empty = $this->createStub(EntityRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class) => $class === User::class ? $users : $empty);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        return [new CommandTester(new BootstrapRbacCommand($em, $hasher)), $hasher];
    }
}
