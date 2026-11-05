<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Une action effectuée (ou tentée) par un utilisateur. Le journal survit à la suppression du compte. */
#[ORM\Entity]
#[ORM\Table(name: 'activity_log')]
#[ORM\Index(columns: ['created_at'])]
#[ORM\Index(columns: ['user_id', 'created_at'])]
#[ORM\Index(columns: ['action'])]
class ActivityLog
{
    public const LABELS = [
        'auth.login' => 'Connexion',
        'auth.login_failed' => 'Échec de connexion',
        'auth.logout' => 'Déconnexion',
        'auth.2fa_enabled' => 'Authentificateur activé',
        'auth.2fa_reset' => 'Authentificateur réinitialisé',
        'auth.recovery_requested' => 'Lien de récupération demandé',
        'tool.open' => 'Ouverture d\'un outil',
        'correction.create' => 'Correction créée',
        'correction.view' => 'Correction consultée',
        'correction.update' => 'Correction modifiée',
        'correction.delete' => 'Correction supprimée',
        'correction.memory' => 'Mémoire du correcteur modifiée',
        'user.create' => 'Utilisateur créé',
        'user.update' => 'Utilisateur modifié',
        'user.toggle' => 'Utilisateur activé/désactivé',
        'user.password_sent' => 'Mot de passe temporaire renvoyé',
        'user.delete' => 'Utilisateur supprimé',
        'settings.update' => 'Réglages du site modifiés',
        'encaissement.create' => 'Encaissement créé',
        'encaissement.update' => 'Encaissement modifié',
        'encaissement.delete' => 'Encaissement supprimé',
        'encaissement.export' => 'Encaissements exportés',
        'facture.create' => 'Facture ou devis créé',
        'facture.update' => 'Facture ou devis modifié',
        'facture.status' => 'Statut d\'une facture ou d\'un devis modifié',
        'facture.convert' => 'Devis transformé en facture',
        'facture.delete' => 'Facture ou devis supprimé',
        'ticket.create' => 'Panne ou demande créée',
        'ticket.update' => 'Panne ou demande modifiée',
        'ticket.status' => 'Statut d\'une panne ou d\'une demande modifié',
        'ticket.delete' => 'Panne ou demande supprimée',
        'absence.create' => 'Absence créée',
        'absence.update' => 'Absence modifiée',
        'absence.decision' => 'Demande d\'absence traitée',
        'absence.cancel' => 'Absence annulée',
        'absence.delete' => 'Absence supprimée',
        'publication.create' => 'Publication planifiée',
        'publication.update' => 'Publication modifiée',
        'publication.status' => 'Publication : statut modifié',
        'publication.delete' => 'Publication supprimée',
        'depense.create' => 'Dépense créée',
        'depense.update' => 'Dépense modifiée',
        'depense.delete' => 'Dépense supprimée',
        'projet.create' => 'Projet créé',
        'projet.update' => 'Projet modifié',
        'projet.status' => 'Projet : statut modifié',
        'projet.delete' => 'Projet supprimé',
        'tache.create' => 'Carte ajoutée',
        'tache.update' => 'Carte modifiée',
        'tache.move' => 'Carte déplacée',
        'tache.delete' => 'Carte supprimée',
        'mission.create' => 'Mission programmée',
        'mission.update' => 'Mission modifiée',
        'mission.status' => 'Mission : statut modifié',
        'mission.report' => 'Compte rendu de mission',
        'mission.delete' => 'Mission supprimée',
        'client.create' => 'Client créé',
        'client.update' => 'Client modifié',
        'fiscal.update' => 'Paramètres fiscaux modifiés',
        'personnel.create' => 'Fiche personnel créée',
        'personnel.update' => 'Fiche personnel modifiée',
        'personnel.delete' => 'Fiche personnel supprimée',
        'materiel.create' => 'Matériel créé',
        'materiel.update' => 'Matériel modifié',
        'materiel.delete' => 'Matériel supprimé',
        'materiel.assign' => 'Matériel remis',
        'materiel.return' => 'Matériel restitué',
        'materiel.export' => 'Inventaire exporté',
        'permissions.update' => 'Permissions modifiées',
        'role.create' => 'Rôle créé',
        'role.update' => 'Rôle modifié',
        'role.delete' => 'Rôle supprimé',
        'choice.create' => 'Valeur de liste ajoutée',
        'choice.update' => 'Valeur de liste renommée',
        'choice.delete' => 'Valeur de liste supprimée',
    ];

    public const CATEGORIES = [
        'auth' => 'Authentification',
        'tool' => 'Outils',
        'correction' => 'Corrections',
        'user' => 'Gestion des utilisateurs',
        'settings' => 'Réglages',
        'encaissement' => 'Encaissements',
        'facture' => 'Factures et devis',
        'ticket' => 'Pannes et demandes',
        'absence' => 'Congés et absences',
        'publication' => 'Calendrier éditorial',
        'depense' => 'Dépenses',
        'projet' => 'Projets',
        'tache' => 'Tâches',
        'mission' => 'Missions',
        'client' => 'Clients',
        'fiscal' => 'Paramètres fiscaux',
        'personnel' => 'Personnel',
        'materiel' => 'Matériel',
        'permissions' => 'Permissions',
        'role' => 'Rôles',
        'choice' => 'Listes de choix',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** E-mail au moment de l'action (conservé même si le compte est supprimé, ou inconnu). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $userEmail = null;

    #[ORM\Column(length: 40)]
    private string $action = '';

    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUserEmail(): ?string
    {
        return $this->userEmail;
    }

    public function setUserEmail(?string $userEmail): static
    {
        $this->userEmail = $userEmail;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getLabel(): string
    {
        return self::LABELS[$this->action] ?? $this->action;
    }

    public function getCategory(): string
    {
        return strstr($this->action, '.', true) ?: $this->action;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function setDetails(array $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
