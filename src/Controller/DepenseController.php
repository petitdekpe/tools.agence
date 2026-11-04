<?php

namespace App\Controller;

use App\Dto\DepenseFilter;
use App\Entity\Depense;
use App\Entity\User;
use App\Form\DepenseType;
use App\Repository\DepenseRepository;
use App\Repository\EncaissementRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\EncaissementCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Dépenses et décaissements. Chaque action exige sa permission « depense.* » ; la suppression est logique. */
#[Route('/depenses')]
class DepenseController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepenseRepository $depenses,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[IsGranted(Permission::DEPENSE_VIEW)]
    #[Route('', name: 'app_depenses', methods: 'GET')]
    public function index(Request $request): Response
    {
        $filter = DepenseFilter::fromRequest($request);
        [$rows, $total] = $this->depenses->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->depenses->paginate($filter, self::PER_PAGE);
        }

        return $this->render('depense/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'totals' => $this->depenses->totals($filter),
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'dashboard' => $this->depenses->dashboard(),
        ]);
    }

    /**
     * Synthèse par mois : décaissé, et, pour qui peut voir les encaissements, encaissé net, solde et TVA nette
     * (TVA à reverser sur les encaissements moins TVA déductible sur les dépenses).
     */
    #[IsGranted(Permission::DEPENSE_VIEW)]
    #[Route('/synthese', name: 'app_depenses_summary', methods: 'GET')]
    public function summary(Request $request, EncaissementRepository $encaissements): Response
    {
        $filter = DepenseFilter::fromRequest($request);
        $canSeeIncome = $this->isGranted(Permission::ENCAISSEMENT_VIEW);

        $expenses = array_column($this->depenses->monthlyExpenses($filter->from, $filter->to), null, 'month');
        $paid = array_column($this->depenses->monthlyPaid($filter->from, $filter->to), null, 'month');
        $income = $canSeeIncome ? array_column($encaissements->monthlySummary($filter->from, $filter->to), null, 'month') : [];

        $months = array_unique([...array_keys($expenses), ...array_keys($paid), ...array_keys($income)]);
        rsort($months);

        $int = EncaissementCalculator::toInt(...);
        $rows = [];
        $sums = ['expenses' => 0, 'tva' => 0, 'paid' => 0, 'income' => 0, 'tvaCollected' => 0];
        foreach ($months as $month) {
            $row = [
                'month' => $month,
                'n' => (int) ($expenses[$month]['n'] ?? 0),
                'expenses' => $int((string) ($expenses[$month]['ttc'] ?? 0)),
                'tva' => $int((string) ($expenses[$month]['tva'] ?? 0)),
                'paid' => $int((string) ($paid[$month]['ttc'] ?? 0)),
                'income' => $int((string) ($income[$month]['net'] ?? 0)),
                'tvaCollected' => $int((string) ($income[$month]['tvaReverser'] ?? 0)),
            ];
            $row['balance'] = $row['income'] - $row['paid'];
            $row['tvaNet'] = $row['tvaCollected'] - $row['tva'];
            foreach (array_keys($sums) as $key) {
                $sums[$key] += $row[$key];
            }
            $rows[] = $row;
        }
        $sums['balance'] = $sums['income'] - $sums['paid'];
        $sums['tvaNet'] = $sums['tvaCollected'] - $sums['tva'];

        return $this->render('depense/summary.html.twig', [
            'filter' => $filter,
            'rows' => $rows,
            'sums' => $sums,
            'byCategory' => $this->depenses->categorySummary($filter->from, $filter->to),
            'bySupplier' => $this->depenses->supplierSummary($filter->from, $filter->to),
            'canSeeIncome' => $canSeeIncome,
            'categories' => Depense::CATEGORIES,
        ]);
    }

    #[IsGranted(Permission::DEPENSE_EDIT)]
    #[Route('/nouvelle', name: 'app_depense_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $depense = (new Depense())->setDateDepense(new \DateTimeImmutable('today'));
        $user = $this->getUser();
        if ($user instanceof User) {
            $depense->setCreatedBy($user);
        }

        $form = $this->createForm(DepenseType::class, $depense);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($depense);
            $this->em->flush();
            $this->activity->log('depense.create', ['id' => $depense->getId()] + $depense->snapshot());
            $this->addFlash('success', sprintf('Dépense de %s enregistrée.', $depense->getFournisseur()));

            return $this->redirectToRoute('app_depenses');
        }

        return $this->render('depense/form.html.twig', ['form' => $form, 'depense' => $depense, 'isNew' => true, 'suppliers' => $this->depenses->suppliers()]);
    }

    #[IsGranted(Permission::DEPENSE_EDIT)]
    #[Route('/{id}/modifier', name: 'app_depense_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $depense = $this->findOr404($id);
        $before = $depense->snapshot();

        // Le bouton « Payer » de la liste ouvre le formulaire avec la date du jour déjà posée.
        if ($request->isMethod('GET') && $request->query->getBoolean('payer') && !$depense->isPaid()) {
            $depense->setDatePaiement(new \DateTimeImmutable('today'));
        }

        $form = $this->createForm(DepenseType::class, $depense);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $depense->touch();
            $this->em->flush();
            $after = $depense->snapshot();
            $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, \ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->activity->log('depense.update', [
                    'id' => $depense->getId(),
                    'avant' => array_intersect_key($before, array_flip($changed)),
                    'apres' => array_intersect_key($after, array_flip($changed)),
                ]);
            }
            $this->addFlash('success', 'Dépense mise à jour.');

            return $this->redirectToRoute('app_depenses');
        }

        return $this->render('depense/form.html.twig', ['form' => $form, 'depense' => $depense, 'isNew' => false, 'suppliers' => $this->depenses->suppliers()]);
    }

    #[IsGranted(Permission::DEPENSE_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_depense_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $depense = $this->findOr404($id);
        if (!$this->isCsrfTokenValid('depense_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $user = $this->getUser();
        $depense->softDelete($user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('depense.delete', ['id' => $id] + $depense->snapshot());
        $this->addFlash('success', 'Dépense supprimée.');

        return $this->redirectToRoute('app_depenses');
    }

    private function findOr404(int $id): Depense
    {
        return $this->depenses->findActive($id) ?? throw $this->createNotFoundException('Dépense introuvable.');
    }
}
