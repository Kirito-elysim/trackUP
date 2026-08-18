<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\UserPermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class AuthController extends AbstractController
{
    private const RESET_TOKEN_TTL = '+1 hour';
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserPermissionResolver $permissionResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $frontendUrl,
        private readonly string $fromAddress,
    ) {
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['message' => 'Unauthenticated.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $roles = array_values(array_map(
            static fn ($role) => [
                'id' => $role->getId(),
                'code' => $role->getCode(),
                'name' => $role->getName(),
            ],
            $user->getRoleEntities()->toArray()
        ));

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => trim($user->getFirstName() . ' ' . $user->getLastName()),
            'isAdmin' => \in_array('ROLE_ADMIN', $user->getRoles(), true),
            'roles' => $roles,
            'features' => $this->permissionResolver->resolveFeatureCodes($user),
        ]);
    }

    #[Route('/me/password', name: 'api_me_change_password', methods: ['PUT'])]
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['message' => 'Unauthenticated.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = $request->toArray();
        $currentPassword = (string) ($data['currentPassword'] ?? '');
        $newPassword = (string) ($data['newPassword'] ?? '');

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json(['message' => 'Mot de passe actuel incorrect.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return $this->json(
                ['message' => sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_PASSWORD_LENGTH)],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();

        return $this->json(['message' => 'Mot de passe mis à jour.']);
    }

    #[Route('/auth/forgot-password', name: 'api_auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        // Always return the same generic message whether or not the email is
        // registered/active — confirming account existence to an anonymous caller
        // is a user-enumeration risk.
        $generic = ['message' => 'Si un compte existe avec cette adresse, un email de réinitialisation vient d\'être envoyé.'];

        $data = $request->toArray();
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '') {
            return $this->json($generic);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user instanceof User && $user->isActive()) {
            $token = bin2hex(random_bytes(32));
            $user->setResetToken($token, new \DateTimeImmutable(self::RESET_TOKEN_TTL));
            $this->entityManager->flush();

            $this->sendResetEmail($user, $token);
        }

        return $this->json($generic);
    }

    #[Route('/auth/reset-password', name: 'api_auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $token = trim((string) ($data['token'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($token === '' || $password === '') {
            return $this->json(['message' => 'Requête invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return $this->json(
                ['message' => sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_PASSWORD_LENGTH)],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['resetToken' => $token]);
        $expiresAt = $user?->getResetTokenExpiresAt();

        if (!$user instanceof User || !$expiresAt || $expiresAt < new \DateTimeImmutable()) {
            return $this->json(
                ['message' => 'Ce lien de réinitialisation est invalide ou a expiré.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setResetToken(null);
        $this->entityManager->flush();

        return $this->json(['message' => 'Votre mot de passe a été mis à jour.']);
    }

    private function sendResetEmail(User $user, string $token): void
    {
        $resetUrl = sprintf('%s/reset-password?token=%s', rtrim($this->frontendUrl, '/'), $token);

        $email = (new Email())
            ->from($this->fromAddress)
            ->to($user->getEmail())
            ->subject('TrackUp - Réinitialisation de votre mot de passe')
            ->text(
                "Bonjour {$user->getFirstName()},\n\n"
                . "Une demande de réinitialisation de mot de passe a été effectuée pour votre compte TrackUp.\n"
                . "Cliquez sur le lien suivant pour choisir un nouveau mot de passe (valable 1 heure) :\n"
                . "{$resetUrl}\n\n"
                . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.\n"
            )
            ->html(
                "<p>Bonjour {$user->getFirstName()},</p>"
                . "<p>Une demande de réinitialisation de mot de passe a été effectuée pour votre compte TrackUp.</p>"
                . "<p><a href=\"{$resetUrl}\">Cliquez ici pour choisir un nouveau mot de passe</a> (lien valable 1 heure).</p>"
                . "<p>Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.</p>"
            );

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            // Swallowed on purpose: the caller always sees the generic success
            // message regardless of delivery outcome, so there is nothing
            // actionable to surface here beyond what ops monitoring would catch.
        }
    }
}
