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
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email', 'admin@trackup.local')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password', 'TrackUp123!');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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

        $adminEmail = (string) $input->getOption('admin-email');
        $adminPassword = (string) $input->getOption('admin-password');

        $adminUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => strtolower($adminEmail)]) ?? new User();
        $adminUser
            ->setEmail($adminEmail)
            ->setFirstName('Track')
            ->setLastName('Admin')
            ->setActive(true)
            ->setPassword($this->passwordHasher->hashPassword($adminUser, $adminPassword));

        if (!$adminUser->getRoleEntities()->contains($adminRole)) {
            $adminUser->addRoleEntity($adminRole);
        }

        $this->entityManager->persist($adminUser);
        $this->entityManager->flush();

        $io->success(sprintf('RBAC initialisé. Compte admin: %s / %s', $adminEmail, $adminPassword));

        return Command::SUCCESS;
    }
}
