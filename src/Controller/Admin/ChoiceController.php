<?php

namespace App\Controller\Admin;

use App\Entity\Choice;
use App\Service\ActivityLogger;
use App\Service\Choices;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Gestion des listes de choix : ajouter, renommer, réordonner et supprimer leurs valeurs. Réservé à l'administrateur. */
#[Route('/admin/listes')]
#[IsGranted('ROLE_ADMIN')]
class ChoiceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Choices $choices,
        private readonly ActivityLogger $activity,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_admin_choices', methods: 'GET')]
    public function index(): Response
    {
        $groups = [];
        foreach (Choices::definitions() as $key => $definition) {
            $groups[$definition['group']][] = ['key' => $key, 'title' => $definition['title'], 'help' => $definition['help'], 'count' => \count($this->choices->labels($key))];
        }

        return $this->render('admin/choices/index.html.twig', ['groups' => $groups]);
    }

    #[Route('/{list}', name: 'app_admin_choice_list', methods: 'GET', requirements: ['list' => '[a-z_]+'])]
    public function show(string $list): Response
    {
        $definition = $this->definition($list);
        $usage = $this->choices->usage($list);

        return $this->render('admin/choices/list.html.twig', [
            'list' => $list,
            'definition' => $definition,
            'rows' => $this->choices->manage($list),
            'usage' => array_map($this->choices->describe(...), $usage),
            'locked' => $definition['locked'],
        ]);
    }

    #[Route('/{list}/ajouter', name: 'app_admin_choice_add', methods: 'POST', requirements: ['list' => '[a-z_]+'])]
    public function add(Request $request, string $list): Response
    {
        $definition = $this->definition($list);
        $this->checkCsrf($request, $list);

        $label = (string) $request->request->get('label');
        if ($error = $this->labelError($label)) {
            $this->addFlash('error', $error);
        } else {
            try {
                $choice = $this->choices->add($list, $label);
                $this->activity->log('choice.create', ['liste' => $definition['title'], 'code' => $choice->getCode(), 'libelle' => $choice->getLabel()]);
                $this->addFlash('success', sprintf('« %s » ajouté à la liste.', $choice->getLabel()));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_choice_list', ['list' => $list]);
    }

    #[Route('/{list}/{id}/renommer', name: 'app_admin_choice_rename', methods: 'POST', requirements: ['list' => '[a-z_]+', 'id' => '\d+'])]
    public function rename(Request $request, string $list, int $id): Response
    {
        $definition = $this->definition($list);
        $choice = $this->find($list, $id);
        $this->checkCsrf($request, $list);

        $label = (string) $request->request->get('label');
        $before = $choice->getLabel();
        if ($error = $this->labelError($label)) {
            $this->addFlash('error', $error);
        } else {
            try {
                $this->choices->rename($choice, $label);
                if ($before !== $choice->getLabel()) {
                    $this->activity->log('choice.update', ['liste' => $definition['title'], 'code' => $choice->getCode(), 'avant' => $before, 'apres' => $choice->getLabel()]);
                    $this->addFlash('success', sprintf('« %s » renommé en « %s ».', $before, $choice->getLabel()));
                }
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_choice_list', ['list' => $list]);
    }

    #[Route('/{list}/{id}/deplacer', name: 'app_admin_choice_move', methods: 'POST', requirements: ['list' => '[a-z_]+', 'id' => '\d+'])]
    public function move(Request $request, string $list, int $id): Response
    {
        $this->definition($list);
        $choice = $this->find($list, $id);
        $this->checkCsrf($request, $list);

        $this->choices->move($choice, 'up' === $request->request->get('sens') ? -1 : 1);

        return $this->redirectToRoute('app_admin_choice_list', ['list' => $list]);
    }

    #[Route('/{list}/{id}/supprimer', name: 'app_admin_choice_delete', methods: 'POST', requirements: ['list' => '[a-z_]+', 'id' => '\d+'])]
    public function delete(Request $request, string $list, int $id): Response
    {
        $definition = $this->definition($list);
        $choice = $this->find($list, $id);
        $this->checkCsrf($request, $list);

        $details = ['liste' => $definition['title'], 'code' => $choice->getCode(), 'libelle' => $choice->getLabel()];
        try {
            $this->choices->delete($choice);
            $this->activity->log('choice.delete', $details);
            $this->addFlash('success', sprintf('« %s » supprimé de la liste.', $details['libelle']));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_choice_list', ['list' => $list]);
    }

    /** @return array{title: string, group: string, help: string, defaults: array<string, string>, locked: list<string>, uses: array<string, array{class-string, string}>} */
    private function definition(string $list): array
    {
        return Choices::definitions()[$list] ?? throw $this->createNotFoundException('Liste inconnue.');
    }

    private function find(string $list, int $id): Choice
    {
        $choice = $this->em->getRepository(Choice::class)->find($id);
        if (!$choice || $choice->getListKey() !== $list) {
            throw $this->createNotFoundException('Valeur introuvable.');
        }

        return $choice;
    }

    private function labelError(string $label): ?string
    {
        $violations = $this->validator->validate((new Choice())->setLabel($label), null, null);
        foreach ($violations as $violation) {
            if ('label' === $violation->getPropertyPath()) {
                return $violation->getMessage();
            }
        }

        return null;
    }

    private function checkCsrf(Request $request, string $list): void
    {
        if (!$this->isCsrfTokenValid('choice_'.$list, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
