-- Résumés générés par IA (agent externe).
--
-- Stockés dans un schéma applicatif dédié « alinea », séparé des schémas
-- open data (legifrance, assemblee…) qui sont en lecture seule et rechargés
-- depuis les dumps. Une même table couvre deux cibles polymorphes :
--   * la synthèse globale d'une loi   → type_cible = 'loi',        cible_id = id JORFTEXT… (legifrance.texte_version.id)
--   * le résumé d'un amendement       → type_cible = 'amendement', cible_id = uid AMANR5… (assemblee.amendements.uid)
--
-- Idempotent : réexécutable sans risque.

CREATE SCHEMA IF NOT EXISTS alinea;

CREATE TABLE IF NOT EXISTS alinea.resume_ia (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type_cible  text        NOT NULL CHECK (type_cible IN ('loi', 'amendement')),
    cible_id    text        NOT NULL,
    resume      text        NOT NULL,
    donnees     jsonb,      -- champs structurés optionnels (points clés, thèmes…)
    modele      text,       -- modèle ayant produit le résumé (ex. claude-haiku-4-5)
    genere_le   timestamptz NOT NULL DEFAULT now(),
    maj_le      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (type_cible, cible_id)   -- une cible = un résumé (permet l'upsert)
);

-- Analyse détaillée d'un amendement (adopté/rejeté) produite par l'IA.
-- Le cœur de l'analyse est l'INTENTION derrière les changements :
--   resume          → ce que l'amendement change concrètement (< 120 caractères)
--   resume_detaille → développement (enjeux, débat, portée), si pertinent
--   intention       → l'objectif réellement poursuivi par l'auteur
--   ambiguite       → 0-100 : écart entre l'objectif affiché et l'effet réel
--                     (ex. présenté comme rédactionnel mais à portée réelle)
--   categorie       → nature de l'amendement (coordination, rédactionnel, …, fond)
--   score_impact    → importance du changement, de 0 à 100 (module la mise en avant)
ALTER TABLE alinea.resume_ia ADD COLUMN IF NOT EXISTS resume_detaille text;
ALTER TABLE alinea.resume_ia ADD COLUMN IF NOT EXISTS intention       text;
ALTER TABLE alinea.resume_ia ADD COLUMN IF NOT EXISTS ambiguite       smallint
    CHECK (ambiguite BETWEEN 0 AND 100);
ALTER TABLE alinea.resume_ia ADD COLUMN IF NOT EXISTS categorie       text;
ALTER TABLE alinea.resume_ia ADD COLUMN IF NOT EXISTS score_impact    smallint
    CHECK (score_impact BETWEEN 0 AND 100);

COMMENT ON TABLE  alinea.resume_ia                 IS 'Résumés IA : synthèse d''une loi ou résumé d''un amendement.';
COMMENT ON COLUMN alinea.resume_ia.type_cible      IS 'Nature de la cible : loi | amendement.';
COMMENT ON COLUMN alinea.resume_ia.cible_id        IS 'Identifiant de la cible : id JORFTEXT (loi) ou uid AMANR5 (amendement).';
COMMENT ON COLUMN alinea.resume_ia.resume          IS 'Ce que l''amendement change concrètement (< 120 caractères) — ou résumé de la loi.';
COMMENT ON COLUMN alinea.resume_ia.resume_detaille IS 'Résumé détaillé, si pertinent.';
COMMENT ON COLUMN alinea.resume_ia.intention       IS 'Intention réellement poursuivie par l''auteur de l''amendement.';
COMMENT ON COLUMN alinea.resume_ia.ambiguite       IS 'Écart objectif affiché / effet réel, de 0 (clair) à 100 (très ambigu).';
COMMENT ON COLUMN alinea.resume_ia.categorie       IS 'Catégorie : coordination | redactionnel | simplification | correction | precision | consequence | coherence | fond.';
COMMENT ON COLUMN alinea.resume_ia.score_impact    IS 'Score d''impact de 0 (technique) à 100 (majeur).';
COMMENT ON COLUMN alinea.resume_ia.donnees         IS 'Données structurées optionnelles (JSON).';
COMMENT ON COLUMN alinea.resume_ia.modele          IS 'Modèle ayant généré le résumé.';
