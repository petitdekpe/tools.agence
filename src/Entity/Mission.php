<?php

namespace App\Entity;

use App\Repository\MissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une mission sur le terrain (tournage, reportage, installation, rencontre client…) programmée par le responsable de
 * programmation : un lieu, une période, une équipe et un chef de mission. Une fois passée, l'équipe en fait le compte rendu
 * (réalisée, objectifs atteints ou non). Suppression logique.
 */
#[ORM\Entity(repositoryClass: MissionRepository::class)]
#[ORM\Table(name: 'mission')]
#[ORM\Index(columns: ['date_debut', 'date_fin'])]
#[ORM\Index(columns: ['deleted_at'])]
class Mission
{
    public const PLANIFIEE = 'planifiee';
    public const CONFIRMEE = 'confirmee';
    public const REALISEE = 'realisee';
    public const REPORTEE = 'reportee';
    public const ANNULEE = 'annulee';

    public const STATUTS = [
        self::PLANIFIEE => 'Planifiée',
        self::CONFIRMEE => 'Confirmée',
        self::REALISEE => 'Réalisée',
        self::REPORTEE => 'Reportée',
        self::ANNULEE => 'Annulée',
    ];

    /** Missions encore à venir ou à clôturer : elles occupent l'équipe et le calendrier. */
    public const OUVERTES = [self::PLANIFIEE, self::CONFIRMEE];

    public const RESULTATS = [
        'atteint' => 'Objectifs atteints',
        'partiel' => 'Partiellement atteints',
        'non_atteint' => 'Non atteints',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(max: 160, maxMessage: '160 caractères maximum.')]
    private string $titre = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Projet $projet = null;

    /** Le client concerné ; repris du projet quand il n'est pas précisé. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Client $client = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Le lieu est obligatoire.')]
    #[Assert\Length(max: 200, maxMessage: '200 caractères maximum.')]
    private string $lieu = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de début est obligatoire.')]
    #[Assert\GreaterThanOrEqual('2000-01-01', message: 'Date invalide.')]
    private ?\DateTimeImmutable $dateDebut = null;

    /** Dernier jour de la mission (inclus). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de fin est obligatoire.')]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $heureDebut = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::PLANIFIEE;

    /** Le chef de mission, sur place. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Personnel $responsable = null;

    /** @var Collection<int, Personnel> */
    #[ORM\ManyToMany(targetEntity: Personnel::class)]
    #[ORM\JoinTable(name: 'mission_personnel')]
    private Collection $equipe;

    /** Consignes et objectifs fixés avant le départ. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4000, maxMessage: '4 000 caractères maximum.')]
    private ?string $objectifs = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $resultat = null;

    /** Retour de terrain, rédigé par l'équipe une fois la mission faite. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 6000, maxMessage: '6 000 caractères maximum.')]
    private ?string $compteRendu = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reportedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deletedBy = null;

    public function __construct()
    {
        $this->equipe = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if ($this->dateDebut && $this->dateFin && $this->dateFin < $this->dateDebut) {
            $context->buildViolation('La date de fin est antérieure à la date de début.')->atPath('dateFin')->addViolation();
        }
        if (null !== $this->resultat && !isset(self::RESULTATS[$this->resultat])) {
            $context->buildViolation('Résultat invalide.')->atPath('resultat')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = trim((string) $titre);

        return $this;
    }

    public function getProjet(): ?Projet
    {
        return $this->projet;
    }

    public function setProjet(?Projet $projet): static
    {
        $this->projet = $projet;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    /** Le client de la mission, ou à défaut celui de son projet. */
    public function getEffectiveClient(): ?Client
    {
        return $this->client ?? $this->projet?->getClient();
    }

    public function getLieu(): string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = trim((string) $lieu);

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getHeureDebut(): ?\DateTimeImmutable
    {
        return $this->heureDebut;
    }

    public function setHeureDebut(?\DateTimeImmutable $heureDebut): static
    {
        $this->heureDebut = $heureDebut;

        return $this;
    }

    /** Nombre de jours calendaires de la mission, bornes comprises. */
    public function getDays(): int
    {
        return $this->dateDebut && $this->dateFin && $this->dateFin >= $this->dateDebut ? $this->dateDebut->diff($this->dateFin)->days + 1 : 0;
    }

    public function covers(\DateTimeImmutable $day): bool
    {
        return null !== $this->dateDebut && null !== $this->dateFin && $this->dateDebut <= $day && $day <= $this->dateFin;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function isOpen(): bool
    {
        return \in_array($this->statut, self::OUVERTES, true);
    }

    /** Passée sans compte rendu : la mission attend d'être clôturée. */
    public function isToClose(?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return $this->isOpen() && null !== $this->dateFin && $this->dateFin < $today;
    }

    public function getResponsable(): ?Personnel
    {
        return $this->responsable;
    }

    public function setResponsable(?Personnel $responsable): static
    {
        $this->responsable = $responsable;

        return $this;
    }

    /** @return Collection<int, Personnel> */
    public function getEquipe(): Collection
    {
        return $this->equipe;
    }

    public function addEquipe(Personnel $personnel): static
    {
        if (!$this->equipe->contains($personnel)) {
            $this->equipe->add($personnel);
        }

        return $this;
    }

    public function removeEquipe(Personnel $personnel): static
    {
        $this->equipe->removeElement($personnel);

        return $this;
    }

    /**
     * Toutes les personnes mobilisées : le chef de mission puis l'équipe, sans doublon.
     *
     * @return list<Personnel>
     */
    public function getParticipants(): array
    {
        $people = [];
        foreach ([$this->responsable, ...$this->equipe->toArray()] as $person) {
            if ($person && !\in_array($person, $people, true)) {
                $people[] = $person;
            }
        }

        return $people;
    }

    public function hasParticipant(?User $user): bool
    {
        if (null === $user) {
            return false;
        }
        foreach ($this->getParticipants() as $person) {
            if ($person->getUser() === $user) {
                return true;
            }
        }

        return false;
    }

    public function getObjectifs(): ?string
    {
        return $this->objectifs;
    }

    public function setObjectifs(?string $objectifs): static
    {
        $objectifs = null === $objectifs ? null : trim($objectifs);
        $this->objectifs = '' === $objectifs ? null : $objectifs;

        return $this;
    }

    public function getResultat(): ?string
    {
        return $this->resultat;
    }

    public function setResultat(?string $resultat): static
    {
        $this->resultat = '' === $resultat ? null : $resultat;

        return $this;
    }

    public function getResultatLabel(): ?string
    {
        return null === $this->resultat ? null : (self::RESULTATS[$this->resultat] ?? $this->resultat);
    }

    public function getCompteRendu(): ?string
    {
        return $this->compteRendu;
    }

    public function setCompteRendu(?string $compteRendu): static
    {
        $compteRendu = null === $compteRendu ? null : trim($compteRendu);
        $this->compteRendu = '' === $compteRendu ? null : $compteRendu;

        return $this;
    }

    public function getReportedAt(): ?\DateTimeImmutable
    {
        return $this->reportedAt;
    }

    /** Enregistre le retour de terrain : la mission passe à « réalisée ». */
    public function report(?string $resultat, ?string $compteRendu): static
    {
        $this->statut = self::REALISEE;
        $this->setResultat($resultat)->setCompteRendu($compteRendu);
        $this->reportedAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function softDelete(?User $by): static
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->deletedBy = $by;

        return $this;
    }

    /** Photo lisible de la mission pour le journal d'activité. */
    public function snapshot(): array
    {
        return [
            'titre' => $this->titre,
            'projet' => $this->projet?->getNom(),
            'client' => $this->getEffectiveClient()?->getName(),
            'lieu' => $this->lieu,
            'du' => $this->dateDebut?->format('d/m/Y'),
            'au' => $this->dateFin?->format('d/m/Y'),
            'statut' => $this->getStatutLabel(),
            'chef' => $this->responsable?->getFullName(),
            'equipe' => implode(', ', array_map(static fn (Personnel $p): string => $p->getFullName(), $this->equipe->toArray())),
            'objectifs' => $this->objectifs,
        ];
    }
}
