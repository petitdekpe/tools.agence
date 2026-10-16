<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Admin et direction consultent la liste ; seul l'admin crée, modifie et supprime. */
#[Route('/admin/users')]
class UserController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $hasher, private readonly ActivityLogger $activity)
    {
    }

    #[Route('', name: 'app_admin_users', methods: 'GET')]
    public function index(): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'users' => $this->em->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $this->em->persist($user);
            $this->em->flush();
            $this->activity->log('user.create', ['id' => $user->getId(), 'email' => $user->getEmail(), 'role' => $user->getRoleLabel()]);
            $this->addFlash('success', sprintf('Utilisateur %s créé. Il activera Google Authenticator à sa première connexion avec ce mot de passe initial.', $user->getFullName()));

            return $this->redirectToRoute('app_admin_users');
        }

        return $this->render('admin/users/form.html.twig', ['form' => $form, 'user' => $user, 'isNew' => true]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, User $user): Response
    {
        $wasAdmin = $user->isAdmin() && $user->isActive();
        $before = ['email' => $user->getEmail(), 'nom' => $user->getFullName(), 'role' => $user->getRoleLabel(), 'actif' => $user->isActive()];
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $stillAdmin = $user->isAdmin() && $user->isActive();
            if ($wasAdmin && !$stillAdmin && 0 === $this->otherActiveAdmins($user)) {
                $form->addError(new \Symfony\Component\Form\FormError('Il doit rester au moins un administrateur actif.'));
            } elseif ($user === $this->getUser() && !$user->isActive()) {
                $form->addError(new \Symfony\Component\Form\FormError('Vous ne pouvez pas désactiver votre propre compte.'));
            } else {
                $plain = $form->get('plainPassword')->getData();
                if ($plain) {
                    $user->setPassword($this->hasher->hashPassword($user, $plain));
                }
                $this->em->flush();
                $this->activity->log('user.update', ['id' => $user->getId(), 'avant' => $before, 'apres' => ['email' => $user->getEmail(), 'nom' => $user->getFullName(), 'role' => $user->getRoleLabel(), 'actif' => $user->isActive()], 'mot_de_passe_change' => (bool) $plain]);
                $this->addFlash('success', sprintf('Utilisateur %s mis à jour.', $user->getFullName()));

                return $this->redirectToRoute('app_admin_users');
            }
        }

        return $this->render('admin/users/form.html.twig', ['form' => $form, 'user' => $user, 'isNew' => false]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/toggle', name: 'app_admin_user_toggle', methods: 'POST', requirements: ['id' => '\d+'])]
    public function toggle(Request $request, User $user): Response
    {
        $this->checkCsrf($request, 'user_'.$user->getId());

        if ($user->isActive() && ($user === $this->getUser() || ($user->isAdmin() && 0 === $this->otherActiveAdmins($user)))) {
            $this->addFlash('error', 'Ce compte ne peut pas être désactivé (compte courant ou dernier administrateur).');
        } else {
            $user->setActive(!$user->isActive());
            $this->em->flush();
            $this->activity->log('user.toggle', ['id' => $user->getId(), 'email' => $user->getEmail(), 'actif' => $user->isActive()]);
            $this->addFlash('success', sprintf('%s : compte %s.', $user->getFullName(), $user->isActive() ? 'réactivé' : 'désactivé'));
        }

        return $this->redirectToRoute('app_admin_users');
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/delete', name: 'app_admin_user_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, User $user): Response
    {
        $this->checkCsrf($request, 'user_'.$user->getId());

        if ($user === $this->getUser() || ($user->isAdmin() && $user->isActive() && 0 === $this->otherActiveAdmins($user))) {
            $this->addFlash('error', 'Ce compte ne peut pas être supprimé (compte courant ou dernier administrateur).');
        } else {
            $name = $user->getFullName();
            $this->activity->log('user.delete', ['id' => $user->getId(), 'email' => $user->getEmail(), 'role' => $user->getRoleLabel()]);
            $this->em->remove($user);
            $this->em->flush();
            $this->addFlash('success', sprintf('Utilisateur %s supprimé, ainsi que son historique.', $name));
        }

        return $this->redirectToRoute('app_admin_users');
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/reset-2fa', name: 'app_admin_user_reset2fa', methods: 'POST', requirements: ['id' => '\d+'])]
    public function reset2fa(Request $request, User $user): Response
    {
        $this->checkCsrf($request, 'user_'.$user->getId());
        $user->setTotpSecret(null);
        $this->em->flush();
        $this->activity->log('auth.2fa_reset', ['id' => $user->getId(), 'email' => $user->getEmail()]);
        $this->addFlash('success', sprintf('Authentificateur de %s réinitialisé : à sa prochaine connexion, il utilisera son mot de passe puis réactivera Google Authenticator.', $user->getFullName()));

        return $this->redirectToRoute('app_admin_users');
    }

    private function otherActiveAdmins(User $except): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.role = :r AND u.active = true AND u.id != :id')
            ->setParameters(['r' => User::ROLE_ADMIN, 'id' => $except->getId()])
            ->getSingleScalarResult();
    }

    private function checkCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
