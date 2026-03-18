<?php

/**
 * boot.php — Chargement avant le point d'entrée.
 * Propagation de la clé SerpAPI pour la récupération SERP.
 */

// Propagation des clés API de la plateforme.
// La plateforme peut injecter via putenv() ou $_ENV selon la config PHP.
// On s'assure que la clé est disponible dans les deux.
foreach (['SERPAPI_KEY'] as $cleEnv) {
    $valeur = $_ENV[$cleEnv] ?? getenv($cleEnv) ?: '';
    if ($valeur !== '') {
        putenv("{$cleEnv}={$valeur}");
        $_ENV[$cleEnv] = $valeur;
    }
}
