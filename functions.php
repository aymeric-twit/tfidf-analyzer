<?php

declare(strict_types=1);

/**
 * functions.php — Logique métier du plugin TF-IDF Analyzer.
 *
 * Scraping, tokenisation, calcul TF-IDF, analyse du gap sémantique.
 */

// ─── Configuration ───────────────────────────────────────────────────────────

const TAILLE_MAX_PAGE = 512000; // 500 Ko
const TIMEOUT_REQUETE = 15;     // secondes
const NB_CONCURRENTS  = 10;
const LONGUEUR_MIN_TERME = 3;
const DOSSIER_CACHE   = __DIR__ . '/data';

// Paramètres BM25 (Okapi BM25)
const BM25_K1 = 1.2;   // Saturation de la fréquence du terme
const BM25_B  = 0.75;  // Normalisation par la longueur du document

// Bonus de zone (additifs, en fraction du score BM25 de base)
// Score final = BM25_base × (1 + Σ bonus applicables)
const BONUS_ZONES = [
    'titre'            => 1.00,  // +100% — balise <title>
    'h1'               => 0.80,  // +80%  — balise <h1>
    'mots_url'         => 0.50,  // +50%  — mots dans le chemin URL
    'meta_description' => 0.40,  // +40%  — meta description
    'h2'               => 0.30,  // +30%  — balise <h2>
    'premiers_mots'    => 0.25,  // +25%  — 100 premiers mots du body
    'h3'               => 0.20,  // +20%  — balise <h3>
    'ancres_internes'  => 0.20,  // +20%  — ancres des liens internes
    'strong'           => 0.15,  // +15%  — <strong>, <b>, <em>
    'alt_images'       => 0.15,  // +15%  — attribut alt des images
];

/**
 * Liste des mots vides français (stop words).
 * Filtrés lors de la tokenisation pour ne garder que les termes significatifs.
 */
const MOTS_VIDES_FR = [
    'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'au', 'aux',
    'ce', 'ces', 'cet', 'cette', 'mon', 'ton', 'son', 'ma', 'ta', 'sa',
    'mes', 'tes', 'ses', 'nos', 'vos', 'leur', 'leurs', 'quel', 'quelle',
    'quels', 'quelles', 'que', 'qui', 'quoi', 'dont', 'ou', 'où',
    'je', 'tu', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'elles',
    'me', 'te', 'se', 'lui', 'eux',
    'et', 'ou', 'mais', 'donc', 'car', 'ni', 'or', 'puis',
    'dans', 'sur', 'sous', 'avec', 'sans', 'pour', 'par', 'en', 'vers',
    'chez', 'entre', 'contre', 'depuis', 'pendant', 'avant', 'après',
    'est', 'sont', 'était', 'être', 'avoir', 'fait', 'faire', 'peut',
    'pas', 'plus', 'moins', 'très', 'bien', 'aussi', 'tout', 'tous',
    'toute', 'toutes', 'même', 'autre', 'autres', 'comme', 'si',
    'ne', 'ni', 'non', 'oui',
    'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
    'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
    'could', 'should', 'may', 'might', 'shall', 'can',
    'not', 'no', 'nor', 'and', 'but', 'or', 'so', 'if', 'then',
    'than', 'too', 'very', 'just', 'about', 'above', 'after', 'again',
    'all', 'also', 'an', 'any', 'at', 'by', 'each', 'for', 'from',
    'get', 'got', 'he', 'her', 'here', 'him', 'his', 'how',
    'in', 'into', 'it', 'its', 'let', 'me', 'more', 'my',
    'of', 'off', 'on', 'one', 'only', 'our', 'out', 'own',
    'said', 'she', 'some', 'still', 'such', 'that', 'their', 'them',
    'there', 'these', 'they', 'this', 'those', 'through', 'to', 'up',
    'us', 'use', 'was', 'we', 'what', 'when', 'which', 'who',
    'why', 'with', 'you', 'your',
];

// ─── Envoi d'événements SSE ──────────────────────────────────────────────────

/**
 * Envoie un événement SSE au client.
 *
 * @param string $type Type d'événement (log, done, error)
 * @param array<string, mixed> $donnees Données JSON
 */
function envoyerEvenement(string $type, array $donnees): void
{
    echo "event: {$type}\ndata: " . json_encode($donnees, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();

    if (connection_aborted()) {
        exit;
    }
}

// ─── Cache ───────────────────────────────────────────────────────────────────

/**
 * Génère une clé de cache unique pour un couple URL/mot-clé.
 */
function genererCleCache(string $urlCible, string $motCle): string
{
    $jour = date('Y-m-d');
    return md5("{$urlCible}|{$motCle}|{$jour}");
}

/**
 * Lit le cache pour une clé donnée. Retourne null si absent ou expiré.
 *
 * @return array<string, mixed>|null
 */
function lireCache(string $cle): ?array
{
    $chemin = DOSSIER_CACHE . "/{$cle}.json";

    if (!file_exists($chemin)) {
        return null;
    }

    // Expiration : même jour uniquement
    if (date('Y-m-d', filemtime($chemin)) !== date('Y-m-d')) {
        @unlink($chemin);
        return null;
    }

    $contenu = file_get_contents($chemin);
    if ($contenu === false) {
        return null;
    }

    $donnees = json_decode($contenu, true);
    return is_array($donnees) ? $donnees : null;
}

/**
 * Écrit les résultats dans le cache.
 *
 * @param array<string, mixed> $donnees
 */
function ecrireCache(string $cle, array $donnees): void
{
    if (!is_dir(DOSSIER_CACHE)) {
        mkdir(DOSSIER_CACHE, 0755, true);
    }

    $chemin = DOSSIER_CACHE . "/{$cle}.json";
    $cheminTemp = $chemin . '.tmp';
    file_put_contents($cheminTemp, json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($cheminTemp, $chemin);
}

/**
 * Nettoie les fichiers de cache de plus de 24h.
 */
function nettoyerCache(): void
{
    if (!is_dir(DOSSIER_CACHE)) {
        return;
    }

    $limite = time() - 86400;
    foreach (new DirectoryIterator(DOSSIER_CACHE) as $fichier) {
        if ($fichier->isDot() || !$fichier->isFile()) {
            continue;
        }
        if ($fichier->getExtension() !== 'json') {
            continue;
        }
        if ($fichier->getMTime() < $limite) {
            @unlink($fichier->getPathname());
        }
    }
}

// ─── Requêtes HTTP ───────────────────────────────────────────────────────────

/**
 * Effectue une requête HTTP GET via cURL.
 *
 * @return array{code: int, body: string, erreur: string}
 */
function requeteHttp(string $url): array
{
    // Mode plateforme : client HTTP centralise (WebClient pour le crawl de pages)
    if (defined('PLATFORM_EMBEDDED') && class_exists('\\Platform\\Http\\WebClient')) {
        $webClient = new \Platform\Http\WebClient('tfidf-analyzer');
        $reponse = $webClient->fetch($url);
        return [
            'code'   => $reponse->statusCode,
            'body'   => $reponse->body,
            'erreur' => $reponse->estSucces() ? '' : 'HTTP ' . $reponse->statusCode,
        ];
    }

    // Mode standalone : curl natif
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => TIMEOUT_REQUETE,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
        ],
        CURLOPT_ENCODING       => '',
        // Limite à 500 Ko
        CURLOPT_BUFFERSIZE     => 8192,
    ]);

    // Limiter la taille du téléchargement via callback
    $contenuRecu = '';
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $donnees) use (&$contenuRecu): int {
        $contenuRecu .= $donnees;
        if (strlen($contenuRecu) > TAILLE_MAX_PAGE) {
            return 0; // Arrêter le téléchargement
        }
        return strlen($donnees);
    });

    curl_exec($ch);
    $codeHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);

    return [
        'code'   => $codeHttp,
        'body'   => $contenuRecu,
        'erreur' => $erreur,
    ];
}

/**
 * Effectue une requête HTTP GET vers une API JSON (sans headers navigateur).
 *
 * @return array{code: int, body: string, erreur: string}
 */
function requeteApi(string $url): array
{
    // Mode plateforme : client HTTP centralise (ApiClient pour les API JSON)
    if (defined('PLATFORM_EMBEDDED') && class_exists('\\Platform\\Http\\ApiClient')) {
        $apiClient = new \Platform\Http\ApiClient('tfidf-analyzer');
        $reponse = $apiClient->get($url, [], ['Accept' => 'application/json']);
        return [
            'code'   => $reponse->statusCode,
            'body'   => $reponse->body,
            'erreur' => $reponse->estSucces() ? '' : 'HTTP ' . $reponse->statusCode,
        ];
    }

    // Mode standalone : curl natif
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => TIMEOUT_REQUETE,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
        ],
        CURLOPT_ENCODING       => '',
    ]);

    $body = curl_exec($ch);
    $codeHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);

    return [
        'code'   => $codeHttp,
        'body'   => is_string($body) ? $body : '',
        'erreur' => $erreur,
    ];
}

// ─── Récupération SERP (SerpAPI — Google) ────────────────────────────────────

/**
 * Récupère les résultats organiques Google via SerpAPI.
 * Nécessite la variable d'environnement SERPAPI_KEY.
 *
 * @return array{urls: string[], erreur: string}
 */
function recupererResultatsSERP(string $motCle): array
{
    $cleApi = $_ENV['SERPAPI_KEY'] ?? getenv('SERPAPI_KEY') ?: '';
    if ($cleApi === '') {
        return ['urls' => [], 'erreur' => 'Clé API SerpAPI manquante. Configurez SERPAPI_KEY dans le .env de la plateforme.'];
    }

    $url = 'https://serpapi.com/search.json?' . http_build_query([
        'q'      => $motCle,
        'num'    => NB_CONCURRENTS + 5,
        'gl'     => 'fr',
        'hl'     => 'fr',
        'engine' => 'google',
        'api_key' => $cleApi,
    ]);

    $reponse = requeteApi($url);

    if ($reponse['erreur'] !== '') {
        return ['urls' => [], 'erreur' => "Erreur SerpAPI : {$reponse['erreur']}"];
    }

    if ($reponse['code'] === 401 || $reponse['code'] === 403) {
        return ['urls' => [], 'erreur' => 'Clé API SerpAPI invalide ou expirée.'];
    }

    if ($reponse['code'] === 429) {
        return ['urls' => [], 'erreur' => 'Quota SerpAPI épuisé. Réessayez plus tard ou utilisez le mode manuel.'];
    }

    if ($reponse['code'] !== 200 || $reponse['body'] === '') {
        return ['urls' => [], 'erreur' => "Réponse SerpAPI inattendue (HTTP {$reponse['code']})."];
    }

    $donnees = json_decode($reponse['body'], true);
    if (!is_array($donnees)) {
        return ['urls' => [], 'erreur' => 'Réponse SerpAPI invalide (JSON malformé).'];
    }

    if (isset($donnees['error'])) {
        return ['urls' => [], 'erreur' => "Erreur SerpAPI : {$donnees['error']}"];
    }

    $urls = extraireUrlsSerpApi($donnees);

    if (empty($urls)) {
        $nbOrganiques = count($donnees['organic_results'] ?? []);
        if ($nbOrganiques === 0) {
            // Vérifier si la réponse contient des clés attendues
            $clesPresentes = implode(', ', array_keys($donnees));
            return ['urls' => [], 'erreur' => "SerpAPI n'a retourné aucun résultat organique. Clés reçues : {$clesPresentes}"];
        }
        return ['urls' => [], 'erreur' => "{$nbOrganiques} résultat(s) organique(s) trouvé(s) mais tous filtrés (domaines exclus)."];
    }

    return ['urls' => $urls, 'erreur' => ''];
}

/**
 * Extrait les URLs organiques depuis la réponse JSON de SerpAPI.
 *
 * @param array<string, mixed> $donnees Réponse JSON décodée de SerpAPI
 * @return string[]
 */
function extraireUrlsSerpApi(array $donnees): array
{
    $urls = [];

    $resultatsOrganiques = $donnees['organic_results'] ?? [];

    foreach ($resultatsOrganiques as $resultat) {
        $url = $resultat['link'] ?? '';
        if ($url !== '' && estUrlOrganique($url)) {
            $urls[] = $url;
        }
    }

    return array_slice($urls, 0, NB_CONCURRENTS);
}

/**
 * Vérifie qu'une URL est un résultat organique (pas un lien Google/réseaux sociaux).
 */
function estUrlOrganique(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $hote = parse_url($url, PHP_URL_HOST);
    if (!$hote) {
        return false;
    }

    // Exclure les domaines Google et autres non-organiques
    $exclusions = [
        'google.com', 'google.fr', 'gstatic.com', 'googleapis.com',
        'youtube.com', 'youtu.be',
        'schema.org', 'w3.org',
        'facebook.com', 'twitter.com', 'instagram.com',
    ];

    foreach ($exclusions as $domaine) {
        if (str_contains($hote, $domaine)) {
            return false;
        }
    }

    return true;
}

// ─── Scraping du contenu ─────────────────────────────────────────────────────

/**
 * Scrape et extrait le contenu structuré d'une page web.
 * Extrait 10 zones sémantiques pour le calcul BM25 avec bonus de zones.
 *
 * @return array{
 *     url: string,
 *     titre: string,
 *     meta_description: string,
 *     h1: string[],
 *     h2: string[],
 *     h3: string[],
 *     strong: string[],
 *     alt_images: string[],
 *     ancres_internes: string[],
 *     mots_url: string,
 *     premiers_mots: string,
 *     paragraphes: string[],
 *     listes: string[],
 *     texte_complet: string,
 *     nb_mots: int,
 *     erreur: string
 * }
 */
function scraperContenu(string $url): array
{
    $resultat = [
        'url'              => $url,
        'titre'            => '',
        'meta_description' => '',
        'h1'               => [],
        'h2'               => [],
        'h3'               => [],
        'strong'           => [],
        'alt_images'       => [],
        'ancres_internes'  => [],
        'mots_url'         => '',
        'premiers_mots'    => '',
        'paragraphes'      => [],
        'listes'           => [],
        'texte_complet'    => '',
        'nb_mots'          => 0,
        'erreur'           => '',
    ];

    $reponse = requeteHttp($url);

    if ($reponse['erreur'] !== '') {
        $resultat['erreur'] = $reponse['erreur'];
        return $resultat;
    }

    if ($reponse['code'] < 200 || $reponse['code'] >= 400) {
        $resultat['erreur'] = "HTTP {$reponse['code']}";
        return $resultat;
    }

    $html = $reponse['body'];

    // Supprimer les éléments non pertinents avant le parsing
    $html = preg_replace('#<script[^>]*>.*?</script>#si', '', $html);
    $html = preg_replace('#<style[^>]*>.*?</style>#si', '', $html);
    $html = preg_replace('#<noscript[^>]*>.*?</noscript>#si', '', $html);
    $html = preg_replace('#<!--.*?-->#s', '', $html);

    // Parser le DOM
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();

    // Détection de l'encodage
    $encodage = 'UTF-8';
    if (preg_match('#charset=([^\s";]+)#i', $html, $matches)) {
        $encodage = strtoupper(trim($matches[1]));
    }
    if ($encodage !== 'UTF-8') {
        $html = mb_convert_encoding($html, 'UTF-8', $encodage);
    }

    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // ── Extraire meta description et titre AVANT suppression des noeuds ──

    $metaNodes = $xpath->query('//meta[@name="description"]/@content');
    if ($metaNodes !== false && $metaNodes->length > 0) {
        $resultat['meta_description'] = nettoyerTexte($metaNodes->item(0)->nodeValue);
    }

    $titres = $dom->getElementsByTagName('title');
    if ($titres->length > 0) {
        $resultat['titre'] = nettoyerTexte($titres->item(0)->textContent);
    }

    // ── Extraire les mots de l'URL ──

    $cheminUrl = parse_url($url, PHP_URL_PATH) ?? '';
    $motsUrl = preg_replace('/[\/\-_\.]+/', ' ', $cheminUrl);
    $motsUrl = preg_replace('/\.(html?|php|aspx?)$/i', '', $motsUrl);
    $resultat['mots_url'] = nettoyerTexte($motsUrl);

    // ── Supprimer les éléments de navigation, footer, pub ──

    $selecteursASupprimer = [
        '//nav', '//header', '//footer',
        '//*[contains(@class, "nav")]',
        '//*[contains(@class, "menu")]',
        '//*[contains(@class, "sidebar")]',
        '//*[contains(@class, "footer")]',
        '//*[contains(@class, "ad")]',
        '//*[contains(@class, "advertisement")]',
        '//*[contains(@class, "cookie")]',
        '//*[contains(@class, "popup")]',
        '//*[contains(@class, "modal")]',
        '//*[contains(@id, "cookie")]',
        '//*[contains(@id, "footer")]',
        '//*[contains(@role, "navigation")]',
        '//*[contains(@role, "banner")]',
        '//*[contains(@role, "contentinfo")]',
    ];

    foreach ($selecteursASupprimer as $selecteur) {
        $noeuds = $xpath->query($selecteur);
        if ($noeuds !== false) {
            foreach ($noeuds as $noeud) {
                $noeud->parentNode?->removeChild($noeud);
            }
        }
    }

    // ── Extraire les headings ──

    foreach (['h1', 'h2', 'h3'] as $balise) {
        $elements = $dom->getElementsByTagName($balise);
        foreach ($elements as $element) {
            $texte = nettoyerTexte($element->textContent);
            if ($texte !== '') {
                $resultat[$balise][] = $texte;
            }
        }
    }

    // ── Extraire le texte en gras/emphase ──

    foreach (['strong', 'b', 'em'] as $balise) {
        $elements = $dom->getElementsByTagName($balise);
        foreach ($elements as $element) {
            $texte = nettoyerTexte($element->textContent);
            if ($texte !== '' && mb_strlen($texte) > 1) {
                $resultat['strong'][] = $texte;
            }
        }
    }

    // ── Extraire les alt text des images ──

    $images = $dom->getElementsByTagName('img');
    foreach ($images as $img) {
        $alt = $img->getAttribute('alt');
        $alt = nettoyerTexte($alt);
        if ($alt !== '' && mb_strlen($alt) > 2) {
            $resultat['alt_images'][] = $alt;
        }
    }

    // ── Extraire les ancres des liens internes ──

    $hotePage = parse_url($url, PHP_URL_HOST);
    $liens = $dom->getElementsByTagName('a');
    foreach ($liens as $lien) {
        $href = $lien->getAttribute('href');
        if ($href === '' || str_starts_with($href, '#')) {
            continue;
        }
        $hoteHref = parse_url($href, PHP_URL_HOST);
        // Lien interne = URL relative (host null) ou même domaine
        $estInterne = ($hoteHref === null || $hoteHref === false || $hoteHref === $hotePage);
        if ($estInterne) {
            $texteAncre = nettoyerTexte($lien->textContent);
            if ($texteAncre !== '' && mb_strlen($texteAncre) > 1) {
                $resultat['ancres_internes'][] = $texteAncre;
            }
        }
    }

    // ── Extraire les paragraphes ──

    $paragraphes = $dom->getElementsByTagName('p');
    foreach ($paragraphes as $p) {
        $texte = nettoyerTexte($p->textContent);
        if (mb_strlen($texte) > 10) {
            $resultat['paragraphes'][] = $texte;
        }
    }

    // ── Extraire les listes ──

    foreach (['li', 'dd'] as $balise) {
        $elements = $dom->getElementsByTagName($balise);
        foreach ($elements as $element) {
            $texte = nettoyerTexte($element->textContent);
            if (mb_strlen($texte) > 5) {
                $resultat['listes'][] = $texte;
            }
        }
    }

    // ── Premiers 100 mots du body (intro) ──

    $texteBody = implode(' ', array_merge($resultat['paragraphes'], $resultat['listes']));
    $motsSplit = preg_split('/\s+/', trim($texteBody), 101, PREG_SPLIT_NO_EMPTY);
    $resultat['premiers_mots'] = implode(' ', array_slice($motsSplit, 0, 100));

    // ── Construire le texte complet (toutes les zones sauf premiers_mots pour éviter le double-comptage) ──

    $parties = array_merge(
        [$resultat['titre']],
        [$resultat['meta_description']],
        $resultat['h1'],
        $resultat['h2'],
        $resultat['h3'],
        $resultat['strong'],
        $resultat['alt_images'],
        $resultat['ancres_internes'],
        [$resultat['mots_url']],
        $resultat['paragraphes'],
        $resultat['listes']
    );
    $resultat['texte_complet'] = implode(' ', array_filter($parties));
    $resultat['nb_mots'] = count(preg_split('/\s+/', $resultat['texte_complet'], -1, PREG_SPLIT_NO_EMPTY));

    return $resultat;
}

/**
 * Nettoie une chaîne de texte extraite du DOM.
 */
function nettoyerTexte(string $texte): string
{
    $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texte = preg_replace('/\s+/', ' ', $texte);
    return trim($texte);
}

// ─── Tokenisation ────────────────────────────────────────────────────────────

/**
 * Tokenise un texte en termes normalisés, filtrés des mots vides.
 *
 * @return string[]
 */
function tokeniser(string $texte): array
{
    // Minuscules
    $texte = mb_strtolower($texte, 'UTF-8');

    // Supprimer la ponctuation (garder les lettres accentuées, chiffres, espaces)
    $texte = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $texte);

    // Découper en mots
    $mots = preg_split('/\s+/', $texte, -1, PREG_SPLIT_NO_EMPTY);

    // Filtrer
    $motsCles = [];
    $motsVidesSet = array_flip(MOTS_VIDES_FR);

    foreach ($mots as $mot) {
        if (mb_strlen($mot) < LONGUEUR_MIN_TERME) {
            continue;
        }
        if (isset($motsVidesSet[$mot])) {
            continue;
        }
        // Ignorer les mots purement numériques
        if (preg_match('/^\d+$/', $mot)) {
            continue;
        }
        $motsCles[] = $mot;
    }

    return $motsCles;
}

/**
 * Extrait les bigrammes (paires de mots consécutifs) d'un texte tokenisé.
 *
 * @param string[] $tokens
 * @return string[]
 */
function extraireBigrammes(array $tokens): array
{
    $bigrammes = [];
    $nbTokens = count($tokens);

    for ($i = 0; $i < $nbTokens - 1; $i++) {
        $bigrammes[] = $tokens[$i] . ' ' . $tokens[$i + 1];
    }

    return $bigrammes;
}

// ─── Calcul BM25 (Okapi BM25) ───────────────────────────────────────────────

/**
 * Compte les occurrences brutes de chaque terme dans un tableau de tokens.
 * BM25 utilise les comptes bruts, pas les fréquences normalisées.
 *
 * @param string[] $tokens
 * @return array<string, int>
 */
function compterOccurrences(array $tokens): array
{
    if (empty($tokens)) {
        return [];
    }

    return array_count_values($tokens);
}

/**
 * Calcule l'IDF Robertson pour le corpus (formule BM25).
 * IDF(t) = ln((N - df(t) + 0.5) / (df(t) + 0.5) + 1)
 *
 * Les termes trop communs (présents dans la majorité des documents)
 * obtiennent un IDF faible ou nul, ce qui les élimine naturellement.
 *
 * @param array<int, array<string, int>> $corpusOccurrences Occurrences par document
 * @return array<string, float>
 */
function calculerIDFRobertson(array $corpusOccurrences): array
{
    $nbDocuments = count($corpusOccurrences);
    if ($nbDocuments === 0) {
        return [];
    }

    // Compter le document frequency pour chaque terme
    $df = [];
    foreach ($corpusOccurrences as $occurrences) {
        foreach (array_keys($occurrences) as $terme) {
            $df[$terme] = ($df[$terme] ?? 0) + 1;
        }
    }

    // IDF Robertson
    $idf = [];
    foreach ($df as $terme => $frequence) {
        $idf[$terme] = log(($nbDocuments - $frequence + 0.5) / ($frequence + 0.5) + 1);
    }

    return $idf;
}

/**
 * Calcule le score BM25 de chaque terme pour un document.
 *
 * BM25(t, d) = IDF(t) × (tf × (k1 + 1)) / (tf + k1 × (1 - b + b × (|d| / avgdl)))
 *
 * - k1 contrôle la saturation : un terme répété 50× n'est pas 50× plus pertinent
 * - b contrôle la normalisation par longueur : pénalise les documents très longs
 *
 * @param array<string, int> $occurrences Comptes bruts des termes dans le document
 * @param int $longueurDocument Nombre de tokens dans le document
 * @param float $longueurMoyenne Longueur moyenne des documents du corpus (avgdl)
 * @param array<string, float> $idf Scores IDF Robertson pré-calculés
 * @return array<string, float>
 */
function calculerBM25(
    array $occurrences,
    int $longueurDocument,
    float $longueurMoyenne,
    array $idf
): array {
    $scores = [];

    if ($longueurMoyenne <= 0) {
        $longueurMoyenne = 1.0;
    }

    foreach ($occurrences as $terme => $tf) {
        $scoreIDF = $idf[$terme] ?? 0.0;
        if ($scoreIDF <= 0) {
            continue; // Terme trop commun, IDF négatif ou nul
        }

        $numerateur = $tf * (BM25_K1 + 1);
        $denominateur = $tf + BM25_K1 * (1 - BM25_B + BM25_B * ($longueurDocument / $longueurMoyenne));
        $scores[$terme] = $scoreIDF * ($numerateur / $denominateur);
    }

    return $scores;
}

/**
 * Applique le bonus de zone au score BM25 de base.
 * Les bonus sont additifs : un terme dans title (+100%) ET h2 (+30%) donne BM25 × 2.3.
 *
 * Score final = BM25_base × (1 + Σ bonus des zones où le terme est présent)
 *
 * @param array<string, float> $scoresBM25
 * @param array<string, mixed> $contenu Structure retournée par scraperContenu()
 * @return array<string, float>
 */
function appliquerBonusZones(array $scoresBM25, array $contenu): array
{
    // Pré-tokeniser chaque zone pour lookups O(1)
    $termesParZone = [
        'titre'            => array_flip(tokeniser($contenu['titre'] ?? '')),
        'meta_description' => array_flip(tokeniser($contenu['meta_description'] ?? '')),
        'h1'               => array_flip(tokeniser(implode(' ', $contenu['h1'] ?? []))),
        'h2'               => array_flip(tokeniser(implode(' ', $contenu['h2'] ?? []))),
        'h3'               => array_flip(tokeniser(implode(' ', $contenu['h3'] ?? []))),
        'strong'           => array_flip(tokeniser(implode(' ', $contenu['strong'] ?? []))),
        'alt_images'       => array_flip(tokeniser(implode(' ', $contenu['alt_images'] ?? []))),
        'ancres_internes'  => array_flip(tokeniser(implode(' ', $contenu['ancres_internes'] ?? []))),
        'mots_url'         => array_flip(tokeniser($contenu['mots_url'] ?? '')),
        'premiers_mots'    => array_flip(tokeniser($contenu['premiers_mots'] ?? '')),
    ];

    foreach ($scoresBM25 as $terme => $scoreBase) {
        $bonus = 0.0;

        foreach (BONUS_ZONES as $zone => $coeffBonus) {
            if (isset($termesParZone[$zone][$terme])) {
                $bonus += $coeffBonus;
            }
        }

        if ($bonus > 0) {
            $scoresBM25[$terme] = $scoreBase * (1 + $bonus);
        }
    }

    return $scoresBM25;
}

/**
 * Détermine dans quelles zones sémantiques un terme est présent.
 *
 * @param array<string, mixed> $contenu Structure retournée par scraperContenu()
 * @return string[] Liste des zones (ex: ['title', 'h1', 'meta', 'strong'])
 */
function trouverBalises(string $terme, array $contenu): array
{
    $balises = [];
    $termeMin = mb_strtolower($terme, 'UTF-8');

    // Zones à valeur simple (chaîne)
    $zonesSimples = [
        'title' => 'titre',
        'meta'  => 'meta_description',
        'url'   => 'mots_url',
        'intro' => 'premiers_mots',
    ];

    foreach ($zonesSimples as $label => $cle) {
        $valeur = $contenu[$cle] ?? '';
        if ($valeur !== '' && str_contains(mb_strtolower($valeur, 'UTF-8'), $termeMin)) {
            $balises[] = $label;
        }
    }

    // Zones à valeur tableau
    $zonesTableaux = [
        'h1'     => 'h1',
        'h2'     => 'h2',
        'h3'     => 'h3',
        'strong' => 'strong',
        'alt'    => 'alt_images',
        'anchor' => 'ancres_internes',
    ];

    foreach ($zonesTableaux as $label => $cle) {
        foreach ($contenu[$cle] ?? [] as $texte) {
            if (str_contains(mb_strtolower($texte, 'UTF-8'), $termeMin)) {
                $balises[] = $label;
                break; // Une seule mention suffit par zone
            }
        }
    }

    return $balises;
}

// ─── Analyse du gap sémantique ───────────────────────────────────────────────

/**
 * Réalise l'analyse complète TF-IDF et gap sémantique.
 *
 * @param array<int, array<string, mixed>> $contenusConcurrents Contenus scrapés des concurrents
 * @param array<string, mixed> $contenuCible Contenu scrapé de la page cible
 * @param int $nbResultats Nombre maximum de termes à retourner
 * @return array<string, mixed> Résultats complets de l'analyse
 */
function analyserGapSemantique(array $contenusConcurrents, array $contenuCible, int $nbResultats): array
{
    // Filtrer les concurrents en erreur
    $concurrentsValides = array_filter(
        $contenusConcurrents,
        fn(array $c): bool => $c['erreur'] === '' && $c['texte_complet'] !== ''
    );

    if (empty($concurrentsValides)) {
        return [
            'erreur' => 'Aucun concurrent valide n\'a pu être analysé.',
            'termes' => [],
            'score_couverture' => 0,
            'nb_concurrents' => 0,
        ];
    }

    // Construire le corpus : concurrents + cible
    $corpusTokens = [];
    $corpusContenus = [];
    $longueursDocuments = [];

    foreach ($concurrentsValides as $concurrent) {
        $tokens = tokeniser($concurrent['texte_complet']);
        $bigrammes = extraireBigrammes($tokens);
        $tousTermes = array_merge($tokens, $bigrammes);
        $corpusTokens[] = $tousTermes;
        $corpusContenus[] = $concurrent;
        $longueursDocuments[] = count($tousTermes);
    }

    // Tokens de la page cible
    $tokensCible = tokeniser($contenuCible['texte_complet']);
    $bigrammesCible = extraireBigrammes($tokensCible);
    $tousTermesCible = array_merge($tokensCible, $bigrammesCible);
    $corpusTokens[] = $tousTermesCible;
    $longueursDocuments[] = count($tousTermesCible);

    // Longueur moyenne des documents (avgdl pour BM25)
    $longueurMoyenne = count($longueursDocuments) > 0
        ? array_sum($longueursDocuments) / count($longueursDocuments)
        : 1.0;

    // Compter les occurrences brutes pour chaque document
    $corpusOccurrences = array_map('compterOccurrences', $corpusTokens);

    // Calculer IDF Robertson sur tout le corpus
    $idf = calculerIDFRobertson($corpusOccurrences);

    // Calculer BM25 par document avec bonus de zones
    $bm25Concurrents = [];
    foreach ($corpusOccurrences as $idx => $occurrences) {
        // Dernier élément = page cible
        if ($idx === count($corpusOccurrences) - 1) {
            break;
        }
        $scores = calculerBM25($occurrences, $longueursDocuments[$idx], $longueurMoyenne, $idf);
        $bm25Concurrents[] = appliquerBonusZones($scores, $corpusContenus[$idx]);
    }

    $idxCible = count($corpusOccurrences) - 1;
    $bm25Cible = calculerBM25($corpusOccurrences[$idxCible], $longueursDocuments[$idxCible], $longueurMoyenne, $idf);
    $bm25Cible = appliquerBonusZones($bm25Cible, $contenuCible);

    // Calculer le score moyen par terme chez les concurrents
    $scoresMoyensConcurrents = [];
    $nbConcurrents = count($bm25Concurrents);

    foreach ($bm25Concurrents as $bm25) {
        foreach ($bm25 as $terme => $score) {
            if (!isset($scoresMoyensConcurrents[$terme])) {
                $scoresMoyensConcurrents[$terme] = ['total' => 0.0, 'nb' => 0];
            }
            $scoresMoyensConcurrents[$terme]['total'] += $score;
            $scoresMoyensConcurrents[$terme]['nb']++;
        }
    }

    // Ne garder que les termes présents chez au moins 30% des concurrents
    // Avec peu de concurrents (≤5), un seul suffit pour éviter de filtrer trop
    $seuilPresence = $nbConcurrents <= 5
        ? max(1, (int)ceil($nbConcurrents * 0.3))
        : max(2, (int)ceil($nbConcurrents * 0.3));
    $termesSignificatifs = [];

    foreach ($scoresMoyensConcurrents as $terme => $stats) {
        if ($stats['nb'] >= $seuilPresence) {
            $termesSignificatifs[$terme] = $stats['total'] / $stats['nb'];
        }
    }

    // Trier par score concurrent décroissant
    arsort($termesSignificatifs);

    // Construire le tableau de résultats
    $termes = [];
    $nbOK = 0;
    $nbTotal = 0;
    $compteur = 0;

    foreach ($termesSignificatifs as $terme => $scoreMoyenConc) {
        if ($compteur >= $nbResultats) {
            break;
        }

        $scoreCible = $bm25Cible[$terme] ?? 0;
        $ratio = $scoreMoyenConc > 0 ? $scoreCible / $scoreMoyenConc : 0;

        // Déterminer la recommandation
        if ($ratio >= 0.8) {
            $recommandation = 'OK';
            $nbOK++;
        } elseif ($ratio >= 0.3) {
            $recommandation = 'À renforcer';
        } else {
            $recommandation = 'À ajouter';
        }

        // Présence dans les balises importantes de la page cible
        $balisesCible = trouverBalises($terme, $contenuCible);
        $balisesConc = [];
        foreach ($concurrentsValides as $concurrent) {
            $bConc = trouverBalises($terme, $concurrent);
            $balisesConc = array_values(array_unique(array_merge($balisesConc, $bConc)));
        }

        $termes[] = [
            'terme'                => $terme,
            'score_concurrents'    => round($scoreMoyenConc, 6),
            'score_cible'          => round($scoreCible, 6),
            'ratio'                => round($ratio, 4),
            'balises_cible'        => $balisesCible,
            'balises_concurrents'  => $balisesConc,
            'recommandation'       => $recommandation,
            'nb_concurrents'       => $scoresMoyensConcurrents[$terme]['nb'],
        ];

        $nbTotal++;
        $compteur++;
    }

    // Score de couverture sémantique
    $scoreCouverture = $nbTotal > 0 ? round(($nbOK / $nbTotal) * 100, 1) : 0;

    return [
        'termes'             => $termes,
        'score_couverture'   => $scoreCouverture,
        'nb_concurrents'     => $nbConcurrents,
        'nb_concurrents_ok'  => count($concurrentsValides),
        'url_cible'          => $contenuCible['url'],
        'mot_cle'            => '', // sera rempli par l'appelant
        'nb_termes_analyses' => count($termesSignificatifs),
    ];
}
