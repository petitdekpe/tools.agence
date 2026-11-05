<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Outils de type lien, gérés depuis les réglages du site. */
final class Version20261107090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table link_tool (outils de type lien)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE link_tool (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, description VARCHAR(255) DEFAULT NULL, url VARCHAR(500) NOT NULL, category VARCHAR(20) NOT NULL, icon VARCHAR(30) NOT NULL, badge VARCHAR(30) DEFAULT NULL, position INT NOT NULL, active TINYINT(1) NOT NULL, role_codes JSON NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_91B4AC78462CE4F5 (position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE link_tool');
    }
}
