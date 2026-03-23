<?php

declare(strict_types=1);

/**
 * stream.php — Endpoint SSE pour l'analyse TF-IDF en streaming.
 *
 * Deux modes :
 *   - "auto" : scrape Google SERP pour trouver les concurrents
 *   - "manuel" : l'utilisateur fournit directement les URLs concurrentes
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// Désactiver tous les buffers de sortie
while (ob_get_level()) {
    ob_end_flush();
}

set_time_limit(300);
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/functions.php';

// ─── Vérification quota ─────────────────────────────────────────────────────

if (class_exists('\\Platform\\Module\\Quota')) {
    if (!\Platform\Module\Quota::creditsDisponibles('tfidf-analyzer')) {
        envoyerEvenement('error', [
            'message' => 'Crédits épuisés',
            'message_fr' => 'Crédits épuisés',
            'message_en' => 'Credits exhausted',
            'code' => 429,
        ]);
        exit;
    }
}

// ─── Validation des paramètres ───────────────────────────────────────────────

$urlCible    = trim($_GET['url_cible'] ?? '');
$motCle      = trim($_GET['mot_cle'] ?? '');
$nbResultats = (int)($_GET['nb_resultats'] ?? 50);
$mode        = trim($_GET['mode'] ?? 'auto'); // "auto" ou "manuel"
$urlsManuelles = trim($_GET['urls_manuelles'] ?? '');

if ($urlCible === '') {
    envoyerEvenement('error', [
        'message' => 'URL cible requise.',
        'message_fr' => 'URL cible requise.',
        'message_en' => 'Target URL is required.',
    ]);
    exit;
}

if (!filter_var($urlCible, FILTER_VALIDATE_URL)) {
    envoyerEvenement('error', [
        'message' => 'URL cible invalide.',
        'message_fr' => 'URL cible invalide.',
        'message_en' => 'Invalid target URL.',
    ]);
    exit;
}

if ($mode === 'auto' && $motCle === '') {
    envoyerEvenement('error', [
        'message' => 'Mot-clé requis en mode automatique.',
        'message_fr' => 'Mot-clé requis en mode automatique.',
        'message_en' => 'Keyword is required in automatic mode.',
    ]);
    exit;
}

if ($mode === 'manuel' && $urlsManuelles === '') {
    envoyerEvenement('error', [
        'message' => 'Aucune URL concurrente fournie.',
        'message_fr' => 'Aucune URL concurrente fournie.',
        'message_en' => 'No competitor URLs provided.',
    ]);
    exit;
}

$nbResultats = max(10, min(200, $nbResultats));

// ─── Vérification du cache ───────────────────────────────────────────────────

$cleIdentifiant = $mode === 'auto' ? $motCle : md5($urlsManuelles);
$cleCache = genererCleCache($urlCible, $cleIdentifiant);
$cache = lireCache($cleCache);

if ($cache !== null) {
    envoyerEvenement('log', [
        'message' => 'Résultats trouvés en cache (même jour).',
        'message_fr' => 'Résultats trouvés en cache (même jour).',
        'message_en' => 'Results found in cache (same day).',
        'percent' => 100,
    ]);
    envoyerEvenement('done', $cache);
    exit;
}

// Nettoyage des anciens caches (1 fois sur 10)
if (random_int(1, 10) === 1) {
    nettoyerCache();
}

// ─── Étape 1 : Récupération des URLs concurrentes ───────────────────────────

$urlsConcurrents = [];

if ($mode === 'manuel') {
    // Mode manuel : parser les URLs fournies par l'utilisateur
    $lignes = preg_split('/[\r\n]+/', $urlsManuelles, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($lignes as $ligne) {
        $ligne = trim($ligne);
        if ($ligne !== '' && filter_var($ligne, FILTER_VALIDATE_URL)) {
            $urlsConcurrents[] = $ligne;
        }
    }

    if (empty($urlsConcurrents)) {
        envoyerEvenement('error', [
            'message' => 'Aucune URL valide trouvée dans la liste.',
            'message_fr' => 'Aucune URL valide trouvée dans la liste.',
            'message_en' => 'No valid URL found in the list.',
        ]);
        exit;
    }

    $urlsConcurrents = array_slice($urlsConcurrents, 0, NB_CONCURRENTS);

    $nbUrlsManuelles = count($urlsConcurrents);
    envoyerEvenement('log', [
        'message' => $nbUrlsManuelles . ' URL(s) concurrente(s) fournies.',
        'message_fr' => $nbUrlsManuelles . ' URL(s) concurrente(s) fournies.',
        'message_en' => $nbUrlsManuelles . ' competitor URL(s) provided.',
        'percent' => 10,
    ]);
} else {
    // Mode auto : recherche Google via SerpAPI
    $cleApi = $_ENV['SERPAPI_KEY'] ?? getenv('SERPAPI_KEY') ?: '';
    if ($cleApi === '') {
        envoyerEvenement('error', [
            'message' => 'Clé API SerpAPI manquante. Configurez SERPAPI_KEY dans le .env de la plateforme.',
            'message_fr' => 'Clé API SerpAPI manquante. Configurez SERPAPI_KEY dans le .env de la plateforme.',
            'message_en' => 'SerpAPI key missing. Configure SERPAPI_KEY in the platform .env file.',
        ]);
        exit;
    }

    envoyerEvenement('log', [
        'message' => "Recherche Google pour « {$motCle} »…",
        'message_fr' => "Recherche Google pour « {$motCle} »…",
        'message_en' => "Google search for \"{$motCle}\"…",
        'percent' => 5,
    ]);

    $resultatSerp = recupererResultatsSERP($motCle);

    if ($resultatSerp['erreur'] !== '') {
        envoyerEvenement('error', [
            'message' => $resultatSerp['erreur'],
            'message_fr' => $resultatSerp['erreur'],
            'message_en' => $resultatSerp['erreur_en'] ?? $resultatSerp['erreur'],
        ]);
        exit;
    }

    $urlsConcurrents = $resultatSerp['urls'];

    if (empty($urlsConcurrents)) {
        envoyerEvenement('error', [
            'message' => 'Aucun résultat organique trouvé. Essayez le mode manuel.',
            'message_fr' => 'Aucun résultat organique trouvé. Essayez le mode manuel.',
            'message_en' => 'No organic results found. Try manual mode.',
        ]);
        exit;
    }

    $nbConcurrentsGoogle = count($urlsConcurrents);
    envoyerEvenement('log', [
        'message' => $nbConcurrentsGoogle . ' concurrent(s) trouvé(s) sur Google.',
        'message_fr' => $nbConcurrentsGoogle . ' concurrent(s) trouvé(s) sur Google.',
        'message_en' => $nbConcurrentsGoogle . ' competitor(s) found on Google.',
        'percent' => 10,
    ]);
}

// Exclure l'URL cible si elle apparaît dans les concurrents
$hoteCible = parse_url($urlCible, PHP_URL_HOST);
$urlsConcurrents = array_values(array_filter(
    $urlsConcurrents,
    fn(string $url): bool => parse_url($url, PHP_URL_HOST) !== $hoteCible
));

// ─── Étape 2 : Scraping des concurrents ─────────────────────────────────────

$contenusConcurrents = [];
$erreursScraping = 0;
$nbUrls = count($urlsConcurrents);

foreach ($urlsConcurrents as $idx => $urlConc) {
    $numPage = $idx + 1;
    $pctBase = 10;
    $pctFin = 75;
    $pct = (int)($pctBase + (($pctFin - $pctBase) * $numPage / $nbUrls));

    // Tronquer l'URL pour l'affichage
    $urlAffichee = mb_strlen($urlConc) > 60
        ? mb_substr($urlConc, 0, 57) . '…'
        : $urlConc;

    envoyerEvenement('log', [
        'message' => "Analyse concurrent {$numPage}/{$nbUrls} : {$urlAffichee}",
        'message_fr' => "Analyse concurrent {$numPage}/{$nbUrls} : {$urlAffichee}",
        'message_en' => "Analyzing competitor {$numPage}/{$nbUrls}: {$urlAffichee}",
        'percent' => $pct,
    ]);

    $contenu = scraperContenu($urlConc);
    $contenusConcurrents[] = $contenu;

    if ($contenu['erreur'] !== '') {
        $erreursScraping++;
    }

    // Pause pour ne pas surcharger les serveurs
    usleep(500000); // 500ms
}

if ($erreursScraping > 0) {
    envoyerEvenement('log', [
        'message' => "{$erreursScraping} page(s) en erreur (ignorées).",
        'message_fr' => "{$erreursScraping} page(s) en erreur (ignorées).",
        'message_en' => "{$erreursScraping} page(s) with errors (skipped).",
        'percent' => 76,
    ]);
}

// ─── Étape 3 : Scraping de la page cible ────────────────────────────────────

envoyerEvenement('log', [
    'message' => 'Analyse de la page cible…',
    'message_fr' => 'Analyse de la page cible…',
    'message_en' => 'Analyzing the target page…',
    'percent' => 80,
]);

$contenuCible = scraperContenu($urlCible);

if ($contenuCible['erreur'] !== '') {
    envoyerEvenement('error', [
        'message' => "Impossible de récupérer la page cible : {$contenuCible['erreur']}",
        'message_fr' => "Impossible de récupérer la page cible : {$contenuCible['erreur']}",
        'message_en' => "Unable to fetch the target page: {$contenuCible['erreur']}",
    ]);
    exit;
}

if ($contenuCible['texte_complet'] === '') {
    envoyerEvenement('error', [
        'message' => 'La page cible ne contient aucun texte exploitable.',
        'message_fr' => 'La page cible ne contient aucun texte exploitable.',
        'message_en' => 'The target page contains no usable text.',
    ]);
    exit;
}

// ─── Étape 4 : Calcul TF-IDF et gap sémantique ─────────────────────────────

envoyerEvenement('log', [
    'message' => 'Calcul TF-IDF et analyse du gap sémantique…',
    'message_fr' => 'Calcul TF-IDF et analyse du gap sémantique…',
    'message_en' => 'Computing TF-IDF and semantic gap analysis…',
    'percent' => 90,
]);

$resultats = analyserGapSemantique($contenusConcurrents, $contenuCible, $nbResultats);
$resultats['mot_cle'] = $motCle;
$resultats['mode'] = $mode;
$resultats['urls_concurrents'] = $urlsConcurrents;

if (!empty($resultats['erreur'])) {
    envoyerEvenement('error', [
        'message' => $resultats['erreur'],
        'message_fr' => $resultats['erreur'],
        'message_en' => $resultats['erreur_en'] ?? $resultats['erreur'],
    ]);
    exit;
}

// ─── Étape 5 : Mise en cache et envoi des résultats ─────────────────────────

envoyerEvenement('log', [
    'message' => 'Analyse terminée.',
    'message_fr' => 'Analyse terminée.',
    'message_en' => 'Analysis complete.',
    'percent' => 100,
]);

ecrireCache($cleCache, $resultats);

// Décompter les crédits
if (class_exists(\Platform\Module\Quota::class)) {
    \Platform\Module\Quota::track('tfidf-analyzer');
}

envoyerEvenement('done', $resultats);
