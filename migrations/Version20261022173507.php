<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Nature de l'encaissement (facture, avance, solde, autre) ; les lignes existantes sont des paiements de facture. */
final class Version20261022173507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la nature du paiement aux encaissements';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encaissement ADD nature VARCHAR(20) DEFAULT \'facture\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE encaissement DROP nature');
    }
}
