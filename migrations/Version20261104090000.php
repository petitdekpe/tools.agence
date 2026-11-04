<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suivi des factures et devis : table document (type, numéro unique, client, montants, statut, dates)
 * et encaissement.facture_id pour rattacher un paiement à la facture qu'il règle.
 */
final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table document (factures et devis) et encaissement.facture_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, reference VARCHAR(30) NOT NULL, objet VARCHAR(200) NOT NULL, date_emission DATE NOT NULL, date_limite DATE DEFAULT NULL, statut VARCHAR(20) NOT NULL, montant_ttc NUMERIC(15, 2) NOT NULL, montant_ht NUMERIC(15, 2) NOT NULL, montant_tva NUMERIC(15, 2) NOT NULL, taux_tva NUMERIC(5, 2) NOT NULL, observation LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, client_id INT NOT NULL, source_devis_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, deleted_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D8698A76AEA34913 (reference), INDEX IDX_D8698A768CDE572933173552 (type, date_emission), INDEX IDX_D8698A764AF38FD1 (deleted_at), INDEX IDX_D8698A7619EB6921 (client_id), INDEX IDX_D8698A762FECC08 (source_devis_id), INDEX IDX_D8698A76B03A8386 (created_by_id), INDEX IDX_D8698A76C76F1F52 (deleted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7619EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A762FECC08 FOREIGN KEY (source_devis_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE encaissement ADD facture_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE encaissement ADD CONSTRAINT FK_5D4869B07F2DEE08 FOREIGN KEY (facture_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5D4869B07F2DEE08 ON encaissement (facture_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encaissement DROP FOREIGN KEY FK_5D4869B07F2DEE08');
        $this->addSql('DROP INDEX IDX_5D4869B07F2DEE08 ON encaissement');
        $this->addSql('ALTER TABLE encaissement DROP facture_id');
        $this->addSql('DROP TABLE document');
    }
}
