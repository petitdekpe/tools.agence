<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les rôles deviennent des données : table app_role (avec les trois rôles d'origine), app_user.role et
 * role_permission.role passent de simples textes à une référence vers app_role. Les comptes et les
 * permissions déjà réglées sont conservés.
 *
 * down() revient aux textes ; un rôle créé depuis l'administration n'existe pas dans l'ancien modèle :
 * ses utilisateurs deviennent « Agent » et ses permissions sont perdues.
 */
final class Version20261026153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table app_role ; app_user.role et role_permission.role deviennent des références';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_role (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(60) NOT NULL, label VARCHAR(60) NOT NULL, description VARCHAR(255) DEFAULT NULL, is_system TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_5247AFCA77153098 (code), UNIQUE INDEX UNIQ_5247AFCAEA750E8 (label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $system = [
            ['ROLE_ADMIN', 'Admin', 'Accès complet : utilisateurs, rôles, réglages et tous les outils. Ce rôle ne peut ni être restreint ni supprimé.'],
            ['ROLE_DIRECTION', 'Direction', 'Utilise les outils, gère le personnel et consulte l\'administration en lecture seule.'],
            ['ROLE_AGENT', 'Agent', 'Utilise les outils au quotidien, sans accès à l\'administration.'],
        ];
        foreach ($system as [$code, $label, $description]) {
            $this->addSql('INSERT INTO app_role (code, label, description, is_system, created_at) VALUES (?, ?, ?, 1, NOW())', [$code, $label, $description]);
        }

        // Comptes : un rôle inconnu retombe sur Agent, le moins privilégié.
        $this->addSql('ALTER TABLE app_user ADD role_id INT DEFAULT NULL');
        $this->addSql('UPDATE app_user u SET u.role_id = (SELECT r.id FROM app_role r WHERE r.code = u.role)');
        $this->addSql('UPDATE app_user SET role_id = (SELECT id FROM app_role WHERE code = \'ROLE_AGENT\') WHERE role_id IS NULL');
        $this->addSql('ALTER TABLE app_user MODIFY role_id INT NOT NULL, DROP role');
        $this->addSql('CREATE INDEX IDX_88BDF3E9D60322AC ON app_user (role_id)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E9D60322AC FOREIGN KEY (role_id) REFERENCES app_role (id) ON DELETE RESTRICT');

        // Permissions : les lignes d'un rôle inconnu n'ont plus de sens.
        $this->addSql('ALTER TABLE role_permission ADD role_id INT DEFAULT NULL');
        $this->addSql('UPDATE role_permission rp SET rp.role_id = (SELECT r.id FROM app_role r WHERE r.code = rp.role)');
        $this->addSql('DELETE FROM role_permission WHERE role_id IS NULL');
        $this->addSql('DROP INDEX UNIQ_6F7DF88657698A6AE04992AA ON role_permission');
        $this->addSql('ALTER TABLE role_permission MODIFY role_id INT NOT NULL, DROP role');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6F7DF886D60322ACE04992AA ON role_permission (role_id, permission)');
        $this->addSql('CREATE INDEX IDX_6F7DF886D60322AC ON role_permission (role_id)');
        $this->addSql('ALTER TABLE role_permission ADD CONSTRAINT FK_6F7DF886D60322AC FOREIGN KEY (role_id) REFERENCES app_role (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->dropForeignKeyIfExists('app_user', 'FK_88BDF3E9D60322AC');
        $this->dropForeignKeyIfExists('role_permission', 'FK_6F7DF886D60322AC');

        $this->addSql('ALTER TABLE app_user ADD role VARCHAR(20) DEFAULT NULL');
        $this->addSql('UPDATE app_user u JOIN app_role r ON r.id = u.role_id SET u.role = CASE WHEN r.code IN (\'ROLE_ADMIN\', \'ROLE_DIRECTION\', \'ROLE_AGENT\') THEN r.code ELSE \'ROLE_AGENT\' END');
        $this->addSql('DROP INDEX IDX_88BDF3E9D60322AC ON app_user');
        $this->addSql('ALTER TABLE app_user MODIFY role VARCHAR(20) NOT NULL, DROP role_id');

        $this->addSql('ALTER TABLE role_permission ADD role VARCHAR(20) DEFAULT NULL');
        $this->addSql('UPDATE role_permission rp JOIN app_role r ON r.id = rp.role_id SET rp.role = r.code');
        $this->addSql('DELETE FROM role_permission WHERE role IS NULL OR role NOT IN (\'ROLE_AGENT\', \'ROLE_DIRECTION\')');
        $this->addSql('DROP INDEX UNIQ_6F7DF886D60322ACE04992AA ON role_permission');
        $this->addSql('DROP INDEX IDX_6F7DF886D60322AC ON role_permission');
        $this->addSql('ALTER TABLE role_permission MODIFY role VARCHAR(20) NOT NULL, DROP role_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6F7DF88657698A6AE04992AA ON role_permission (role, permission)');

        $this->addSql('DROP TABLE app_role');
    }

    /** Les moteurs sans clés étrangères (MyISAM en local) n'ont pas la contrainte : on ne la supprime que si elle existe. */
    private function dropForeignKeyIfExists(string $table, string $name): void
    {
        foreach ($this->connection->createSchemaManager()->listTableForeignKeys($table) as $foreignKey) {
            if (0 === strcasecmp($foreignKey->getName(), $name)) {
                $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $name));

                return;
            }
        }
    }
}
