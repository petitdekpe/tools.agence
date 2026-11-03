<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Suivi des encaissements : clients, paramètres fiscaux et encaissements (suppression logique). */
final class Version20261022172212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suivi des encaissements : tables client, fiscal_setting et encaissement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(160) NOT NULL, ifu VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_C74404555E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE encaissement (id INT AUTO_INCREMENT NOT NULL, date_encaissement DATE NOT NULL, montant_ttc NUMERIC(15, 2) NOT NULL, montant_ht NUMERIC(15, 2) NOT NULL, tva_facturee NUMERIC(15, 2) NOT NULL, tva_prelevee_source NUMERIC(15, 2) NOT NULL, tva_areverser NUMERIC(15, 2) NOT NULL, aib_preleve_source NUMERIC(15, 2) NOT NULL, montant_net_recu NUMERIC(15, 2) NOT NULL, montant_especes_momo NUMERIC(15, 2) DEFAULT 0.00 NOT NULL, montant_cheque_virement NUMERIC(15, 2) DEFAULT 0.00 NOT NULL, retenue_tva_source TINYINT NOT NULL, taux_tva NUMERIC(5, 2) NOT NULL, taux_aib NUMERIC(5, 2) NOT NULL, observation LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, client_id INT NOT NULL, created_by_id INT DEFAULT NULL, deleted_by_id INT DEFAULT NULL, INDEX IDX_5D4869B0D2128E7A (date_encaissement), INDEX IDX_5D4869B04AF38FD1 (deleted_at), INDEX IDX_5D4869B019EB6921 (client_id), INDEX IDX_5D4869B0B03A8386 (created_by_id), INDEX IDX_5D4869B0C76F1F52 (deleted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fiscal_setting (id INT AUTO_INCREMENT NOT NULL, taux_tva NUMERIC(5, 2) NOT NULL, taux_aib_avec_ifu NUMERIC(5, 2) NOT NULL, taux_aib_sans_ifu NUMERIC(5, 2) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE encaissement ADD CONSTRAINT FK_5D4869B019EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE encaissement ADD CONSTRAINT FK_5D4869B0B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE encaissement ADD CONSTRAINT FK_5D4869B0C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encaissement DROP FOREIGN KEY FK_5D4869B019EB6921');
        $this->addSql('ALTER TABLE encaissement DROP FOREIGN KEY FK_5D4869B0B03A8386');
        $this->addSql('ALTER TABLE encaissement DROP FOREIGN KEY FK_5D4869B0C76F1F52');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE encaissement');
        $this->addSql('DROP TABLE fiscal_setting');
    }
}
