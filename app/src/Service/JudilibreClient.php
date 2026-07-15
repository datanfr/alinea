<?php

namespace App\Service;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client de l'API Judilibre (Cour de cassation, open data) servie par la
 * plateforme PISTE. Deux modes d'authentification selon la configuration de
 * l'application PISTE :
 *
 *  - OAuth 2 client_credentials (mode par défaut) : jeton Bearer obtenu
 *    auprès de oauth.piste.gouv.fr avec le couple Client ID / Client Secret ;
 *  - clé API : en-tête « KeyId », si l'application est en mode « API Key ».
 *
 * https://api.gouv.fr/les-api/api-judilibre
 */
class JudilibreClient
{
    private const URL_API = 'https://api.piste.gouv.fr/cassation/judilibre/v1.0/';
    private const URL_OAUTH = 'https://oauth.piste.gouv.fr/api/oauth/token';
    private const PAGE_SIZE = 50;

    private readonly HttpClient $http;
    private ?string $jeton = null;
    private int $jetonExpire = 0;

    public function __construct(
        #[Autowire(env: 'JUDILIBRE_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'JUDILIBRE_CLIENT_SECRET')] private readonly string $clientSecret,
        #[Autowire(env: 'JUDILIBRE_API_KEY')] private readonly string $apiKey,
    ) {
        $this->http = new HttpClient(['timeout' => 30]);
    }

    public function estConfigure(): bool
    {
        return ($this->clientId !== '' && $this->clientSecret !== '') || $this->apiKey !== '';
    }

    /**
     * Décisions citant une loi, par pertinence décroissante.
     *
     * La recherche porte sur l'expression exacte « loi n° 2025-532 » : c'est
     * la forme canonique de citation dans les arrêts. Limite assumée : les
     * décisions qui ne citent que l'article de code modifié (sans le numéro
     * de la loi) échappent à la requête.
     *
     * @return array{total: int, decisions: list<array<string, mixed>>}
     */
    public function rechercherLoi(string $numLoi, int $maxPages = 2): array
    {
        $decisions = [];
        $total = 0;

        for ($page = 0; $page < $maxPages; ++$page) {
            $reponse = $this->requete('search', [
                'query' => sprintf('loi n° %s', $numLoi),
                'operator' => 'exact',
                'sort' => 'score',
                'order' => 'desc',
                'page_size' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            $total = (int) ($reponse['total'] ?? 0);
            foreach ($reponse['results'] ?? [] as $r) {
                $decisions[] = [
                    'id' => $r['id'],
                    'juridiction' => $r['jurisdiction'] ?? null,
                    'chambre' => $r['chamber'] ?? null,
                    'formation' => $r['formation'] ?? null,
                    'type' => $r['type'] ?? null,
                    'numero' => $r['number'] ?? ($r['numbers'][0] ?? null),
                    'ecli' => $r['ecli'] ?? null,
                    'date_decision' => $r['decision_date'] ?? null,
                    'solution' => $r['solution'] ?? null,
                    'sommaire' => $r['summary'] ?? null,
                    'publication' => implode(',', (array) ($r['publication'] ?? [])),
                ];
            }

            if (($reponse['next_page'] ?? null) === null || \count($reponse['results'] ?? []) < self::PAGE_SIZE) {
                break;
            }
        }

        return ['total' => $total, 'decisions' => $decisions];
    }

    /**
     * @return array<string, mixed> réponse JSON décodée
     */
    private function requete(string $chemin, array $query): array
    {
        if (!$this->estConfigure()) {
            throw new \RuntimeException(
                'API Judilibre non configurée : renseigner JUDILIBRE_CLIENT_ID + JUDILIBRE_CLIENT_SECRET '
                . '(OAuth PISTE) ou JUDILIBRE_API_KEY dans .env.local.'
            );
        }

        $entetes = $this->clientId !== '' && $this->clientSecret !== ''
            ? ['Authorization' => 'Bearer ' . $this->jeton()]
            : ['KeyId' => $this->apiKey];

        try {
            $reponse = $this->http->get(self::URL_API . $chemin, [
                'headers' => $entetes + ['Accept' => 'application/json'],
                'query' => $query,
            ]);
        } catch (ClientException $e) {
            $code = $e->getResponse()->getStatusCode();
            if (\in_array($code, [400, 401, 403], true)) {
                throw new \RuntimeException(sprintf(
                    'Judilibre a refusé la requête (HTTP %d). Vérifier sur piste.gouv.fr que '
                    . 'l\'application est bien abonnée à l\'API Judilibre et que le mode '
                    . 'd\'authentification correspond aux identifiants fournis (%s).',
                    $code,
                    $this->clientId !== '' ? 'OAuth' : 'clé API / en-tête KeyId'
                ), previous: $e);
            }

            throw $e;
        }

        return json_decode((string) $reponse->getBody(), true) ?? [];
    }

    /**
     * Jeton OAuth PISTE (client_credentials), renouvelé avant expiration.
     */
    private function jeton(): string
    {
        if ($this->jeton !== null && time() < $this->jetonExpire - 60) {
            return $this->jeton;
        }

        try {
            $reponse = $this->http->post(self::URL_OAUTH, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'openid',
                ],
            ]);
        } catch (ClientException $e) {
            throw new \RuntimeException(
                'Authentification OAuth PISTE refusée (invalid_client ?) : vérifier le couple '
                . 'JUDILIBRE_CLIENT_ID / JUDILIBRE_CLIENT_SECRET — ce sont les « identifiants OAuth » '
                . 'de l\'application PISTE, distincts de sa clé API.',
                previous: $e
            );
        }

        $corps = json_decode((string) $reponse->getBody(), true);
        $this->jeton = $corps['access_token'] ?? throw new \RuntimeException('Réponse OAuth PISTE sans access_token.');
        $this->jetonExpire = time() + (int) ($corps['expires_in'] ?? 3600);

        return $this->jeton;
    }
}
