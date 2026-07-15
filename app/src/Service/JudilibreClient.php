<?php

namespace App\Service;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException;
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
    // Le sandbox PISTE est un espace séparé (applications et souscriptions
    // propres) servant un jeu de données Judilibre anonymisé : utile pour
    // valider la chaîne, pas pour la production.
    private const URL_API_SANDBOX = 'https://sandbox-api.piste.gouv.fr/cassation/judilibre/v1.0/';
    private const URL_OAUTH_SANDBOX = 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token';
    private const PAGE_SIZE = 50;

    private readonly HttpClient $http;
    private ?string $jeton = null;
    private int $jetonExpire = 0;

    public function __construct(
        #[Autowire(env: 'JUDILIBRE_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'JUDILIBRE_CLIENT_SECRET')] private readonly string $clientSecret,
        #[Autowire(env: 'JUDILIBRE_API_KEY')] private readonly string $apiKey,
        #[Autowire(env: 'bool:JUDILIBRE_SANDBOX')] private readonly bool $sandbox = false,
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
     * La recherche porte sur l'expression exacte « loi n° 2025 532 » — le
     * numéro est passé SANS tiret : un « 2025-532 » dans la requête brute
     * déclenche le détecteur de numéros de pourvoi de Judilibre, qui écrase
     * la recherche plein texte par un filtre wildcard sur le champ number
     * (zéro résultat). L'analyseur plein texte découpant de toute façon
     * « 2025-532 » en deux tokens, la phrase exacte matche bien la citation
     * canonique « loi n° 2025-532 du … » des arrêts. Limite assumée : les
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
                'query' => sprintf('loi n° %s', str_replace('-', ' ', $numLoi)),
                'operator' => 'exact',
                'sort' => 'score',
                'order' => 'desc',
                'page_size' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            // Quand la phrase exacte ne matche rien, Judilibre « relâche » la
            // requête en OR sur les tokens (relaxed: true) et renvoie tout le
            // corpus (~380 000 décisions) : aucune décision ne cite la loi.
            if ($reponse['relaxed'] ?? false) {
                return ['total' => 0, 'decisions' => []];
            }

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

        // PISTE renvoie des 401 transitoires (jeton invalidé avant son
        // expires_in, propagation du nouveau jeton à la passerelle…) : on
        // redemande un jeton et on rejoue la requête après une pause
        // croissante, plusieurs fois, avant d'abandonner. Même traitement
        // pour un éventuel 429 (limitation de débit).
        for ($essai = 0; ; ++$essai) {
            $entetes = $this->clientId !== '' && $this->clientSecret !== ''
                ? ['Authorization' => 'Bearer ' . $this->jeton()]
                : ['KeyId' => $this->apiKey];

            try {
                $reponse = $this->http->get(($this->sandbox ? self::URL_API_SANDBOX : self::URL_API) . $chemin, [
                    'headers' => $entetes + ['Accept' => 'application/json'],
                    'query' => $query,
                ]);

                return json_decode((string) $reponse->getBody(), true) ?? [];
            } catch (BadResponseException $e) {
                $code = $e->getResponse()->getStatusCode();
                // 400/401/429 : la passerelle PISTE signale un jeton absent ou
                // invalidé par un 400 à corps vide (« Unable to find token »)
                // autant que par un 401 — nouveau jeton et pause. 5xx : elle
                // flanche sous charge soutenue (502 constatés) — même pause,
                // sans toucher au jeton. Un vrai 400 métier épuiserait les
                // trois tentatives avant de remonter.
                if ($essai < 3 && (\in_array($code, [400, 401, 429], true) || $code >= 500)) {
                    if ($code < 500) {
                        $this->jeton = null;
                    }
                    sleep(1 << $essai);

                    continue;
                }
                if (\in_array($code, [400, 401, 403], true)) {
                    throw new \RuntimeException(sprintf(
                        'Judilibre a refusé la requête (HTTP %d, environnement %s). Un 403 avec un jeton '
                        . 'valide signifie que l\'application PISTE n\'est pas souscrite à l\'API Judilibre '
                        . 'dans cet environnement (piste.gouv.fr → APIs → Judilibre → Souscrire). '
                        . 'Authentification utilisée : %s.',
                        $code,
                        $this->sandbox ? 'sandbox' : 'production',
                        $this->clientId !== '' ? 'OAuth' : 'clé API / en-tête KeyId'
                    ), previous: $e);
                }

                throw $e;
            }
        }
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
            $reponse = $this->http->post($this->sandbox ? self::URL_OAUTH_SANDBOX : self::URL_OAUTH, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'openid',
                ],
            ]);
        } catch (BadResponseException $e) {
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
