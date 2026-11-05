<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Listes de choix gérées depuis l'administration (catégories, canaux, priorités...). Une liste jamais modifiée n'a aucune ligne : ses valeurs d'origine s'appliquent. */
final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table app_choice : valeurs des listes de choix modifiables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_choice (id INT AUTO_INCREMENT NOT NULL, list_key VARCHAR(40) NOT NULL, code VARCHAR(40) NOT NULL, label VARCHAR(80) NOT NULL, position INT NOT NULL, UNIQUE INDEX uniq_choice_list_code (list_key, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_choice');
    }
}
