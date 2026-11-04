<?php

namespace App\Controller;

use App\Entity\Correction;
use App\Entity\CorrectionMemory;
use App\Entity\User;
use App\Repository\CorrectionMemoryRepository;
use App\Repository\CorrectionRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\CorrectionContext;
use App\Service\CorrectionMemoryManager;
use App\Service\DossierResolver;
use App\Service\MediaCorrector;
use App\Service\MediaReview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le fil de relectures de l'utilisateur : une conversation unique, classée par dossiers, avec sa mémoire.
 * L'API répond en JSON ; les erreurs prennent la forme {"error": "..."} (ApiExceptionListener).
 */
#[Route('/correcteur-media')]
#[IsGranted(Permission::CORRECTEUR_USE)]
class CorrecteurController extends AbstractController
{
    private const MAX_LENGTH = 20000;
    private const PAGE = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CorrectionRepository $corrections,
        private readonly CorrectionMemoryRepository $memories,
        private readonly MediaCorrector $corrector,
        private readonly CorrectionContext $context,
        private readonly CorrectionMemoryManager $memoryManager,
        private readonly DossierResolver $resolver,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[Route('', name: 'app_correcteur', methods: 'GET')]
    public function index(): Response
    {
        $this->activity->log('tool.open', ['tool' => 'Correcteur média']);

        return $this->render('correcteur/index.html.twig');
    }

    /** Une page du fil, de la plus ancienne à la plus récente. `before` : identifiant de la plus ancienne relecture déjà affichée. */
    #[Route('/api/corrections', name: 'app_correcteur_list', methods: 'GET')]
    public function list(Request $request): JsonResponse
    {
        $dossier = $request->query->has('dossier') ? Correction::normalizeDossier((string) $request->query->get('dossier')) : null;
        $limit = min(50, max(1, $request->query->getInt('limit', self::PAGE)));
        [$items, $more] = $this->corrections->thread($this->user(), $request->query->getInt('before') ?: null, $limit, $dossier, $request->query->has('none'));
        $memo = $this->memoFor($items);

        return $this->json(['items' => array_map(fn (Correction $c) => $this->detail($c, $memo[$c->getId()] ?? []), $items), 'hasMore' => $more]);
    }

    #[Route('/api/corrections/{id}', name: 'app_correcteur_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $correction = $this->find($id);
        $this->activity->log('correction.view', ['id' => $correction->getId(), 'title' => $correction->getTitle()]);

        return $this->json($this->detail($correction, $this->memoFor([$correction])[$id] ?? []));
    }

    /** Relit un texte. `revisionOf` : identifiant de la relecture dont c'est une version révisée (le dossier est repris sauf indication contraire). */
    #[Route('/api/corrections', name: 'app_correcteur_create', methods: 'POST')]
    public function create(Request $request): JsonResponse
    {
        $this->checkCsrf($request);
        $data = $request->toArray();
        $text = $this->readText($data['text'] ?? '');
        $previous = isset($data['revisionOf']) ? $this->find((int) $data['revisionOf']) : null;
        $dossier = Correction::normalizeDossier(\array_key_exists('dossier', $data) ? (string) $data['dossier'] : $previous?->getDossier());
        $user = $this->user();

        $review = $this->review($text, $this->context->build($user, $dossier, $text, $previous));

        $correction = (new Correction())
            ->setUser($user)
            ->setTitle($this->titleFrom($text))
            ->setDossier($dossier)
            ->setPrevious($previous)
            ->setInputText($text)
            ->setResultText($review->text)
            ->recordUsage($review);

        $this->em->persist($correction);
        $this->em->flush(); // enregistre aussi l'usage des entrées de mémoire injectées
        $this->activity->log('correction.create', ['id' => $correction->getId(), 'title' => $correction->getTitle(), 'dossier' => $dossier, 'text' => $text, 'result' => $correction->getResultText()]);

        return $this->json($this->detail($correction, []), 201);
    }

    /** Classe une relecture dans un autre dossier (ou dans aucun). */
    #[Route('/api/corrections/{id}', name: 'app_correcteur_update', methods: 'PUT', requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkCsrf($request);
        $correction = $this->find($id);
        $data = $request->toArray();

        if (\array_key_exists('dossier', $data)) {
            $dossier = Correction::normalizeDossier((string) $data['dossier']);
            $before = $correction->getDossier();
            $correction->setDossier($dossier)->touch();
            $this->em->flush();
            $this->activity->log('correction.update', ['id' => $correction->getId(), 'dossier' => ['de' => $before, 'vers' => $dossier]]);
        }

        return $this->json($this->detail($correction, $this->memoFor([$correction])[$id] ?? []));
    }

    #[Route('/api/corrections/{id}', name: 'app_correcteur_delete', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $this->checkCsrf($request);
        $correction = $this->find($id);
        $this->activity->log('correction.delete', ['id' => $correction->getId(), 'title' => $correction->getTitle(), 'text' => $correction->getInputText()]);
        $this->corrections->remove($correction);

        return $this->json(null, 204);
    }

    #[Route('/api/dossiers', name: 'app_correcteur_dossiers', methods: 'GET')]
    public function dossiers(): JsonResponse
    {
        return $this->json($this->corrections->dossiers($this->user()));
    }

    /** Le dossier le plus probable pour un texte, ou null. */
    #[Route('/api/dossiers/suggest', name: 'app_correcteur_suggest', methods: 'POST')]
    public function suggest(Request $request): JsonResponse
    {
        $this->checkCsrf($request);

        return $this->json(['dossier' => $this->resolver->suggest($this->user(), mb_substr((string) ($request->toArray()['text'] ?? ''), 0, self::MAX_LENGTH))]);
    }

    /** La mémoire visible pour un dossier : les règles de l'équipe, puis les entrées de l'utilisateur. */
    #[Route('/api/memory', name: 'app_correcteur_memory', methods: 'GET')]
    public function memory(Request $request): JsonResponse
    {
        $dossier = Correction::normalizeDossier((string) $request->query->get('dossier'));

        return $this->json(array_map($this->memoryPayload(...), $this->memories->visible($this->user(), $dossier)));
    }

    /** « Retenir » (la correction devient un choix validé) ou « Ignorer » (ne plus signaler) sur une remarque d'une relecture. */
    #[Route('/api/corrections/{id}/remarks/{n}/memory', name: 'app_correcteur_remember', methods: 'POST', requirements: ['id' => '\d+', 'n' => '\d+'])]
    public function remember(Request $request, int $id, int $n): JsonResponse
    {
        $this->checkCsrf($request);
        $correction = $this->find($id);
        $action = (string) ($request->toArray()['action'] ?? '');
        $entry = $this->memoryManager->remember($this->user(), $correction, $n, $action);
        $this->activity->log('correction.memory', ['id' => $correction->getId(), 'remarque' => $n, 'action' => $action, 'contenu' => $entry->getContent()]);

        return $this->json($this->memoryPayload($entry), 201);
    }

    #[Route('/api/memory/{id}', name: 'app_correcteur_forget', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function forget(Request $request, CorrectionMemory $entry): JsonResponse
    {
        $this->checkCsrf($request);
        if (!$this->canManage($entry)) {
            throw new AccessDeniedHttpException('Cette entrée ne vous appartient pas.');
        }
        $this->activity->log('correction.memory', ['action' => 'forget', 'contenu' => $entry->getContent(), 'equipe' => $entry->isShared()]);
        $this->memoryManager->forget($entry);

        return $this->json(null, 204);
    }

    /** Promeut une entrée en règle de l'équipe (administrateurs). */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/api/memory/{id}/share', name: 'app_correcteur_share', methods: 'POST', requirements: ['id' => '\d+'])]
    public function share(Request $request, CorrectionMemory $entry): JsonResponse
    {
        $this->checkCsrf($request);
        $this->memoryManager->share($entry);
        $this->activity->log('correction.memory', ['action' => 'share', 'contenu' => $entry->getContent()]);

        return $this->json($this->memoryPayload($entry));
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function find(int $id): Correction
    {
        $correction = $this->corrections->findOneBy(['id' => $id, 'user' => $this->getUser()]);

        return $correction ?? throw $this->createNotFoundException();
    }

    private function canManage(CorrectionMemory $entry): bool
    {
        return $this->isGranted('ROLE_ADMIN') || (!$entry->isShared() && $entry->getUser()->getId() === $this->user()->getId());
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('correcteur', (string) $request->headers->get('X-CSRF-Token'))) {
            throw new AccessDeniedHttpException('Jeton de sécurité invalide, rechargez la page.');
        }
    }

    private function readText(mixed $text): string
    {
        $text = trim((string) $text);
        if ('' === $text) {
            throw new UnprocessableEntityHttpException('Collez un texte à relire.');
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new UnprocessableEntityHttpException('Texte trop long (20 000 caractères maximum).');
        }

        return $text;
    }

    private function review(string $text, \App\Service\ReviewContext $context): MediaReview
    {
        if ('cli' !== \PHP_SAPI) { // en ligne de commande (tests), la limite s'appliquerait à tout le processus
            set_time_limit(150);
        }
        try {
            return $this->corrector->review($text, $context);
        } catch (\Throwable $e) {
            throw new HttpException(502, $e->getMessage(), $e);
        }
    }

    private function titleFrom(string $text): string
    {
        $firstLine = trim(strtok(preg_replace('/[^\p{L}\p{N}\s\'’«».,!?:;()%-]/u', '', $text) ?: $text, "\n") ?: '');

        return mb_strimwidth($firstLine ?: 'Sans titre', 0, 60, '…');
    }

    /**
     * Les remarques déjà retenues ou ignorées, par relecture puis par position de remarque.
     *
     * @param list<Correction> $corrections
     *
     * @return array<int, array<int, array{id: int, action: string}>>
     */
    private function memoFor(array $corrections): array
    {
        if (!$corrections) {
            return [];
        }
        $entries = $this->memories->findBy(['user' => $this->user(), 'shared' => false, 'source' => $corrections]);
        $memo = [];
        foreach ($entries as $entry) {
            if (null !== $entry->getSource() && null !== $entry->getSourceRemark()) {
                $memo[$entry->getSource()->getId()][$entry->getSourceRemark()] = ['id' => $entry->getId(), 'action' => CorrectionMemory::KIND_EXCLUSION === $entry->getKind() ? CorrectionMemoryManager::IGNORE : CorrectionMemoryManager::RETAIN];
            }
        }

        return $memo;
    }

    /** @param array<int, array{id: int, action: string}> $memo */
    private function detail(Correction $c, array $memo): array
    {
        return [
            'id' => $c->getId(),
            'title' => $c->getTitle(),
            'dossier' => $c->getDossier(),
            'previousId' => $c->getPrevious()?->getId(),
            'text' => $c->getInputText(),
            'result' => $c->getResultText(),
            'memo' => $memo ?: new \stdClass(),
            'createdAt' => $c->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $c->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    private function memoryPayload(CorrectionMemory $m): array
    {
        return [
            'id' => $m->getId(),
            'kind' => $m->getKind(),
            'content' => $m->getContent(),
            'dossier' => $m->getDossier(),
            'shared' => $m->isShared(),
            'canManage' => $this->canManage($m),
        ];
    }
}
