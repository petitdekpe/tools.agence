<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correcteur média : fil unique classé en dossiers (dossier, version révisée), suivi de la consommation de l'API
 * (modèle et tokens par relecture) et table correction_memory (mémoire : choix validés, remarques ignorées, règles de l'équipe).
 */
final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correcteur média : dossier, version révisée, tokens consommés et table correction_memory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE correction ADD dossier VARCHAR(120) DEFAULT NULL, ADD model VARCHAR(80) DEFAULT NULL, ADD input_tokens INT DEFAULT NULL, ADD output_tokens INT DEFAULT NULL, ADD cache_read_tokens INT DEFAULT NULL, ADD cache_write_tokens INT DEFAULT NULL, ADD previous_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE correction ADD CONSTRAINT FK_A29DA1B82DE62210 FOREIGN KEY (previous_id) REFERENCES correction (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A29DA1B82DE62210 ON correction (previous_id)');
        $this->addSql('CREATE INDEX IDX_A29DA1B8A76ED3953D48E037 ON correction (user_id, dossier)');

        $this->addSql('CREATE TABLE correction_memory (id INT AUTO_INCREMENT NOT NULL, dossier VARCHAR(120) DEFAULT NULL, kind VARCHAR(12) NOT NULL, anchor VARCHAR(200) DEFAULT NULL, content VARCHAR(300) NOT NULL, shared TINYINT NOT NULL, source_remark INT DEFAULT NULL, use_count INT NOT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, source_id INT DEFAULT NULL, INDEX IDX_1C702215A76ED3953D48E037 (user_id, dossier), INDEX IDX_1C702215138CF4BB (shared), INDEX IDX_1C702215A76ED395 (user_id), INDEX IDX_1C702215953C1C61 (source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE correction_memory ADD CONSTRAINT FK_1C702215A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE correction_memory ADD CONSTRAINT FK_1C702215953C1C61 FOREIGN KEY (source_id) REFERENCES correction (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE correction_memory');
        $this->addSql('ALTER TABLE correction DROP FOREIGN KEY FK_A29DA1B82DE62210');
        $this->addSql('DROP INDEX IDX_A29DA1B82DE62210 ON correction');
        $this->addSql('DROP INDEX IDX_A29DA1B8A76ED3953D48E037 ON correction');
        $this->addSql('ALTER TABLE correction DROP dossier, DROP model, DROP input_tokens, DROP output_tokens, DROP cache_read_tokens, DROP cache_write_tokens, DROP previous_id');
    }
}
