<?php

namespace App\Service;

use App\Entity\Correction;
use App\Entity\CorrectionMemory;
use App\Entity\User;
use App\Repository\CorrectionMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Crée les entrées de mémoire à partir des remarques que l'utilisateur retient ou ignore. */
class CorrectionMemoryManager
{
    public const RETAIN = 'retain';
    public const IGNORE = 'ignore';

    /** Au-delà, l'utilisateur supprime des entrées : une mémoire sans limite finirait par peser sur chaque relecture. */
    private const MAX_PER_USER = 300;

    public function __construct(private readonly EntityManagerInterface $em, private readonly CorrectionMemoryRepository $memories)
    {
    }

    /**
     * @param string $action RETAIN (la correction devient un choix validé) ou IGNORE (ne plus signaler ce point)
     * @param int    $n      position de la remarque dans la réponse de la relecture
     */
    public function remember(User $user, Correction $correction, int $n, string $action): CorrectionMemory
    {
        if (!\in_array($action, [self::RETAIN, self::IGNORE], true)) {
            throw new UnprocessableEntityHttpException('Action inconnue.');
        }
        $remark = null;
        foreach (RemarkParser::parse($correction->getResultText()) as $candidate) {
            if ($candidate['n'] === $n) {
                $remark = $candidate;
            }
        }
        if (null === $remark) {
            throw new UnprocessableEntityHttpException('Remarque introuvable dans cette relecture.');
        }

        if (self::IGNORE === $action) {
            $kind = CorrectionMemory::KIND_EXCLUSION;
            $content = 'Ne pas signaler : '.$remark['heading'];
        } else {
            $kind = CorrectionMemory::KIND_CHOIX;
            $reason = '' === $remark['body'] ? '' : (preg_split('/(?<=[.!?])\s/u', $remark['body'])[0] ?? '');
            $content = $remark['heading'].('' === $reason ? '' : ' — '.$reason);
        }

        $dossier = $correction->getDossier();
        $entry = $this->existing($user, $dossier, $remark['quote'], $content);
        if (null === $entry) {
            if ($this->memories->count(['user' => $user, 'shared' => false]) >= self::MAX_PER_USER) {
                throw new UnprocessableEntityHttpException('Mémoire pleine : supprimez des entrées avant d\'en ajouter.');
            }
            $entry = (new CorrectionMemory())->setUser($user)->setDossier($dossier)->setAnchor($remark['quote']);
            $this->em->persist($entry);
        }
        $entry->setKind($kind)->setContent($content)->setSource($correction, $n);

        $this->em->flush();
        $this->memories->purgeStale();

        return $entry;
    }

    /** Promeut une entrée personnelle en règle de l'équipe : elle vaut alors pour tous les dossiers de tous les utilisateurs. */
    public function share(CorrectionMemory $entry): void
    {
        $entry->setShared(true)->setDossier(null);
        $this->em->flush();
    }

    public function forget(CorrectionMemory $entry): void
    {
        $this->em->remove($entry);
        $this->em->flush();
    }

    /** L'entrée déjà créée pour le même point : même texte cité, sinon même contenu. */
    private function existing(User $user, ?string $dossier, ?string $anchor, string $content): ?CorrectionMemory
    {
        foreach ($this->memories->findBy(['user' => $user, 'shared' => false, 'dossier' => $dossier]) as $entry) {
            $sameAnchor = null !== $anchor && null !== $entry->getAnchor() && mb_strtolower($anchor) === mb_strtolower($entry->getAnchor());
            if ($sameAnchor || $entry->getContent() === $content) {
                return $entry;
            }
        }

        return null;
    }
}
