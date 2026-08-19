<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819110000 extends AbstractMigration
{
    // Un numéro français est classé "mobile" s'il commence par 06/07 (ou l'équivalent +336/+337,
    // avec ou sans espace après l'indicatif) — sinon "fixe". Utilisé pour scinder les anciennes
    // colonnes "phone" uniques lors du backfill.
    private const MOBILE_PATTERN = '^(\\\\+33[[:space:]]?[67]|0[67])';

    public function getDescription(): string
    {
        return 'Split company/tutor/prospect contact info into postal code, city, mobile/fixed phone and date of birth';
    }

    public function up(Schema $schema): void
    {
        // --- companies : adresse "DD - VILLE" (issue de l'import) -> code postal + ville séparés ---
        $this->addSql('ALTER TABLE companies ADD postal_code VARCHAR(10) DEFAULT NULL, ADD city VARCHAR(120) DEFAULT NULL');
        $this->addSql(
            'UPDATE companies SET '
            . 'postal_code = TRIM(SUBSTRING_INDEX(address, \'-\', 1)), '
            . 'city = TRIM(SUBSTRING(address, LOCATE(\'-\', address) + 1)), '
            . 'address = NULL '
            . 'WHERE address LIKE \'%-%\''
        );

        $this->addSql('ALTER TABLE companies ADD contact_phone_mobile VARCHAR(40) DEFAULT NULL, ADD contact_phone_fixe VARCHAR(40) DEFAULT NULL');
        $this->addSql('UPDATE companies SET contact_phone_mobile = contact_phone WHERE contact_phone REGEXP \'' . self::MOBILE_PATTERN . '\'');
        $this->addSql('UPDATE companies SET contact_phone_fixe = contact_phone WHERE contact_phone IS NOT NULL AND contact_phone_mobile IS NULL');
        $this->addSql('ALTER TABLE companies DROP contact_phone');

        // --- tutors : téléphone unique -> mobile/fixe, + adresse/code postal/ville/date de naissance (nouveaux) ---
        $this->addSql(
            'ALTER TABLE tutors ADD phone_mobile VARCHAR(40) DEFAULT NULL, ADD phone_fixe VARCHAR(40) DEFAULT NULL, '
            . 'ADD address LONGTEXT DEFAULT NULL, ADD postal_code VARCHAR(10) DEFAULT NULL, ADD city VARCHAR(120) DEFAULT NULL, '
            . 'ADD date_of_birth DATE DEFAULT NULL'
        );
        $this->addSql('UPDATE tutors SET phone_mobile = phone WHERE phone REGEXP \'' . self::MOBILE_PATTERN . '\'');
        $this->addSql('UPDATE tutors SET phone_fixe = phone WHERE phone IS NOT NULL AND phone_mobile IS NULL');
        $this->addSql('ALTER TABLE tutors DROP phone');

        // --- prospects : téléphone unique -> mobile/fixe, + code postal/ville/date de naissance (nouveaux) ---
        $this->addSql(
            'ALTER TABLE prospects ADD phone_mobile VARCHAR(40) DEFAULT NULL, ADD phone_fixe VARCHAR(40) DEFAULT NULL, '
            . 'ADD postal_code VARCHAR(10) DEFAULT NULL, ADD city VARCHAR(120) DEFAULT NULL, ADD date_of_birth DATE DEFAULT NULL'
        );
        $this->addSql('UPDATE prospects SET phone_mobile = phone WHERE phone REGEXP \'' . self::MOBILE_PATTERN . '\'');
        $this->addSql('UPDATE prospects SET phone_fixe = phone WHERE phone IS NOT NULL AND phone_mobile IS NULL');
        $this->addSql('ALTER TABLE prospects DROP phone');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD contact_phone VARCHAR(40) DEFAULT NULL');
        $this->addSql('UPDATE companies SET contact_phone = COALESCE(contact_phone_mobile, contact_phone_fixe)');
        $this->addSql('ALTER TABLE companies DROP contact_phone_mobile, DROP contact_phone_fixe');
        $this->addSql(
            'UPDATE companies SET address = CASE '
            . 'WHEN postal_code IS NOT NULL AND city IS NOT NULL THEN CONCAT(postal_code, \' - \', city) '
            . 'ELSE city END '
            . 'WHERE postal_code IS NOT NULL OR city IS NOT NULL'
        );
        $this->addSql('ALTER TABLE companies DROP postal_code, DROP city');

        $this->addSql('ALTER TABLE tutors ADD phone VARCHAR(40) DEFAULT NULL');
        $this->addSql('UPDATE tutors SET phone = COALESCE(phone_mobile, phone_fixe)');
        $this->addSql('ALTER TABLE tutors DROP phone_mobile, DROP phone_fixe, DROP address, DROP postal_code, DROP city, DROP date_of_birth');

        $this->addSql('ALTER TABLE prospects ADD phone VARCHAR(40) DEFAULT NULL');
        $this->addSql('UPDATE prospects SET phone = COALESCE(phone_mobile, phone_fixe)');
        $this->addSql('ALTER TABLE prospects DROP phone_mobile, DROP phone_fixe, DROP postal_code, DROP city, DROP date_of_birth');
    }
}
