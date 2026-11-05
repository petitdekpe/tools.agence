<?php

namespace App\Controller;

use App\Dto\MaterielFilter;
use App\Entity\Materiel;
use App\Entity\Personnel;
use App\Entity\User;
use App\Form\AssignType;
use App\Form\GiveBackType;
use App\Form\MaterielType;
use App\Repository\MaterielRepository;
use App\Repository\PersonnelRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\ChangeSet;
use App\Service\Choices;
use App\Service\MaterielAssigner;
use App\Service\MaterielExporter;
use App\Service\Notifications;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion du matériel. Un équipement se remet à un membre du personnel en poste et se restitue ;
 * chaque action demande sa permission (voir Permission).
 */
#[Route('/materiel')]
class MaterielController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterielRepository $materiel,
        private readonly PersonnelRepository $personnel,
        private readonly MaterielAssigner $assigner,
        private readonly ActivityLogger $activity,
        private readonly Choices $choices,
        private readonly Notifications $notifications,
    ) {
    }

    #[IsGranted(Permission::MATERIEL_VIEW)]
    #[Route('', name: 'app_materiel', methods: 'GET')]
    public function index(Request $request): Response
    {
        $filter = MaterielFilter::fromRequest($request);
        [$rows, $total] = $this->materiel->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->materiel->paginate($filter, self::PER_PAGE);
        }

        return $this->render('materiel/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'totals' => $this->materiel->totals($filter),
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'categories' => $this->choices->labels('materiel_category'),
            'etats' => $this->choices->labels('materiel_etat'),
            'affectations' => MaterielFilter::AFFECTATIONS,
            'holders' => $this->personnel->findBy(['deletedAt' => null], ['lastName' => 'ASC', 'firstName' => 'ASC']),
            'departedCount' => $this->materiel->countHeldByDeparted(),
        ]);
    }

    #[IsGranted(Permission::MATERIEL_EDIT)]
    #[Route('/nouveau', name: 'app_materiel_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $item = new Materiel();
        $form = $this->createForm(MaterielType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($item);
            $this->em->flush();
            $this->activity->log('materiel.create', ['id' => $item->getId()] + $item->snapshot());
            $this->addFlash('success', sprintf('« %s » ajouté à l\'inventaire.', $item->getDesignation()));

            return $this->redirectToRoute('app_materiel_show', ['id' => $item->getId()]);
        }

        return $this->render('materiel/form.html.twig', ['form' => $form, 'item' => $item, 'isNew' => true]);
    }

    #[IsGranted(Permission::MATERIEL_VIEW)]
    #[Route('/{id}', name: 'app_materiel_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->renderShow($this->findOr404($id));
    }

    #[IsGranted(Permission::MATERIEL_EDIT)]
    #[Route('/{id}/modifier', name: 'app_materiel_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $item = $this->findOr404($id);
        $before = $item->snapshot();
        $form = $this->createForm(MaterielType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($item->isReformed() && $item->getCurrentAffectation()) {
                $form->get('etat')->addError(new FormError('Ce matériel est affecté : restituez-le avant de le réformer.'));
            } else {
                $item->touch();
                $this->em->flush();
                if ($change = ChangeSet::diff($before, $item->snapshot())) {
                    $this->activity->log('materiel.update', ['id' => $item->getId(), 'materiel' => $item->getDesignation()] + $change);
                }
                $this->addFlash('success', 'Matériel mis à jour.');

                return $this->redirectToRoute('app_materiel_show', ['id' => $item->getId()]);
            }
        }

        return $this->render('materiel/form.html.twig', ['form' => $form, 'item' => $item, 'isNew' => false]);
    }

    #[IsGranted(Permission::MATERIEL_ASSIGN)]
    #[Route('/{id}/affecter', name: 'app_materiel_assign', methods: 'POST', requirements: ['id' => '\d+'])]
    public function assign(Request $request, int $id): Response
    {
        $item = $this->findOr404($id);
        $form = $this->assignForm($item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{personnel: Personnel, assignedAt: \DateTimeImmutable, note: ?string} $data */
            $data = $form->getData();
            try {
                $this->assigner->assign($item, $data['personnel'], $data['assignedAt'], $data['note'], $this->currentUser());
                $this->activity->log('materiel.assign', ['id' => $id, 'materiel' => $item->getDesignation(), 'remis_a' => $data['personnel']->getFullName(), 'date' => $data['assignedAt']->format('d/m/Y')]);
                $this->notifications->materielAssigned($item, $data['personnel'], $data['assignedAt']);
                $this->addFlash('success', sprintf('« %s » remis à %s.', $item->getDesignation(), $data['personnel']->getFullName()));

                return $this->redirectToRoute('app_materiel_show', ['id' => $id]);
            } catch (\DomainException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->renderShow($item, assignForm: $form);
    }

    #[IsGranted(Permission::MATERIEL_ASSIGN)]
    #[Route('/{id}/restituer', name: 'app_materiel_return', methods: 'POST', requirements: ['id' => '\d+'])]
    public function giveBack(Request $request, int $id): Response
    {
        $item = $this->findOr404($id);
        $holder = $item->getHolder();
        $form = $this->createForm(GiveBackType::class, null, ['action' => $this->generateUrl('app_materiel_return', ['id' => $id])]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{returnedAt: \DateTimeImmutable, etat: ?string, note: ?string} $data */
            $data = $form->getData();
            try {
                $this->assigner->giveBack($item, $data['returnedAt'], $data['etat'], $data['note'], $this->currentUser());
                $this->activity->log('materiel.return', ['id' => $id, 'materiel' => $item->getDesignation(), 'rendu_par' => $holder?->getFullName(), 'date' => $data['returnedAt']->format('d/m/Y'), 'etat' => $data['etat'] ? $this->choices->label('materiel_etat', $data['etat']) : null]);
                $this->notifications->materielReturned($item, $holder, $data['returnedAt']);
                $this->addFlash('success', sprintf('« %s » restitué.', $item->getDesignation()));

                return $this->redirectToRoute('app_materiel_show', ['id' => $id]);
            } catch (\DomainException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->renderShow($item, returnForm: $form);
    }

    #[IsGranted(Permission::MATERIEL_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_materiel_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $item = $this->findOr404($id);
        if (!$this->isCsrfTokenValid('materiel_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if ($item->getCurrentAffectation()) {
            $this->addFlash('error', 'Ce matériel est affecté : restituez-le avant de le supprimer.');

            return $this->redirectToRoute('app_materiel_show', ['id' => $id]);
        }

        $item->softDelete();
        $this->em->flush();
        $this->activity->log('materiel.delete', ['id' => $id] + $item->snapshot());
        $this->addFlash('success', 'Matériel supprimé.');

        return $this->redirectToRoute('app_materiel');
    }

    #[IsGranted(Permission::MATERIEL_EXPORT)]
    #[Route('/export.xlsx', name: 'app_materiel_export_xlsx', methods: 'GET')]
    public function exportExcel(Request $request, MaterielExporter $exporter): Response
    {
        $filter = MaterielFilter::fromRequest($request);
        $this->activity->log('materiel.export', ['format' => 'Excel', 'filtres' => $filter->toQuery(false)]);

        return $exporter->excel($this->materiel->findAllFiltered($filter), $this->materiel->totals($filter), $this->describe($filter));
    }

    #[IsGranted(Permission::MATERIEL_EXPORT)]
    #[Route('/export.pdf', name: 'app_materiel_export_pdf', methods: 'GET')]
    public function exportPdf(Request $request, MaterielExporter $exporter): Response
    {
        $filter = MaterielFilter::fromRequest($request);
        $this->activity->log('materiel.export', ['format' => 'PDF', 'filtres' => $filter->toQuery(false)]);

        return $exporter->pdf($this->materiel->findAllFiltered($filter), $this->materiel->totals($filter), $this->describe($filter));
    }

    private function renderShow(Materiel $item, ?FormInterface $assignForm = null, ?FormInterface $returnForm = null): Response
    {
        $canAssign = $this->isGranted(Permission::MATERIEL_ASSIGN);
        $open = $item->getCurrentAffectation();
        $assignForm ??= $canAssign && !$open && !$item->isReformed() ? $this->assignForm($item) : null;
        $returnForm ??= $canAssign && $open ? $this->createForm(GiveBackType::class, ['returnedAt' => new \DateTimeImmutable('today')], ['action' => $this->generateUrl('app_materiel_return', ['id' => $item->getId()])]) : null;

        return $this->render('materiel/show.html.twig', [
            'item' => $item,
            'open' => $open,
            'assignForm' => $assignForm,
            'returnForm' => $returnForm,
        ], new Response(status: ($assignForm?->isSubmitted() || $returnForm?->isSubmitted()) ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function assignForm(Materiel $item): FormInterface
    {
        return $this->createForm(AssignType::class, ['assignedAt' => new \DateTimeImmutable('today')], [
            'staff' => $this->personnel->findInPost(),
            'action' => $this->generateUrl('app_materiel_assign', ['id' => $item->getId()]),
        ]);
    }

    private function findOr404(int $id): Materiel
    {
        return $this->materiel->findActive($id) ?? throw $this->createNotFoundException('Matériel introuvable.');
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function describe(MaterielFilter $filter): string
    {
        $parts = [];
        if ('' !== $filter->q) {
            $parts[] = 'recherche « '.$filter->q.' »';
        }
        if ($filter->categorie) {
            $parts[] = 'catégorie '.$this->choices->label('materiel_category', $filter->categorie);
        }
        if ($filter->etat) {
            $parts[] = 'état '.$this->choices->label('materiel_etat', $filter->etat);
        }
        if ($filter->affectation) {
            $parts[] = MaterielFilter::AFFECTATIONS[$filter->affectation];
        }
        if ($filter->detenteur && ($person = $this->personnel->find($filter->detenteur))) {
            $parts[] = 'détenteur '.$person->getFullName();
        }

        return implode(' · ', $parts);
    }
}
