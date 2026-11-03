<?php

namespace App\Controller;

use App\Dto\PersonnelFilter;
use App\Entity\Affectation;
use App\Entity\Personnel;
use App\Form\PersonnelType;
use App\Repository\MaterielRepository;
use App\Repository\PersonnelRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\ChangeSet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Gestion du personnel. Chaque action demande sa permission (voir Permission). */
#[Route('/personnel')]
class PersonnelController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PersonnelRepository $personnel,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[IsGranted(Permission::PERSONNEL_VIEW)]
    #[Route('', name: 'app_personnel', methods: 'GET')]
    public function index(Request $request): Response
    {
        $filter = PersonnelFilter::fromRequest($request);
        [$rows, $total] = $this->personnel->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->personnel->paginate($filter, self::PER_PAGE);
        }

        return $this->render('personnel/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'statuses' => PersonnelFilter::STATUSES,
            'contracts' => Personnel::CONTRACTS,
            'departments' => $this->personnel->departments(),
            // Le nombre de matériels détenus n'est affiché qu'à ceux qui peuvent voir l'inventaire.
            'held' => $this->isGranted(Permission::MATERIEL_VIEW) ? $this->personnel->heldCounts(array_map(static fn (Personnel $p): int => $p->getId(), $rows)) : null,
        ]);
    }

    #[IsGranted(Permission::PERSONNEL_EDIT)]
    #[Route('/nouveau', name: 'app_personnel_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $person = (new Personnel())->setHiredAt(new \DateTimeImmutable('today'));
        $form = $this->createForm(PersonnelType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($person);
            $this->em->flush();
            $this->activity->log('personnel.create', ['id' => $person->getId()] + $person->snapshot());
            $this->addFlash('success', sprintf('Fiche de %s créée.', $person->getFullName()));

            return $this->redirectToRoute('app_personnel_show', ['id' => $person->getId()]);
        }

        return $this->render('personnel/form.html.twig', ['form' => $form, 'person' => $person, 'isNew' => true]);
    }

    #[IsGranted(Permission::PERSONNEL_VIEW)]
    #[Route('/{id}', name: 'app_personnel_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id, MaterielRepository $materiel): Response
    {
        $person = $this->findOr404($id);
        $canSeeMateriel = $this->isGranted(Permission::MATERIEL_VIEW);

        return $this->render('personnel/show.html.twig', [
            'person' => $person,
            'canSeeMateriel' => $canSeeMateriel,
            'held' => $canSeeMateriel ? $materiel->heldBy($person) : [],
            'history' => $canSeeMateriel ? $this->em->getRepository(Affectation::class)->findBy(['personnel' => $person], ['assignedAt' => 'DESC', 'id' => 'DESC']) : [],
        ]);
    }

    #[IsGranted(Permission::PERSONNEL_EDIT)]
    #[Route('/{id}/modifier', name: 'app_personnel_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $person = $this->findOr404($id);
        $before = $person->snapshot();
        $form = $this->createForm(PersonnelType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $person->touch();
            $this->em->flush();
            if ($change = ChangeSet::diff($before, $person->snapshot())) {
                $this->activity->log('personnel.update', ['id' => $person->getId(), 'personne' => $person->getFullName()] + $change);
            }
            $this->addFlash('success', 'Fiche mise à jour.');

            return $this->redirectToRoute('app_personnel_show', ['id' => $person->getId()]);
        }

        return $this->render('personnel/form.html.twig', ['form' => $form, 'person' => $person, 'isNew' => false]);
    }

    #[IsGranted(Permission::PERSONNEL_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_personnel_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id, MaterielRepository $materiel): Response
    {
        $person = $this->findOr404($id);
        if (!$this->isCsrfTokenValid('personnel_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if ($materiel->heldBy($person)) {
            $this->addFlash('error', 'Cette personne détient encore du matériel : récupérez-le avant de supprimer la fiche.');

            return $this->redirectToRoute('app_personnel_show', ['id' => $id]);
        }

        $person->softDelete();
        $this->em->flush();
        $this->activity->log('personnel.delete', ['id' => $id] + $person->snapshot());
        $this->addFlash('success', 'Fiche supprimée.');

        return $this->redirectToRoute('app_personnel');
    }

    private function findOr404(int $id): Personnel
    {
        return $this->personnel->findActive($id) ?? throw $this->createNotFoundException('Fiche introuvable.');
    }
}
