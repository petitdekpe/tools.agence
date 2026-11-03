<?php

namespace App\Controller\Admin;

use App\Form\FiscalSettingType;
use App\Service\ActivityLogger;
use App\Service\FiscalSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Taux de TVA et d'AIB utilisés par le suivi des encaissements. Réservé à l'admin. */
#[Route('/admin/fiscal')]
#[IsGranted('ROLE_ADMIN')]
class FiscalSettingsController extends AbstractController
{
    #[Route('', name: 'app_admin_fiscal')]
    public function index(Request $request, FiscalSettings $fiscal, EntityManagerInterface $em, ActivityLogger $activity): Response
    {
        $setting = $fiscal->get();
        $before = $this->rates($setting);
        $form = $this->createForm(FiscalSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $setting->touch();
            $em->flush();
            $em->refresh($setting);
            $after = $this->rates($setting);
            if ($before !== $after) {
                $activity->log('fiscal.update', ['avant' => $before, 'apres' => $after]);
            }
            $this->addFlash('success', 'Paramètres fiscaux enregistrés. Seuls les nouveaux encaissements utiliseront ces taux.');

            return $this->redirectToRoute('app_admin_fiscal');
        }

        return $this->render('admin/fiscal.html.twig', ['form' => $form]);
    }

    private function rates(\App\Entity\FiscalSetting $setting): array
    {
        return [
            'tva' => sprintf('%.2f', $setting->getTauxTva()),
            'aib_avec_ifu' => sprintf('%.2f', $setting->getTauxAibAvecIfu()),
            'aib_sans_ifu' => sprintf('%.2f', $setting->getTauxAibSansIfu()),
        ];
    }
}
