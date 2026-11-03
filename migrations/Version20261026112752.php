<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Gestion du personnel et du matériel : fiches, inventaire, affectations, et permissions par rôle. */
final class Version20261026112752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables personnel, materiel, affectation et role_permission';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE affectation (id INT AUTO_INCREMENT NOT NULL, assigned_at DATE NOT NULL, returned_at DATE DEFAULT NULL, note LONGTEXT DEFAULT NULL, return_note LONGTEXT DEFAULT NULL, return_etat VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, materiel_id INT NOT NULL, personnel_id INT NOT NULL, assigned_by_id INT DEFAULT NULL, returned_by_id INT DEFAULT NULL, INDEX IDX_F4DD61D3530929C8 (returned_at), INDEX IDX_F4DD61D316880AAF (materiel_id), INDEX IDX_F4DD61D31C109075 (personnel_id), INDEX IDX_F4DD61D36E6F1246 (assigned_by_id), INDEX IDX_F4DD61D371AD87D9 (returned_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE materiel (id INT AUTO_INCREMENT NOT NULL, designation VARCHAR(160) NOT NULL, category VARCHAR(20) NOT NULL, reference VARCHAR(80) DEFAULT NULL, purchased_at DATE DEFAULT NULL, purchase_value NUMERIC(15, 2) DEFAULT NULL, etat VARCHAR(20) NOT NULL, observation LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, INDEX IDX_18D2B0914AF38FD1 (deleted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personnel (id INT AUTO_INCREMENT NOT NULL, last_name VARCHAR(80) NOT NULL, first_name VARCHAR(80) NOT NULL, job_title VARCHAR(120) DEFAULT NULL, department VARCHAR(120) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, hired_at DATE NOT NULL, left_at DATE DEFAULT NULL, contract_type VARCHAR(20) NOT NULL, contract_start DATE DEFAULT NULL, contract_end DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_A6BCF3DEA76ED395 (user_id), INDEX IDX_A6BCF3DEC808BA5AA9D1C132 (last_name, first_name), INDEX IDX_A6BCF3DE4AF38FD1 (deleted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE role_permission (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, permission VARCHAR(40) NOT NULL, granted TINYINT NOT NULL, UNIQUE INDEX UNIQ_6F7DF88657698A6AE04992AA (role, permission), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D316880AAF FOREIGN KEY (materiel_id) REFERENCES materiel (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D31C109075 FOREIGN KEY (personnel_id) REFERENCES personnel (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D36E6F1246 FOREIGN KEY (assigned_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D371AD87D9 FOREIGN KEY (returned_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE personnel ADD CONSTRAINT FK_A6BCF3DEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D316880AAF');
        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D31C109075');
        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D36E6F1246');
        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D371AD87D9');
        $this->addSql('ALTER TABLE personnel DROP FOREIGN KEY FK_A6BCF3DEA76ED395');
        $this->addSql('DROP TABLE affectation');
        $this->addSql('DROP TABLE materiel');
        $this->addSql('DROP TABLE personnel');
        $this->addSql('DROP TABLE role_permission');
    }
}
