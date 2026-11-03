<?php

namespace App\Controller;

use App\Dto\EncaissementFilter;
use App\Entity\Client;
use App\Entity\Encaissement;
use App\Entity\User;
use App\Form\EncaissementType;
use App\Repository\ClientRepository;
use App\Repository\EncaissementRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\EncaissementExporter;
use App\Service\FiscalSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Chaque action exige sa permission « encaissement.* » ; la suppression est logique. */
#[Route('/encaissements')]
class EncaissementController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EncaissementRepository $encaissements,
        private readonly ActivityLogger $activity,
        private readonly FiscalSettings $fiscal,
    ) {
    }

    #[IsGranted(Permission::ENCAISSEMENT_VIEW)]
    #[Route('', name: 'app_encaissements', methods: 'GET')]
    public function index(Request $request, ClientRepository $clients): Response
    {
        $filter = EncaissementFilter::fromRequest($request);
        [$rows, $total] = $this->encaissements->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->encaissements->paginate($filter, self::PER_PAGE);
        }

        return $this->render('encaissement/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'totals' => $this->encaissements->totals($filter),
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'clients' => $clients->findAllByName(),
            'natures' => Encaissement::NATURES,
            'modes' => EncaissementFilter::MODES,
            'coherences' => EncaissementFilter::COHERENCES,
        ]);
    }

    #[IsGranted(Permission::ENCAISSEMENT_EDIT)]
    #[Route('/nouveau', name: 'app_encaissement_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $encaissement = (new Encaissement())->setDateEncaissement(new \DateTimeImmutable('today'));
        $user = $this->getUser();
        if ($user instanceof User) {
            $encaissement->setCreatedBy($user);
        }
        if ($clientId = $request->query->getInt('client')) {
            $encaissement->setClient($this->em->getRepository(Client::class)->find($clientId));
        }

        $form = $this->createForm(EncaissementType::class, $encaissement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($encaissement);
            $this->em->flush();
            $this->activity->log('encaissement.create', ['id' => $encaissement->getId()] + $encaissement->snapshot());
            $this->addFlash('success', sprintf('Encaissement de %s enregistré.', $encaissement->getClient()->getName()));

            return $this->redirectToRoute('app_encaissements');
        }

        return $this->renderForm($form, $encaissement, true);
    }

    #[IsGranted(Permission::ENCAISSEMENT_EDIT)]
    #[Route('/{id}/modifier', name: 'app_encaissement_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $encaissement = $this->findOr404($id);
        $before = $encaissement->snapshot();

        $form = $this->createForm(EncaissementType::class, $encaissement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $encaissement->touch();
            $this->em->flush();
            $after = $encaissement->snapshot();
            $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, \ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->activity->log('encaissement.update', [
                    'id' => $encaissement->getId(),
                    'avant' => array_intersect_key($before, array_flip($changed)),
                    'apres' => array_intersect_key($after, array_flip($changed)),
                ]);
            }
            $this->addFlash('success', 'Encaissement mis à jour.');

            return $this->redirectToRoute('app_encaissements');
        }

        return $this->renderForm($form, $encaissement, false);
    }

    #[IsGranted(Permission::ENCAISSEMENT_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_encaissement_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $encaissement = $this->findOr404($id);
        if (!$this->isCsrfTokenValid('encaissement_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $user = $this->getUser();
        $encaissement->softDelete($user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('encaissement.delete', ['id' => $id] + $encaissement->snapshot());
        $this->addFlash('success', 'Encaissement supprimé.');

        return $this->redirectToRoute('app_encaissements');
    }

    #[IsGranted(Permission::ENCAISSEMENT_EXPORT)]
    #[Route('/export.xlsx', name: 'app_encaissements_export_xlsx', methods: 'GET')]
    public function exportExcel(Request $request, EncaissementExporter $exporter): Response
    {
        $filter = EncaissementFilter::fromRequest($request);
        $this->activity->log('encaissement.export', ['format' => 'Excel', 'filtres' => $filter->toQuery(false)]);

        return $exporter->excel($this->encaissements->findAllFiltered($filter), $this->encaissements->totals($filter), $this->describe($filter));
    }

    #[IsGranted(Permission::ENCAISSEMENT_EXPORT)]
    #[Route('/export.pdf', name: 'app_encaissements_export_pdf', methods: 'GET')]
    public function exportPdf(Request $request, EncaissementExporter $exporter): Response
    {
        $filter = EncaissementFilter::fromRequest($request);
        $this->activity->log('encaissement.export', ['format' => 'PDF', 'filtres' => $filter->toQuery(false)]);

        return $exporter->pdf($this->encaissements->findAllFiltered($filter), $this->encaissements->totals($filter), $this->describe($filter));
    }

    #[IsGranted(Permission::ENCAISSEMENT_VIEW)]
    #[Route('/synthese', name: 'app_encaissements_summary', methods: 'GET')]
    public function summary(Request $request): Response
    {
        $filter = EncaissementFilter::fromRequest($request);
        $months = $this->encaissements->monthlySummary($filter->from, $filter->to);
        $byClient = $this->encaissements->clientSummary($filter->from, $filter->to);
        $byNature = $this->encaissements->natureSummary($filter->from, $filter->to);

        return $this->render('encaissement/summary.html.twig', [
            'filter' => $filter,
            'months' => $months,
            'byClient' => $byClient,
            'byNature' => $byNature,
            'natures' => Encaissement::NATURES,
            'sums' => [
                'months' => $this->sumColumns($months, ['n', 'ttc', 'ht', 'tvaReverser', 'aib', 'net']),
                'clients' => $this->sumColumns($byClient, ['n', 'ttc', 'net']),
            ],
        ]);
    }

    private function renderForm(\Symfony\Component\Form\FormInterface $form, Encaissement $encaissement, bool $isNew): Response
    {
        $rates = $this->fiscal->get();

        return $this->render('encaissement/form.html.twig', [
            'form' => $form,
            'encaissement' => $encaissement,
            'isNew' => $isNew,
            'rates' => ['tva' => $rates->getTauxTva(), 'aibAvecIfu' => $rates->getTauxAibAvecIfu(), 'aibSansIfu' => $rates->getTauxAibSansIfu()],
            // À la modification, les taux figés restent valables tant que le client ne change pas.
            'frozen' => $isNew ? null : ['tva' => $encaissement->getTauxTva(), 'aib' => $encaissement->getTauxAib(), 'client' => $encaissement->getClient()?->getId()],
        ]);
    }

    private function findOr404(int $id): Encaissement
    {
        return $this->encaissements->findActive($id) ?? throw $this->createNotFoundException('Encaissement introuvable.');
    }

    /** Résumé des filtres actifs, en clair, pour les en-têtes d'export. */
    private function describe(EncaissementFilter $filter): string
    {
        $parts = [];
        if ($filter->from || $filter->to) {
            $parts[] = 'période '.($filter->from ? 'du '.$filter->from->format('d/m/Y') : '').($filter->to ? ' au '.$filter->to->format('d/m/Y') : '');
        }
        if ($filter->client && ($client = $this->em->getRepository(Client::class)->find($filter->client))) {
            $parts[] = 'client '.$client->getName();
        }
        if ($filter->nature) {
            $parts[] = 'nature '.Encaissement::NATURES[$filter->nature];
        }
        if ($filter->mode) {
            $parts[] = 'mode '.EncaissementFilter::MODES[$filter->mode];
        }
        if ($filter->coherence) {
            $parts[] = 'cohérence '.EncaissementFilter::COHERENCES[$filter->coherence];
        }
        if ('' !== $filter->q) {
            $parts[] = 'recherche « '.$filter->q.' »';
        }

        return implode(' · ', $parts);
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumColumns(array $rows, array $columns): array
    {
        $sums = array_fill_keys($columns, 0);
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $sums[$column] += (int) $row[$column];
            }
        }

        return $sums;
    }
}
