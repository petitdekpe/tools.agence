<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suivi des projets et missions : tables projet, tache (tableaux Kanban), mission et mission_personnel (équipe de la mission).
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables projet, tache, mission et mission_personnel (projets et missions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE projet (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, date_debut DATE DEFAULT NULL, echeance DATE DEFAULT NULL, statut VARCHAR(20) NOT NULL, position INT NOT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, client_id INT DEFAULT NULL, responsable_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, deleted_by_id INT DEFAULT NULL, INDEX IDX_50159CA9E564F0BF462CE4F5 (statut, position), INDEX IDX_50159CA94AF38FD1 (deleted_at), INDEX IDX_50159CA919EB6921 (client_id), INDEX IDX_50159CA953C59D72 (responsable_id), INDEX IDX_50159CA9B03A8386 (created_by_id), INDEX IDX_50159CA9C76F1F52 (deleted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE projet ADD CONSTRAINT FK_50159CA919EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE projet ADD CONSTRAINT FK_50159CA953C59D72 FOREIGN KEY (responsable_id) REFERENCES personnel (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE projet ADD CONSTRAINT FK_50159CA9B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE projet ADD CONSTRAINT FK_50159CA9C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE tache (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, statut VARCHAR(20) NOT NULL, position INT NOT NULL, priorite VARCHAR(10) NOT NULL, echeance DATE DEFAULT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, projet_id INT NOT NULL, assignee_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_93872075E564F0BF462CE4F5 (statut, position), INDEX IDX_93872075C18272 (projet_id), INDEX IDX_9387207559EC7D60 (assignee_id), INDEX IDX_93872075B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075C18272 FOREIGN KEY (projet_id) REFERENCES projet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_9387207559EC7D60 FOREIGN KEY (assignee_id) REFERENCES personnel (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE mission (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(160) NOT NULL, lieu VARCHAR(200) NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, heure_debut TIME DEFAULT NULL, statut VARCHAR(20) NOT NULL, objectifs LONGTEXT DEFAULT NULL, resultat VARCHAR(20) DEFAULT NULL, compte_rendu LONGTEXT DEFAULT NULL, reported_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, projet_id INT DEFAULT NULL, client_id INT DEFAULT NULL, responsable_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, deleted_by_id INT DEFAULT NULL, INDEX IDX_9067F23C52EBEEF51C22B544 (date_debut, date_fin), INDEX IDX_9067F23C4AF38FD1 (deleted_at), INDEX IDX_9067F23CC18272 (projet_id), INDEX IDX_9067F23C19EB6921 (client_id), INDEX IDX_9067F23C53C59D72 (responsable_id), INDEX IDX_9067F23CB03A8386 (created_by_id), INDEX IDX_9067F23CC76F1F52 (deleted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CC18272 FOREIGN KEY (projet_id) REFERENCES projet (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23C19EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23C53C59D72 FOREIGN KEY (responsable_id) REFERENCES personnel (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CC76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE mission_personnel (mission_id INT NOT NULL, personnel_id INT NOT NULL, INDEX IDX_140904F7BE6CAE90 (mission_id), INDEX IDX_140904F71C109075 (personnel_id), PRIMARY KEY (mission_id, personnel_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mission_personnel ADD CONSTRAINT FK_140904F7BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mission_personnel ADD CONSTRAINT FK_140904F71C109075 FOREIGN KEY (personnel_id) REFERENCES personnel (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mission_personnel');
        $this->addSql('DROP TABLE mission');
        $this->addSql('DROP TABLE tache');
        $this->addSql('DROP TABLE projet');
    }
}
