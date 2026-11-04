<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Mot de passe temporaire avec échéance et lien de récupération du compte (authentificateur indisponible). */
final class Version20261103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'app_user : échéance du mot de passe temporaire, jeton de récupération';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD password_expires_at DATETIME DEFAULT NULL, ADD recovery_token_hash VARCHAR(64) DEFAULT NULL, ADD recovery_token_expires_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP password_expires_at, DROP recovery_token_hash, DROP recovery_token_expires_at');
    }
}
