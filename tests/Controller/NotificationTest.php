<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Mission;
use App\Entity\Personnel;
use App\Entity\Projet;
use App\Entity\Publication;
use App\Entity\Tache;
use App\Entity\Ticket;
use App\Entity\User;
use App\Service\Notifications;
use App\Service\Notifier;
use App\Service\PermissionManager;
use App\Service\SiteSettings;
use App\Tests\AppWebTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-mails d'information : qui est prévenu de quoi, jamais l'auteur de l'action, et jamais d'échec de l'action si l'envoi échoue. */
class NotificationTest extends AppWebTestCase
{
    // ---------- absences ----------

    public function testUneDemandeDAbsenceAlerteLesGestionnairesSeulement(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $this->linked('Dupont', $agent);
        $this->user(User::ROLE_AGENT, 'collegue@procom.pro'); // ne gère pas les absences
        $this->user(User::ROLE_DIRECTION);
        $this->login($agent);

        $crawler = $this->browser->request('GET', '/absences/nouvelle');
        $this->browser->submit($crawler->selectButton('Envoyer la demande')->form(['absence[type]' => 'conge', 'absence[dateDebut]' => '2026-11-02', 'absence[dateFin]' => '2026-11-06']));
        self::assertResponseRedirects();

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'direction@procom.pro');
        self::assertEmailHeaderSame($mail, 'subject', '[PROCOM Tools] Demande d\'absence de Awa DUPONT');
        self::assertStringContainsString('du 02/11/2026 au 06/11/2026 (5 jours)', $mail->getTextBody());
        self::assertStringContainsString('/absences/', $mail->getTextBody());
    }

    public function testLaDecisionPrevientLeDemandeur(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $person = $this->linked('Dupont', $agent);
        $ok = $this->absence($person, '2026-11-02', '2026-11-06');
        $no = $this->absence($person, '2026-12-07', '2026-12-11');
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/absences/'.$ok->getId());
        $this->browser->submit($crawler->selectButton('Approuver')->form());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'agent@procom.pro');
        self::assertStringContainsString('approuvée', $mail->getTextBody());

        $crawler = $this->browser->request('GET', '/absences/'.$no->getId());
        $this->browser->submit($crawler->selectButton('Refuser')->form(['note' => 'Période de clôture.']));
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertStringContainsString('refusée', $mail->getTextBody());
        self::assertStringContainsString('Commentaire : Période de clôture.', $mail->getTextBody());
    }

    public function testUneAbsenceDeclareeParLaDirectionPrevientLInteresse(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $person = $this->linked('Dupont', $agent);
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/absences/nouvelle');
        $this->browser->submit($crawler->selectButton('Enregistrer l\'absence')->form(['absence[personnel]' => (string) $person->getId(), 'absence[type]' => 'maladie', 'absence[dateDebut]' => '2026-11-02', 'absence[dateFin]' => '2026-11-04']));
        self::assertResponseRedirects();

        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'agent@procom.pro');
    }

    public function testLAnnulationParLInteressePrevientLesGestionnairesPasLui(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $person = $this->linked('Dupont', $agent);
        $this->user(User::ROLE_DIRECTION);
        $absence = $this->absence($person, '+10 days', '+12 days');
        $this->login($agent);

        $crawler = $this->browser->request('GET', '/absences/'.$absence->getId());
        $this->browser->submit($crawler->selectButton('Annuler')->form());

        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'direction@procom.pro');
    }

    // ---------- tickets ----------

    public function testUnTicketAlerteLesGestionnairesPuisSonStatutPrevientLAuteur(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $this->user(User::ROLE_DIRECTION);
        $item = $this->materiel('MacBook Pro 14', 'MB-01');
        $this->login($agent);

        $crawler = $this->browser->request('GET', '/pannes/nouveau?type=panne&materiel='.$item->getId());
        $this->browser->submit($crawler->selectButton('Envoyer le signalement')->form(['ticket[titre]' => 'L\'écran ne s\'allume plus', 'ticket[priorite]' => 'haute']));
        self::assertResponseRedirects();

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'direction@procom.pro');
        self::assertStringContainsString('Matériel concerné : MacBook Pro 14', $mail->getTextBody());
        self::assertStringContainsString('Priorité : haute', $mail->getTextBody());

        $ticket = $this->em->getRepository(Ticket::class)->findOneBy([]);
        $this->login($this->user(User::ROLE_DIRECTION, 'autre-direction@procom.pro'));
        $crawler = $this->browser->request('GET', '/pannes/'.$ticket->getId());
        $this->browser->submit($crawler->selectButton('Mettre à jour')->form(['statut' => Ticket::RESOLU, 'resolution' => 'Écran remplacé.']));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'agent@procom.pro');
        self::assertStringContainsString('Résolution : Écran remplacé.', $mail->getTextBody());
    }

    // ---------- missions ----------

    public function testUnChangementDeStatutDeMissionPrevientLeResponsableEtLEquipe(): void
    {
        [$lead, $member, $outsider] = [$this->linkedStaff('Chef', 'chef@procom.pro'), $this->linkedStaff('Membre', 'membre@procom.pro'), $this->linkedStaff('Autre', 'autre@procom.pro')];
        $mission = (new Mission())->setTitre('Reportage')->setLieu('Cotonou')->setDateDebut(new \DateTimeImmutable('+3 days'))->setDateFin(new \DateTimeImmutable('+3 days'))->setResponsable($lead)->addEquipe($member);
        $this->em->persist($mission);
        $this->em->flush();
        $this->login($this->user(User::ROLE_DIRECTION));

        $this->postFromPage('/missions/'.$mission->getId(), '/statut', ['statut' => Mission::CONFIRMEE]);

        self::assertEmailCount(2);
        $to = array_map(static fn ($m) => $m->getTo()[0]->getAddress(), self::getMailerMessages());
        sort($to);
        self::assertSame(['chef@procom.pro', 'membre@procom.pro'], $to);
        self::assertNotContains($outsider->getUser()->getEmail(), $to);
    }

    public function testMissionProgrammeeEtCompteRendu(): void
    {
        $lead = $this->linkedStaff('Chef', 'chef@procom.pro');
        $mission = (new Mission())->setTitre('Reportage')->setLieu('Cotonou')->setDateDebut(new \DateTimeImmutable('-1 day'))->setDateFin(new \DateTimeImmutable('-1 day'))->setResponsable($lead);
        $this->em->persist($mission);
        $this->em->flush();
        $this->user(User::ROLE_DIRECTION); // programme les missions

        $notifications = static::getContainer()->get(Notifications::class);
        $notifications->missionScheduled($mission);
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'chef@procom.pro');

        $notifications->missionReported($mission);
        self::assertEmailCount(3); // chef + direction (cumul des deux envois)
    }

    // ---------- matériel ----------

    public function testLaRemiseEtLaRestitutionPrevientLeDetenteur(): void
    {
        $holder = $this->linkedStaff('Porteur', 'porteur@procom.pro');
        $item = $this->materiel('MacBook Pro 14', 'MB-01');
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId());
        $this->browser->submit($crawler->selectButton('Enregistrer la remise')->form(['assign[personnel]' => (string) $holder->getId()]));
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'porteur@procom.pro');
        self::assertStringContainsString('« MacBook Pro 14 » vous a été remis', $mail->getTextBody());

        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId());
        $this->browser->submit($crawler->selectButton('Enregistrer la restitution')->form());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'porteur@procom.pro');
        self::assertStringContainsString('La restitution de « MacBook Pro 14 »', $mail->getTextBody());
    }

    // ---------- projets ----------

    public function testUneCarteDeplaceePrevientSonResponsableEtLeResponsableDuProjet(): void
    {
        $assignee = $this->linkedStaff('Assigne', 'assigne@procom.pro');
        $owner = $this->linkedStaff('Proprio', 'proprio@procom.pro');
        $projet = (new Projet())->setNom('Refonte')->setResponsable($owner);
        $tache = (new Tache())->setProjet($projet)->setTitre('Maquette')->setAssignee($assignee);
        $this->em->persist($projet);
        $this->em->persist($tache);
        $projet->getTaches()->add($tache);
        $this->em->flush();
        $this->login($this->user(User::ROLE_DIRECTION));

        $token = $this->browser->request('GET', '/projets/'.$projet->getId())->filter('.kb')->attr('data-kanban-token-value');
        $this->browser->request('POST', '/projets/taches/'.$tache->getId().'/deplacer', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['statut' => Tache::EN_COURS, 'index' => 0]));
        self::assertResponseIsSuccessful();

        self::assertEmailCount(2);
        $to = array_map(static fn ($m) => $m->getTo()[0]->getAddress(), self::getMailerMessages());
        sort($to);
        self::assertSame(['assigne@procom.pro', 'proprio@procom.pro'], $to);
    }

    public function testUneCarteConfieeEtUnProjetQuiChangeDeStatut(): void
    {
        $assignee = $this->linkedStaff('Assigne', 'assigne@procom.pro');
        $projet = (new Projet())->setNom('Refonte')->setResponsable($assignee);
        $tache = (new Tache())->setProjet($projet)->setTitre('Maquette')->setAssignee($assignee);
        $this->em->persist($projet);
        $this->em->persist($tache);
        $this->em->flush();

        $notifications = static::getContainer()->get(Notifications::class);
        $notifications->tacheAssigned($tache);
        self::assertEmailCount(1);
        self::assertStringContainsString('vous a confié la carte « Maquette »', self::getMailerMessage()->getTextBody());

        $notifications->projetStatusChanged($projet, 'À lancer');
        self::assertEmailCount(2);
    }

    // ---------- publications ----------

    public function testUnePublicationEnRelecturePrevientCeuxQuiValidentPasLAuteur(): void
    {
        $author = $this->linkedStaff('Auteur', 'agent@procom.pro', User::ROLE_AGENT);
        $this->user(User::ROLE_DIRECTION);
        $this->user(User::ROLE_STRATEGIE);
        $publication = (new Publication())->setTitre('Post de rentrée')->setDatePublication(new \DateTimeImmutable('+5 days'))->setStatut(Publication::REDACTION)->setResponsable($author);
        $this->em->persist($publication);
        $this->em->flush();
        $this->login($this->em->getRepository(User::class)->findOneBy(['email' => 'agent@procom.pro']));

        $this->postFromPage('/calendrier/'.$publication->getId(), '/statut', ['statut' => Publication::RELECTURE]);

        self::assertEmailCount(2);
        $to = array_map(static fn ($m) => $m->getTo()[0]->getAddress(), self::getMailerMessages());
        sort($to);
        self::assertSame(['direction@procom.pro', 'strategie@procom.pro'], $to);
        self::assertStringContainsString('relecture et votre validation', self::getMailerMessage()->getTextBody());
    }

    // ---------- factures ----------

    public function testUneFactureQuiChangeDeStatutPrevientSonAuteurEtLaFinance(): void
    {
        $author = $this->user(User::ROLE_AGENT);
        $this->user(User::ROLE_RH_FINANCE);
        $client = (new Client())->setName('ACME')->setIfu('1234567890123');
        $facture = (new Document(Document::TYPE_FACTURE))->setReference('FA-2026-001')->setClient($client)->setObjet('Prestation')->setStatut(Document::EMISE)
            ->setDateEmission(new \DateTimeImmutable('-1 month'))->setDateLimite(new \DateTimeImmutable('+30 days'))->setMontantTtc('118000')->setMontantHt('100000.00')->setMontantTva('18000.00')->setTauxTva('18.00')->setCreatedBy($author);
        $this->em->persist($client);
        $this->em->persist($facture);
        $this->em->flush();
        $this->login($this->user(User::ROLE_DIRECTION));

        $this->postFromPage('/factures/'.$facture->getId(), '/statut', ['statut' => Document::ANNULEE]);

        self::assertEmailCount(2);
        $to = array_map(static fn ($m) => $m->getTo()[0]->getAddress(), self::getMailerMessages());
        sort($to);
        self::assertSame(['agent@procom.pro', 'rh_finance@procom.pro'], $to);
    }

    // ---------- sécurité ----------

    public function testDesactiverUnCompteAlerteLesAutresAdministrateurs(): void
    {
        $target = $this->user(User::ROLE_AGENT, 'cible@procom.pro');
        $this->user(User::ROLE_ADMIN, 'admin2@procom.pro');
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/users');
        $this->browser->submit($crawler->filter('form[action$="/'.$target->getId().'/toggle"]')->form());

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'admin2@procom.pro');
        self::assertStringContainsString('désactivé le compte de Cible', $mail->getTextBody());
    }

    public function testChangementDeRoleEtActivationDeLAuthentificateur(): void
    {
        $user = $this->user(User::ROLE_AGENT, 'cible@procom.pro');
        $this->user(User::ROLE_ADMIN);

        $notifications = static::getContainer()->get(Notifications::class);
        $notifications->roleChanged($user, 'Direction');
        self::assertEmailCount(2); // la personne concernée et l'administrateur

        $notifications->twoFactorEnabled($user);
        self::assertEmailCount(3);
        self::assertStringContainsString('activer Google Authenticator', self::getMailerMessage(2)->getTextBody());
    }

    public function testCinqConnexionsEchoueesAlertentLesAdministrateursUneSeuleFois(): void
    {
        $this->user(User::ROLE_ADMIN);
        $this->user(User::ROLE_AGENT, 'cible@procom.pro');

        for ($i = 1; $i <= 6; ++$i) {
            $crawler = $this->browser->request('GET', '/login');
            $this->browser->submit($crawler->selectButton('Se connecter')->form(['email' => 'cible@procom.pro', 'password' => 'mauvais', 'code' => '']));
            if (Notifications::LOGIN_FAILURE_THRESHOLD === $i) {
                self::assertEmailCount(1, message: 'alerte au seuil');
                $mail = self::getMailerMessage();
                self::assertEmailAddressContains($mail, 'To', 'admin@procom.pro');
                self::assertStringContainsString('cible@procom.pro', $mail->getTextBody());
            } else {
                self::assertEmailCount(0, message: "pas d'alerte à la tentative $i");
            }
        }
    }

    // ---------- robustesse ----------

    public function testUnEchecDEnvoiNeCasseJamaisLAction(): void
    {
        $user = $this->user(User::ROLE_AGENT, 'cible@procom.pro');
        $container = static::getContainer();
        $failing = $this->createStub(TransportInterface::class);
        $failing->method('send')->willThrowException(new TransportException('SMTP indisponible'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('SMTP indisponible'));

        $notifier = new Notifier(
            $this->em,
            $container->get(PermissionManager::class),
            $failing,
            $container->get(UrlGeneratorInterface::class),
            $container->get(SiteSettings::class),
            $container->get(Security::class),
            $logger,
            'PROCOM <no-reply@agenceprocom.pro>',
            'http://localhost',
        );

        $notifier->send([$user], 'Sujet', ['Ligne']); // ne lève rien
        self::assertTrue(true);
    }

    // ---------- fabriques ----------

    /** Une fiche du personnel rattachée à un compte. */
    private function linked(string $last, User $user): Personnel
    {
        $person = $this->personnel($last, 'Awa');
        $person->setUser($user);
        $this->em->flush();

        return $person;
    }

    private function linkedStaff(string $last, string $email, string $role = User::ROLE_AGENT): Personnel
    {
        return $this->linked($last, $this->em->getRepository(User::class)->findOneBy(['email' => $email]) ?? $this->user($role, $email));
    }

    private function absence(Personnel $person, string $from, string $to, string $statut = Absence::EN_ATTENTE): Absence
    {
        $absence = (new Absence())->setPersonnel($person)->setType('conge')->setDateDebut(new \DateTimeImmutable($from))->setDateFin(new \DateTimeImmutable($to))->setStatut($statut)->recomputeDays();
        $this->em->persist($absence);
        $this->em->flush();

        return $absence;
    }

    /** Poste vers le formulaire de la fiche dont l'action se termine par $suffix, avec le jeton lu sur la page comme le ferait le navigateur. */
    private function postFromPage(string $page, string $suffix, array $fields): void
    {
        $crawler = $this->browser->request('GET', $page);
        $form = $crawler->filter('form[action$="'.$suffix.'"]')->first();
        self::assertCount(1, $form, "Aucun formulaire « $suffix » sur $page.");
        $this->browser->request('POST', $form->attr('action'), $fields + ['_token' => $form->filter('input[name="_token"]')->attr('value')]);
    }
}
