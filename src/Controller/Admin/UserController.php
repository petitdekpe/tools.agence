<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\AccountInvitationType;
use App\Form\UserType;
use App\Repository\PersonnelRepository;
use App\Repository\RoleRepository;
use App\Security\Permission;
use App\Service\AccountProvisioner;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Les rôles autorisés (permission « users.view ») consultent la liste ; seul l'admin crée, modifie et supprime. */
#[Route('/admin/users')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ActivityLogger $activity,
        private readonly RoleRepository $roles,
        private readonly AccountProvisioner $accounts,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[IsGranted(Permission::USERS_VIEW)]
    #[Route('', name: 'app_admin_users', methods: 'GET')]
    public function index(Request $request): Response
    {
        $role = ($roleId = $request->query->getInt('role')) ? $this->roles->find($roleId) : null;

        return $this->render('admin/users/index.html.twig', [
            'users' => $this->em->getRepository(User::class)->findBy($role ? ['role' => $role] : [], ['fullName' => 'ASC']),
            'roles' => $this->roles->findAllOrdered(),
            'role' => $role,
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = (new User())->setRole($this->roles->findOneByCode(User::ROLE_AGENT));
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

    /** Création d'un compte depuis une fiche du personnel : le mot de passe temporaire part par e-mail. */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/depuis-personnel/{id}', name: 'app_admin_user_from_personnel', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function fromPersonnel(Request $request, int $id, PersonnelRepository $personnel): Response
    {
        $person = $personnel->findActive($id) ?? throw $this->createNotFoundException('Fiche introuvable.');
        if ($person->getUser() || !$person->isActive()) {
            $this->addFlash('error', $person->getUser() ? 'Cette personne a déjà un compte.' : 'Cette personne est partie : aucun compte ne peut lui être créé.');

            return $this->redirectToRoute('app_personnel_show', ['id' => $id]);
        }

        $form = $this->createForm(AccountInvitationType::class, [
            'email' => $person->getEmail() && User::isAllowedEmail($person->getEmail()) ? $person->getEmail() : null,
            'role' => $this->roles->findOneByCode(User::ROLE_AGENT),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim($form->get('email')->getData()));
            if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
                $form->get('email')->addError(new FormError('Un compte existe déjà avec cet e-mail.'));
            } else {
                try {
                    $user = $this->accounts->createAccount($person, $email, $form->get('role')->getData());
                    $this->activity->log('user.create', ['id' => $user->getId(), 'email' => $email, 'role' => $user->getRoleLabel(), 'fiche' => $person->getId(), 'mot_de_passe_envoye' => true]);
                    $this->addFlash('success', sprintf('Compte créé pour %s. Le mot de passe temporaire a été envoyé à %s (valable %d h).', $person->getFullName(), $email, AccountProvisioner::PASSWORD_VALID_HOURS));

                    return $this->redirectToRoute('app_personnel_show', ['id' => $id]);
                } catch (TransportExceptionInterface $e) {
                    $form->addError(new FormError($this->mailFailure($e).' Aucun compte n\'a été créé.'));
                }
            }
        }

        return $this->render('admin/users/from_personnel.html.twig', ['form' => $form, 'person' => $person, 'hours' => AccountProvisioner::PASSWORD_VALID_HOURS]);
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

    /** Authentificateur perdu : on le réinitialise et un nouveau mot de passe temporaire part par e-mail. */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/reset-2fa', name: 'app_admin_user_reset2fa', methods: 'POST', requirements: ['id' => '\d+'])]
    public function reset2fa(Request $request, User $user): Response
    {
        $this->checkCsrf($request, 'user_'.$user->getId());

        try {
            $this->accounts->resetTwoFactor($user, 'reset');
            $this->em->flush();
            $this->activity->log('auth.2fa_reset', ['id' => $user->getId(), 'email' => $user->getEmail(), 'mot_de_passe_envoye' => true]);
            $this->addFlash('success', sprintf('Authentificateur de %s réinitialisé. Un mot de passe temporaire lui a été envoyé (valable %d h) pour réactiver Google Authenticator.', $user->getFullName(), AccountProvisioner::PASSWORD_VALID_HOURS));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', $this->mailFailure($e).' L\'authentificateur n\'a pas été modifié.');
        }

        return $this->redirectToRoute('app_admin_users');
    }

    /** Renvoie un mot de passe temporaire à un compte qui n'a pas encore activé son authentificateur (mot de passe expiré ou perdu). */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/resend-password', name: 'app_admin_user_resend', methods: 'POST', requirements: ['id' => '\d+'])]
    public function resendPassword(Request $request, User $user): Response
    {
        $this->checkCsrf($request, 'user_'.$user->getId());

        if ($user->hasTwoFactor() || !$user->isActive()) {
            $this->addFlash('error', 'Ce compte est désactivé ou a déjà activé son authentificateur : utilisez « Réinit. 2FA » si besoin.');

            return $this->redirectToRoute('app_admin_users');
        }

        try {
            $this->accounts->issueTemporaryPassword($user, 'resend');
            $this->em->flush();
            $this->activity->log('user.password_sent', ['id' => $user->getId(), 'email' => $user->getEmail()]);
            $this->addFlash('success', sprintf('Nouveau mot de passe temporaire envoyé à %s (valable %d h).', $user->getEmail(), AccountProvisioner::PASSWORD_VALID_HOURS));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', $this->mailFailure($e));
        }

        return $this->redirectToRoute('app_admin_users');
    }

    /** Envoie un e-mail de test à l'adresse du compte pour vérifier la configuration du courrier. Ne modifie rien. */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}/test-mail', name: 'app_admin_user_testmail', methods: 'POST', requirements: ['id' => '\d+'])]
    public function testMail(Request $request, User $user): Response
    {
        $this->checkCsrf($request, 'user_'.$user->getId());

        try {
            $this->accounts->sendTestEmail($user);
            $this->activity->log('user.test_mail', ['id' => $user->getId(), 'email' => $user->getEmail()]);
            $this->addFlash('success', sprintf('E-mail de test envoyé à %s.', $user->getEmail()));
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', $this->mailFailure($e));
        }

        return $this->redirectToRoute('app_admin_users');
    }

    private function mailFailure(\Throwable $e): string
    {
        $this->logger->error('Envoi d\'e-mail de compte impossible : '.$e->getMessage());

        return 'L\'e-mail n\'a pas pu être envoyé (vérifiez MAILER_DSN).';
    }

    private function otherActiveAdmins(User $except): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u JOIN u.role r WHERE r.code = :r AND u.active = true AND u.id != :id')
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
