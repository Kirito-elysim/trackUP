<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create TrackUp local mirror tables for learners, trainings, sessions and activity data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE learners (id INT AUTO_INCREMENT NOT NULL, external_id INT NOT NULL, username VARCHAR(180) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, first_name VARCHAR(120) DEFAULT NULL, last_name VARCHAR(120) DEFAULT NULL, language VARCHAR(10) DEFAULT NULL, phone_number VARCHAR(40) DEFAULT NULL, timezone VARCHAR(80) DEFAULT NULL, rise_up_role VARCHAR(80) DEFAULT NULL, type VARCHAR(80) DEFAULT NULL, state VARCHAR(80) DEFAULT NULL, activated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', suspended_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_login_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_B6FBC942D7707B45 (external_id), UNIQUE INDEX UNIQ_B6FBC942E7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE trainings (id INT AUTO_INCREMENT NOT NULL, external_id INT NOT NULL, title VARCHAR(255) NOT NULL, reference VARCHAR(120) DEFAULT NULL, description LONGTEXT DEFAULT NULL, language VARCHAR(10) DEFAULT NULL, objective LONGTEXT DEFAULT NULL, edu_duration INT DEFAULT NULL, state VARCHAR(80) DEFAULT NULL, external_link LONGTEXT DEFAULT NULL, type VARCHAR(80) DEFAULT NULL, sequential TINYINT(1) DEFAULT NULL, rise_up_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_4B96F0E1D7707B45 (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE training_modules (id INT AUTO_INCREMENT NOT NULL, training_id INT NOT NULL, external_id INT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, edu_duration INT DEFAULT NULL, duration INT DEFAULT NULL, type VARCHAR(80) DEFAULT NULL, reference VARCHAR(120) DEFAULT NULL, position INT DEFAULT NULL, language VARCHAR(10) DEFAULT NULL, rise_up_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_C3121D3F5FB14BA7 (training_id), UNIQUE INDEX UNIQ_C3121D3FD7707B45 (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE training_steps (id INT AUTO_INCREMENT NOT NULL, module_id INT NOT NULL, external_id INT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(80) DEFAULT NULL, position INT DEFAULT NULL, content LONGTEXT DEFAULT NULL, reference VARCHAR(120) DEFAULT NULL, rise_up_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_E740F33AAFC2B591 (module_id), UNIQUE INDEX UNIQ_E740F33AD7707B45 (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE training_registrations (id INT AUTO_INCREMENT NOT NULL, learner_id INT NOT NULL, training_id INT NOT NULL, external_id INT NOT NULL, company_external_id INT DEFAULT NULL, validator_external_id INT DEFAULT NULL, registered_by_external_id INT DEFAULT NULL, state VARCHAR(80) DEFAULT NULL, total_time INT DEFAULT NULL, progress DOUBLE PRECISION DEFAULT NULL, score DOUBLE PRECISION DEFAULT NULL, force_finished TINYINT(1) DEFAULT NULL, reference VARCHAR(120) DEFAULT NULL, subscribed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', training_end_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', course_period_external_id INT DEFAULT NULL, synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_256A4E1496E27F6 (learner_id), INDEX IDX_256A4E15FB14BA7 (training_id), UNIQUE INDEX UNIQ_256A4E1D7707B45 (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE learner_step_states (id INT AUTO_INCREMENT NOT NULL, learner_id INT NOT NULL, step_id INT NOT NULL, external_id INT NOT NULL, state VARCHAR(80) DEFAULT NULL, time_spent INT DEFAULT NULL, total_time INT DEFAULT NULL, score DOUBLE PRECISION DEFAULT NULL, activity_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_EE9EE6E896E27F6 (learner_id), INDEX IDX_EE9EE6E99DD0041D (step_id), UNIQUE INDEX UNIQ_EE9EE6E8D7707B45 (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE classroom_sessions (id INT AUTO_INCREMENT NOT NULL, module_id INT DEFAULT NULL, training_id INT DEFAULT NULL, external_id INT NOT NULL, start_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', end_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', state VARCHAR(80) DEFAULT NULL, session_type VARCHAR(80) DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, seats INT DEFAULT NULL, meeting_url LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, room VARCHAR(255) DEFAULT NULL, language VARCHAR(10) DEFAULT NULL, edu_duration INT DEFAULT NULL, reference VARCHAR(120) DEFAULT NULL, subscription_count INT DEFAULT NULL, rise_up_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_41E71CC9AFC2B591 (module_id), INDEX IDX_41E71CC95FB14BA7 (training_id), UNIQUE INDEX UNIQ_41E71CC9D7707B45 (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE classroom_session_registrations (id INT AUTO_INCREMENT NOT NULL, learner_id INT NOT NULL, session_id INT NOT NULL, training_registration_id INT DEFAULT NULL, external_id INT NOT NULL, subscribed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', state VARCHAR(80) DEFAULT NULL, attended TINYINT(1) DEFAULT NULL, reference VARCHAR(120) DEFAULT NULL, edu_duration INT DEFAULT NULL, rise_up_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rise_up_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_7E3A087496E27F6 (learner_id), INDEX IDX_7E3A0874613FECDF (session_id), INDEX IDX_7E3A0874B741D53A (training_registration_id), UNIQUE INDEX UNIQ_7E3A0874D7707B45 (external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE classroom_session_signatures (id INT AUTO_INCREMENT NOT NULL, registration_id INT NOT NULL, attendance_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', period VARCHAR(40) NOT NULL, has_signed TINYINT(1) NOT NULL, signature_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_35A0B5CFB333E9F7 (registration_id), UNIQUE INDEX classroom_signature_unique (registration_id, attendance_date, period), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE training_modules ADD CONSTRAINT FK_C3121D3F5FB14BA7 FOREIGN KEY (training_id) REFERENCES trainings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_steps ADD CONSTRAINT FK_E740F33AAFC2B591 FOREIGN KEY (module_id) REFERENCES training_modules (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_registrations ADD CONSTRAINT FK_256A4E1496E27F6 FOREIGN KEY (learner_id) REFERENCES learners (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_registrations ADD CONSTRAINT FK_256A4E15FB14BA7 FOREIGN KEY (training_id) REFERENCES trainings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learner_step_states ADD CONSTRAINT FK_EE9EE6E896E27F6 FOREIGN KEY (learner_id) REFERENCES learners (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learner_step_states ADD CONSTRAINT FK_EE9EE6E99DD0041D FOREIGN KEY (step_id) REFERENCES training_steps (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_sessions ADD CONSTRAINT FK_41E71CC9AFC2B591 FOREIGN KEY (module_id) REFERENCES training_modules (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE classroom_sessions ADD CONSTRAINT FK_41E71CC95FB14BA7 FOREIGN KEY (training_id) REFERENCES trainings (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE classroom_session_registrations ADD CONSTRAINT FK_7E3A087496E27F6 FOREIGN KEY (learner_id) REFERENCES learners (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_session_registrations ADD CONSTRAINT FK_7E3A0874613FECDF FOREIGN KEY (session_id) REFERENCES classroom_sessions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE classroom_session_registrations ADD CONSTRAINT FK_7E3A0874B741D53A FOREIGN KEY (training_registration_id) REFERENCES training_registrations (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE classroom_session_signatures ADD CONSTRAINT FK_35A0B5CFB333E9F7 FOREIGN KEY (registration_id) REFERENCES classroom_session_registrations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_modules DROP FOREIGN KEY FK_C3121D3F5FB14BA7');
        $this->addSql('ALTER TABLE training_steps DROP FOREIGN KEY FK_E740F33AAFC2B591');
        $this->addSql('ALTER TABLE training_registrations DROP FOREIGN KEY FK_256A4E1496E27F6');
        $this->addSql('ALTER TABLE training_registrations DROP FOREIGN KEY FK_256A4E15FB14BA7');
        $this->addSql('ALTER TABLE learner_step_states DROP FOREIGN KEY FK_EE9EE6E896E27F6');
        $this->addSql('ALTER TABLE learner_step_states DROP FOREIGN KEY FK_EE9EE6E99DD0041D');
        $this->addSql('ALTER TABLE classroom_sessions DROP FOREIGN KEY FK_41E71CC9AFC2B591');
        $this->addSql('ALTER TABLE classroom_sessions DROP FOREIGN KEY FK_41E71CC95FB14BA7');
        $this->addSql('ALTER TABLE classroom_session_registrations DROP FOREIGN KEY FK_7E3A087496E27F6');
        $this->addSql('ALTER TABLE classroom_session_registrations DROP FOREIGN KEY FK_7E3A0874613FECDF');
        $this->addSql('ALTER TABLE classroom_session_registrations DROP FOREIGN KEY FK_7E3A0874B741D53A');
        $this->addSql('ALTER TABLE classroom_session_signatures DROP FOREIGN KEY FK_35A0B5CFB333E9F7');
        $this->addSql('DROP TABLE classroom_session_signatures');
        $this->addSql('DROP TABLE classroom_session_registrations');
        $this->addSql('DROP TABLE classroom_sessions');
        $this->addSql('DROP TABLE learner_step_states');
        $this->addSql('DROP TABLE training_registrations');
        $this->addSql('DROP TABLE training_steps');
        $this->addSql('DROP TABLE training_modules');
        $this->addSql('DROP TABLE trainings');
        $this->addSql('DROP TABLE learners');
    }
}
