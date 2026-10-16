<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015103134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, full_name VARCHAR(120) NOT NULL, role VARCHAR(20) NOT NULL, password VARCHAR(255) NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP INDEX IDX_A29DA1B8CF60E67C43625D9F ON correction');
        $this->addSql('ALTER TABLE correction ADD user_id INT NOT NULL, DROP owner');
        $this->addSql('ALTER TABLE correction ADD CONSTRAINT FK_A29DA1B8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_A29DA1B8A76ED39543625D9F ON correction (user_id, updated_at)');
        $this->addSql('CREATE INDEX IDX_A29DA1B8A76ED395 ON correction (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, password VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, UNIQUE INDEX UNIQ_AD8A54A9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = MyISAM COMMENT = \'\' ');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('ALTER TABLE correction DROP FOREIGN KEY FK_A29DA1B8A76ED395');
        $this->addSql('DROP INDEX IDX_A29DA1B8A76ED39543625D9F ON correction');
        $this->addSql('DROP INDEX IDX_A29DA1B8A76ED395 ON correction');
        $this->addSql('ALTER TABLE correction ADD owner VARCHAR(32) NOT NULL, DROP user_id');
        $this->addSql('CREATE INDEX IDX_A29DA1B8CF60E67C43625D9F ON correction (owner, updated_at)');
    }
}
