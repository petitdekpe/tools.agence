<?php

namespace App\Repository;

use App\Dto\DocumentFilter;
use App\Entity\Document;
use App\Entity\Encaissement;
use App\Service\EncaissementCalculator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toutes les requêtes ignorent les documents supprimés (suppression logique). Le montant déjà encaissé
 * d'une facture est la somme des encaissements actifs qui lui sont rattachés.
 */
class DocumentRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'date' => 'd.dateEmission',
        'reference' => 'd.reference',
        'client' => 'c.name',
        'ttc' => 'd.montantTtc',
        'echeance' => 'd.dateLimite',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    public function findActive(int $id): ?Document
    {
        return $this->createQueryBuilder('d')->andWhere('d.id = :id')->andWhere('d.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** @return array{0: list<array{0: Document, paid: string}>, 1: int} la page demandée (document + montant encaissé) et le nombre total de lignes filtrées */
    public function paginate(DocumentFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
        $rows = $this->filtered($filter)->addSelect(self::paidExpression().' AS paid')
            ->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'asc' === $filter->dir ? 'ASC' : 'DESC')->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$rows, $total];
    }

    /** @return array{n: int, ttc: string} nombre et total TTC de toutes les lignes filtrées (pas seulement la page affichée) */
    public function totals(DocumentFilter $filter): array
    {
        $row = $this->filtered($filter)->select('COUNT(d.id) AS n', 'COALESCE(SUM(d.montantTtc), 0) AS ttc')->getQuery()->getSingleResult();

        return ['n' => (int) $row['n'], 'ttc' => (string) $row['ttc']];
    }

    /** Ce qui a déjà été encaissé sur cette facture, en francs. */
    public function paidFor(Document $document): int
    {
        if (null === $document->getId()) {
            return 0;
        }
        $paid = $this->getEntityManager()->createQuery('SELECT COALESCE(SUM(e.montantTtc), 0) FROM '.Encaissement::class.' e WHERE e.facture = :d AND e.deletedAt IS NULL')
            ->setParameter('d', $document)->getSingleScalarResult();

        return EncaissementCalculator::toInt((string) $paid);
    }

    /** @return list<Encaissement> les encaissements actifs rattachés à cette facture, du plus récent au plus ancien */
    public function paymentsOf(Document $document): array
    {
        return $this->getEntityManager()->createQuery('SELECT e FROM '.Encaissement::class.' e WHERE e.facture = :d AND e.deletedAt IS NULL ORDER BY e.dateEncaissement DESC, e.id DESC')
            ->setParameter('d', $document)->getResult();
    }

    /** @return list<Document> les factures actives établies à partir de ce devis */
    public function invoicesFrom(Document $devis): array
    {
        return $this->createQueryBuilder('d')->andWhere('d.sourceDevis = :s')->andWhere('d.deletedAt IS NULL')
            ->setParameter('s', $devis)->orderBy('d.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * Tableau de bord : ce qui reste à encaisser sur les factures émises, ce qui est en retard,
     * et l'état du carnet de devis.
     *
     * @return array{receivable: int, overdue: int, overdueCount: int, openInvoices: int, quotesPending: int, quotesPendingAmount: int, quotesToInvoice: int}
     */
    public function dashboard(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');

        $invoices = $this->createQueryBuilder('d')
            ->select('d.montantTtc AS ttc', 'd.dateLimite AS limite', self::paidExpression().' AS paid')
            ->andWhere('d.type = :facture')->andWhere('d.statut = :emise')->andWhere('d.deletedAt IS NULL')
            ->setParameter('facture', Document::TYPE_FACTURE)->setParameter('emise', Document::EMISE)
            ->getQuery()->getArrayResult();

        $receivable = $overdue = $overdueCount = $open = 0;
        foreach ($invoices as $invoice) {
            $balance = max(0, EncaissementCalculator::toInt($invoice['ttc']) - EncaissementCalculator::toInt((string) $invoice['paid']));
            if (0 === $balance) {
                continue;
            }
            ++$open;
            $receivable += $balance;
            if ($invoice['limite'] && $invoice['limite'] < $today) {
                $overdue += $balance;
                ++$overdueCount;
            }
        }

        $pending = $this->createQueryBuilder('d')->select('COUNT(d.id) AS n', 'COALESCE(SUM(d.montantTtc), 0) AS ttc')
            ->andWhere('d.type = :devis')->andWhere('d.statut = :envoye')->andWhere('d.deletedAt IS NULL')
            ->andWhere('d.dateLimite IS NULL OR d.dateLimite >= :today')
            ->setParameter('devis', Document::TYPE_DEVIS)->setParameter('envoye', Document::ENVOYE)->setParameter('today', $today, 'date_immutable')
            ->getQuery()->getSingleResult();

        $toInvoice = $this->createQueryBuilder('d')->select('COUNT(d.id)')
            ->andWhere('d.type = :devisType')->andWhere('d.statut = :accepte')->andWhere('d.deletedAt IS NULL')
            ->andWhere('NOT EXISTS (SELECT f.id FROM '.Document::class.' f WHERE f.sourceDevis = d AND f.deletedAt IS NULL)')
            ->setParameter('devisType', Document::TYPE_DEVIS)->setParameter('accepte', Document::ACCEPTE)
            ->getQuery()->getSingleScalarResult();

        return [
            'receivable' => $receivable,
            'overdue' => $overdue,
            'overdueCount' => $overdueCount,
            'openInvoices' => $open,
            'quotesPending' => (int) $pending['n'],
            'quotesPendingAmount' => EncaissementCalculator::toInt((string) $pending['ttc']),
            'quotesToInvoice' => (int) $toInvoice,
        ];
    }

    /** Somme encaissée sur la facture « d » : sous-requête réutilisable dans un SELECT ou un WHERE. */
    private static function paidExpression(string $alias = 'pe'): string
    {
        return sprintf('(SELECT COALESCE(SUM(%1$s.montantTtc), 0) FROM %2$s %1$s WHERE %1$s.facture = d AND %1$s.deletedAt IS NULL)', $alias, Encaissement::class);
    }

    private function filtered(DocumentFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')->join('d.client', 'c')
            ->andWhere('d.type = :type')->andWhere('d.deletedAt IS NULL')->setParameter('type', $filter->type);

        if ($filter->from) {
            $qb->andWhere('d.dateEmission >= :from')->setParameter('from', $filter->from, 'date_immutable');
        }
        if ($filter->to) {
            $qb->andWhere('d.dateEmission <= :to')->setParameter('to', $filter->to, 'date_immutable');
        }
        if ($filter->client) {
            $qb->andWhere('c.id = :client')->setParameter('client', $filter->client);
        }
        if ('' !== $filter->q) {
            $qb->andWhere('d.reference LIKE :q OR d.objet LIKE :q OR c.name LIKE :q')
                ->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        $today = new \DateTimeImmutable('today');
        // Un alias distinct par occurrence : DQL refuse un alias défini deux fois (ni dans le SELECT de paginate()).
        $paid = self::paidExpression('wp1');
        $paidAgain = self::paidExpression('wp2');
        switch ($filter->etat) {
            case 'impayee':
                $qb->andWhere('d.statut = :emise')->andWhere("$paid = 0")->setParameter('emise', Document::EMISE);
                break;
            case 'partielle':
                $qb->andWhere('d.statut = :emise')->andWhere("$paid > 0")->andWhere("$paidAgain < d.montantTtc")->setParameter('emise', Document::EMISE);
                break;
            case 'soldee':
                $qb->andWhere('d.statut = :emise')->andWhere("$paid >= d.montantTtc")->setParameter('emise', Document::EMISE);
                break;
            case 'retard':
                $qb->andWhere('d.statut = :emise')->andWhere("$paid < d.montantTtc")->andWhere('d.dateLimite < :today')
                    ->setParameter('emise', Document::EMISE)->setParameter('today', $today, 'date_immutable');
                break;
            case 'expire':
                $qb->andWhere('d.statut = :envoye')->andWhere('d.dateLimite < :today')
                    ->setParameter('envoye', Document::ENVOYE)->setParameter('today', $today, 'date_immutable');
                break;
            case 'afacturer':
                $qb->andWhere('d.statut = :accepte')
                    ->andWhere('NOT EXISTS (SELECT f.id FROM '.Document::class.' f WHERE f.sourceDevis = d AND f.deletedAt IS NULL)')
                    ->setParameter('accepte', Document::ACCEPTE);
                break;
            case null:
                break;
            default: // un statut simple : brouillon, annulee, envoye, accepte, refuse
                $qb->andWhere('d.statut = :statut')->setParameter('statut', $filter->etat);
        }

        return $qb;
    }
}
