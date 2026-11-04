<?php

namespace App\Tests\Controller;

use App\Entity\Correction;
use App\Entity\CorrectionMemory;
use App\Entity\User;
use App\Tests\AnthropicMock;
use App\Tests\AppWebTestCase;

/** Le fil du Correcteur média : relectures, dossiers, mémoire, versions révisées. L'API Anthropic est simulée (AnthropicMock). */
class CorrecteurTest extends AppWebTestCase
{
    private const API = '/correcteur-media/api';

    private string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();
        AnthropicMock::reset();
    }

    /** Connecte l'utilisateur et récupère le jeton CSRF de la page, comme le fait l'interface. */
    private function start(User $user): void
    {
        $this->login($user);
        $this->csrf = $this->browser->request('GET', '/correcteur-media')->filter('[data-controller="correcteur"]')->attr('data-correcteur-token-value');
    }

    private function api(string $method, string $path, array $body = []): array
    {
        if ('GET' === $method) {
            $this->browser->request('GET', self::API.$path);
        } else {
            $this->browser->jsonRequest($method, self::API.$path, $body, ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        }

        return json_decode((string) $this->browser->getResponse()->getContent(), true) ?: [];
    }

    private function code(): int
    {
        return $this->browser->getResponse()->getStatusCode();
    }

    private function review(string $text, ?string $dossier = null, ?int $revisionOf = null): array
    {
        $body = ['text' => $text];
        if (null !== $dossier) {
            $body['dossier'] = $dossier;
        }
        if (null !== $revisionOf) {
            $body['revisionOf'] = $revisionOf;
        }

        return $this->api('POST', '/corrections', $body);
    }

    public function testReviewSendsACachedPromptAndRecordsUsage(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $data = $this->review('Découvrez Sellopas,votre billetterie');

        self::assertSame(201, $this->code());
        self::assertSame(AnthropicMock::REPLY, $data['result']);

        $system = AnthropicMock::lastSystem();
        self::assertCount(1, $system, 'Sans règle d\'équipe, un seul bloc système.');
        self::assertSame(['type' => 'ephemeral'], $system[0]['cache_control']);
        self::assertStringContainsString('Relecteur éditorial', $system[0]['text']);
        self::assertStringContainsString('CONTEXTE JOINT AU TEXTE', $system[0]['text']);
        self::assertSame("## TEXTE À RELIRE\n\nDécouvrez Sellopas,votre billetterie", AnthropicMock::lastUserMessage());

        /** @var Correction $correction */
        $correction = $this->fresh(Correction::class, $data['id']);
        self::assertSame('claude-test', $correction->getModel());
        self::assertSame(1200, $correction->getInputTokens());
        self::assertSame(300, $correction->getOutputTokens());
        self::assertSame(4000, $correction->getCacheReadTokens());
        self::assertSame(0, $correction->getCacheWriteTokens());
    }

    public function testFailedReviewIsNotSaved(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        AnthropicMock::$status = 529;
        $data = $this->review('Un texte');

        self::assertSame(502, $this->code());
        self::assertSame('Surcharge de l\'API.', $data['error']);
        self::assertSame(0, $this->em->getRepository(Correction::class)->count([]));
    }

    public function testRejectsEmptyOrTooLongText(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $this->review('   ');
        self::assertSame(422, $this->code());
        $this->review(str_repeat('a', 20001));
        self::assertSame(422, $this->code());
        self::assertSame([], AnthropicMock::$requests);
    }

    public function testRequiresTheCsrfToken(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $this->csrf = 'faux';
        $this->review('Un texte');
        self::assertSame(403, $this->code());
        self::assertSame([], AnthropicMock::$requests);
    }

    public function testThreadIsPagedOldestFirstAndPrivate(): void
    {
        $this->start($this->user(User::ROLE_AGENT, 'awa@procom.pro'));
        $a = $this->review('Premier')['id'];
        $b = $this->review('Deuxième')['id'];
        $c = $this->review('Troisième')['id'];

        $page = $this->api('GET', '/corrections?limit=2');
        self::assertSame([$b, $c], array_column($page['items'], 'id'));
        self::assertTrue($page['hasMore']);

        $older = $this->api('GET', "/corrections?limit=2&before=$b");
        self::assertSame([$a], array_column($older['items'], 'id'));
        self::assertFalse($older['hasMore']);

        $this->start($this->user(User::ROLE_AGENT, 'kofi@procom.pro'));
        self::assertSame([], $this->api('GET', '/corrections')['items']);
        $this->api('GET', "/corrections/$a");
        self::assertSame(404, $this->code());
        $this->api('DELETE', "/corrections/$a");
        self::assertSame(404, $this->code());
    }

    public function testThreadCanBeFilteredByDossier(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $x = $this->review('Texte X', 'Forum')['id'];
        $y = $this->review('Texte Y', 'Campagne')['id'];
        $none = $this->review('Texte sans dossier')['id'];

        self::assertSame([$x], array_column($this->api('GET', '/corrections?dossier=Forum')['items'], 'id'));
        self::assertSame([$none], array_column($this->api('GET', '/corrections?none=1')['items'], 'id'));
        self::assertSame([$x, $y, $none], array_column($this->api('GET', '/corrections')['items'], 'id'));

        $dossiers = $this->api('GET', '/dossiers');
        self::assertEqualsCanonicalizing(['Forum', 'Campagne'], array_column($dossiers, 'name'));
    }

    public function testRetainAndIgnoreFeedTheNextReviewsOfTheSameDossier(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $id = $this->review('Découvrez Sellopas,votre billetterie. Cliquez ici', 'Sellopass')['id'];

        $this->api('POST', "/corrections/$id/remarks/1/memory", ['action' => 'retain']);
        self::assertSame(201, $this->code());
        $this->api('POST', "/corrections/$id/remarks/2/memory", ['action' => 'ignore']);
        self::assertSame(201, $this->code());

        $shown = $this->api('GET', "/corrections/$id");
        self::assertSame('retain', $shown['memo']['1']['action']);
        self::assertSame('ignore', $shown['memo']['2']['action']);

        $this->review('Un autre post', 'Sellopass');
        $message = AnthropicMock::lastUserMessage();
        self::assertStringContainsString('## MÉMOIRE DU DOSSIER « Sellopass »', $message);
        self::assertStringContainsString('- « Sellopas,votre » → « Sellopas, votre ». — Espace manquante après la virgule.', $message);
        self::assertStringContainsString('- Ne pas signaler : « cliquez ici » → supprimé.', $message);

        $this->review('Un post d\'un autre sujet', 'Autre sujet');
        self::assertStringNotContainsString('MÉMOIRE', AnthropicMock::lastUserMessage());
        $this->review('Un post sans dossier');
        self::assertStringNotContainsString('MÉMOIRE', AnthropicMock::lastUserMessage());

        /** @var CorrectionMemory $entry */
        $entry = $this->em->getRepository(CorrectionMemory::class)->findOneBy(['kind' => CorrectionMemory::KIND_CHOIX]);
        self::assertSame(1, $entry->getUseCount(), 'Seule la relecture du même dossier a utilisé l\'entrée.');
    }

    public function testMemoryWithoutDossierAppliesToEveryDossier(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $id = $this->review('Texte sans dossier')['id'];
        $this->api('POST', "/corrections/$id/remarks/2/memory", ['action' => 'ignore']);

        $this->review('Un post', 'Forum');
        self::assertStringContainsString('Ne pas signaler : « cliquez ici »', AnthropicMock::lastUserMessage());
    }

    public function testRetainingTwiceUpdatesTheSameEntry(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $id = $this->review('Un texte', 'Forum')['id'];

        $this->api('POST', "/corrections/$id/remarks/2/memory", ['action' => 'retain']);
        $this->api('POST', "/corrections/$id/remarks/2/memory", ['action' => 'ignore']);

        $entries = $this->em->getRepository(CorrectionMemory::class)->findAll();
        self::assertCount(1, $entries);
        self::assertSame(CorrectionMemory::KIND_EXCLUSION, $entries[0]->getKind());
    }

    public function testRejectsAnUnknownRemarkOrAction(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $id = $this->review('Un texte')['id'];

        $this->api('POST', "/corrections/$id/remarks/9/memory", ['action' => 'retain']);
        self::assertSame(422, $this->code());
        $this->api('POST', "/corrections/$id/remarks/1/memory", ['action' => 'detruire']);
        self::assertSame(422, $this->code());
        self::assertSame(0, $this->em->getRepository(CorrectionMemory::class)->count([]));
    }

    public function testMemoryIsPrivateToItsAuthor(): void
    {
        $this->start($this->user(User::ROLE_AGENT, 'awa@procom.pro'));
        $id = $this->review('Un texte', 'Forum')['id'];
        $memoryId = $this->api('POST', "/corrections/$id/remarks/1/memory", ['action' => 'retain'])['id'];

        $this->start($this->user(User::ROLE_AGENT, 'kofi@procom.pro'));
        $this->api('POST', "/corrections/$id/remarks/1/memory", ['action' => 'retain']);
        self::assertSame(404, $this->code());
        $this->api('DELETE', "/memory/$memoryId");
        self::assertSame(403, $this->code());
        self::assertSame([], $this->api('GET', '/memory?dossier=Forum'));

        $this->review('Un autre post', 'Forum');
        self::assertStringNotContainsString('MÉMOIRE', AnthropicMock::lastUserMessage());
    }

    public function testForgettingAnEntryRemovesItFromTheNextReviews(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $id = $this->review('Un texte', 'Forum')['id'];
        $memoryId = $this->api('POST', "/corrections/$id/remarks/1/memory", ['action' => 'retain'])['id'];

        self::assertCount(1, $this->api('GET', '/memory?dossier=Forum'));
        $this->api('DELETE', "/memory/$memoryId");
        self::assertSame(204, $this->code());
        self::assertSame([], $this->api('GET', '/memory?dossier=Forum'));

        $this->review('Un autre post', 'Forum');
        self::assertStringNotContainsString('MÉMOIRE', AnthropicMock::lastUserMessage());
    }

    public function testStaleEntriesLeaveTheMemory(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $id = $this->review('Un texte', 'Forum')['id'];
        $this->api('POST', "/corrections/$id/remarks/1/memory", ['action' => 'retain']);
        $this->em->getConnection()->executeStatement("UPDATE correction_memory SET created_at = '2020-01-01 00:00:00'");

        $this->review('Un autre post', 'Forum');
        self::assertStringNotContainsString('MÉMOIRE', AnthropicMock::lastUserMessage());
    }

    public function testInjectedMemoryStaysWithinItsBudgetAndMatchingEntriesComeFirst(): void
    {
        $user = $this->user(User::ROLE_AGENT);
        $this->start($user);
        $first = null;
        for ($i = 1; $i <= 100; ++$i) {
            $entry = (new CorrectionMemory())->setUser($user)->setDossier('Forum')->setContent("Entrée $i ".str_repeat('x', 240));
            if (1 === $i) {
                $entry->setAnchor('Sellopass');
                $first = $entry;
            }
            $this->em->persist($entry);
        }
        $this->em->flush();

        $this->review('Un post sur Sellopass', 'Forum');
        $message = AnthropicMock::lastUserMessage();
        preg_match_all('/^- Entrée (\d+) /m', $message, $lines);

        self::assertContains('1', $lines[1], 'L\'entrée qui cite un mot du texte passe en premier, même ancienne.');
        self::assertLessThanOrEqual(8, \count($lines[1]));
        self::assertLessThan(2600, mb_strlen($message), 'Le contexte ne grossit pas avec la mémoire.');
        self::assertSame($lines[1][0], '1');
        self::assertNotNull($first);
    }

    public function testRecentPostsOfTheSameDossierGiveContext(): void
    {
        $this->start($this->user(User::ROLE_AGENT, 'awa@procom.pro'));
        $this->review("Post A du forum\nsuite", 'Forum');
        $this->review('Post B du forum', 'Forum');
        $this->review('Post C de la campagne', 'Campagne');
        $this->review('Post D sans dossier');

        $this->start($this->user(User::ROLE_AGENT, 'kofi@procom.pro'));
        $this->review('Post E d\'un collègue', 'Forum');

        $this->start($this->em->getRepository(User::class)->findOneBy(['email' => 'awa@procom.pro']));
        $this->review('Post F du forum', 'Forum');
        $message = AnthropicMock::lastUserMessage();

        self::assertStringContainsString('## POSTS RÉCENTS DU DOSSIER', $message);
        self::assertStringContainsString('Texte : Post A du forum ⏎ suite', $message);
        self::assertStringContainsString('Texte : Post B du forum', $message);
        self::assertStringContainsString('Relecture : Verdict : publiable après corrections', $message);
        self::assertStringNotContainsString('Post C', $message);
        self::assertStringNotContainsString('Post D', $message);
        self::assertStringNotContainsString('Post E', $message);
        self::assertStringEndsWith("## TEXTE À RELIRE\n\nPost F du forum", $message);

        $this->review('Post G sans dossier');
        self::assertStringNotContainsString('POSTS RÉCENTS', AnthropicMock::lastUserMessage(), 'Sans dossier, aucun post voisin : pas de mélange de sujets.');
    }

    public function testRecentPostsAreLimitedToThree(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        foreach (['un', 'deux', 'trois', 'quatre'] as $word) {
            $this->review("Post $word", 'Forum');
        }
        $this->review('Post cinq', 'Forum');
        $message = AnthropicMock::lastUserMessage();

        self::assertStringNotContainsString('Texte : Post un', $message);
        foreach (['deux', 'trois', 'quatre'] as $word) {
            self::assertStringContainsString("Texte : Post $word", $message);
        }
    }

    public function testRevisedVersionCarriesThePreviousReviewAndKeepsTheDossier(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $first = $this->review('Découvrez Sellopas,votre billetterie', 'Sellopass');
        $revised = $this->review('Découvrez Sellopass, votre billetterie', null, $first['id']);

        self::assertSame(201, $this->code());
        self::assertSame('Sellopass', $revised['dossier']);
        self::assertSame($first['id'], $revised['previousId']);

        $message = AnthropicMock::lastUserMessage();
        self::assertStringContainsString("## VERSION PRÉCÉDENTE DE CE TEXTE\n\nDécouvrez Sellopas,votre billetterie", $message);
        self::assertStringContainsString('## RELECTURE PRÉCÉDENTE', $message);
        self::assertStringContainsString('- 🔴 « Sellopas,votre » → « Sellopas, votre ».', $message);
        self::assertStringNotContainsString('POSTS RÉCENTS', $message, 'La version précédente n\'est pas répétée comme post voisin.');
        self::assertStringEndsWith("## TEXTE À RELIRE\n\nDécouvrez Sellopass, votre billetterie", $message);
    }

    public function testCannotReviseSomeoneElsesReview(): void
    {
        $this->start($this->user(User::ROLE_AGENT, 'awa@procom.pro'));
        $id = $this->review('Un texte')['id'];

        $this->start($this->user(User::ROLE_AGENT, 'kofi@procom.pro'));
        $this->review('Une version', null, $id);
        self::assertSame(404, $this->code());
    }

    public function testDeletingAReviewKeepsItsMemoryAndItsRevisions(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $first = $this->review('Premier jet', 'Forum')['id'];
        $revised = $this->review('Deuxième jet', null, $first)['id'];
        $memoryId = $this->api('POST', "/corrections/$first/remarks/1/memory", ['action' => 'retain'])['id'];

        $this->api('DELETE', "/corrections/$first");
        self::assertSame(204, $this->code());

        self::assertNull($this->fresh(Correction::class, $revised)->getPrevious());
        /** @var CorrectionMemory $entry */
        $entry = $this->fresh(CorrectionMemory::class, $memoryId);
        self::assertNull($entry->getSource());

        $this->review('Troisième jet', null, $revised);
        self::assertSame(201, $this->code());
    }

    public function testMovingAReviewToAnotherDossier(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $id = $this->review('Un texte', 'Forum')['id'];

        $moved = $this->api('PUT', "/corrections/$id", ['dossier' => '  Mois   du Local ']);
        self::assertSame('Mois du Local', $moved['dossier']);
        $cleared = $this->api('PUT', "/corrections/$id", ['dossier' => '']);
        self::assertNull($cleared['dossier']);
    }

    public function testSuggestsTheDossierOfAText(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $this->review('Premier post', 'Mois du Consommons Local');
        $this->review('Deuxième post', 'Forum du Cadre de Vie');

        self::assertSame('Mois du Consommons Local', $this->api('POST', '/dossiers/suggest', ['text' => 'Sellopass rejoint #ConsommonsLocal !'])['dossier']);
        self::assertSame('Forum du Cadre de Vie', $this->api('POST', '/dossiers/suggest', ['text' => 'Le Forum International du Cadre de Vie ouvre ses portes.'])['dossier']);
        self::assertNull($this->api('POST', '/dossiers/suggest', ['text' => 'Un texte sans rapport'])['dossier']);
    }

    public function testSuggestsTheDossierFromTheMemoryOfItsEntries(): void
    {
        $this->start($this->user(User::ROLE_AGENT));
        $id = $this->review('Un texte', 'Partenaire Sellopass')['id'];
        $this->api('POST', "/corrections/$id/remarks/1/memory", ['action' => 'retain']);

        self::assertSame('Partenaire Sellopass', $this->api('POST', '/dossiers/suggest', ['text' => 'On dit « Sellopas,votre » partout'])['dossier']);
    }

    public function testAdminsPromoteEntriesToTeamRules(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'admin@procom.pro');
        $this->start($admin);
        $id = $this->review('Un texte', 'Forum')['id'];
        $memoryId = $this->api('POST', "/corrections/$id/remarks/1/memory", ['action' => 'retain'])['id'];
        $shared = $this->api('POST', "/memory/$memoryId/share");
        self::assertTrue($shared['shared']);
        self::assertNull($shared['dossier']);

        $this->start($this->user(User::ROLE_AGENT, 'awa@procom.pro'));
        $this->review('Un post', 'Autre sujet');
        $system = AnthropicMock::lastSystem();
        self::assertCount(2, $system);
        self::assertStringStartsWith("## RÈGLES DE L'ÉQUIPE\n\n- « Sellopas,votre » → « Sellopas, votre ».", $system[1]['text']);
        self::assertSame(['type' => 'ephemeral'], $system[1]['cache_control']);
        self::assertStringNotContainsString('MÉMOIRE', AnthropicMock::lastUserMessage());
        self::assertSame($shared['id'], $this->api('GET', '/memory')[0]['id'], 'Les règles de l\'équipe figurent dans le panneau Mémoire.');

        $this->api('DELETE', "/memory/$memoryId");
        self::assertSame(403, $this->code(), 'Un agent ne retire pas une règle de l\'équipe.');
        $this->api('POST', "/memory/$memoryId/share");
        self::assertSame(403, $this->code());
    }

    public function testAdminDashboardShowsTheUsageAndTheCacheShare(): void
    {
        $this->start($this->user(User::ROLE_AGENT, 'awa@procom.pro'));
        $this->review('Premier post');
        $this->review('Deuxième post');

        $this->login($this->user(User::ROLE_ADMIN, 'admin@procom.pro'));
        $page = $this->browser->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $panel = $page->filter('.panel:contains("Consommation du correcteur")')->text();
        self::assertStringContainsString('2 relectures', $panel);
        self::assertStringContainsString('entrée 2 400', $panel);
        self::assertStringContainsString('cache 8 000', $panel);
        self::assertStringContainsString('cache 77 %', $panel, '4 000 relus dans le cache sur 5 200 tokens d\'entrée.');
    }

    public function testTeamRulesKeepAStableOrderForTheCache(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'admin@procom.pro');
        $this->start($admin);
        foreach (['Règle B', 'Règle A', 'Règle C'] as $content) {
            $this->em->persist((new CorrectionMemory())->setUser($admin)->setShared(true)->setContent($content));
        }
        $this->em->flush();

        $this->review('Un post');
        $first = AnthropicMock::lastSystem()[1]['text'];
        $this->review('Un autre post');

        self::assertSame($first, AnthropicMock::lastSystem()[1]['text']);
        self::assertStringContainsString("- Règle B\n- Règle A\n- Règle C", $first);
    }
}
