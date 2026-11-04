<?php

namespace App\Controller;

use App\Dto\DocumentFilter;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Form\DocumentType;
use App\Repository\ClientRepository;
use App\Repository\DocumentRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\Notifications;
use App\Service\DocumentNumbering;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Factures et devis. Chaque action exige sa permission « facture.* » ; la suppression est logique. */
#[Route('/factures')]
class DocumentController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentRepository $documents,
        private readonly ActivityLogger $activity,
        private readonly Notifications $notifications,
    ) {
    }

    #[IsGranted(Permission::FACTURE_VIEW)]
    #[Route('', name: 'app_factures', methods: 'GET')]
    public function index(Request $request, ClientRepository $clients): Response
    {
        $filter = DocumentFilter::fromRequest($request);
        [$rows, $total] = $this->documents->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->documents->paginate($filter, self::PER_PAGE);
        }

        return $this->render('facture/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'totals' => $this->documents->totals($filter),
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'clients' => $clients->findAllByName(),
            'etats' => DocumentFilter::ETATS[$filter->type],
            'dashboard' => $this->documents->dashboard(),
        ]);
    }

    #[IsGranted(Permission::FACTURE_EDIT)]
    #[Route('/nouveau', name: 'app_facture_new', methods: ['GET', 'POST'])]
    public function new(Request $request, DocumentNumbering $numbering): Response
    {
        $type = (string) $request->query->get('type');
        $document = new Document(isset(Document::TYPES[$type]) ? $type : Document::TYPE_FACTURE);
        $today = new \DateTimeImmutable('today');
        $document->setDateEmission($today)->setDateLimite($today->modify('+'.Document::DEFAULT_DELAY_DAYS.' days'))
            ->setStatut($document->isFacture() ? Document::EMISE : Document::ENVOYE);
        $user = $this->getUser();
        if ($user instanceof User) {
            $document->setCreatedBy($user);
        }
        if ($clientId = $request->query->getInt('client')) {
            $document->setClient($this->em->getRepository(Client::class)->find($clientId));
        }

        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($document);
            $this->em->flush();
            $this->activity->log('facture.create', ['id' => $document->getId()] + $document->snapshot());
            $this->addFlash('success', sprintf('%s %s enregistré%s.', $document->getTypeLabel(), $document->getReference(), $document->isFacture() ? 'e' : ''));

            return $this->redirectToRoute('app_facture_show', ['id' => $document->getId()]);
        }

        return $this->render('facture/form.html.twig', ['form' => $form, 'document' => $document, 'isNew' => true, 'nextNumber' => $numbering->next($document->getType(), $today)]);
    }

    #[IsGranted(Permission::FACTURE_VIEW)]
    #[Route('/{id}', name: 'app_facture_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $document = $this->findOr404($id);
        $paid = $this->documents->paidFor($document);

        return $this->render('facture/show.html.twig', [
            'document' => $document,
            'paid' => $paid,
            'payments' => $document->isFacture() ? $this->documents->paymentsOf($document) : [],
            'invoices' => $document->isDevis() ? $this->documents->invoicesFrom($document) : [],
        ]);
    }

    #[IsGranted(Permission::FACTURE_EDIT)]
    #[Route('/{id}/modifier', name: 'app_facture_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $document = $this->findOr404($id);
        $before = $document->snapshot();

        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $document->touch();
            $this->em->flush();
            $after = $document->snapshot();
            $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, \ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->activity->log('facture.update', [
                    'id' => $document->getId(),
                    'numero' => $document->getReference(),
                    'avant' => array_intersect_key($before, array_flip($changed)),
                    'apres' => array_intersect_key($after, array_flip($changed)),
                ]);
            }
            $this->addFlash('success', $document->getTypeLabel().' mis à jour.');

            return $this->redirectToRoute('app_facture_show', ['id' => $document->getId()]);
        }

        return $this->render('facture/form.html.twig', ['form' => $form, 'document' => $document, 'isNew' => false, 'nextNumber' => null]);
    }

    /** Changement rapide de statut depuis la fiche (émettre, envoyer, accepter, refuser, annuler). */
    #[IsGranted(Permission::FACTURE_EDIT)]
    #[Route('/{id}/statut', name: 'app_facture_status', methods: 'POST', requirements: ['id' => '\d+'])]
    public function status(Request $request, int $id): Response
    {
        $document = $this->findOr404($id);
        $this->checkToken($request, $id);

        $statut = (string) $request->request->get('statut');
        if (!isset($document->getStatuts()[$statut])) {
            throw $this->createNotFoundException('Statut inconnu.');
        }
        if (Document::ANNULEE === $statut && $this->documents->paidFor($document) > 0) {
            $this->addFlash('error', 'Cette facture a déjà des encaissements : elle ne peut pas être annulée.');

            return $this->redirectToRoute('app_facture_show', ['id' => $id]);
        }

        if ($document->getStatut() !== $statut) {
            $from = $document->getStatutLabel();
            $document->setStatut($statut)->touch();
            $this->em->flush();
            $this->activity->log('facture.status', ['id' => $id, 'numero' => $document->getReference(), 'de' => $from, 'vers' => $document->getStatutLabel()]);
            $this->notifications->documentStatusChanged($document, $from);
            $this->addFlash('success', sprintf('%s : statut « %s ».', $document->getReference(), $document->getStatutLabel()));
        }

        return $this->redirectToRoute('app_facture_show', ['id' => $id]);
    }

    /** Établit la facture d'un devis accepté, avec les mêmes montants et le même taux de TVA. */
    #[IsGranted(Permission::FACTURE_EDIT)]
    #[Route('/{id}/facturer', name: 'app_facture_convert', methods: 'POST', requirements: ['id' => '\d+'])]
    public function convert(Request $request, int $id, DocumentNumbering $numbering): Response
    {
        $devis = $this->findOr404($id);
        $this->checkToken($request, $id);

        if (!$devis->isDevis() || Document::ACCEPTE !== $devis->getStatut()) {
            $this->addFlash('error', 'Seul un devis accepté peut être transformé en facture.');

            return $this->redirectToRoute('app_facture_show', ['id' => $id]);
        }
        if ($existing = $this->documents->invoicesFrom($devis)) {
            $this->addFlash('error', 'Ce devis a déjà été facturé : '.$existing[0]->getReference().'.');

            return $this->redirectToRoute('app_facture_show', ['id' => $existing[0]->getId()]);
        }

        $today = new \DateTimeImmutable('today');
        $facture = (new Document(Document::TYPE_FACTURE))
            ->setReference($numbering->next(Document::TYPE_FACTURE, $today))
            ->setClient($devis->getClient())
            ->setObjet($devis->getObjet())
            ->setDateEmission($today)
            ->setDateLimite($today->modify('+'.Document::DEFAULT_DELAY_DAYS.' days'))
            ->setStatut(Document::EMISE)
            ->setMontantTtc($devis->getMontantTtc())
            ->setMontantHt($devis->getMontantHt())
            ->setMontantTva($devis->getMontantTva())
            ->setTauxTva($devis->getTauxTva())
            ->setObservation($devis->getObservation())
            ->setSourceDevis($devis);
        $user = $this->getUser();
        if ($user instanceof User) {
            $facture->setCreatedBy($user);
        }

        $this->em->persist($facture);
        $this->em->flush();
        $this->activity->log('facture.convert', ['id' => $facture->getId(), 'devis' => $devis->getReference(), 'facture' => $facture->getReference()]);
        $this->addFlash('success', sprintf('Facture %s établie à partir du devis %s.', $facture->getReference(), $devis->getReference()));

        return $this->redirectToRoute('app_facture_show', ['id' => $facture->getId()]);
    }

    #[IsGranted(Permission::FACTURE_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_facture_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $document = $this->findOr404($id);
        $this->checkToken($request, $id);

        if ($document->isFacture() && $this->documents->paymentsOf($document)) {
            $this->addFlash('error', 'Cette facture a des encaissements rattachés : détachez-les ou supprimez-les avant de la supprimer.');

            return $this->redirectToRoute('app_facture_show', ['id' => $id]);
        }

        $user = $this->getUser();
        $document->softDelete($user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('facture.delete', ['id' => $id] + $document->snapshot());
        $this->addFlash('success', $document->getTypeLabel().' '.$document->getReference().' supprimé'.($document->isFacture() ? 'e' : '').'.');

        return $this->redirectToRoute('app_factures', ['type' => $document->getType()]);
    }

    private function findOr404(int $id): Document
    {
        return $this->documents->findActive($id) ?? throw $this->createNotFoundException('Document introuvable.');
    }

    private function checkToken(Request $request, int $id): void
    {
        if (!$this->isCsrfTokenValid('document_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
