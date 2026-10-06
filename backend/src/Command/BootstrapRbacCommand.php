<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Feature;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:bootstrap-rbac', description: 'Create default features, roles and an admin account.')]
class BootstrapRbacCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email')
            ->addOption('admin-password-stdin', null, InputOption::VALUE_NONE, 'Read the new admin password from standard input');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $adminEmail = strtolower(trim((string) $input->getOption('admin-email')));
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $io->error('Une adresse valide est obligatoire via --admin-email.');
            return Command::INVALID;
        }

        $adminUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $adminEmail]);
        if (!$adminUser instanceof User) {
            if ($input->getOption('admin-password-stdin')) {
                $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
                $adminPassword = rtrim((string) fgets($stream ?? STDIN, 4098), "\r\n");
            } elseif ($input->isInteractive()) {
                $question = (new Question('Mot de passe du nouvel administrateur (12 caractères minimum)'))
                    ->setHidden(true)->setHiddenFallback(false);
                $adminPassword = (string) $io->askQuestion($question);
            } else {
                $io->error('Utilisez le mode interactif ou --admin-password-stdin pour créer un administrateur.');
                return Command::INVALID;
            }

            if (mb_strlen($adminPassword) < 12 || strlen($adminPassword) > 4096) {
                $io->error('Le mot de passe doit contenir au moins 12 caractères et au plus 4096 octets.');
                return Command::INVALID;
            }
            $adminUser = (new User())->setEmail($adminEmail)->setFirstName('Track')->setLastName('Admin')->setActive(true);
            $adminUser->setPassword($this->passwordHasher->hashPassword($adminUser, $adminPassword));
            unset($adminPassword);
        }

        $featureMap = [
            ['code' => 'dashboard.view', 'name' => 'Dashboard', 'category' => 'Pilotage', 'description' => 'Voir le tableau de bord global.'],
            ['code' => 'analytics.view', 'name' => 'Analytics', 'category' => 'Pilotage', 'description' => 'Analyser l’activité, le temps tracé et la progression par période.'],
            ['code' => 'learningpaths.view', 'name' => 'Parcours', 'category' => 'Pilotage', 'description' => 'Consulter les parcours et leur synthèse.'],
            ['code' => 'learners.view', 'name' => 'Apprenants', 'category' => 'Pilotage', 'description' => 'Consulter les apprenants et leur suivi.'],
            ['code' => 'courses.view', 'name' => 'Formations', 'category' => 'Pilotage', 'description' => 'Consulter les formations et leur avancement.'],
            ['code' => 'exports.view', 'name' => 'Exports', 'category' => 'Pilotage', 'description' => 'Accéder aux exports PDF et CSV.'],
            ['code' => 'integrations.view', 'name' => 'Intégrations', 'category' => 'Pilotage', 'description' => 'Voir l’état des synchronisations Rise Up.'],
            ['code' => 'activity_logs.import', 'name' => 'Import de journaux Rise Up', 'category' => 'Administration', 'description' => 'Importer un export Rise Up (XLSX/CSV) dans les journaux d’activité — droit d’écriture, distinct de la consultation des exports.'],
            ['code' => 'settings.learningpaths', 'name' => 'Parcours', 'category' => 'Administration', 'description' => 'Superviser la synchronisation des parcours Rise Up.'],
            ['code' => 'settings.roles', 'name' => 'Rôles et permissions', 'category' => 'Administration', 'description' => 'Gérer les rôles et les droits.'],
            ['code' => 'settings.users', 'name' => 'Utilisateurs', 'category' => 'Administration', 'description' => 'Gérer les comptes utilisateurs internes.'],
        ];

        $features = [];
        foreach ($featureMap as $item) {
            $feature = $this->entityManager->getRepository(Feature::class)->findOneBy(['code' => $item['code']]) ?? new Feature();
            $feature
                ->setCode($item['code'])
                ->setName($item['name'])
                ->setCategory($item['category'])
                ->setDescription($item['description']);
            $this->entityManager->persist($feature);
            $features[$item['code']] = $feature;
        }

        $adminRole = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => 'ADMIN']) ?? new Role();
        $adminRole
            ->setCode('ADMIN')
            ->setName('Administrateur')
            ->setDescription('Accès complet à toutes les fonctionnalités.')
            ->setSystem(true);

        foreach ($features as $feature) {
            $adminRole->addFeature($feature);
        }
        $this->entityManager->persist($adminRole);

        $managerRole = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => 'MANAGER']) ?? new Role();
        $managerRole
            ->setCode('MANAGER')
            ->setName('Manager')
            ->setDescription('Accès au pilotage sans administration.')
            ->setSystem(true);

        foreach (['dashboard.view', 'analytics.view', 'learningpaths.view', 'learners.view', 'courses.view', 'exports.view', 'integrations.view'] as $code) {
            $managerRole->addFeature($features[$code]);
        }
        $this->entityManager->persist($managerRole);

        if (!$adminUser->getRoleEntities()->contains($adminRole)) {
            $adminUser->addRoleEntity($adminRole);
        }

        $this->entityManager->persist($adminUser);
        $this->entityManager->flush();

        $io->success(sprintf('RBAC initialisé pour %s. Les identifiants des comptes existants sont conservés.', $adminEmail));

        return Command::SUCCESS;
    }
}
