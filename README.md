# TF-IDF Analyzer

> **EN** — Comparative TF-IDF analysis between a target page and its SERP competitors to identify semantic content gaps, powered by BM25 scoring with zone-weighted bonuses.

---

## Description

**TF-IDF Analyzer** est un outil d'analyse de gap semantique qui compare le contenu d'une page cible avec celui de ses concurrents sur la SERP. L'objectif : identifier les termes importants utilises par les concurrents mais absents ou sous-representes sur la page cible, afin de guider l'optimisation du contenu.

L'outil s'adresse aux **professionnels du referencement (SEO)** qui cherchent a ameliorer la couverture semantique de leurs pages. Il repond a la question cle : *"Quels termes dois-je ajouter ou renforcer sur ma page pour etre au niveau de mes concurrents ?"*. Le score de couverture semantique global et les recommandations terme par terme (OK / A renforcer / A ajouter) permettent de prioriser les actions d'optimisation.

Le workflow est entierement automatise : l'utilisateur saisit une URL cible et un mot-cle (ou une liste d'URLs concurrentes en mode manuel), et l'outil scrape les pages, extrait le contenu structure (titre, headings, meta, texte, images, liens internes...), calcule les scores **BM25 (Okapi BM25)** avec bonus de zones semantiques, puis produit un tableau comparatif actionnable avec export CSV.

---

## Fonctionnalites

- **Deux modes de fonctionnement** : automatique (scraping Bing SERP pour trouver les concurrents) ou manuel (URLs concurrentes fournies par l'utilisateur)
- **Scraping intelligent** : extraction de 10 zones semantiques distinctes (title, meta description, h1, h2, h3, strong/b/em, alt images, ancres internes, mots de l'URL, premiers mots du body)
- **Algorithme BM25 (Okapi BM25)** avec parametres k1=1.2 et b=0.75 pour le scoring des termes
- **Bonus de zones ponderes** : chaque zone apporte un bonus additif au score BM25 (title +100%, h1 +80%, URL +50%, meta +40%, h2 +30%, intro +25%, h3 +20%, ancres +20%, strong +15%, alt +15%)
- **Analyse des unigrammes et bigrammes** pour capturer les expressions multi-mots
- **Score de couverture semantique** global (pourcentage de termes bien couverts)
- **Recommandations par terme** : OK (ratio >= 80%), A renforcer (ratio >= 30%), A ajouter (ratio < 30%)
- **Affichage des balises** : visualisation des zones ou chaque terme est present (cible vs concurrents), avec badges colores par impact SEO
- **KPI en un coup d'oeil** : couverture semantique, termes OK, a renforcer, a ajouter, concurrents analyses
- **Filtrage et recherche** : filtre par recommandation (Tous / A ajouter / A renforcer / OK) et recherche textuelle dans les termes
- **Tri multi-colonnes** : clic sur les en-tetes pour trier par terme, score concurrents, score cible, ratio ou recommandation
- **Pagination** : navigation par pages de 25 resultats
- **Export CSV** : telechargement des resultats filtres au format CSV (separateur point-virgule, encodage UTF-8 BOM)
- **Cache journalier** : les resultats sont mis en cache pour eviter les analyses repetees le meme jour, avec nettoyage automatique des caches de plus de 24h
- **Streaming SSE** : progression en temps reel via Server-Sent Events (etapes : recherche SERP, scraping concurrents, scraping cible, calcul TF-IDF)
- **Possibilite d'arreter l'analyse** en cours via le bouton Stop
- **Internationalisation (i18n)** : interface disponible en francais et en anglais
- **Responsive** : grille KPI et tableau adaptes aux ecrans mobiles
- **Filtrage des mots vides** : liste de stop words francais et anglais pour ne garder que les termes significatifs
- **Exclusion automatique** du domaine cible parmi les concurrents
- **Limitation de taille** : pages limitees a 500 Ko, timeout de 15 secondes par requete
- **Nettoyage DOM** : suppression des elements de navigation, footer, pubs, cookies, modales avant extraction du contenu

---

## Prerequis

- **PHP 8.3+** avec les extensions suivantes activees :
  - `curl` (requetes HTTP)
  - `dom` et `libxml` (parsing HTML)
  - `mbstring` (manipulation de chaines Unicode)
  - `json` (encodage/decodage JSON)
- Aucune cle API externe requise
- Aucune dependance Composer (pas de `vendor/`)

---

## Installation

```bash
git clone https://github.com/aymeric-twit/tfidf-analyzer.git
cd tfidf-analyzer/
php -S localhost:8080
```

Ouvrir ensuite `http://localhost:8080` dans le navigateur.

Le repertoire `data/` sera cree automatiquement pour le cache des resultats.

---

## Utilisation

### Mode automatique (Bing SERP)

1. Selectionner le mode **Automatique (Bing SERP)** (selectionne par defaut)
2. Saisir l'**URL de la page cible** a analyser (ex : `https://www.monsite.com/ma-page`)
3. Saisir le **mot-cle principal** correspondant a la requete cible (ex : `chaussures running`)
4. Ajuster le **nombre de termes** a afficher (10 a 200, defaut : 50)
5. Cliquer sur **Lancer l'analyse**
6. L'outil scrape les resultats Bing pour le mot-cle, recupere jusqu'a 10 URLs concurrentes, scrape chaque page, puis calcule le gap semantique
7. La progression s'affiche en temps reel (barre de progression + messages)

### Mode manuel (URLs fournies)

1. Selectionner le mode **Manuel (URLs fournies)**
2. Saisir l'**URL de la page cible**
3. Coller les **URLs concurrentes** dans le champ prevu (une par ligne, maximum 10)
4. Cliquer sur **Lancer l'analyse**

### Lecture des resultats

- Les **KPI** affichent le score de couverture semantique, le nombre de termes OK, a renforcer et a ajouter, ainsi que le ratio de concurrents analyses avec succes
- Le **tableau de gap semantique** liste chaque terme avec :
  - Le **score concurrents** (moyenne BM25 ponderee par zones chez les concurrents)
  - Le **score cible** (BM25 pondere par zones sur la page cible)
  - Le **ratio** (score cible / score concurrents, en pourcentage)
  - Les **balises cible/concurrents** : zones semantiques ou le terme est present (title, h1, h2, h3, meta, url, intro, strong, alt, anchor)
  - La **recommandation** : OK (>= 80%), A renforcer (30-80%), A ajouter (< 30%)
- Utiliser les **filtres** pour isoler les termes a ajouter ou a renforcer
- **Exporter en CSV** pour integrer les resultats dans un livrable client

---

## Stack technique

- **PHP 8.3** — Backend, scraping cURL, parsing DOM, calcul BM25
- **HTML5 / CSS3** — Interface utilisateur avec charte graphique brand
- **JavaScript vanilla** — Gestion formulaire, streaming SSE (EventSource), rendu dynamique du tableau, pagination, tri, export CSV
- **Bootstrap 5.3.3** — Framework CSS (CDN en standalone, fourni par la plateforme en embedded)
- **Bootstrap Icons 1.11.3** — Iconographie
- **Google Fonts (Poppins)** — Typographie
- **Server-Sent Events (SSE)** — Streaming de la progression en temps reel
- **Algorithme BM25 (Okapi BM25)** — Scoring des termes avec normalisation par longueur de document
- **Bonus de zones semantiques** — Ponderation additive basee sur la position HTML des termes

---

## Structure du projet

```
tfidf-analyzer/
├── module.json          # Configuration du plugin (slug, quota, routes, i18n)
├── boot.php             # Chargement avant le point d'entree (minimal)
├── index.php            # Interface HTML principale (formulaire + resultats)
├── stream.php           # Endpoint SSE — orchestration de l'analyse en streaming
├── functions.php        # Logique metier : scraping, tokenisation, BM25, gap semantique
├── styles.css           # Styles CSS (charte graphique brand)
├── app.js               # JavaScript client (formulaire, SSE, tableau, export)
├── translations.js      # Traductions FR/EN pour l'i18n
├── .gitignore           # Fichiers exclus du depot (vendor, data, .env, logs)
└── data/                # Cache des resultats (cree automatiquement, ignore par git)
    └── *.json           # Fichiers de cache journaliers (MD5 du couple URL/mot-cle)
```

---

## Routes (module.json)

| Chemin       | Methode | Type     | Description                                      |
|-------------|---------|----------|--------------------------------------------------|
| `index.php` | GET     | page     | Point d'entree — interface principale             |
| `stream.php`| GET     | stream   | Endpoint SSE — analyse TF-IDF en temps reel       |

### Parametres de `stream.php`

| Parametre        | Type   | Requis              | Description                                    |
|-----------------|--------|---------------------|------------------------------------------------|
| `url_cible`     | string | Oui                 | URL de la page a analyser                      |
| `mot_cle`       | string | Oui (mode auto)     | Mot-cle pour la recherche Bing                 |
| `nb_resultats`  | int    | Non (defaut : 50)   | Nombre de termes a retourner (10-200)          |
| `mode`          | string | Non (defaut : auto) | `auto` (Bing SERP) ou `manuel` (URLs fournies) |
| `urls_manuelles`| string | Oui (mode manuel)   | URLs concurrentes separees par des sauts de ligne |

---

## Integration plateforme

| Parametre       | Valeur                                                         |
|----------------|---------------------------------------------------------------|
| **Mode**       | `embedded` — HTML injecte dans le layout de la plateforme      |
| **Quota**      | `api_call` — 100 appels par mois par defaut                   |
| **Langues**    | `fr`, `en`                                                     |
| **Cles API**   | Aucune (`env_keys: []`)                                        |
| **Icone**      | `bi-bar-chart-steps`                                           |
| **Ordre sidebar** | 55                                                          |

En mode embedded, la navbar est automatiquement supprimee, les CDN (Bootstrap, Poppins) sont ignores (deja dans le layout plateforme), et les chemins CSS/JS sont recrits vers `/module-assets/tfidf-analyzer/`. Le token CSRF est injecte automatiquement dans les formulaires POST. Les appels SSE utilisent `window.MODULE_BASE_URL + '/stream.php'` pour garantir la resolution correcte des URLs.
