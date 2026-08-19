<?php
declare(strict_types=1);

namespace App\Validation;

// Règles de validation partagées pour les champs de contact saisis manuellement dans le module
// Entreprises/Tuteurs/Apprenants (Company, Tutor, Prospect) — factorisées ici pour rester
// cohérentes entre les 3 contrôleurs qui les appliquent.
class ContactInfoValidator
{
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // Numéro français : mobile (06/07) ou fixe (01-05/09), avec ou sans indicatif +33, séparateurs
    // (espaces/points/tirets) tolérés. Même famille de format que
    // CompanyTutorImportService::isMobileNumber, généralisée aux deux catégories.
    public static function isValidPhone(string $phone): bool
    {
        $normalized = preg_replace('/[\s.\-]/', '', $phone) ?? $phone;

        return preg_match('/^(0[1-9]\d{8}|\+33[1-9]\d{8})$/', $normalized) === 1;
    }

    public static function isValidPostalCode(string $postalCode): bool
    {
        return preg_match('/^\d{5}$/', $postalCode) === 1;
    }
}
