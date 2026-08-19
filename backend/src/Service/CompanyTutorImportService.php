<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Learner;
use App\Entity\Prospect;
use App\Entity\Tutor;
use Doctrine\ORM\EntityManagerInterface;

class CompanyTutorImportService
{
    // Le fichier fourni par Laurie a 2 lignes décoratives (titre + groupes) avant la vraie
    // ligne d'en-têtes — les libellés Nom/Prénom/Email/Tel se répètent (apprenti puis tuteur),
    // donc la lecture se fait par position de colonne, pas par nom.
    private const HEADER_ROW_INDEX = 2;
    private const COLUMN_COUNT = 15;
    private const EXPECTED_HEADERS = [
        'Nom', 'Prénom', 'Email', 'Tel',
        'Nom', 'Prénom', 'Email', 'Tel',
        'Entreprise', 'Ville', 'Formation suivie', 'Année', 'Début', 'Fin', 'Durée',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly XlsxRawReader $xlsxReader,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function import(string $filePath, ?string $fileExtension = null): array
    {
        $rows = $this->readRows($filePath, $fileExtension);

        $summary = [
            'rowsRead' => 0,
            'companiesCreated' => 0,
            'companiesMatched' => 0,
            'tutorsCreated' => 0,
            'tutorsMatched' => 0,
            'learnersLinked' => 0,
            'learnersNotFound' => 0,
            'prospectsCreated' => 0,
            'prospectsUpdated' => 0,
            'rowsSkipped' => 0,
        ];

        if (count($rows) <= self::HEADER_ROW_INDEX) {
            return $summary;
        }

        $this->assertExpectedHeader(array_map($this->normalizeCell(...), $rows[self::HEADER_ROW_INDEX]));

        /** @var array<string, Company> $companyCache */
        $companyCache = [];
        /** @var array<string, Tutor> $tutorCache */
        $tutorCache = [];

        for ($i = self::HEADER_ROW_INDEX + 1; $i < count($rows); ++$i) {
            $cells = array_map(
                $this->normalizeCell(...),
                array_pad($rows[$i], self::COLUMN_COUNT, '')
            );

            if ($this->isBlankRow($cells) || $this->isSectionMarkerRow($cells)) {
                continue;
            }

            $learnerEmail = $cells[2];
            $learnerPhone = $cells[3];
            $tutorLastName = $cells[4];
            $tutorFirstName = $cells[5];
            $tutorEmailRaw = $cells[6];
            $tutorPhone = $cells[7];
            $companyName = $cells[8];
            $city = $cells[9];

            $summary['rowsRead']++;

            if ($learnerEmail === '' || $tutorLastName === '') {
                $summary['rowsSkipped']++;
                continue;
            }

            if ($learnerPhone !== '') {
                $prospectOutcome = $this->upsertProspectPhone($learnerEmail, $learnerPhone);
                if ($prospectOutcome !== null) {
                    $summary[$prospectOutcome]++;
                }
            }

            $company = null;
            if ($companyName !== '') {
                [$company, $companyWasCreated] = $this->findOrCreateCompany(
                    $companyName,
                    $city,
                    $tutorFirstName,
                    $tutorLastName,
                    $tutorEmailRaw,
                    $tutorPhone,
                    $companyCache
                );
                $summary[$companyWasCreated ? 'companiesCreated' : 'companiesMatched']++;
            }

            [$tutor, $tutorWasCreated] = $this->findOrCreateTutor($tutorFirstName, $tutorLastName, $tutorEmailRaw, $tutorPhone, $tutorCache);
            $summary[$tutorWasCreated ? 'tutorsCreated' : 'tutorsMatched']++;

            if ($company instanceof Company) {
                $tutor->addCompany($company);
            }

            $learner = $this->entityManager->getRepository(Learner::class)->findOneBy(['email' => strtolower($learnerEmail)]);

            if ($learner instanceof Learner) {
                $learner->setTutor($tutor);
                $learner->setCompany($company);
                $summary['learnersLinked']++;
            } else {
                $summary['learnersNotFound']++;
            }
        }

        $this->entityManager->flush();

        return $summary;
    }

    /**
     * @param array<string, Company> $cache
     *
     * @return array{0: Company, 1: bool}
     */
    private function findOrCreateCompany(
        string $name,
        string $city,
        string $tutorFirstName,
        string $tutorLastName,
        string $tutorEmailRaw,
        string $tutorPhone,
        array &$cache
    ): array {
        $key = mb_strtolower($name);

        if (isset($cache[$key])) {
            return [$cache[$key], false];
        }

        $company = $this->entityManager->getRepository(Company::class)->createQueryBuilder('c')
            ->where('LOWER(c.name) = :name')
            ->setParameter('name', $key)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $wasCreated = !$company instanceof Company;

        if ($wasCreated) {
            [$postalCode, $cityName] = $this->parsePostalCodeAndCity($city);
            $company = (new Company())->setName($name)->setPostalCode($postalCode)->setCity($cityName);
            $this->entityManager->persist($company);
        }

        // Le fichier n'a pas de colonne "contact entreprise" dédiée : le tuteur de la ligne sert
        // de contact par défaut. On ne renseigne que les champs encore vides (à la création comme
        // sur un ré-import), pour ne jamais écraser une correction faite manuellement par l'admin.
        $contactName = trim($tutorFirstName . ' ' . $tutorLastName);
        if ($contactName !== '' && $company->getContactName() === null) {
            $company->setContactName($contactName);
        }

        $tutorEmail = filter_var($tutorEmailRaw, FILTER_VALIDATE_EMAIL) !== false ? $tutorEmailRaw : null;
        if ($tutorEmail !== null && $company->getContactEmail() === null) {
            $company->setContactEmail($tutorEmail);
        }

        if ($tutorPhone !== '') {
            if ($this->isMobileNumber($tutorPhone)) {
                if ($company->getContactPhoneMobile() === null) {
                    $company->setContactPhoneMobile($tutorPhone);
                }
            } elseif ($company->getContactPhoneFixe() === null) {
                $company->setContactPhoneFixe($tutorPhone);
            }
        }

        $cache[$key] = $company;

        return [$company, $wasCreated];
    }

    /**
     * @param array<string, Tutor> $cache
     *
     * @return array{0: Tutor, 1: bool}
     */
    private function findOrCreateTutor(string $firstName, string $lastName, string $emailRaw, string $phone, array &$cache): array
    {
        $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) !== false ? strtolower($emailRaw) : null;
        $key = $email ?? mb_strtolower($firstName . '|' . $lastName);

        if (isset($cache[$key])) {
            return [$cache[$key], false];
        }

        $tutor = $email !== null
            ? $this->entityManager->getRepository(Tutor::class)->findOneBy(['email' => $email])
            : $this->entityManager->getRepository(Tutor::class)->createQueryBuilder('t')
                ->where('LOWER(t.firstName) = :first AND LOWER(t.lastName) = :last')
                ->setParameter('first', mb_strtolower($firstName))
                ->setParameter('last', mb_strtolower($lastName))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

        $wasCreated = !$tutor instanceof Tutor;

        if ($wasCreated) {
            $tutor = (new Tutor())
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setEmail($email);

            if ($phone !== '') {
                $this->isMobileNumber($phone) ? $tutor->setPhoneMobile($phone) : $tutor->setPhoneFixe($phone);
            }

            $this->entityManager->persist($tutor);
        }

        $cache[$key] = $tutor;

        return [$tutor, $wasCreated];
    }

    // Ne renseigne le téléphone que s'il est absent : un ré-import ne doit jamais écraser une
    // valeur déjà corrigée/complétée manuellement par l'admin sur la fiche prospect.
    private function upsertProspectPhone(string $email, string $phone): ?string
    {
        $email = strtolower($email);
        $prospect = $this->entityManager->getRepository(Prospect::class)->findOneBy(['email' => $email]);
        $isMobile = $this->isMobileNumber($phone);

        if (!$prospect instanceof Prospect) {
            $prospect = (new Prospect())->setEmail($email);
            $isMobile ? $prospect->setPhoneMobile($phone) : $prospect->setPhoneFixe($phone);
            $this->entityManager->persist($prospect);

            return 'prospectsCreated';
        }

        if ($isMobile && $prospect->getPhoneMobile() === null) {
            $prospect->setPhoneMobile($phone);

            return 'prospectsUpdated';
        }

        if (!$isMobile && $prospect->getPhoneFixe() === null) {
            $prospect->setPhoneFixe($phone);

            return 'prospectsUpdated';
        }

        return null;
    }

    // Un numéro français est considéré "mobile" s'il commence par 06/07 (ou l'équivalent
    // +336/+337, avec ou sans espace après l'indicatif) — sinon "fixe".
    private function isMobileNumber(string $phone): bool
    {
        return preg_match('/^(\+33\s?[67]|0[67])/', $phone) === 1;
    }

    /**
     * @return array{0: ?string, 1: ?string} [postalCode, city]
     */
    private function parsePostalCodeAndCity(string $raw): array
    {
        if ($raw === '') {
            return [null, null];
        }

        if (preg_match('/^\s*(\S+)\s*-\s*(.+)$/', $raw, $matches) === 1) {
            return [$matches[1], trim($matches[2])];
        }

        return [null, $raw];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readRows(string $filePath, ?string $fileExtension = null): array
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException(sprintf('File not found: %s', $filePath));
        }

        $extension = $fileExtension ?? strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->readCsvRows($filePath),
            'xlsx' => $this->xlsxReader->readRows($filePath),
            default => throw new \RuntimeException(sprintf('Unsupported file extension: .%s', $extension)),
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsvRows(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open file: %s', $filePath));
        }

        try {
            $rows = [];
            while (($line = fgetcsv($handle, null, ';')) !== false) {
                $rows[] = $line;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string[] $header
     */
    private function assertExpectedHeader(array $header): void
    {
        $header = array_pad(array_slice($header, 0, self::COLUMN_COUNT), self::COLUMN_COUNT, '');

        if ($header !== self::EXPECTED_HEADERS) {
            throw new \RuntimeException(sprintf(
                'Format de fichier inattendu : la ligne d\'en-têtes (ligne %d) ne correspond pas à celle attendue. '
                . 'Attendu : %s. Trouvé : %s.',
                self::HEADER_ROW_INDEX + 1,
                implode(' | ', self::EXPECTED_HEADERS),
                implode(' | ', $header)
            ));
        }
    }

    /**
     * @param string[] $cells
     */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $cells
     */
    private function isSectionMarkerRow(array $cells): bool
    {
        if ($cells[0] === '') {
            return false;
        }

        for ($i = 1; $i < self::COLUMN_COUNT; ++$i) {
            if ($cells[$i] !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCell(mixed $raw): string
    {
        $value = (string) $raw;

        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        return trim($value);
    }
}
