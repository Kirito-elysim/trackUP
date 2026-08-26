<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

// Personnalisation du Dashboard, propre à chaque compte interne connecté (pas de garde de Feature :
// chacun ne gère que ses propres préférences via $this->getUser()). Voir plan
// "Dashboard personnalisable par utilisateur interne".
#[Route('/api/me/dashboard-preferences')]
class DashboardPreferencesController extends AbstractController
{
    private const ALLOWED_THEMES = ['brand', 'blue', 'green', 'violet'];

    private const ALLOWED_KPIS = [
        'activeLearners7d',
        'learningPathsCount',
        'promotionsCount',
        'companiesTutors',
        'learnersCount',
        'activeLearnersCount',
        'trainingsCount',
        'sessionsCount',
        'signedAttendancesCount',
        'totalTrackedTime',
        'totalYearTime',
        'averageProgress',
        'absencesCount',
        'absencesPendingCount',
        'absencesActiveAlertsCount',
    ];

    // Doit rester synchronisé avec les "to" de NAV_ITEMS (frontend/src/components/AppLayout.tsx) —
    // seules des routes réellement navigables peuvent être enregistrées comme raccourci.
    private const ALLOWED_SHORTCUT_TARGETS = [
        '/dashboard',
        '/analytics',
        '/learningpaths',
        '/courses',
        '/learners',
        '/companies',
        '/tutors',
        '/riseup-logs',
        '/exports',
        '/absences/dashboard',
        '/absences',
        '/absences/alertes',
        '/absences/apprenants',
        '/integrations',
        '/sync',
        '/roles',
        '/users',
    ];

    private const MAX_SHORTCUTS = 12;
    private const MAX_LABEL_LENGTH = 60;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'api_me_dashboard_preferences_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['message' => 'Unauthenticated.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json($user->getDashboardPreferences());
    }

    #[Route('', name: 'api_me_dashboard_preferences_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['message' => 'Unauthenticated.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = $request->toArray();

        $theme = (string) ($data['theme'] ?? '');
        if (!in_array($theme, self::ALLOWED_THEMES, true)) {
            return $this->json(['message' => 'Thème invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $kpis = $data['kpis'] ?? [];
        if (!is_array($kpis)) {
            return $this->json(['message' => 'Liste de KPI invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        foreach ($kpis as $kpi) {
            if (!is_string($kpi) || !in_array($kpi, self::ALLOWED_KPIS, true)) {
                return $this->json(['message' => 'Liste de KPI invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $shortcuts = $data['shortcuts'] ?? [];
        if (!is_array($shortcuts) || count($shortcuts) > self::MAX_SHORTCUTS) {
            return $this->json(['message' => 'Liste de raccourcis invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $normalizedShortcuts = [];
        foreach ($shortcuts as $shortcut) {
            if (!is_array($shortcut) || !isset($shortcut['to']) || !is_string($shortcut['to'])) {
                return $this->json(['message' => 'Raccourci invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!in_array($shortcut['to'], self::ALLOWED_SHORTCUT_TARGETS, true)) {
                return $this->json(['message' => 'Destination de raccourci invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $label = isset($shortcut['label']) && is_string($shortcut['label']) ? trim($shortcut['label']) : null;
            if ($label !== null && mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                return $this->json(['message' => 'Libellé de raccourci trop long.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $normalizedShortcuts[] = [
                'to' => $shortcut['to'],
                'label' => $label !== '' ? $label : null,
            ];
        }

        $user->setDashboardPreferences([
            'theme' => $theme,
            'kpis' => array_values($kpis),
            'shortcuts' => $normalizedShortcuts,
        ]);
        $this->entityManager->flush();

        return $this->json($user->getDashboardPreferences());
    }
}
