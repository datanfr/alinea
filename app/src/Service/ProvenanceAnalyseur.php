<?php

namespace App\Service;

use Dom\HTMLDocument;

/**
 * Rattache les alinéas d'une loi promulguée aux amendements adoptés qui les
 * ont produits (le « Rayon X » d'Alinéa).
 *
 * Principe : le dispositif d'un amendement cite entre guillemets français le
 * texte qu'il insère ou réécrit (« Art. L. 121-1.-Il est institué… »). On
 * extrait ces fragments, on les normalise, et on marque chaque paragraphe de
 * la loi dont le texte normalisé recoupe un fragment. Seules les insertions
 * et réécritures substantielles (≥ 60 caractères utiles) sont donc tracées ;
 * les retouches de quelques mots et le texte hérité du Sénat restent non
 * marqués.
 */
class ProvenanceAnalyseur
{
    private const LONGUEUR_MIN = 60;

    /**
     * Annote le contenu HTML des articles et renvoie les amendements retenus.
     *
     * @param list<array<string, mixed>> $articles    articles de la loi (clé « contenu » en HTML)
     * @param list<array<string, mixed>> $amendements amendements adoptés (clé « dispositif »)
     *
     * @return array{articles: list<array<string, mixed>>, refs: array<string, array<string, mixed>>}
     */
    public function annoter(array $articles, array $amendements): array
    {
        $fragments = [];
        foreach ($amendements as $amendement) {
            foreach ($this->extraireFragments($amendement['dispositif'] ?? null) as $fragment) {
                $fragments[] = ['texte' => $fragment, 'uid' => $amendement['uid']];
            }
        }

        if ($fragments === []) {
            return ['articles' => $articles, 'refs' => []];
        }

        $uidsUtilises = [];
        foreach ($articles as &$article) {
            if (!empty($article['contenu'])) {
                $article['contenu'] = $this->annoterContenu($article['contenu'], $fragments, $uidsUtilises);
            }
        }
        unset($article);

        $refs = [];
        foreach ($amendements as $amendement) {
            if (isset($uidsUtilises[$amendement['uid']])) {
                $refs[$amendement['uid']] = $amendement;
            }
        }

        return ['articles' => $articles, 'refs' => $refs];
    }

    /**
     * Fragments de texte cités par le dispositif d'un amendement.
     *
     * Deux formes couvertes : la citation en ligne (« … » sur une ligne) et la
     * rédaction complète multi-alinéas, où chaque alinéa inséré commence par
     * « mais seul le dernier se ferme par » — d'où l'extraction ligne à ligne.
     *
     * @return list<string> fragments normalisés
     */
    private function extraireFragments(?string $dispositif): array
    {
        if ($dispositif === null || $dispositif === '') {
            return [];
        }

        $texte = preg_replace('#</p>|<br\s*/?>#i', "\n", $dispositif);
        $texte = html_entity_decode(strip_tags($texte), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $bruts = [];

        // Citations en ligne (sans guillemet imbriqué).
        preg_match_all('/«([^«»]+)»/u', $texte, $matches);
        $bruts = $matches[1];

        // Alinéas insérés : lignes ouvertes par « (fermées ou non).
        foreach (preg_split('/\R/u', $texte) as $ligne) {
            $ligne = trim($ligne);
            if (str_starts_with($ligne, '«')) {
                $bruts[] = trim($ligne, "«» \t");
            }
        }

        $fragments = [];
        foreach ($bruts as $brut) {
            $fragment = $this->normaliser($brut);
            if (mb_strlen($fragment) >= self::LONGUEUR_MIN) {
                $fragments[$fragment] = $fragment;
            }
        }

        return array_values($fragments);
    }

    /**
     * @param list<array{texte: string, uid: string}> $fragments
     * @param array<string, true>                     $uidsUtilises
     */
    private function annoterContenu(string $contenu, array $fragments, array &$uidsUtilises): string
    {
        $document = HTMLDocument::createFromString(
            '<div id="racine">' . $contenu . '</div>',
            LIBXML_NOERROR,
            'UTF-8'
        );

        $modifie = false;
        foreach ($document->querySelectorAll('p') as $paragraphe) {
            $texte = $this->normaliser($paragraphe->textContent);
            if (mb_strlen($texte) < self::LONGUEUR_MIN) {
                continue;
            }

            $uids = [];
            foreach ($fragments as $fragment) {
                if (str_contains($fragment['texte'], $texte) || str_contains($texte, $fragment['texte'])) {
                    $uids[$fragment['uid']] = true;
                }
            }

            if ($uids !== []) {
                $paragraphe->className = 'alinea-amende';
                $paragraphe->setAttribute('data-amendements', implode(',', array_keys($uids)));
                $paragraphe->setAttribute('data-nb', (string) \count($uids));
                $uidsUtilises += $uids;
                $modifie = true;
            }
        }

        return $modifie ? $document->getElementById('racine')->innerHTML : $contenu;
    }

    /**
     * Réduit un texte à sa substance comparable : minuscules, sans ponctuation
     * ni guillemets, espaces normalisés — les dispositifs et le texte publié
     * diffèrent souvent par la typographie (espaces insécables, « ; » finaux…).
     */
    private function normaliser(string $texte): string
    {
        $texte = mb_strtolower($texte);
        $texte = preg_replace('/[^a-z0-9àâäçéèêëîïôöùûüÿœæ]+/u', ' ', $texte);

        return trim($texte);
    }
}
