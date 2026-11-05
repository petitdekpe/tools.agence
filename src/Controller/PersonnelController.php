<?php

namespace App\Controller;

use App\Dto\PersonnelFilter;
use App\Entity\Affectation;
use App\Entity\Personnel;
use App\Entity\User;
use App\Form\PersonnelType;
use App\Repository\MaterielRepository;
use App\Repository\PersonnelDocumentRepository;
use App\Repository\PersonnelRepository;
use App\Repository\RoleRepository;
use App\Security\Permission;
use App\Service\AccountProvisioner;
use App\Service\ActivityLogger;
use App\Service\ChangeSet;
use App\Service\Choices;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
        private readonly Choices $choices,
        private readonly RoleRepository $roles,
        private readonly AccountProvisioner $accounts,
        private readonly LoggerInterface $logger,
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
            'contracts' => $this->choices->labels('personnel_contract'),
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
        // Seul un administrateur choisit le rôle du compte créé avec la fiche ; pour les autres, c'est le rôle Agent.
        $chooseRole = $this->isGranted('ROLE_ADMIN');
        $agent = $this->roles->findOneByCode(User::ROLE_AGENT);
        $form = $this->createForm(PersonnelType::class, $person, ['is_new' => true, 'choose_role' => $chooseRole]);
        if ($chooseRole) {
            $form->get('role')->setData($agent);
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $person->getEmail();
            if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
                $form->get('email')->addError(new FormError('Un compte existe déjà avec cet e-mail.'));
            } else {
                $role = $chooseRole ? $form->get('role')->getData() : $agent;
                try {
                    $this->em->persist($person);
                    // Une personne déjà partie à la création n'a pas besoin de compte.
                    $user = $person->isActive() ? $this->accounts->createAccount($person, $email, $role) : null;
                    $this->em->flush();
                } catch (TransportExceptionInterface $e) {
                    $this->em->detach($person);
                    $this->logger->error('Envoi d\'e-mail de compte impossible : '.$e->getMessage());
                    $form->addError(new FormError('L\'e-mail n\'a pas pu être envoyé (vérifiez MAILER_DSN). Ni la fiche ni le compte n\'ont été créés.'));
                    $user = false;
                }

                if (false !== $user) {
                    $this->activity->log('personnel.create', ['id' => $person->getId()] + $person->snapshot());
                    if ($user) {
                        $this->activity->log('user.create', ['id' => $user->getId(), 'email' => $email, 'role' => $user->getRoleLabel(), 'fiche' => $person->getId(), 'mot_de_passe_envoye' => true]);
                    }
                    $this->addFlash('success', $user
                        ? sprintf('Fiche de %s créée. Un compte a été créé et le mot de passe temporaire envoyé à %s (valable %d h).', $person->getFullName(), $email, AccountProvisioner::PASSWORD_VALID_HOURS)
                        : sprintf('Fiche de %s créée (aucun compte : la personne est déjà partie).', $person->getFullName()));

                    return $this->redirectToRoute('app_personnel_show', ['id' => $person->getId()]);
                }
            }
        }

        return $this->render('personnel/form.html.twig', ['form' => $form, 'person' => $person, 'isNew' => true]);
    }

    #[IsGranted(Permission::PERSONNEL_VIEW)]
    #[Route('/{id}', name: 'app_personnel_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id, MaterielRepository $materiel, PersonnelDocumentRepository $documents): Response
    {
        $person = $this->findOr404($id);
        $canSeeMateriel = $this->isGranted(Permission::MATERIEL_VIEW);
        // Adresse, contacts d'urgence, e-mail personnel et pièces d'identité : réservés à ceux qui gèrent les fiches.
        $canSeeProfile = $this->isGranted(Permission::PERSONNEL_EDIT);

        return $this->render('personnel/show.html.twig', [
            'person' => $person,
            'canSeeProfile' => $canSeeProfile,
            'documents' => $canSeeProfile ? $documents->byKind($person) : [],
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
