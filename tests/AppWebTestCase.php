<?php

namespace App\Tests;

use App\Entity\Affectation;
use App\Entity\Materiel;
use App\Entity\Personnel;
use App\Entity\Role;
use App\Entity\User;
use App\Service\RoleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Base des tests fonctionnels : base vidée avant chaque test (rôles système recréés), connexion directe d'un utilisateur, fabriques de données. */
abstract class AppWebTestCase extends WebTestCase
{
    protected KernelBrowser $browser;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['tache', 'mission', 'projet', 'publication', 'depense', 'ticket', 'absence', 'affectation', 'materiel', 'personnel', 'role_permission', 'encaissement', 'document', 'activity_log', 'correction_memory', 'correction', 'client', 'fiscal_setting', 'app_user', 'app_role'] as $table) {
            $this->em->getConnection()->executeStatement('DELETE FROM '.$table);
        }
        static::getContainer()->get(RoleManager::class)->ensureSystemRoles();
    }

    /** Un rôle système (User::ROLE_AGENT, ROLE_ADMIN, ROLE_DIRECTION) ou un rôle créé, par son code. */
    protected function role(string $code): Role
    {
        return $this->em->getRepository(Role::class)->findOneBy(['code' => $code]) ?? self::fail("Rôle $code introuvable.");
    }

    /**
     * Crée un rôle comme le fait l'administration.
     *
     * @param list<string> $permissions
     */
    protected function customRole(string $label, array $permissions = []): Role
    {
        return static::getContainer()->get(RoleManager::class)->create((new Role())->setLabel($label), $permissions);
    }

    /** @param string $role code d'un rôle (User::ROLE_AGENT…) */
    protected function user(string $role, ?string $email = null): User
    {
        $email ??= strtolower(substr($role, 5)).'@procom.pro';
        $user = (new User())->setEmail($email)->setFullName(ucfirst(strstr($email, '@', true)))->setRole($this->role($role))->setPassword('x')->setTotpSecret('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function login(User $user): void
    {
        $this->browser->loginUser($user);
    }

    protected function personnel(string $last = 'Dupont', string $first = 'Awa', ?string $left = null): Personnel
    {
        $person = (new Personnel())->setLastName($last)->setFirstName($first)->setHiredAt(new \DateTimeImmutable('-2 years'))
            ->setLeftAt($left ? new \DateTimeImmutable($left) : null)->setJobTitle('Graphiste')->setDepartment('Création');
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    protected function materiel(string $designation = 'MacBook Pro 14', ?string $reference = null, string $etat = 'bon'): Materiel
    {
        $item = (new Materiel())->setDesignation($designation)->setReference($reference)->setEtat($etat);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    protected function assignDirectly(Materiel $item, Personnel $person, string $date = '-30 days'): Affectation
    {
        $affectation = new Affectation($item, $person, new \DateTimeImmutable($date));
        $item->getAffectations()->add($affectation);
        $this->em->persist($affectation);
        $this->em->flush();

        return $affectation;
    }

    /** Le noyau redémarre entre deux requêtes : repartir de la base. */
    protected function fresh(string $class, int $id): object
    {
        $this->em->clear();

        return $this->em->find($class, $id) ?? self::fail("$class #$id introuvable.");
    }
}
