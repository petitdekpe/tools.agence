<?php

namespace App\Repository;

use App\Entity\Personnel;
use App\Entity\PersonnelDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PersonnelDocument> */
class PersonnelDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonnelDocument::class);
    }

    /**
     * Les fichiers d'une personne, regroupés par type : une seule entrée pour la pièce d'identité, le CV et le diplôme,
     * jusqu'à quatre pour les photos.
     *
     * @return array<string, list<PersonnelDocument>> tous les types sont présents, même vides
     */
    public function byKind(Personnel $person): array
    {
        $grouped = array_fill_keys(array_keys(PersonnelDocument::KINDS), []);
        foreach ($this->findBy(['personnel' => $person], ['id' => 'ASC']) as $document) {
            $grouped[$document->getKind()][] = $document;
        }

        return $grouped;
    }

    public function countPhotos(Personnel $person): int
    {
        return $this->count(['personnel' => $person, 'kind' => PersonnelDocument::PHOTO]);
    }
}
