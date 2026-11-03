<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Entity\User;
use App\Tests\AppWebTestCase;

/** Un champ laissé vide doit donner une erreur de validation affichée, jamais une erreur 500. */
class BlankFieldsTest extends AppWebTestCase
{
    public function testClientSansNom(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/encaissements/clients/nouveau');
        $this->browser->submit($crawler->selectButton('Créer le client')->form(['client[name]' => '', 'client[ifu]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le nom du client est obligatoire.');
        self::assertSame(0, $this->em->getRepository(Client::class)->count([]));
    }

    public function testParametresFiscauxSansTaux(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/admin/fiscal');
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['fiscal_setting[tauxTva]' => '', 'fiscal_setting[tauxAibSansIfu]' => '150']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le taux de TVA est obligatoire.');
        self::assertSelectorTextContains('body', 'Le taux doit être compris entre 0 et 100 %.');
    }
}
