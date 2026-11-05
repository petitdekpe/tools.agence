<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Matériel : quantité du lot et article de la liste de référence. */
final class Version20261106090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Matériel : colonnes quantity et article';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE materiel ADD article VARCHAR(40) DEFAULT NULL, ADD quantity INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE materiel DROP article, DROP quantity');
    }
}
