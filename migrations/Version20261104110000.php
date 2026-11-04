<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calendrier éditorial (table publication) et dépenses et décaissements (table depense).
 */
final class Version20261104110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables publication (calendrier éditorial) et depense (dépenses et décaissements)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE publication (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(160) NOT NULL, canal VARCHAR(20) NOT NULL, format VARCHAR(20) NOT NULL, date_publication DATE NOT NULL, heure TIME DEFAULT NULL, statut VARCHAR(20) NOT NULL, contenu LONGTEXT DEFAULT NULL, lien VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, client_id INT DEFAULT NULL, responsable_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, deleted_by_id INT DEFAULT NULL, INDEX IDX_AF3C6779904552A5 (date_publication), INDEX IDX_AF3C67794AF38FD1 (deleted_at), INDEX IDX_AF3C677919EB6921 (client_id), INDEX IDX_AF3C677953C59D72 (responsable_id), INDEX IDX_AF3C6779B03A8386 (created_by_id), INDEX IDX_AF3C6779C76F1F52 (deleted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE publication ADD CONSTRAINT FK_AF3C677919EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE publication ADD CONSTRAINT FK_AF3C677953C59D72 FOREIGN KEY (responsable_id) REFERENCES personnel (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE publication ADD CONSTRAINT FK_AF3C6779B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE publication ADD CONSTRAINT FK_AF3C6779C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE depense (id INT AUTO_INCREMENT NOT NULL, date_depense DATE NOT NULL, fournisseur VARCHAR(160) NOT NULL, categorie VARCHAR(20) NOT NULL, libelle VARCHAR(160) NOT NULL, numero_piece VARCHAR(60) DEFAULT NULL, montant_ttc NUMERIC(15, 2) NOT NULL, tva_applicable TINYINT DEFAULT 1 NOT NULL, montant_ht NUMERIC(15, 2) NOT NULL, montant_tva NUMERIC(15, 2) NOT NULL, taux_tva NUMERIC(5, 2) NOT NULL, echeance DATE DEFAULT NULL, date_paiement DATE DEFAULT NULL, mode VARCHAR(20) DEFAULT NULL, observation LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, created_by_id INT DEFAULT NULL, deleted_by_id INT DEFAULT NULL, INDEX IDX_34059757DE290AA (date_depense), INDEX IDX_3405975772E913B8 (date_paiement), INDEX IDX_340597574AF38FD1 (deleted_at), INDEX IDX_34059757B03A8386 (created_by_id), INDEX IDX_34059757C76F1F52 (deleted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE depense');
        $this->addSql('DROP TABLE publication');
    }
}
