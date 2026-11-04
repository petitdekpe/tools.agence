<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quatre rôles d'équipe livrés avec l'application : Administration, RH et finance, Matériel et Stratégie.
 * Ce sont des rôles système : leurs permissions d'origine sont définies dans Permission::DEFAULTS, aucune ligne
 * role_permission n'est nécessaire. Un rôle déjà présent sous le même code ou le même nom est laissé intact.
 */
final class Version20261104130000 extends AbstractMigration
{
    private const ROLES = [
        ['ROLE_ADMINISTRATION', 'Administration', 'Fonctionnement de l\'organisation : espace d\'administration en lecture (tableau de bord, utilisateurs, journal), personnel, congés et suivi des pannes.'],
        ['ROLE_RH_FINANCE', 'RH et finance', 'Personnel et congés, encaissements, factures et devis, dépenses et décaissements.'],
        ['ROLE_MATERIEL', 'Matériel', 'Inventaire du matériel, remises et restitutions, traitement des pannes et des demandes.'],
        ['ROLE_STRATEGIE', 'Stratégie', 'Média et production : correcteur, projets et missions (programmation), calendrier éditorial et CRM.'],
    ];

    public function getDescription(): string
    {
        return 'Rôles système Administration, RH et finance, Matériel et Stratégie';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROLES as [$code, $label, $description]) {
            $this->addSql(
                'INSERT INTO app_role (code, label, description, is_system, created_at) SELECT ?, ?, ?, 1, NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM app_role WHERE code = ? OR label = ?)',
                [$code, $label, $description, $code, $label],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Les utilisateurs encore rattachés à l'un de ces rôles repassent en « Agent » avant sa suppression.
        foreach (self::ROLES as [$code]) {
            $this->addSql('UPDATE app_user SET role_id = (SELECT id FROM (SELECT id FROM app_role WHERE code = \'ROLE_AGENT\') agent) WHERE role_id = (SELECT id FROM (SELECT id FROM app_role WHERE code = ?) r)', [$code]);
            $this->addSql('DELETE FROM role_permission WHERE role_id = (SELECT id FROM (SELECT id FROM app_role WHERE code = ?) r)', [$code]);
            $this->addSql('DELETE FROM app_role WHERE code = ?', [$code]);
        }
    }
}
