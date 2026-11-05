<?php

namespace App\Service;

use App\Entity\Personnel;
use App\Entity\PersonnelDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Range les fichiers du dossier personnel hors du dossier public (var/uploads/personnel) : pièce d'identité, CV,
 * diplôme et photos. Un fichier ne se lit que par PersonnelDocumentController, qui vérifie les droits.
 */
class PersonnelFiles
{
    /** Types MIME acceptés par type de fichier, avec l'extension donnée au fichier rangé. */
    public const ALLOWED = [
        PersonnelDocument::IDENTITY => ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
        PersonnelDocument::CV => ['application/pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'],
        PersonnelDocument::DIPLOMA => ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
        PersonnelDocument::PHOTO => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
    ];

    /** Taille maximale, en Mo. */
    public const MAX_MB = [PersonnelDocument::IDENTITY => 5, PersonnelDocument::CV => 5, PersonnelDocument::DIPLOMA => 5, PersonnelDocument::PHOTO => 3];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/var/uploads/personnel')] private readonly string $directory,
    ) {
    }

    /**
     * Enregistre un fichier. Pour la pièce d'identité, le CV et le diplôme, il remplace le précédent.
     * Le type du fichier est contrôlé sur son contenu, pas sur son nom. Le nombre de photos se contrôle avant (voir PersonnelDocument::MAX_PHOTOS).
     *
     * @throws \DomainException si le fichier n'est pas d'un type accepté
     */
    public function store(Personnel $person, string $kind, UploadedFile $file): PersonnelDocument
    {
        $mime = (string) $file->getMimeType();
        $extension = self::ALLOWED[$kind][$mime] ?? throw new \DomainException('Format de fichier non accepté.');

        if (PersonnelDocument::PHOTO !== $kind) {
            foreach ($this->em->getRepository(PersonnelDocument::class)->findBy(['personnel' => $person, 'kind' => $kind]) as $previous) {
                $this->remove($previous, false);
            }
        }

        $name = bin2hex(random_bytes(16)).'.'.$extension;
        $original = (string) $file->getClientOriginalName();
        $size = (int) $file->getSize();
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        $file->move($this->directory, $name);

        $document = new PersonnelDocument($person, $kind, $name, $original, $mime, $size);
        $this->em->persist($document);

        return $document;
    }

    public function remove(PersonnelDocument $document, bool $flush = true): void
    {
        @unlink($this->pathOf($document));
        $this->em->remove($document);
        if ($flush) {
            $this->em->flush();
        }
    }

    public function pathOf(PersonnelDocument $document): string
    {
        // Le nom est généré par store() ; basename() écarte tout chemin qui viendrait d'ailleurs.
        return $this->directory.'/'.basename($document->getPath());
    }
}
