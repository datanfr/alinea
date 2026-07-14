<?php

namespace App\Service;

use Anthropic\Client as AnthropicClient;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use App\Repository\AmendementRepository;
use App\Repository\DebatRepository;
use App\Repository\ResumeIaRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Analyse IA des amendements adoptés et rejetés d'un dossier : pour chacun,
 * l'exposé sommaire et l'extrait du débat en séance sont envoyés à un modèle
 * — local via Ollama (Gemma 4) ou distant via Anthropic selon le nom du
 * modèle : « claude-* » passe par Anthropic, le reste par Ollama ; le modèle
 * par défaut se règle avec la variable d'environnement IA_MODELE — qui
 * renvoie, au format JSON garanti par un schéma (structured outputs),
 * ce que l'amendement change (< 120 caractères), un résumé
 * détaillé si pertinent, l'INTENTION réellement poursuivie par l'auteur,
 * un degré d'ambiguïté entre l'objectif affiché et l'effet réel, une
 * catégorie et un score d'impact sur 100.
 *
 * L'ambiguïté est le signal clé côté front : un amendement présenté comme
 * « rédactionnel » ou « de précision » mais dont l'effet réel modifie la
 * portée du texte doit être mis en avant.
 *
 * Les analyses sont persistées dans alinea.resume_ia (une seule génération
 * par amendement, upsert) ; à chaque affichage de la page d'une loi, les
 * analyses manquantes sont générées dans la limite de MAX_GENERATIONS pour
 * garder un temps de chargement raisonnable — la page se complète au fil
 * des visites.
 */
class AnalyseAmendementIa
{
    // Plafond d'appels par chargement de page (une génération locale peut
    // prendre plusieurs secondes selon la machine et la taille du modèle).
    private const MAX_GENERATIONS = 10;

    // L'extrait de débat est tronqué au-delà (les débats fleuves n'apportent
    // plus rien au résumé et coûtent des tokens).
    private const MAX_DEBAT = 8000;

    private const CATEGORIES = [
        'coordination', 'redactionnel', 'simplification', 'correction',
        'precision', 'consequence', 'coherence', 'fond',
    ];

    private const SYSTEM = <<<'TXT'
        Tu travailles pour Alinéa, un outil citoyen dont la mission première est de déterminer
        L'INTENTION DERRIÈRE LA LOI : ce que le législateur cherche réellement à obtenir, au-delà
        du texte publié au Journal officiel. C'est dans les amendements — adoptés comme rejetés —
        que se lit la fabrique de la loi : qui a voulu l'infléchir, dans quel sens, ce qui a été
        accepté ou écarté, et ce que cela révèle de l'intention finale du texte. Chaque analyse
        d'amendement doit donc répondre, en filigrane, à la question centrale : qu'est-ce que cet
        amendement dit de l'intention de la loi ? Comment en extraire une interprétation de
        l'Esprit de la loi qui servira au magistrat ou au juriste dans ses activités ?

        Pour cela, croise l'exposé sommaire (le discours que l'auteur tient sur son amendement)
        avec le dispositif visé et, quand il est fourni, l'extrait du débat en séance — c'est
        souvent le débat qui révèle l'enjeu véritable derrière un changement d'apparence anodine.

        Tu produis :
        - resume_court : ce que l'amendement change (ou proposait de changer) concrètement dans
          le texte, en moins de 120 caractères, en français clair, sans jargon, sans commencer
          par « Cet amendement » ; quand c'est pertinent, intègre une seule phrase qui présente
          la tension dans laquelle se cristallise le cœur du débat sur l'amendement en question
          (pourquoi oui et pourquoi non) ; pour un amendement purement technique, renvoie une
          chaîne vide ;
        - resume_detaille : un développement uniquement si l'amendement le justifie — en quoi il
          infléchit (ou aurait infléchi) la portée et l'intention de la loi, ce que le débat
          révèle des positions en présence, ce que son adoption ou son rejet dit de ce que le
          législateur voulait vraiment ; pour un amendement purement technique, renvoie une
          chaîne vide ;
        - intention : l'objectif réellement poursuivi par l'auteur, en une phrase — au-delà de
          ce que l'exposé sommaire affirme : qui protège-t-il, qu'assouplit-il, que
          verrouille-t-il, et dans quel sens cela tire-t-il la loi ? ; synthétise les arguments
          favorables à l'amendement lorsqu'il a été approuvé et les arguments contre l'amendement
          lorsqu'il est rejeté afin de mettre en évidence la tension sous-jacente à l'amendement
          proposé ;
        - ambiguite : de 0 à 100, l'écart entre l'objectif affiché et l'effet réel du changement.
          0-20 : l'amendement fait ce qu'il dit. 50+ : le discours minimise ou déguise la portée
          (ex. présenté comme « rédactionnel » ou « de précision » alors qu'il restreint un droit,
          élargit une exception, déplace une charge). 80+ : l'effet réel contredit l'objectif affiché
          ou le débat révèle un enjeu que l'exposé sommaire passe sous silence. Un amendement qui
          déguise sa portée est précisément ce qui brouille l'intention de la loi : signale-le ;
        - categorie : la nature RÉELLE de l'amendement (pas celle que l'auteur revendique) —
          coordination, redactionnel, simplification, correction, precision, consequence ou
          coherence pour les amendements techniques ; fond quand le changement modifie
          réellement la portée du texte ;
        - score_impact : à quel point le changement pèse sur la portée et l'intention de la loi
          (ou aurait pesé, si rejeté), de 0 à 100 — 0-20 purement technique (renvois, coquilles),
          21-50 ajustement limité avec une légère altération du sens du texte, 51-79 changement
          notable d'un dispositif, du périmètre ou d'une définition, 80-100 changement majeur qui
          redéfinit ce que la loi veut faire, son périmètre d'application ou ses destinataires.
        TXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'resume_court' => [
                'type' => 'string',
                'description' => "Ce que l'amendement change concrètement, en moins de 120 caractères. Chaîne vide pour un amendement purement technique.",
            ],
            'resume_detaille' => [
                'type' => 'string',
                'description' => 'Résumé détaillé si pertinent, sinon chaîne vide.',
            ],
            'intention' => [
                'type' => 'string',
                'description' => "L'objectif réellement poursuivi par l'auteur, en une phrase.",
            ],
            'ambiguite' => [
                'type' => 'integer',
                'description' => "Écart entre objectif affiché et effet réel, de 0 (clair) à 100 (très ambigu).",
            ],
            'categorie' => [
                'type' => 'string',
                'enum' => self::CATEGORIES,
            ],
            'score_impact' => [
                'type' => 'integer',
                'description' => "Impact de l'amendement, de 0 à 100.",
            ],
        ],
        'required' => ['resume_court', 'resume_detaille', 'intention', 'ambiguite', 'categorie', 'score_impact'],
        'additionalProperties' => false,
    ];

    private readonly HttpClient $ollama;
    private ?AnthropicClient $anthropic = null;

    public function __construct(
        #[Autowire(env: 'IA_MODELE')] private readonly string $modele,
        #[Autowire(env: 'OLLAMA_URL')] string $ollamaUrl,
        #[Autowire(env: 'ANTHROPIC_KEY')] private readonly string $anthropicKey,
        private readonly ResumeIaRepository $resumes,
        private readonly DebatRepository $debats,
        private readonly AmendementRepository $amendements,
        private readonly LoggerInterface $logger,
    ) {
        $this->ollama = new HttpClient([
            'base_uri' => rtrim($ollamaUrl, '/') . '/',
            // Génération locale : laisser le temps au modèle de répondre,
            // surtout au premier appel (chargement du modèle en mémoire).
            'timeout' => 300,
        ]);
    }

    /**
     * Analyses IA des amendements donnés (adoptés/rejetés d'un dossier),
     * indexées par uid : celles déjà en base, complétées par les manquantes
     * générées à la volée (au plus MAX_GENERATIONS par appel).
     *
     * @param list<array<string, mixed>> $amendements lignes de AmendementRepository::findParSortPourDossier()
     *
     * @return array{analyses: array<string, array<string, mixed>>, restantes: int}
     */
    public function analyser(string $dossierUid, array $amendements): array
    {
        $analyses = $this->resumes->analysesAmendements(array_column($amendements, 'uid'));

        $manquants = array_values(array_filter(
            $amendements,
            static fn (array $a): bool => !isset($analyses[$a['uid']])
        ));

        // Repli pour retrouver les débats : séances publiques du dossier et
        // comptes rendus de commission, chargés au premier besoin.
        $contexte = null;
        $generes = 0;

        foreach ($manquants as $amendement) {
            if ($generes >= self::MAX_GENERATIONS) {
                break;
            }

            $contexte ??= [
                'seances' => $this->amendements->findSeancesPourDossier($dossierUid),
                'crCommissions' => $this->amendements->findCrCommissionsPourDossier($dossierUid),
            ];

            $analyse = $this->genererAnalyse($amendement, $contexte);
            if ($analyse === null) {
                break; // erreur API : on n'insiste pas, la prochaine visite réessaiera
            }

            $this->resumes->enregistrerAmendement($amendement['uid'], $analyse, $this->modele);

            $analyses[$amendement['uid']] = $analyse;
            ++$generes;
        }

        return [
            'analyses' => $analyses,
            'restantes' => max(0, \count($manquants) - $generes),
        ];
    }

    /**
     * Génère (ou régénère) puis persiste l'analyse d'un amendement, avec un
     * modèle au choix — utilisé par la commande de pré-génération app:ia:analyser.
     *
     * @param array{seances: list<string>, crCommissions: list<string>} $contexte
     */
    public function regenerer(array $amendement, array $contexte, ?string $modele = null): ?array
    {
        $modele ??= $this->modele;
        $analyse = $this->genererAnalyse($amendement, $contexte, $modele);

        if ($analyse !== null) {
            $this->resumes->enregistrerAmendement($amendement['uid'], $analyse, $modele);
        }

        return $analyse;
    }

    /**
     * @param array{seances: list<string>, crCommissions: list<string>} $contexte
     *
     * @return array{resume: string, resume_detaille: ?string, intention: ?string, ambiguite: ?int, categorie: ?string, score_impact: ?int}|null
     */
    private function genererAnalyse(array $amendement, array $contexte, ?string $modele = null): ?array
    {
        $modele ??= $this->modele;
        $prompt = $this->construirePrompt($amendement, $contexte);

        $json = str_starts_with($modele, 'claude-')
            ? $this->appelerAnthropic($modele, $prompt, $amendement['uid'])
            : $this->appelerOllama($modele, $prompt, $amendement['uid']);

        return $json === null ? null : $this->normaliser(json_decode($json, true));
    }

    /** Réponse JSON brute du modèle local, ou null en cas d'échec. */
    private function appelerOllama(string $modele, string $prompt, string $uid): ?string
    {
        try {
            $reponse = $this->ollama->post('api/chat', [
                'json' => [
                    'model' => $modele,
                    'stream' => false,
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    // Structured outputs Ollama : la réponse est contrainte
                    // au schéma JSON.
                    'format' => self::SCHEMA,
                    'options' => [
                        'num_predict' => 2000,
                        'temperature' => 0.2,
                    ],
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Analyse IA : appel Ollama en échec', [
                'amendement' => $uid,
                'exception' => $e,
            ]);

            return null;
        }

        $corps = json_decode((string) $reponse->getBody(), true);
        $contenu = $corps['message']['content'] ?? null;

        return \is_string($contenu) ? $contenu : null;
    }

    /** Réponse JSON brute du modèle Anthropic, ou null en cas d'échec. */
    private function appelerAnthropic(string $modele, string $prompt, string $uid): ?string
    {
        $this->anthropic ??= new AnthropicClient(apiKey: $this->anthropicKey);

        try {
            $message = $this->anthropic->messages->create(
                model: $modele,
                maxTokens: 2000,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
            );
        } catch (RateLimitException $e) {
            $this->logger->warning('Analyse IA : limite de débit Anthropic atteinte', ['exception' => $e]);

            return null;
        } catch (APIStatusException|APIConnectionException $e) {
            $this->logger->error('Analyse IA : appel Anthropic en échec', [
                'amendement' => $uid,
                'exception' => $e,
            ]);

            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        return null;
    }

    /**
     * @param array{seances: list<string>, crCommissions: list<string>} $contexte
     */
    private function construirePrompt(array $amendement, array $contexte): string
    {
        $parties = [sprintf(
            "Amendement n° %s (%s) — %s\nDivision visée : %s\nAuteur : %s%s",
            $amendement['numero'],
            $amendement['phase'] ?? 'Séance publique',
            $amendement['sort'],
            $amendement['division'] ?? '—',
            $amendement['auteur'],
            $amendement['groupe'] ? sprintf(' (%s)', $amendement['groupe']) : ''
        )];

        if (!empty($amendement['expose_sommaire'])) {
            $parties[] = "Exposé sommaire :\n" . $this->aplatir($amendement['expose_sommaire']);
        }

        $debat = $this->debats->findExtrait(
            $amendement['seance_ref'] ?? null,
            $amendement['numero'],
            $contexte['seances'],
            $amendement['date_depot'] ?? null,
            $contexte['crCommissions']
        );

        if ($debat !== null) {
            $paroles = array_map(
                static fn (array $p): string => sprintf('%s : %s', $p['orateur_nom'] ?? '—', strip_tags($p['texte_brut'])),
                $debat['paroles']
            );
            $texte = implode("\n", $paroles);
            if (mb_strlen($texte) > self::MAX_DEBAT) {
                $texte = mb_substr($texte, 0, self::MAX_DEBAT) . ' […]';
            }
            $parties[] = "Extrait du débat (compte rendu officiel) :\n" . $texte;
        }

        return implode("\n\n", $parties);
    }

    /**
     * Le schéma garantit la forme du JSON ; on borne malgré tout les valeurs
     * avant insertion (score dans [0, 100]). Le résumé court n'est pas tronqué :
     * le modèle est guidé pour rester concis, mais on ne coupe jamais en plein
     * mot (sinon le texte devient illisible et non recherchable).
     */
    private function normaliser(mixed $donnees): ?array
    {
        if (!\is_array($donnees) || !\is_string($donnees['resume_court'] ?? null)) {
            return null;
        }

        $court = trim($donnees['resume_court']);
        $detaille = trim((string) ($donnees['resume_detaille'] ?? ''));
        $intention = trim((string) ($donnees['intention'] ?? ''));
        $categorie = $donnees['categorie'] ?? null;

        return [
            'resume' => $court,
            'resume_detaille' => $detaille !== '' ? $detaille : null,
            'intention' => $intention !== '' ? $intention : null,
            'ambiguite' => min(100, max(0, (int) ($donnees['ambiguite'] ?? 0))),
            'categorie' => \in_array($categorie, self::CATEGORIES, true) ? $categorie : null,
            'score_impact' => min(100, max(0, (int) ($donnees['score_impact'] ?? 0))),
        ];
    }

    /** Texte brut à partir du HTML Légifrance/AN (exposés sommaires). */
    private function aplatir(string $html): string
    {
        $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $texte));
    }
}
