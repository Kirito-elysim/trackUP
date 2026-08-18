<?php
declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

// Account-status failures (e.g. inactive user, thrown by UserChecker) already carry
// a safe, French, user-facing message — pass it through as-is. Anything else (wrong
// email/password) gets a single generic French message instead of Lexik's default
// English "Invalid credentials." so the frontend never has to guess/translate it.
class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        $message = $exception instanceof AccountStatusException
            ? $exception->getMessageKey()
            : 'Email ou mot de passe incorrect.';

        return new JsonResponse(['message' => $message], JsonResponse::HTTP_UNAUTHORIZED);
    }
}
