<?php

namespace App\Controller\Admin;

use App\Form\SiteSettingType;
use App\Service\ActivityLogger;
use App\Service\SiteSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    #[Route('', name: 'app_admin_settings')]
    public function index(
        Request $request,
        SiteSettings $settings,
        EntityManagerInterface $em,
        ActivityLogger $activity,
        #[Autowire('%kernel.project_dir%/public/uploads')] string $uploadDir,
    ): Response {
        $setting = $settings->get();
        $form = $this->createForm(\App\Form\SiteSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $logo */
            $logo = $form->get('logo')->getData();
            if (($logo || $form->get('removeLogo')->getData()) && $setting->getLogoPath()) {
                @unlink($uploadDir.'/'.$setting->getLogoPath());
                $setting->setLogoPath(null);
            }
            if ($logo) {
                $ext = 'svg' === $logo->guessExtension() || 'image/svg+xml' === $logo->getMimeType() ? 'svg' : ($logo->guessExtension() ?: 'png');
                $name = bin2hex(random_bytes(8)).'.'.$ext;
                $logo->move($uploadDir, $name);
                $setting->setLogoPath($name);
            }
            $em->flush();
            $activity->log('settings.update', ['titre' => $setting->getHeaderTitle(), 'sous_titre' => $setting->getHeaderSubtitle(), 'logo' => $setting->getLogoPath()]);
            $this->addFlash('success', 'Réglages enregistrés.');

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/settings.html.twig', ['form' => $form, 'setting' => $setting]);
    }
}
