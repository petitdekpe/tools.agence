<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015121458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activity_log (id INT AUTO_INCREMENT NOT NULL, user_email VARCHAR(180) DEFAULT NULL, action VARCHAR(40) NOT NULL, details JSON NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_FD06F6478B8E8428 (created_at), INDEX IDX_FD06F647A76ED3958B8E8428 (user_id, created_at), INDEX IDX_FD06F64747CC8C92 (action), INDEX IDX_FD06F647A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F647A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE app_user ADD totp_secret LONGTEXT DEFAULT NULL, ADD totp_last_code VARCHAR(6) DEFAULT NULL, ADD totp_last_code_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE correction ADD CONSTRAINT FK_A29DA1B8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activity_log DROP FOREIGN KEY FK_FD06F647A76ED395');
        $this->addSql('DROP TABLE activity_log');
        $this->addSql('ALTER TABLE app_user DROP totp_secret, DROP totp_last_code, DROP totp_last_code_at');
        $this->addSql('ALTER TABLE correction DROP FOREIGN KEY FK_A29DA1B8A76ED395');
    }
}
