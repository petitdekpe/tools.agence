<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pannes et demandes de matériel (table ticket) et congés et absences (table absence).
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables ticket (pannes et demandes de matériel) et absence (congés et absences)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ticket (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, reference VARCHAR(30) NOT NULL, titre VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, categorie VARCHAR(20) DEFAULT NULL, priorite VARCHAR(10) NOT NULL, statut VARCHAR(20) NOT NULL, resolution LONGTEXT DEFAULT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, materiel_id INT DEFAULT NULL, handled_by_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, deleted_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_97A0ADA3AEA34913 (reference), INDEX IDX_97A0ADA38CDE5729E564F0BF (type, statut), INDEX IDX_97A0ADA34AF38FD1 (deleted_at), INDEX IDX_97A0ADA316880AAF (materiel_id), INDEX IDX_97A0ADA3FE65AF40 (handled_by_id), INDEX IDX_97A0ADA3B03A8386 (created_by_id), INDEX IDX_97A0ADA3C76F1F52 (deleted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA316880AAF FOREIGN KEY (materiel_id) REFERENCES materiel (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3FE65AF40 FOREIGN KEY (handled_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE absence (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, jours SMALLINT NOT NULL, statut VARCHAR(20) NOT NULL, motif LONGTEXT DEFAULT NULL, decision LONGTEXT DEFAULT NULL, decided_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, personnel_id INT NOT NULL, decided_by_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, deleted_by_id INT DEFAULT NULL, INDEX IDX_765AE0C952EBEEF51C22B544 (date_debut, date_fin), INDEX IDX_765AE0C94AF38FD1 (deleted_at), INDEX IDX_765AE0C91C109075 (personnel_id), INDEX IDX_765AE0C9E26B496B (decided_by_id), INDEX IDX_765AE0C9B03A8386 (created_by_id), INDEX IDX_765AE0C9C76F1F52 (deleted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_765AE0C91C109075 FOREIGN KEY (personnel_id) REFERENCES personnel (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_765AE0C9E26B496B FOREIGN KEY (decided_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_765AE0C9B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_765AE0C9C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE absence');
        $this->addSql('DROP TABLE ticket');
    }
}
