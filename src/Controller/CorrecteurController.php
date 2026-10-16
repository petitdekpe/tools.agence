<?php

namespace App\Controller;

use App\Entity\Correction;
use App\Service\ActivityLogger;
use App\Service\MediaCorrector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/correcteur-media')]
class CorrecteurController extends AbstractController
{
    private const MAX_LENGTH = 20000;

    public function __construct(private readonly EntityManagerInterface $em, private readonly MediaCorrector $corrector, private readonly ActivityLogger $activity)
    {
    }

    #[Route('', name: 'app_correcteur', methods: 'GET')]
    public function index(): Response
    {
        $this->activity->log('tool.open', ['tool' => 'Correcteur média']);

        return $this->render('correcteur/index.html.twig');
    }

    #[Route('/api/corrections', name: 'app_correcteur_list', methods: 'GET')]
    public function list(): JsonResponse
    {
        $items = $this->em->getRepository(Correction::class)->findBy(['user' => $this->getUser()], ['updatedAt' => 'DESC'], 200);

        return $this->json(array_map(fn (Correction $c) => $this->summary($c), $items));
    }

    #[Route('/api/corrections/{id}', name: 'app_correcteur_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $correction = $this->find($id);
        $this->activity->log('correction.view', ['id' => $correction->getId(), 'title' => $correction->getTitle()]);

        return $this->json($this->detail($correction));
    }

    #[Route('/api/corrections', name: 'app_correcteur_create', methods: 'POST')]
    public function create(Request $request): JsonResponse
    {
        $this->checkCsrf($request);
        $text = $this->readText($request->toArray()['text'] ?? '');

        $correction = (new Correction())
            ->setUser($this->getUser())
            ->setTitle($this->titleFrom($text))
            ->setInputText($text);
        $correction->setResultText($this->review($text));

        $this->em->persist($correction);
        $this->em->flush();
        $this->activity->log('correction.create', ['id' => $correction->getId(), 'title' => $correction->getTitle(), 'text' => $text, 'result' => $correction->getResultText()]);

        return $this->json($this->detail($correction), 201);
    }

    #[Route('/api/corrections/{id}', name: 'app_correcteur_update', methods: 'PUT', requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkCsrf($request);
        $correction = $this->find($id);
        $data = $request->toArray();
        $changes = [];

        if (isset($data['title'])) {
            $title = trim((string) $data['title']);
            if ('' === $title) {
                return $this->json(['error' => 'Le titre ne peut pas être vide.'], 422);
            }
            $changes['title'] = ['de' => $correction->getTitle(), 'vers' => $title];
            $correction->setTitle($title);
        }

        // Texte modifié : on relance la relecture. En cas d'échec, l'ancienne version est conservée.
        if (isset($data['text'])) {
            $text = $this->readText($data['text']);
            if ($text !== $correction->getInputText()) {
                $result = $this->review($text);
                $changes['text'] = ['de' => $correction->getInputText(), 'vers' => $text];
                $correction->setInputText($text)->setResultText($result);
            }
        }

        $correction->touch();
        $this->em->flush();
        if ($changes) {
            $this->activity->log('correction.update', ['id' => $correction->getId()] + $changes);
        }

        return $this->json($this->detail($correction));
    }

    #[Route('/api/corrections/{id}', name: 'app_correcteur_delete', methods: 'DELETE', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $this->checkCsrf($request);
        $correction = $this->find($id);
        $this->activity->log('correction.delete', ['id' => $correction->getId(), 'title' => $correction->getTitle(), 'text' => $correction->getInputText()]);
        $this->em->remove($correction);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function find(int $id): Correction
    {
        $correction = $this->em->getRepository(Correction::class)->findOneBy(['id' => $id, 'user' => $this->getUser()]);

        return $correction ?? throw $this->createNotFoundException();
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('correcteur', (string) $request->headers->get('X-CSRF-Token'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Jeton de sécurité invalide, rechargez la page.');
        }
    }

    private function readText(mixed $text): string
    {
        $text = trim((string) $text);
        if ('' === $text) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Collez un texte à relire.');
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('Texte trop long (20 000 caractères maximum).');
        }

        return $text;
    }

    private function review(string $text): string
    {
        set_time_limit(150);
        try {
            return $this->corrector->review($text);
        } catch (\Throwable $e) {
            throw new \Symfony\Component\HttpKernel\Exception\BadGatewayHttpException($e->getMessage(), $e);
        }
    }

    private function titleFrom(string $text): string
    {
        $firstLine = trim(strtok(preg_replace('/[^\p{L}\p{N}\s\'’«».,!?:;()%-]/u', '', $text) ?: $text, "\n") ?: '');

        return mb_strimwidth($firstLine ?: 'Sans titre', 0, 60, '…');
    }

    private function summary(Correction $c): array
    {
        return ['id' => $c->getId(), 'title' => $c->getTitle(), 'updatedAt' => $c->getUpdatedAt()->format(\DATE_ATOM)];
    }

    private function detail(Correction $c): array
    {
        return $this->summary($c) + [
            'text' => $c->getInputText(),
            'result' => $c->getResultText(),
            'createdAt' => $c->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
