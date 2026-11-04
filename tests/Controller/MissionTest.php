<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
use App\Entity\Mission;
use App\Entity\Personnel;
use App\Entity\Projet;
use App\Entity\User;
use App\Security\Permission;
use App\Service\MissionPlanner;
use App\Service\MissionReview;
use App\Tests\AppWebTestCase;

/** Missions sur le terrain : programmation, conflits, calendrier, compte rendu, bilan de période, permissions. */
class MissionTest extends AppWebTestCase
{
    public function testLeResponsableDeProgrammationProgrammeUneMissionAvecSonEquipe(): void
    {
        $awa = $this->personnel('Dupont', 'Awa');
        $paul = $this->personnel('Martin', 'Paul');
        $projet = $this->projet('Campagne');
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/missions/nouvelle?date=2026-12-07&projet='.$projet->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('2026-12-07', $crawler->filter('input[name="mission[dateDebut]"]')->attr('value'));
        self::assertSame((string) $projet->getId(), $crawler->filter('select[name="mission[projet]"] option[selected]')->attr('value'));

        $form = $crawler->selectButton('Programmer la mission')->form(['mission[titre]' => 'Tournage du clip', 'mission[lieu]' => 'Ouidah', 'mission[dateFin]' => '2026-12-09', 'mission[responsable]' => (string) $awa->getId()]);
        $values = $form->getPhpValues();
        $values['mission']['equipe'] = [(string) $awa->getId(), (string) $paul->getId()];
        $this->browser->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $mission = $this->em->getRepository(Mission::class)->findOneBy([]);
        self::assertSame('Tournage du clip', $mission->getTitre());
        self::assertSame(3, $mission->getDays());
        self::assertSame(Mission::PLANIFIEE, $mission->getStatut());
        self::assertCount(2, $mission->getEquipe());
        self::assertCount(2, $mission->getParticipants(), 'le chef de mission fait partie de l\'équipe sans doublon');
    }

    public function testLeClientDuProjetSertDeClientALaMission(): void
    {
        $client = (new \App\Entity\Client())->setName('Société Alpha');
        $this->em->persist($client);
        $projet = (new Projet())->setNom('Campagne')->setClient($client);
        $this->em->persist($projet);
        $this->em->flush();
        $mission = $this->mission('Tournage', '2026-12-07', '2026-12-07', [], null, Mission::PLANIFIEE, $projet);

        self::assertSame('Société Alpha', $mission->getEffectiveClient()?->getName());

        $this->login($this->user(User::ROLE_DIRECTION));
        $crawler = $this->browser->request('GET', '/missions/liste?client='.$client->getId());
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'), 'filtrer par client retrouve aussi les missions de ses projets');
    }

    public function testLesChampsObligatoiresEtLesDatesSontControles(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/missions/nouvelle');
        $this->browser->submit($crawler->selectButton('Programmer la mission')->form(['mission[titre]' => '', 'mission[lieu]' => '', 'mission[dateDebut]' => '2026-12-10', 'mission[dateFin]' => '2026-12-08']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le titre est obligatoire.');
        self::assertSelectorTextContains('body', 'Le lieu est obligatoire.');
        self::assertSelectorTextContains('body', 'La date de fin est antérieure à la date de début.');
    }

    public function testUnConflitDeMissionOuDAbsenceEstSignaleSansBloquer(): void
    {
        $awa = $this->personnel('Dupont', 'Awa');
        $paul = $this->personnel('Martin', 'Paul');
        $this->mission('Déjà prise', '2026-12-08', '2026-12-10', [$awa]);
        $absence = (new Absence())->setPersonnel($paul)->setType('conge')->setDateDebut(new \DateTimeImmutable('2026-12-09'))->setDateFin(new \DateTimeImmutable('2026-12-09'))
            ->setStatut(Absence::APPROUVEE)->recomputeDays();
        $this->em->persist($absence);
        $this->em->flush();
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/missions/nouvelle');
        $form = $crawler->selectButton('Programmer la mission')->form(['mission[titre]' => 'Reportage', 'mission[lieu]' => 'Cotonou', 'mission[dateDebut]' => '2026-12-09', 'mission[dateFin]' => '2026-12-09']);
        $values = $form->getPhpValues();
        $values['mission']['equipe'] = [(string) $awa->getId(), (string) $paul->getId()];
        $this->browser->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('', null, 'la mission est enregistrée malgré les conflits');

        $crawler = $this->browser->followRedirect();
        self::assertCount(2, $crawler->filter('.alert.warning'));
        self::assertSelectorTextContains('.alert.warning', 'Awa DUPONT est déjà sur « Déjà prise »');
        self::assertSelectorTextContains('body', 'Paul MARTIN est absent(e) du 09/12/2026');
        self::assertCount(2, $crawler->filter('.banner.warn'), 'les conflits restent visibles sur la fiche');
    }

    public function testUneMissionAnnuleeOuReporteeNeBloquePlusPersonne(): void
    {
        $awa = $this->personnel('Dupont', 'Awa');
        $this->mission('Annulée', '2026-12-08', '2026-12-10', [$awa], null, Mission::ANNULEE);
        $this->mission('Reportée', '2026-12-08', '2026-12-10', [$awa], null, Mission::REPORTEE);
        $new = $this->mission('Nouvelle', '2026-12-09', '2026-12-09', [$awa]);

        self::assertSame([], static::getContainer()->get(MissionPlanner::class)->conflicts($new));
    }

    public function testLeCalendrierPlaceUneMissionSurChaqueJourOuElleADeroule(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $awa = $this->personnel('Dupont', 'Awa');
        $multi = $this->mission('Tournage', '2026-12-07', '2026-12-09', [$awa]);
        $this->mission('Autre mois', '2027-01-12', '2027-01-12', []);

        $crawler = $this->browser->request('GET', '/missions?mois=2026-12');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cal-month', 'décembre 2026');
        self::assertCount(3, $crawler->filter('a.pchip[href$="/missions/'.$multi->getId().'"]'), 'trois jours, trois pastilles');
        self::assertSelectorTextNotContains('.pgrid', 'Autre mois');

        $crawler = $this->browser->request('GET', '/missions?mois=2026-12&personnel='.$awa->getId());
        self::assertCount(3, $crawler->filter('.pchip'));
        $crawler = $this->browser->request('GET', '/missions?mois=2026-12&personnel='.$this->personnel('Martin', 'Paul')->getId());
        self::assertCount(0, $crawler->filter('.pchip'), 'filtre par personne mobilisée');

        $this->browser->request('GET', '/missions?mois=pas-un-mois');
        self::assertResponseIsSuccessful('un mois invalide retombe sur le mois courant');
    }

    public function testLEquipeClotureLaMissionAvecUnCompteRendu(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $awa = $this->personnel('Dupont', 'Awa');
        $awa->setUser($agent);
        $this->em->flush();
        $mission = $this->mission('Tournage', '-3 days', '-2 days', [$awa]);
        $this->login($agent);

        $crawler = $this->browser->request('GET', '/missions/'.$mission->getId());
        self::assertSelectorTextContains('.banner.bad', 'À clôturer');

        // Le résultat est obligatoire.
        $this->browser->submit($crawler->selectButton('Clôturer la mission')->form(['compte_rendu' => 'Tout est tourné.']));
        self::assertSame(Mission::PLANIFIEE, $this->fresh(Mission::class, $mission->getId())->getStatut());

        $crawler = $this->browser->request('GET', '/missions/'.$mission->getId());
        $this->browser->submit($crawler->selectButton('Clôturer la mission')->form(['resultat' => 'atteint', 'compte_rendu' => 'Tout est tourné, rushes sauvegardés.']));
        self::assertResponseRedirects('/missions/'.$mission->getId());

        $mission = $this->fresh(Mission::class, $mission->getId());
        self::assertSame(Mission::REALISEE, $mission->getStatut());
        self::assertSame('Objectifs atteints', $mission->getResultatLabel());
        self::assertSame('Tout est tourné, rushes sauvegardés.', $mission->getCompteRendu());
        self::assertNotNull($mission->getReportedAt());
        self::assertFalse($mission->isToClose());

        $this->browser->request('GET', '/missions/'.$mission->getId());
        self::assertSelectorNotExists('.banner.bad');
    }

    public function testSeuleLEquipeOuLaProgrammationClotureUneMission(): void
    {
        $awa = $this->personnel('Dupont', 'Awa');
        $mission = $this->mission('Tournage', '-3 days', '-2 days', [$awa]);

        $this->login($this->user(User::ROLE_AGENT, 'etranger@procom.pro'));
        $crawler = $this->browser->request('GET', '/missions/'.$mission->getId());
        self::assertSelectorNotExists('form[action$="/compte-rendu"]');
        $this->browser->request('POST', '/missions/'.$mission->getId().'/compte-rendu', ['_token' => 'x', 'resultat' => 'atteint']);
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user(User::ROLE_DIRECTION));
        $crawler = $this->browser->request('GET', '/missions/'.$mission->getId());
        $this->browser->submit($crawler->selectButton('Clôturer la mission')->form(['resultat' => 'partiel']));
        self::assertSame(Mission::REALISEE, $this->fresh(Mission::class, $mission->getId())->getStatut());
    }

    public function testUneMissionFutureOuAnnuleeNeSeClotureParPas(): void
    {
        $future = $this->mission('À venir', '+5 days', '+6 days', []);
        $cancelled = $this->mission('Annulée', '-3 days', '-2 days', [], null, Mission::ANNULEE);
        $this->login($this->user(User::ROLE_DIRECTION));

        foreach ([$future, $cancelled] as $mission) {
            $crawler = $this->browser->request('GET', '/missions/'.$mission->getId());
            self::assertSelectorNotExists('form[action$="/compte-rendu"]');
            $token = $crawler->filter('form[action$="/statut"] input[name=_token]')->attr('value');
            $this->browser->request('POST', '/missions/'.$mission->getId().'/compte-rendu', ['_token' => $token, 'resultat' => 'atteint']);
            self::assertNotSame(Mission::REALISEE, $this->fresh(Mission::class, $mission->getId())->getStatut());
        }
    }

    public function testLeResponsableDeProgrammationChangeLeStatutMaisPasVersRealisee(): void
    {
        $mission = $this->mission('Tournage', '+5 days', '+6 days', []);
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/missions/'.$mission->getId());
        $this->browser->submit($crawler->selectButton('Mettre à jour')->form(['statut' => Mission::REPORTEE]));
        self::assertSame(Mission::REPORTEE, $this->fresh(Mission::class, $mission->getId())->getStatut());

        $crawler = $this->browser->request('GET', '/missions/'.$mission->getId());
        $token = $crawler->filter('form[action$="/statut"] input[name=_token]')->attr('value');
        $this->browser->request('POST', '/missions/'.$mission->getId().'/statut', ['_token' => $token, 'statut' => Mission::REALISEE]);
        self::assertResponseStatusCodeSame(404, '« réalisée » ne s\'obtient que par le compte rendu');
    }

    public function testLeBilanDePeriodeRassembleMissionsEquipesEtTaches(): void
    {
        $awa = $this->personnel('Dupont', 'Awa');
        $paul = $this->personnel('Martin', 'Paul');
        $projet = $this->projet('Campagne');
        $done = $this->mission('Tournage', '2026-11-03', '2026-11-04', [$awa, $paul], $awa, Mission::PLANIFIEE, $projet);
        $done->report('atteint', 'RAS');
        $partial = $this->mission('Reportage', '2026-11-10', '2026-11-10', [$awa], $awa, Mission::PLANIFIEE, $projet);
        $partial->report('partiel', 'Météo.');
        $this->mission('Oubliée', '2026-11-12', '2026-11-12', [$paul]); // passée, jamais clôturée
        $this->mission('Annulée', '2026-11-15', '2026-11-15', [$paul], null, Mission::ANNULEE);
        $this->mission('Reportée', '2026-11-16', '2026-11-16', [], null, Mission::REPORTEE);
        $this->mission('Autre mois', '2026-12-01', '2026-12-01', [$awa]);
        $this->em->flush();

        $report = static::getContainer()->get(MissionReview::class)->build(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable('2026-11-30'));

        self::assertSame(5, $report['total']);
        self::assertSame(2, $report['done']);
        self::assertSame(1, $report['cancelled']);
        self::assertSame(1, $report['postponed']);
        self::assertSame(1, $report['toClose']);
        self::assertSame(2 * 2 + 1 * 1, $report['fieldDays'], 'tournage : 2 jours × 2 personnes ; reportage : 1 jour × 1 personne');
        self::assertSame(67, $report['rate'], '2 réalisées sur 3 missions échues (2 réalisées + 1 à clôturer)');
        self::assertSame(1, $report['byResult']['atteint']);
        self::assertSame(1, $report['byResult']['partiel']);

        $byName = [];
        foreach ($report['byPerson'] as $row) {
            $byName[$row['person']->getLastName()] = $row;
        }
        self::assertSame(2, $byName['Dupont']['missions']);
        self::assertSame(3, $byName['Dupont']['days'], '2 + 1 jours réalisés');
        self::assertSame(2, $byName['Dupont']['lead']);
        self::assertSame(3, $byName['Martin']['missions']);
        self::assertSame(1, $byName['Martin']['done']);

        $projects = array_column($report['byProject'], null, 'label');
        self::assertSame(2, $projects['Campagne']['missions']);
        self::assertSame(3, $projects['Sans projet']['missions']);
    }

    public function testLePageDuBilanSAffichePourLaProgrammationSeulement(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/missions/bilan');
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user(User::ROLE_DIRECTION));
        $this->mission('Tournage', '-3 days', '-3 days', []);
        $crawler = $this->browser->request('GET', '/missions/bilan');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.review-period', 'Point du');
        self::assertCount(4, $crawler->filter('.presets a'));

        $crawler = $this->browser->request('GET', '/missions/bilan?from=2026-11-01&to=2026-11-30');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.review-period', '01/11/2026');
        self::assertSelectorTextContains('.review-period', '30/11/2026');

        $this->browser->request('GET', '/missions/bilan?from=2026-11-30&to=2026-11-01');
        self::assertResponseIsSuccessful('des dates inversées sont remises dans l\'ordre');
        self::assertSelectorTextContains('.review-period', '01/11/2026');

        foreach (['semaine', 'courant', 'mois', 'trimestre', 'nimporte'] as $periode) {
            $this->browser->request('GET', '/missions/bilan?periode='.$periode);
            self::assertResponseIsSuccessful($periode);
        }
    }

    public function testLaListeSeFiltre(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $awa = $this->personnel('Dupont', 'Awa');
        $this->mission('Tournage', '2026-12-07', '2026-12-08', [$awa]);
        $this->mission('Annulée', '2026-12-09', '2026-12-09', [], null, Mission::ANNULEE);
        $this->mission('Passée ouverte', '-10 days', '-9 days', []);

        $crawler = $this->browser->request('GET', '/missions/liste');
        self::assertCount(3, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/missions/liste?statut=annulee');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/missions/liste?statut=aclore');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'Passée ouverte');
        $crawler = $this->browser->request('GET', '/missions/liste?personnel='.$awa->getId());
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/missions/liste?from=2026-12-08&to=2026-12-08');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'), 'une mission qui chevauche la période est retenue');
        $crawler = $this->browser->request('GET', '/missions/liste?q=tournage');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $this->browser->request('GET', '/missions/liste?statut=zzz&projet=abc&client=abc&personnel=abc&sort=zzz&page=abc');
        self::assertResponseIsSuccessful('des paramètres invalides retombent sur les valeurs par défaut');
    }

    public function testLesPermissionsGouvernentChaqueAction(): void
    {
        $mission = $this->mission('Tournage', '2026-12-07', '2026-12-07', []);

        $this->login($this->user($this->customRole('Observateur')->getCode(), 'obs@procom.pro'));
        foreach (['/missions', '/missions/liste', '/missions/'.$mission->getId(), '/missions/nouvelle', '/missions/bilan'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        // Lecture seule : on voit le calendrier, on ne programme ni ne supprime.
        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/missions?mois=2026-12');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/missions/nouvelle"]');
        self::assertSelectorNotExists('.tabs a[href$="/missions/bilan"]', 'l\'onglet du bilan est réservé à la programmation');
        foreach (['/missions/nouvelle', '/missions/'.$mission->getId().'/modifier'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->browser->request('POST', '/missions/'.$mission->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403);

        // La direction programme (rôle de responsable de programmation) et voit le bilan.
        $this->login($this->user(User::ROLE_DIRECTION));
        $this->browser->request('GET', '/missions?mois=2026-12');
        self::assertSelectorExists('a[href*="/missions/nouvelle"]');
        self::assertSelectorExists('.tabs a[href$="/missions/bilan"]');
    }

    public function testLaSuppressionEstLogique(): void
    {
        $mission = $this->mission('Tournage', '2026-12-07', '2026-12-07', []);
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/missions/'.$mission->getId());
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects();

        self::assertTrue($this->fresh(Mission::class, $mission->getId())->isDeleted());
        $this->browser->request('GET', '/missions/'.$mission->getId());
        self::assertResponseStatusCodeSame(404);
    }

    // ---------- fabriques ----------

    private function projet(string $nom): Projet
    {
        $projet = (new Projet())->setNom($nom);
        $this->em->persist($projet);
        $this->em->flush();

        return $projet;
    }

    /** @param list<Personnel> $team */
    private function mission(string $titre, string $from, string $to, array $team, ?Personnel $lead = null, string $statut = Mission::PLANIFIEE, ?Projet $projet = null): Mission
    {
        $mission = (new Mission())->setTitre($titre)->setLieu('Cotonou')->setDateDebut(new \DateTimeImmutable($from))->setDateFin(new \DateTimeImmutable($to))
            ->setStatut($statut)->setResponsable($lead)->setProjet($projet);
        foreach ($team as $person) {
            $mission->addEquipe($person);
        }
        $this->em->persist($mission);
        $this->em->flush();

        return $mission;
    }
}
