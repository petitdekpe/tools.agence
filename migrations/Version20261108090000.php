<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Profil du personnel (naissance, résidence, urgence, réseaux, documents) et jeton d'abonnement au calendrier éditorial. */
final class Version20261108090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profil du personnel, documents du personnel et jeton de calendrier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personnel ADD birth_date DATE DEFAULT NULL, ADD adventure_started_at DATE DEFAULT NULL, ADD residence VARCHAR(255) DEFAULT NULL, ADD residence_map_url VARCHAR(500) DEFAULT NULL, ADD personal_email VARCHAR(180) DEFAULT NULL, ADD emergency1_name VARCHAR(120) DEFAULT NULL, ADD emergency1_phone VARCHAR(40) DEFAULT NULL, ADD emergency2_name VARCHAR(120) DEFAULT NULL, ADD emergency2_phone VARCHAR(40) DEFAULT NULL, ADD socials JSON DEFAULT NULL');
        $this->addSql('CREATE TABLE personnel_document (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(12) NOT NULL, path VARCHAR(80) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, created_at DATETIME NOT NULL, personnel_id INT NOT NULL, INDEX IDX_731891331C1090753BC4BCD9 (personnel_id, kind), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE personnel_document ADD CONSTRAINT FK_731891331C109075 FOREIGN KEY (personnel_id) REFERENCES personnel (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_user ADD calendar_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E93363A255 ON app_user (calendar_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_88BDF3E93363A255 ON app_user');
        $this->addSql('ALTER TABLE app_user DROP calendar_token');
        $this->addSql('DROP TABLE personnel_document');
        $this->addSql('ALTER TABLE personnel DROP birth_date, DROP adventure_started_at, DROP residence, DROP residence_map_url, DROP personal_email, DROP emergency1_name, DROP emergency1_phone, DROP emergency2_name, DROP emergency2_phone, DROP socials');
    }
}
