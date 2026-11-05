<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Affectation;
use App\Entity\Choice;
use App\Entity\Depense;
use App\Entity\Encaissement;
use App\Entity\Materiel;
use App\Entity\Mission;
use App\Entity\Personnel;
use App\Entity\Publication;
use App\Entity\Tache;
use App\Entity\Ticket;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Les listes de choix de l'application (catégories de matériel, canaux de publication, priorités...) et leur gestion.
 *
 * Chaque liste a des valeurs d'origine (les constantes des entités). Tant qu'une liste n'a jamais été modifiée, ces
 * valeurs s'appliquent telles quelles ; à la première modification elles sont copiées en base (table app_choice),
 * puis l'administrateur ajoute, renomme, réordonne et supprime comme il veut.
 *
 * Les données saisies stockent le CODE d'une valeur : renommer ne change rien aux fiches existantes, et une valeur
 * encore utilisée ne se supprime pas. Les valeurs « verrouillées » (celles sur lesquelles le code de l'application
 * s'appuie : état « en panne », contrat CDD...) se renomment mais ne se suppriment pas.
 *
 * Les getters des entités (getCategoryLabel...) ne peuvent pas recevoir de service : ils passent par labelOf(),
 * qui s'adresse à l'instance courante, ou à défaut aux valeurs d'origine.
 */
class Choices
{
    private static ?self $current = null;

    /** @var array<string, array<string, string>>|null libellés par liste puis par code, dans l'ordre d'affichage */
    private ?array $cache = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        self::$current = $this;
    }

    /**
     * title/group/help : présentation ; defaults : valeurs d'origine (code => libellé) ; locked : codes dont dépend
     * l'application ; uses : où le code est stocké (libellé => [entité, propriété]), pour interdire de supprimer une valeur en service.
     *
     * @return array<string, array{title: string, group: string, help: string, defaults: array<string, string>, locked: list<string>, uses: array<string, array{class-string, string}>}>
     */
    public static function definitions(): array
    {
        return [
            'materiel_category' => [
                'title' => 'Catégories de matériel',
                'group' => 'Matériel',
                'help' => 'Classent l\'inventaire. Les demandes de matériel utilisent les mêmes catégories.',
                'defaults' => Materiel::CATEGORIES,
                'locked' => ['informatique'],
                'uses' => ['matériel' => [Materiel::class, 'category'], 'demandes' => [Ticket::class, 'categorie']],
            ],
            'materiel_article' => [
                'title' => 'Articles de référence du matériel',
                'group' => 'Matériel',
                'help' => 'Liste des articles courants. À l\'ajout d\'un matériel, choisir un article reprend son nom comme désignation.',
                'defaults' => Materiel::ARTICLES,
                'locked' => [],
                'uses' => ['matériel' => [Materiel::class, 'article']],
            ],
            'materiel_etat' => [
                'title' => 'États du matériel',
                'group' => 'Matériel',
                'help' => 'Les états « Bon état », « En panne » et « Réformé » pilotent les pannes et les remises : ils se renomment mais ne se suppriment pas.',
                'defaults' => Materiel::ETATS,
                'locked' => ['bon', 'panne', Materiel::ETAT_REFORME],
                'uses' => ['matériel' => [Materiel::class, 'etat'], 'restitutions' => [Affectation::class, 'returnEtat']],
            ],
            'ticket_priorite' => [
                'title' => 'Priorités des pannes et demandes',
                'group' => 'Pannes et demandes',
                'help' => 'Classez-les de la moins urgente (en haut) à la plus urgente (en bas) : cet ordre sert au tri des tickets. « Normale » et « Urgente » sont utilisées par l\'application.',
                'defaults' => Ticket::PRIORITES,
                'locked' => ['normale', 'urgente'],
                'uses' => ['tickets' => [Ticket::class, 'priorite']],
            ],
            'depense_categorie' => [
                'title' => 'Catégories de dépenses',
                'group' => 'Dépenses',
                'help' => 'Classent les dépenses et alimentent la synthèse.',
                'defaults' => Depense::CATEGORIES,
                'locked' => ['autre'],
                'uses' => ['dépenses' => [Depense::class, 'categorie']],
            ],
            'depense_mode' => [
                'title' => 'Modes de règlement des dépenses',
                'group' => 'Dépenses',
                'help' => 'Proposés quand une dépense est marquée payée.',
                'defaults' => Depense::MODES,
                'locked' => [],
                'uses' => ['dépenses' => [Depense::class, 'mode']],
            ],
            'encaissement_nature' => [
                'title' => 'Natures d\'encaissement',
                'group' => 'Encaissements',
                'help' => '« Paiement de facture » et « Avance / acompte » sont utilisées par le rapprochement avec les factures : elles se renomment mais ne se suppriment pas.',
                'defaults' => Encaissement::NATURES,
                'locked' => [Encaissement::NATURE_FACTURE, Encaissement::NATURE_AVANCE],
                'uses' => ['encaissements' => [Encaissement::class, 'nature']],
            ],
            'absence_type' => [
                'title' => 'Types d\'absence',
                'group' => 'Congés et absences',
                'help' => 'Proposés pour toute demande de congé ou d\'absence. Un type ajouté s\'affiche en vert dans le calendrier d\'équipe.',
                'defaults' => Absence::TYPES,
                'locked' => ['conge'],
                'uses' => ['absences' => [Absence::class, 'type']],
            ],
            'publication_canal' => [
                'title' => 'Canaux de publication',
                'group' => 'Calendrier éditorial',
                'help' => 'Réseaux et supports sur lesquels l\'équipe publie.',
                'defaults' => Publication::CANAUX,
                'locked' => ['facebook'],
                'uses' => ['publications' => [Publication::class, 'canal']],
            ],
            'publication_format' => [
                'title' => 'Formats de publication',
                'group' => 'Calendrier éditorial',
                'help' => 'Post, vidéo, story...',
                'defaults' => Publication::FORMATS,
                'locked' => ['post'],
                'uses' => ['publications' => [Publication::class, 'format']],
            ],
            'personnel_contract' => [
                'title' => 'Types de contrat',
                'group' => 'Personnel',
                'help' => '« CDI » et « CDD » sont utilisés pour les alertes de fin de contrat : ils se renomment mais ne se suppriment pas.',
                'defaults' => Personnel::CONTRACTS,
                'locked' => [Personnel::CONTRACT_CDI, Personnel::CONTRACT_CDD],
                'uses' => ['fiches du personnel' => [Personnel::class, 'contractType']],
            ],
            'tache_priorite' => [
                'title' => 'Priorités des tâches',
                'group' => 'Projets et missions',
                'help' => 'Affichées sur les cartes des projets. « Normale » est la valeur par défaut d\'une carte.',
                'defaults' => Tache::PRIORITES,
                'locked' => ['normale'],
                'uses' => ['tâches' => [Tache::class, 'priorite']],
            ],
            'mission_resultat' => [
                'title' => 'Résultats d\'une mission',
                'group' => 'Projets et missions',
                'help' => 'Choisis au compte rendu. Les trois résultats d\'origine alimentent le bilan de période : ils se renomment mais ne se suppriment pas.',
                'defaults' => Mission::RESULTATS,
                'locked' => ['atteint', 'partiel', 'non_atteint'],
                'uses' => ['missions' => [Mission::class, 'resultat']],
            ],
        ];
    }

    /** @return array{title: string, group: string, help: string, defaults: array<string, string>, locked: list<string>, uses: array<string, array{class-string, string}>} */
    public static function definition(string $list): array
    {
        return self::definitions()[$list] ?? throw new \InvalidArgumentException(sprintf('Liste de choix inconnue : %s.', $list));
    }

    // --- Lecture ----------------------------------------------------------------------------------------------

    /** @return array<string, string> code => libellé, dans l'ordre d'affichage */
    public function labels(string $list): array
    {
        return $this->loaded()[$list] ?? self::definition($list)['defaults'];
    }

    public function label(string $list, ?string $code): string
    {
        return null === $code ? '' : ($this->labels($list)[$code] ?? $code);
    }

    public function exists(string $list, string $code): bool
    {
        return isset($this->labels($list)[$code]);
    }

    /** Pour les getters des entités : le libellé d'une valeur, ou son code si elle n'existe plus. */
    public static function labelOf(string $list, ?string $code): ?string
    {
        if (null === $code) {
            return null;
        }

        return self::$current ? self::$current->label($list, $code) : (self::definition($list)['defaults'][$code] ?? $code);
    }

    /** Pour les entités et les filtres : la valeur existe-t-elle dans la liste ? */
    public static function has(string $list, string $code): bool
    {
        return self::$current ? self::$current->exists($list, $code) : isset(self::definition($list)['defaults'][$code]);
    }

    public static function isLocked(string $list, string $code): bool
    {
        return \in_array($code, self::definition($list)['locked'], true);
    }

    // --- Gestion ----------------------------------------------------------------------------------------------

    /** Les valeurs de la liste, prêtes à être modifiées : copie les valeurs d'origine en base si la liste n'a jamais été touchée. @return list<Choice> */
    public function manage(string $list): array
    {
        self::definition($list);
        if (0 === $this->em->getRepository(Choice::class)->count(['listKey' => $list])) {
            $position = 0;
            foreach (self::definition($list)['defaults'] as $code => $label) {
                $this->em->persist((new Choice())->setListKey($list)->setCode((string) $code)->setLabel($label)->setPosition(++$position));
            }
            $this->em->flush();
            $this->cache = null;
        }

        return $this->em->getRepository(Choice::class)->findBy(['listKey' => $list], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /** @throws \DomainException libellé déjà pris */
    public function add(string $list, string $label): Choice
    {
        $rows = $this->manage($list);
        $choice = (new Choice())->setListKey($list)->setLabel($label);
        $this->assertLabelFree($choice->getLabel(), $rows);

        $choice->setCode($this->uniqueCode($rows, $choice->getLabel()))->setPosition($rows ? max(array_map(static fn (Choice $c): int => $c->getPosition(), $rows)) + 1 : 1);
        $this->em->persist($choice);
        $this->em->flush();
        $this->cache = null;

        return $choice;
    }

    /** @throws \DomainException libellé déjà pris */
    public function rename(Choice $choice, string $label): void
    {
        $label = (new Choice())->setLabel($label)->getLabel();
        $this->assertLabelFree($label, $this->manage($choice->getListKey()), $choice);
        $choice->setLabel($label);
        $this->em->flush();
        $this->cache = null;
    }

    /** @param -1|1 $direction -1 : monter, 1 : descendre */
    public function move(Choice $choice, int $direction): void
    {
        $rows = $this->manage($choice->getListKey());
        $index = array_search($choice, $rows, true);
        $target = false === $index ? null : $index + ($direction < 0 ? -1 : 1);
        if (null !== $target && isset($rows[$target])) {
            [$rows[$index], $rows[$target]] = [$rows[$target], $rows[$index]];
        }
        $this->renumber($rows);
        $this->em->flush();
        $this->cache = null;
    }

    /** @throws \DomainException valeur verrouillée, encore utilisée, ou dernière de sa liste */
    public function delete(Choice $choice): void
    {
        $list = $choice->getListKey();
        if (self::isLocked($list, $choice->getCode())) {
            throw new \DomainException(sprintf('« %s » est utilisé par l\'application et ne peut pas être supprimé. Vous pouvez le renommer.', $choice->getLabel()));
        }
        if ($uses = $this->usage($list)[$choice->getCode()] ?? null) {
            throw new \DomainException(sprintf('« %s » est encore utilisé (%s). Changez d\'abord la valeur de ces éléments.', $choice->getLabel(), $this->describe($uses)));
        }
        $rows = $this->manage($list);
        if (\count($rows) <= 1) {
            throw new \DomainException('Une liste doit garder au moins une valeur.');
        }

        $this->em->remove($choice);
        $this->em->flush();
        $this->renumber(array_values(array_filter($rows, static fn (Choice $c): bool => $c !== $choice)));
        $this->em->flush();
        $this->cache = null;
    }

    /** @return array<string, array<string, int>> code => [où => nombre de fiches qui l'utilisent] (seulement les valeurs utilisées) */
    public function usage(string $list): array
    {
        $usage = [];
        foreach (self::definition($list)['uses'] as $where => [$class, $field]) {
            $rows = $this->em->createQuery(sprintf('SELECT e.%1$s AS code, COUNT(e.id) AS n FROM %2$s e WHERE e.%1$s IS NOT NULL GROUP BY e.%1$s', $field, $class))->getArrayResult();
            foreach ($rows as $row) {
                $usage[$row['code']][$where] = ($usage[$row['code']][$where] ?? 0) + (int) $row['n'];
            }
        }

        return $usage;
    }

    /** @param array<string, int> $uses */
    public function describe(array $uses): string
    {
        return implode(', ', array_map(static fn (string $where, int $n): string => $n.' '.$where, array_keys($uses), $uses));
    }

    // --- Outils -----------------------------------------------------------------------------------------------

    /** @return array<string, array<string, string>> */
    private function loaded(): array
    {
        if (null === $this->cache) {
            $this->cache = [];
            try {
                foreach ($this->em->getRepository(Choice::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']) as $choice) {
                    $this->cache[$choice->getListKey()][$choice->getCode()] = $choice->getLabel();
                }
            } catch (TableNotFoundException) {
                // Migration pas encore passée : les valeurs d'origine s'appliquent.
            }
        }

        return $this->cache;
    }

    /** @param list<Choice> $rows */
    private function assertLabelFree(string $label, array $rows, ?Choice $except = null): void
    {
        foreach ($rows as $other) {
            if ($other !== $except && mb_strtolower($other->getLabel()) === mb_strtolower($label)) {
                throw new \DomainException(sprintf('La liste contient déjà « %s ».', $other->getLabel()));
            }
        }
    }

    /** @param list<Choice> $rows */
    private function uniqueCode(array $rows, string $label): string
    {
        $slug = strtolower(str_replace('-', '_', (new AsciiSlugger('fr'))->slug(mb_substr($label, 0, 30))->toString()));
        $base = '' === $slug ? 'valeur' : $slug;
        $taken = array_map(static fn (Choice $c): string => $c->getCode(), $rows);

        $code = $base;
        for ($i = 2; \in_array($code, $taken, true); ++$i) {
            $code = $base.'_'.$i;
        }

        return $code;
    }

    /** @param list<Choice> $rows */
    private function renumber(array $rows): void
    {
        foreach (array_values($rows) as $i => $choice) {
            $choice->setPosition($i + 1);
        }
    }
}
