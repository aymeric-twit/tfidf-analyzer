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
        envoyerEvenement('error', ['message' => 'Crédits épuisés', 'code' => 429]);
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
    envoyerEvenement('error', ['message' => 'URL cible requise.']);
    exit;
}

if (!filter_var($urlCible, FILTER_VALIDATE_URL)) {
    envoyerEvenement('error', ['message' => 'URL cible invalide.']);
    exit;
}

if ($mode === 'auto' && $motCle === '') {
    envoyerEvenement('error', ['message' => 'Mot-clé requis en mode automatique.']);
    exit;
}

if ($mode === 'manuel' && $urlsManuelles === '') {
    envoyerEvenement('error', ['message' => 'Aucune URL concurrente fournie.']);
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
        envoyerEvenement('error', ['message' => 'Aucune URL valide trouvée dans la liste.']);
        exit;
    }

    $urlsConcurrents = array_slice($urlsConcurrents, 0, NB_CONCURRENTS);

    envoyerEvenement('log', [
        'message' => count($urlsConcurrents) . ' URL(s) concurrente(s) fournies.',
        'percent' => 10,
    ]);
} else {
    // Mode auto : recherche Google via SerpAPI
    $cleApi = $_ENV['SERPAPI_KEY'] ?? getenv('SERPAPI_KEY') ?: '';
    if ($cleApi === '') {
        envoyerEvenement('error', [
            'message' => 'Clé API SerpAPI manquante. Configurez SERPAPI_KEY dans le .env de la plateforme.',
        ]);
        exit;
    }

    envoyerEvenement('log', [
        'message' => "Recherche Google pour « {$motCle} »…",
        'percent' => 5,
    ]);

    $resultatSerp = recupererResultatsSERP($motCle);

    if ($resultatSerp['erreur'] !== '') {
        envoyerEvenement('error', [
            'message' => $resultatSerp['erreur'],
        ]);
        exit;
    }

    $urlsConcurrents = $resultatSerp['urls'];

    if (empty($urlsConcurrents)) {
        envoyerEvenement('error', [
            'message' => 'Aucun résultat organique trouvé. Essayez le mode manuel.',
        ]);
        exit;
    }

    envoyerEvenement('log', [
        'message' => count($urlsConcurrents) . ' concurrent(s) trouvé(s) sur Google.',
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
        'percent' => 76,
    ]);
}

// ─── Étape 3 : Scraping de la page cible ────────────────────────────────────

envoyerEvenement('log', [
    'message' => 'Analyse de la page cible…',
    'percent' => 80,
]);

$contenuCible = scraperContenu($urlCible);

if ($contenuCible['erreur'] !== '') {
    envoyerEvenement('error', [
        'message' => "Impossible de récupérer la page cible : {$contenuCible['erreur']}",
    ]);
    exit;
}

if ($contenuCible['texte_complet'] === '') {
    envoyerEvenement('error', [
        'message' => 'La page cible ne contient aucun texte exploitable.',
    ]);
    exit;
}

// ─── Étape 4 : Calcul TF-IDF et gap sémantique ─────────────────────────────

envoyerEvenement('log', [
    'message' => 'Calcul TF-IDF et analyse du gap sémantique…',
    'percent' => 90,
]);

$resultats = analyserGapSemantique($contenusConcurrents, $contenuCible, $nbResultats);
$resultats['mot_cle'] = $motCle;
$resultats['mode'] = $mode;
$resultats['urls_concurrents'] = $urlsConcurrents;

if (!empty($resultats['erreur'])) {
    envoyerEvenement('error', ['message' => $resultats['erreur']]);
    exit;
}

// ─── Étape 5 : Mise en cache et envoi des résultats ─────────────────────────

envoyerEvenement('log', [
    'message' => 'Analyse terminée.',
    'percent' => 100,
]);

ecrireCache($cleCache, $resultats);

// Décompter les crédits
if (class_exists(\Platform\Module\Quota::class)) {
    \Platform\Module\Quota::track('tfidf-analyzer');
}

envoyerEvenement('done', $resultats);
