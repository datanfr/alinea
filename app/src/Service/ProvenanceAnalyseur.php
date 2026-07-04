<?php

namespace App\Service;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;

/**
 * Rattache les alinéas d'une loi promulguée aux amendements adoptés qui les
 * ont produits (le « Rayon X » d'Alinéa).
 *
 * Principe : le dispositif d'un amendement cite entre guillemets français le
 * texte qu'il insère ou réécrit (« Art. L. 121-1.-Il est institué… »). On
 * extrait ces fragments, on les normalise, et on retrouve la portion exacte du
 * texte publié qui les recoupe. Seules les insertions et réécritures
 * substantielles (≥ 60 caractères utiles) sont donc tracées ; les retouches de
 * quelques mots et le texte hérité du Sénat restent non marqués.
 *
 * Subtilité de structure : l'open data Légifrance ne délimite pas les alinéas
 * par des <p> mais par des <br> à l'intérieur d'un même <p> (un article entier
 * tient souvent en un ou deux <p>). Le marquage se fait donc alinéa par alinéa
 * — segment de texte entre deux <br> — et, quand c'est possible, sur la seule
 * portion de mots citée par l'amendement plutôt que sur l'alinéa entier.
 */
class ProvenanceAnalyseur
{
    private const LONGUEUR_MIN = 60;

    // Les remplacements de quelques mots citent des fragments plus courts. On
    // les trace aussi, à partir de ce seuil, mais uniquement s'ils sont uniques
    // dans la loi (voir annoter) — un fragment court et unique désigne sans
    // ambiguïté un seul passage, sans risque de faux positif.
    private const LONGUEUR_MIN_COURT = 30;

    private const CARACTERE_UTILE = '/[a-z0-9àâäçéèêëîïôöùûüÿœæ]/u';

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

        // Texte normalisé de toute la loi : sert à vérifier l'unicité des
        // fragments courts. Un fragment long (≥ LONGUEUR_MIN) est fiable en soi ;
        // un fragment court n'est retenu que s'il n'apparaît qu'une fois dans la
        // loi entière — sinon il pointerait plusieurs passages, donc aucun.
        $texteLoi = '';
        foreach ($articles as $article) {
            if (!empty($article['contenu'])) {
                $texteLoi .= ' ' . $this->normaliser(strip_tags((string) $article['contenu']));
            }
        }

        $fragments = array_values(array_filter($fragments, function (array $f) use ($texteLoi): bool {
            if (mb_strlen($f['texte']) >= self::LONGUEUR_MIN) {
                return true;
            }

            return substr_count($texteLoi, $f['texte']) === 1;
        }));

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
            if (mb_strlen($fragment) >= self::LONGUEUR_MIN_COURT) {
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
            foreach ($this->segmentsAlinea($paragraphe) as $segment) {
                if ($this->annoterAlinea($document, $segment, $fragments, $uidsUtilises)) {
                    $modifie = true;
                }
            }
        }

        return $modifie ? $document->getElementById('racine')->innerHTML : $contenu;
    }

    /**
     * Découpe les enfants d'un <p> en alinéas : chaque <br> marque une frontière.
     *
     * @return list<list<\Dom\Node>> segments de nœuds (un par alinéa)
     */
    private function segmentsAlinea(Element $paragraphe): array
    {
        $segments = [[]];
        foreach ($paragraphe->childNodes as $noeud) {
            if ($noeud instanceof Element && $noeud->localName === 'br') {
                $segments[] = [];
            } else {
                $segments[array_key_last($segments)][] = $noeud;
            }
        }

        return $segments;
    }

    /**
     * Marque un alinéa s'il recoupe un fragment cité par un amendement.
     *
     * @param list<\Dom\Node>                         $segment
     * @param list<array{texte: string, uid: string}> $fragments
     * @param array<string, true>                     $uidsUtilises
     */
    private function annoterAlinea(HTMLDocument $document, array $segment, array $fragments, array &$uidsUtilises): bool
    {
        if ($segment === []) {
            return false;
        }

        $normalise = $this->normaliser($this->texteSegment($segment));
        if (mb_strlen($normalise) < self::LONGUEUR_MIN) {
            return false;
        }

        // Alinéa formé d'un unique nœud texte : on peut surligner la seule
        // portion de mots citée par l'amendement (cas le plus fréquent).
        if (\count($segment) === 1 && $segment[0] instanceof Text) {
            return $this->annoterTexte($document, $segment[0], $fragments, $uidsUtilises);
        }

        // Alinéa contenant des éléments (exposants…) : marquage de l'alinéa entier.
        $uids = [];
        foreach ($fragments as $fragment) {
            if (str_contains($fragment['texte'], $normalise) || str_contains($normalise, $fragment['texte'])) {
                $uids[$fragment['uid']] = true;
            }
        }

        if ($uids === []) {
            return false;
        }

        $this->envelopper($document, $segment, array_keys($uids));
        $uidsUtilises += $uids;

        return true;
    }

    /**
     * Surligne, dans un nœud texte, les portions correspondant à un fragment cité.
     *
     * @param list<array{texte: string, uid: string}> $fragments
     * @param array<string, true>                     $uidsUtilises
     */
    private function annoterTexte(HTMLDocument $document, Text $noeud, array $fragments, array &$uidsUtilises): bool
    {
        $original = $noeud->textContent;
        [$normalise, $carte] = $this->normaliserAvecCarte($original);

        if (mb_strlen($normalise) < self::LONGUEUR_MIN) {
            return false;
        }

        // Localise chaque occurrence des fragments dans l'alinéa.
        $plages = [];
        $couvertureTotale = [];
        foreach ($fragments as $fragment) {
            $frag = $fragment['texte'];
            $taille = mb_strlen($frag);
            $depart = 0;
            $trouve = false;
            while (($pos = mb_strpos($normalise, $frag, $depart, 'UTF-8')) !== false) {
                $plages[] = ['debut' => $carte[$pos], 'fin' => $carte[$pos + $taille - 1], 'uid' => $fragment['uid']];
                $depart = $pos + $taille;
                $trouve = true;
            }
            // Fragment plus long que l'alinéa : l'alinéa entier en fait partie.
            if (!$trouve && str_contains($frag, $normalise)) {
                $couvertureTotale[$fragment['uid']] = true;
            }
        }

        if ($plages === []) {
            if ($couvertureTotale === []) {
                return false;
            }
            $this->envelopper($document, [$noeud], array_keys($couvertureTotale));
            $uidsUtilises += $couvertureTotale;

            return true;
        }

        $plages = $this->fusionnerPlages($plages);
        $this->surlignerPortions($document, $noeud, $original, $plages);
        foreach ($plages as $plage) {
            foreach ($plage['uids'] as $uid => $_) {
                $uidsUtilises[$uid] = true;
            }
        }

        return true;
    }

    /**
     * Fusionne les plages qui se chevauchent ou se touchent, en réunissant leurs uids.
     *
     * @param list<array{debut: int, fin: int, uid: string}> $plages
     *
     * @return list<array{debut: int, fin: int, uids: array<string, true>}>
     */
    private function fusionnerPlages(array $plages): array
    {
        usort($plages, static fn (array $a, array $b): int => $a['debut'] <=> $b['debut']);

        $fusion = [];
        foreach ($plages as $plage) {
            $dernier = array_key_last($fusion);
            if ($dernier !== null && $plage['debut'] <= $fusion[$dernier]['fin'] + 1) {
                $fusion[$dernier]['fin'] = max($fusion[$dernier]['fin'], $plage['fin']);
                $fusion[$dernier]['uids'][$plage['uid']] = true;
            } else {
                $fusion[] = ['debut' => $plage['debut'], 'fin' => $plage['fin'], 'uids' => [$plage['uid'] => true]];
            }
        }

        return $fusion;
    }

    /**
     * Remplace un nœud texte par une suite texte / <span> surlignés / texte.
     *
     * @param list<array{debut: int, fin: int, uids: array<string, true>}> $plages
     */
    private function surlignerPortions(HTMLDocument $document, Text $noeud, string $original, array $plages): void
    {
        $chars = mb_str_split($original);
        $parent = $noeud->parentNode;
        $curseur = 0;

        foreach ($plages as $plage) {
            if ($plage['debut'] > $curseur) {
                $parent->insertBefore(
                    $document->createTextNode(implode('', \array_slice($chars, $curseur, $plage['debut'] - $curseur))),
                    $noeud
                );
            }

            $span = $this->creerSpan($document, array_keys($plage['uids']));
            $span->textContent = implode('', \array_slice($chars, $plage['debut'], $plage['fin'] - $plage['debut'] + 1));
            $parent->insertBefore($span, $noeud);

            $curseur = $plage['fin'] + 1;
        }

        if ($curseur < \count($chars)) {
            $parent->insertBefore(
                $document->createTextNode(implode('', \array_slice($chars, $curseur))),
                $noeud
            );
        }

        $parent->removeChild($noeud);
    }

    /**
     * Enveloppe un segment de nœuds entier dans un <span> surligné.
     *
     * @param list<\Dom\Node> $segment
     * @param list<string>    $uids
     */
    private function envelopper(HTMLDocument $document, array $segment, array $uids): void
    {
        $premier = $segment[0];
        $span = $this->creerSpan($document, $uids);
        $premier->parentNode->insertBefore($span, $premier);
        foreach ($segment as $noeud) {
            $span->appendChild($noeud);
        }
    }

    /**
     * @param list<string> $uids
     */
    private function creerSpan(HTMLDocument $document, array $uids): Element
    {
        $span = $document->createElement('span');
        $span->className = 'alinea-amende';
        $span->setAttribute('data-amendements', implode(',', $uids));
        $span->setAttribute('data-nb', (string) \count($uids));

        return $span;
    }

    /**
     * @param list<\Dom\Node> $segment
     */
    private function texteSegment(array $segment): string
    {
        $texte = '';
        foreach ($segment as $noeud) {
            $texte .= $noeud->textContent;
        }

        return $texte;
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

    /**
     * Comme normaliser(), mais renvoie aussi la carte position normalisée →
     * position d'origine (en caractères), pour retrouver la portion exacte à
     * surligner dans le texte publié.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function normaliserAvecCarte(string $texte): array
    {
        $normalise = '';
        $carte = [];
        $espacePrecedent = true; // supprime les espaces de tête

        foreach (mb_str_split($texte) as $index => $char) {
            $bas = mb_strtolower($char);
            if (preg_match(self::CARACTERE_UTILE, $bas) === 1) {
                $normalise .= $bas;
                $carte[] = $index;
                $espacePrecedent = false;
            } elseif (!$espacePrecedent) {
                $normalise .= ' ';
                $carte[] = $index;
                $espacePrecedent = true;
            }
        }

        if (str_ends_with($normalise, ' ')) {
            $normalise = substr($normalise, 0, -1);
            array_pop($carte);
        }

        return [$normalise, $carte];
    }
}
