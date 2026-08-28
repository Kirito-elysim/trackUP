<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826083543 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create learner_communications table (learner-level communication history, e.g. manual disciplinary emails, not tied to a single absence)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE learner_communications (id INT AUTO_INCREMENT NOT NULL, learner_id INT NOT NULL, actor_id INT DEFAULT NULL, type VARCHAR(40) NOT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', metadata JSON NOT NULL, INDEX IDX_LEARNER_COMMUNICATIONS_LEARNER (learner_id), INDEX IDX_LEARNER_COMMUNICATIONS_ACTOR (actor_id), INDEX IDX_LEARNER_COMMUNICATIONS_OCCURRED_AT (occurred_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE learner_communications ADD CONSTRAINT FK_LEARNER_COMMUNICATIONS_LEARNER FOREIGN KEY (learner_id) REFERENCES learners (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learner_communications ADD CONSTRAINT FK_LEARNER_COMMUNICATIONS_ACTOR FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE learner_communications DROP FOREIGN KEY FK_LEARNER_COMMUNICATIONS_LEARNER');
        $this->addSql('ALTER TABLE learner_communications DROP FOREIGN KEY FK_LEARNER_COMMUNICATIONS_ACTOR');
        $this->addSql('DROP TABLE learner_communications');
    }
}
