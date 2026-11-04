<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
use App\Entity\Personnel;
use App\Entity\User;
use App\Repository\AbsenceRepository;
use App\Security\Permission;
use App\Tests\AppWebTestCase;

/** Congés et absences : demande, décision, jours ouvrés, chevauchements, calendrier d'équipe, permissions. */
class AbsenceTest extends AppWebTestCase
{
    public function testLesJoursOuvresIgnorentLesWeekEnds(): void
    {
        $d = static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date);

        self::assertSame(5, Absence::countWorkingDays($d('2026-11-02'), $d('2026-11-06')), 'lundi à vendredi');
        self::assertSame(2, Absence::countWorkingDays($d('2026-11-06'), $d('2026-11-09')), 'vendredi à lundi');
        self::assertSame(0, Absence::countWorkingDays($d('2026-11-07'), $d('2026-11-08')), 'samedi et dimanche');
        self::assertSame(1, Absence::countWorkingDays($d('2026-11-04'), $d('2026-11-04')), 'un seul jour');
        self::assertSame(0, Absence::countWorkingDays($d('2026-11-09'), $d('2026-11-02')), 'intervalle à l\'envers');
    }

    public function testUnAgentDemandeUnCongePourLuiEtLaDemandeEstEnAttente(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $person = $this->linked('Dupont', $agent);
        $this->login($agent);

        $crawler = $this->browser->request('GET', '/absences/nouvelle');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select[name="absence[personnel]"] option'), 'il ne peut demander que pour lui');
        $this->browser->submit($crawler->selectButton('Envoyer la demande')->form([
            'absence[type]' => 'conge',
            'absence[dateDebut]' => '2026-11-02',
            'absence[dateFin]' => '2026-11-06',
            'absence[motif]' => 'Voyage en famille',
        ]));
        self::assertResponseRedirects();

        $absence = $this->only();
        self::assertSame($person->getId(), $absence->getPersonnel()?->getId());
        self::assertSame(Absence::EN_ATTENTE, $absence->getStatut());
        self::assertSame(5, $absence->getJours());
        self::assertSame('agent@procom.pro', $absence->getCreatedBy()?->getEmail());
    }

    public function testSansFicheRattacheeOnNePeutPasPoserDAbsence(): void
    {
        $this->login($this->user(User::ROLE_AGENT));

        $this->browser->request('GET', '/absences/nouvelle');
        self::assertResponseRedirects('/absences');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('body', 'aucune fiche du personnel');
    }

    public function testOnNePeutPasDemanderPourUnAutre(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $this->linked('Dupont', $agent);
        $other = $this->personnel('Martin', 'Paul');
        $this->login($agent);

        $crawler = $this->browser->request('GET', '/absences/nouvelle');
        $form = $crawler->selectButton('Envoyer la demande')->form(['absence[dateDebut]' => '2026-11-02', 'absence[dateFin]' => '2026-11-03']);
        $values = $form->getPhpValues();
        $values['absence']['personnel'] = (string) $other->getId();
        $this->browser->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Absence::class)->findAll());
    }

    public function testLesPeriodesInvalidesOuQuiSeChevauchentSontRefusees(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $person = $this->linked('Dupont', $agent);
        $this->absence($person, '2026-12-07', '2026-12-11', Absence::APPROUVEE);
        $this->absence($person, '2027-01-04', '2027-01-08', Absence::REFUSEE);
        $this->login($agent);

        $submit = function (string $from, string $to): void {
            $crawler = $this->browser->request('GET', '/absences/nouvelle');
            $this->browser->submit($crawler->selectButton('Envoyer la demande')->form(['absence[dateDebut]' => $from, 'absence[dateFin]' => $to]));
        };

        $submit('2026-12-09', '2026-12-15');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'chevauche une absence déjà enregistrée');

        $submit('2026-11-10', '2026-11-05');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La date de fin est antérieure à la date de début.');

        $submit('2026-11-07', '2026-11-08');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'aucun jour ouvré');

        $submit('2027-01-05', '2027-01-06');
        self::assertResponseRedirects('', null, 'une absence refusée ne bloque pas la période');
    }

    public function testLaDirectionDeclareUneAbsenceDirectementApprouvee(): void
    {
        $person = $this->personnel('Martin', 'Paul');
        $this->personnel('Dupont', 'Awa');
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/absences/nouvelle');
        self::assertGreaterThan(1, $crawler->filter('select[name="absence[personnel]"] option')->count());
        $this->browser->submit($crawler->selectButton('Enregistrer l\'absence')->form([
            'absence[personnel]' => (string) $person->getId(),
            'absence[type]' => 'maladie',
            'absence[dateDebut]' => '2026-11-02',
            'absence[dateFin]' => '2026-11-04',
        ]));
        self::assertResponseRedirects();

        $absence = $this->only();
        self::assertSame(Absence::APPROUVEE, $absence->getStatut());
        self::assertSame('direction@procom.pro', $absence->getDecidedBy()?->getEmail());
        self::assertSame(3, $absence->getJours());
    }

    public function testLaDirectionApprouveOuRefuseUneDemandeUneSeuleFois(): void
    {
        $person = $this->personnel('Dupont');
        $ok = $this->absence($person, '2026-11-02', '2026-11-06', Absence::EN_ATTENTE);
        $no = $this->absence($person, '2026-12-07', '2026-12-11', Absence::EN_ATTENTE);
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/absences/'.$ok->getId());
        $this->browser->submit($crawler->selectButton('Approuver')->form());
        $ok = $this->fresh(Absence::class, $ok->getId());
        self::assertSame(Absence::APPROUVEE, $ok->getStatut());
        self::assertSame('direction@procom.pro', $ok->getDecidedBy()?->getEmail());

        // Un refus sans raison n'est pas enregistré.
        $crawler = $this->browser->request('GET', '/absences/'.$no->getId());
        $this->browser->submit($crawler->selectButton('Refuser')->form(['note' => '']));
        self::assertSame(Absence::EN_ATTENTE, $this->fresh(Absence::class, $no->getId())->getStatut());
        $crawler = $this->browser->request('GET', '/absences/'.$no->getId());
        self::assertSelectorTextContains('body', 'Indiquez la raison du refus.');
        $this->browser->submit($crawler->selectButton('Refuser')->form(['note' => 'Période de clôture.']));
        $no = $this->fresh(Absence::class, $no->getId());
        self::assertSame(Absence::REFUSEE, $no->getStatut());
        self::assertSame('Période de clôture.', $no->getDecision());

        // Une demande déjà traitée n'a plus de formulaire de décision.
        $this->browser->request('GET', '/absences/'.$no->getId());
        self::assertSelectorNotExists('form[action$="/decision"]');
    }

    public function testSeulUnGestionnaireDecide(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $person = $this->linked('Dupont', $agent);
        $absence = $this->absence($person, '2026-11-02', '2026-11-06', Absence::EN_ATTENTE);
        $this->login($agent);

        $crawler = $this->browser->request('GET', '/absences/'.$absence->getId());
        self::assertSelectorNotExists('form[action$="/decision"]');
        $this->browser->request('POST', '/absences/'.$absence->getId().'/decision', ['decision' => 'approuver']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(Absence::EN_ATTENTE, $this->fresh(Absence::class, $absence->getId())->getStatut());
    }

    public function testLInteresseAnnuleSaDemandeMaisPasCelleDUnAutre(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $person = $this->linked('Dupont', $agent);
        $mine = $this->absence($person, '+10 days', '+12 days', Absence::EN_ATTENTE);
        $past = $this->absence($person, '-20 days', '-18 days', Absence::APPROUVEE);
        $theirs = $this->absence($this->personnel('Martin', 'Paul'), '+10 days', '+12 days', Absence::EN_ATTENTE);
        $this->login($agent);

        $crawler = $this->browser->request('GET', '/absences/'.$mine->getId());
        $this->browser->submit($crawler->filter('form[action$="/annuler"]')->form());
        self::assertSame(Absence::ANNULEE, $this->fresh(Absence::class, $mine->getId())->getStatut());

        $this->browser->request('GET', '/absences/'.$past->getId());
        self::assertSelectorNotExists('form[action$="/annuler"]', 'une absence passée ne s\'annule plus');

        $crawler = $this->browser->request('GET', '/absences/'.$theirs->getId());
        self::assertSelectorNotExists('form[action$="/annuler"]');
        $this->browser->request('POST', '/absences/'.$theirs->getId().'/annuler', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeMotifResteConfidentielPourLesAutres(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $mine = $this->absence($this->linked('Dupont', $agent), '2026-11-02', '2026-11-06', Absence::APPROUVEE, 'maladie', 'Rendez-vous médical');
        $other = $this->user(User::ROLE_AGENT, 'collegue@procom.pro');
        $this->linked('Martin', $other);

        $this->login($agent);
        $this->browser->request('GET', '/absences/'.$mine->getId());
        self::assertSelectorTextContains('body', 'Rendez-vous médical');

        $this->login($other);
        $this->browser->request('GET', '/absences/'.$mine->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Rendez-vous médical');

        $this->login($this->user(User::ROLE_DIRECTION));
        $this->browser->request('GET', '/absences/'.$mine->getId());
        self::assertSelectorTextContains('body', 'Rendez-vous médical');
    }

    public function testLeCalendrierMontreLesAbsencesApprouveesEtEnAttenteDuMois(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $approved = $this->absence($this->personnel('Dupont', 'Awa'), '2026-11-02', '2026-11-04', Absence::APPROUVEE);
        $pending = $this->absence($this->personnel('Martin', 'Paul'), '2026-11-10', '2026-11-10', Absence::EN_ATTENTE);
        $this->absence($this->personnel('Refus', 'Rita'), '2026-11-02', '2026-11-04', Absence::REFUSEE);
        $this->absence($this->personnel('Autre', 'Mois'), '2026-12-02', '2026-12-04', Absence::APPROUVEE);

        $crawler = $this->browser->request('GET', '/absences/calendrier?mois=2026-11');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.cal tbody tr'), 'refusée et autre mois exclues');
        self::assertCount(3, $crawler->filter('a.cal-bar[href$="/absences/'.$approved->getId().'"]'), 'trois jours ouvrés');
        self::assertCount(1, $crawler->filter('a.cal-bar.pending[href$="/absences/'.$pending->getId().'"]'));
        self::assertSelectorTextContains('.cal-month', 'novembre 2026');
        self::assertSelectorTextNotContains('.cal', 'REFUS');
        self::assertSelectorTextContains('.cal', 'DUPONT');

        $this->browser->request('GET', '/absences/calendrier?mois=pas-un-mois');
        self::assertResponseIsSuccessful('un mois invalide retombe sur le mois courant');
    }

    public function testLeTableauDeBordEtLesJoursPoses(): void
    {
        $repository = static::getContainer()->get(AbsenceRepository::class);
        $person = $this->personnel('Dupont', 'Awa');
        $today = new \DateTimeImmutable('today');
        $this->absence($person, '-1 day', '+1 day', Absence::APPROUVEE);
        $this->absence($this->personnel('Martin', 'Paul'), '-1 day', '+1 day', Absence::EN_ATTENTE);
        $this->absence($this->personnel('Soleil', 'Eve'), '+7 days', '+8 days', Absence::APPROUVEE);

        $board = $repository->dashboard($today);
        self::assertSame(1, $board['pending']);
        self::assertCount(1, $board['absentToday'], 'seules les absences approuvées comptent');
        self::assertSame($person->getId(), $board['absentToday'][0]->getPersonnel()->getId());
        self::assertSame(1, $board['upcoming']);

        $year = (int) $today->format('Y');
        $taken = $repository->daysTaken($person, $year) + $repository->daysTaken($person, $year - 1) + $repository->daysTaken($person, $year + 1);
        self::assertGreaterThan(0, $taken);
        self::assertSame(0, $repository->daysTaken($person, $year, 'maladie'), 'filtré par type');
    }

    public function testLesPermissionsGouvernentChaqueAction(): void
    {
        $person = $this->personnel('Dupont');
        $absence = $this->absence($person, '2026-11-02', '2026-11-06', Absence::APPROUVEE);

        $this->login($this->user($this->customRole('Observateur')->getCode(), 'obs@procom.pro'));
        foreach (['/absences', '/absences/calendrier', '/absences/'.$absence->getId(), '/absences/nouvelle'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->login($this->user($this->customRole('Lecteur', [Permission::ABSENCE_VIEW])->getCode(), 'lecteur@procom.pro'));
        $this->browser->request('GET', '/absences');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/absences/nouvelle"]');
        $this->browser->request('GET', '/absences/nouvelle');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/absences/'.$absence->getId());
        self::assertSelectorNotExists('a[href$="/modifier"]');
        self::assertSelectorNotExists('form[action$="/supprimer"]');

        $this->login($this->user(User::ROLE_DIRECTION));
        $this->browser->request('POST', '/absences/'.$absence->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403, 'la direction décide mais ne supprime pas');
    }

    public function testLaSuppressionEstLogique(): void
    {
        $absence = $this->absence($this->personnel('Dupont'), '2026-11-02', '2026-11-06', Absence::APPROUVEE);
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/absences/'.$absence->getId());
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects('/absences');

        self::assertTrue($this->fresh(Absence::class, $absence->getId())->isDeleted());
        $this->browser->request('GET', '/absences/'.$absence->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testLaListeSeFiltre(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $awa = $this->personnel('Dupont', 'Awa');
        $paul = $this->personnel('Martin', 'Paul');
        $this->absence($awa, '2026-11-02', '2026-11-03', Absence::APPROUVEE, 'conge');
        $this->absence($paul, '2026-11-09', '2026-11-10', Absence::EN_ATTENTE, 'maladie');

        $crawler = $this->browser->request('GET', '/absences');
        self::assertCount(2, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/absences?etat=en_attente');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'MARTIN');
        $crawler = $this->browser->request('GET', '/absences?type=conge&personnel='.$awa->getId().'&annee=2026');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/absences?annee=2025');
        self::assertCount(0, $crawler->filter('.enc-table tbody tr td a'));
        $this->browser->request('GET', '/absences?etat=zzz&type=zzz&annee=abc&sort=zzz');
        self::assertResponseIsSuccessful();
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

    private function absence(Personnel $person, string $from, string $to, string $statut, string $type = 'conge', ?string $motif = null): Absence
    {
        $absence = (new Absence())->setPersonnel($person)->setType($type)->setDateDebut(new \DateTimeImmutable($from))->setDateFin(new \DateTimeImmutable($to))
            ->setStatut($statut)->setMotif($motif)->recomputeDays();
        if (Absence::REFUSEE === $statut) {
            $absence->setDecision('Non');
        }
        $this->em->persist($absence);
        $this->em->flush();

        return $absence;
    }

    private function only(): Absence
    {
        $this->em->clear();

        return $this->em->getRepository(Absence::class)->findOneBy([]) ?? self::fail('Aucune absence.');
    }
}
