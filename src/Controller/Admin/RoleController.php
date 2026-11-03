<?php

namespace App\Controller\Admin;

use App\Entity\Role;
use App\Form\RoleType;
use App\Repository\RoleRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\PermissionManager;
use App\Service\RoleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Création et réglage des rôles. Réservé à l'admin : qui peut régler les rôles peut s'attribuer tous les droits. */
#[Route('/admin/roles')]
#[IsGranted('ROLE_ADMIN')]
class RoleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleRepository $roles,
        private readonly RoleManager $manager,
        private readonly PermissionManager $permissions,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[Route('', name: 'app_admin_roles', methods: 'GET')]
    public function index(): Response
    {
        $roles = $this->roles->findAllOrdered();

        return $this->render('admin/roles/index.html.twig', [
            'roles' => $roles,
            'counts' => $this->roles->userCounts(),
            'granted' => array_combine(array_map(static fn (Role $r): int => $r->getId(), $roles), array_map(fn (Role $r): array => $this->permissions->granted($r), $roles)),
            'total' => \count(Permission::all()),
        ]);
    }

    #[Route('/nouveau', name: 'app_admin_role_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $role = new Role();
        $form = $this->createForm(RoleType::class, $role);
        $form->handleRequest($request);

        // « Partir de » : ?copier=ID précoche les permissions d'un rôle existant.
        $model = ($copyId = $request->query->getInt('copier')) ? $this->roles->find($copyId) : null;
        $checked = $this->submitted($request) ?? ($model ? $this->permissions->granted($model) : []);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->create($role, $checked);
            $granted = $this->permissions->granted($role);
            $this->activity->log('role.create', ['id' => $role->getId(), 'nom' => $role->getLabel(), 'code' => $role->getCode(), 'permissions' => $this->labels($granted)]);
            $this->addFlash('success', sprintf('Rôle « %s » créé. Vous pouvez maintenant l\'attribuer à des utilisateurs.', $role->getLabel()));

            return $this->redirectToRoute('app_admin_roles');
        }

        return $this->renderForm($form, $role, $checked, true, $model);
    }

    #[Route('/{id}/modifier', name: 'app_admin_role_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Role $role): Response
    {
        $before = ['nom' => $role->getLabel(), 'description' => $role->getDescription()];
        $grantedBefore = $this->permissions->granted($role);
        $form = $this->createForm(RoleType::class, $role);
        $form->handleRequest($request);
        $checked = $this->submitted($request) ?? $grantedBefore;

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            // Le rôle admin garde toutes ses permissions : seuls son nom et sa description changent.
            $changes = $role->isAdmin() ? ['ajoutees' => [], 'retirees' => []] : $this->permissions->save($role, $checked);
            $after = ['nom' => $role->getLabel(), 'description' => $role->getDescription()];

            $details = ['id' => $role->getId(), 'role' => $role->getCode()];
            if ($before !== $after) {
                $details += ['avant' => $before, 'apres' => $after];
            }
            if ($changes['ajoutees'] || $changes['retirees']) {
                $details += ['permissions_ajoutees' => $this->labels($changes['ajoutees']), 'permissions_retirees' => $this->labels($changes['retirees'])];
            }
            if (\count($details) > 2) {
                $this->activity->log('role.update', $details);
            }
            $this->addFlash('success', \count($details) > 2 ? sprintf('Rôle « %s » mis à jour.', $role->getLabel()) : 'Aucun changement.');

            return $this->redirectToRoute('app_admin_roles');
        }

        return $this->renderForm($form, $role, $checked, false, null);
    }

    #[Route('/{id}/supprimer', name: 'app_admin_role_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, Role $role): Response
    {
        if (!$this->isCsrfTokenValid('role_'.$role->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $details = ['id' => $role->getId(), 'nom' => $role->getLabel(), 'code' => $role->getCode()];
        try {
            $this->manager->delete($role);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_roles');
        }
        $this->activity->log('role.delete', $details);
        $this->addFlash('success', sprintf('Rôle « %s » supprimé.', $details['nom']));

        return $this->redirectToRoute('app_admin_roles');
    }

    private function renderForm(FormInterface $form, Role $role, array $checked, bool $isNew, ?Role $model): Response
    {
        return $this->render('admin/roles/form.html.twig', [
            'form' => $form,
            'role' => $role,
            'isNew' => $isNew,
            'catalog' => Permission::CATALOG,
            'checked' => $checked,
            'locked' => $role->isAdmin(),
            'model' => $model,
            'models' => $isNew ? $this->roles->findAllOrdered() : [],
            'users' => $isNew ? 0 : $this->roles->countUsers($role),
        ]);
    }

    /** Permissions cochées dans la requête, ou null si le formulaire n'a pas été envoyé. @return list<string>|null */
    private function submitted(Request $request): ?array
    {
        if (!$request->isMethod('POST')) {
            return null;
        }

        return array_values(array_filter($request->request->all('permissions'), 'is_string'));
    }

    /** @param list<string> $permissions @return list<string> */
    private function labels(array $permissions): array
    {
        return array_map(Permission::label(...), $permissions);
    }
}
