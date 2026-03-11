/**
 * translations.js — TF-IDF Analyzer
 * Traductions FR/EN pour l'interface i18n.
 */
var TRANSLATIONS = {
    fr: {
        // Navbar
        'nav.titre': 'TF-IDF Analyzer',
        'nav.soustitre': 'Gap sémantique SERP',

        // Formulaire
        'form.titre': '<i class="bi bi-search me-2"></i>Paramètres d\'analyse',
        'form.label_source': 'Source des concurrents',
        'form.mode_auto': '<i class="bi bi-search me-1"></i>Automatique (Bing SERP)',
        'form.mode_manuel': '<i class="bi bi-list-ul me-1"></i>Manuel (URLs fournies)',
        'form.label_url_cible': 'URL cible',
        'form.placeholder_url_cible': 'https://www.example.com/page-a-analyser',
        'form.aide_url_cible': 'Page à comparer aux concurrents',
        'form.label_mot_cle': 'Mot-clé principal',
        'form.placeholder_mot_cle': 'ex : chaussures running',
        'form.aide_mot_cle': 'Requête Google cible',
        'form.label_nb_termes': 'Nb termes',
        'form.aide_nb_termes': '10 à 200',
        'form.label_urls_manuelles': 'URLs concurrentes (une par ligne, max 10)',
        'form.placeholder_urls_manuelles': 'https://www.concurrent1.com/page\nhttps://www.concurrent2.com/page\nhttps://www.concurrent3.com/page',
        'form.aide_urls_manuelles': 'Collez les URLs des pages concurrentes à comparer avec votre page cible',

        // Boutons
        'btn.lancer': '<i class="bi bi-play-fill me-1"></i>Lancer l\'analyse',
        'btn.arreter': '<i class="bi bi-stop-fill me-1"></i>Arrêter',
        'btn.csv': '<i class="bi bi-download me-1"></i>CSV',

        // Progression
        'status.initialisation': 'Initialisation…',

        // KPI
        'kpi.couverture': 'Couverture sémantique',
        'kpi.termes_ok': 'Termes OK',
        'kpi.a_renforcer': 'À renforcer',
        'kpi.a_ajouter': 'À ajouter',
        'kpi.concurrents': 'Concurrents analysés',

        // Tableau
        'table.titre': '<i class="bi bi-table me-2"></i>Gap sémantique',
        'table.filtre_tous': 'Tous',
        'table.filtre_ajouter': 'À ajouter',
        'table.filtre_renforcer': 'À renforcer',
        'table.filtre_ok': 'OK',
        'table.placeholder_recherche': 'Filtrer les termes…',
        'table.col_terme': 'Terme',
        'table.col_score_concurrents': 'Score concurrents',
        'table.col_score_cible': 'Score cible',
        'table.col_ratio': 'Ratio',
        'table.col_balises_cible': 'Balises cible',
        'table.col_balises_concurrents': 'Balises concurrents',
        'table.col_recommandation': 'Recommandation',
        'table.aucun_resultat': 'Aucun résultat',
        'table.info_pages': '{debut}–{fin} sur {total} termes',

        // CSV
        'csv.terme': 'Terme',
        'csv.score_concurrents': 'Score concurrents',
        'csv.score_cible': 'Score cible',
        'csv.ratio': 'Ratio',
        'csv.balises_cible': 'Balises cible',
        'csv.balises_concurrents': 'Balises concurrents',
        'csv.recommandation': 'Recommandation',
        'csv.nb_concurrents': 'Nb concurrents',

        // Recommandations
        'reco.ok': 'OK',
        'reco.a_renforcer': 'À renforcer',
        'reco.a_ajouter': 'À ajouter',

        // Messages
        'error.url_cible_vide': 'Veuillez remplir l\'URL cible.',
        'error.mot_cle_vide': 'Veuillez remplir le mot-clé en mode automatique.',
        'error.urls_manuelles_vide': 'Veuillez fournir au moins une URL concurrente.',
        'error.connexion': 'Erreur de connexion.',
        'error.connexion_serveur': 'Connexion terminée par le serveur.',
        'error.connexion_interrompue': 'Connexion interrompue avec le serveur.',
        'status.analyse_annulee': 'Analyse annulée.',
        'status.analyse_terminee': 'Analyse terminée — {nb} termes analysés sur {total} termes significatifs.',
        'error.quota_epuise': 'Quota mensuel épuisé.'
    },
    en: {
        // Navbar
        'nav.titre': 'TF-IDF Analyzer',
        'nav.soustitre': 'SERP Semantic Gap',

        // Formulaire
        'form.titre': '<i class="bi bi-search me-2"></i>Analysis Parameters',
        'form.label_source': 'Competitor Source',
        'form.mode_auto': '<i class="bi bi-search me-1"></i>Automatic (Bing SERP)',
        'form.mode_manuel': '<i class="bi bi-list-ul me-1"></i>Manual (Provided URLs)',
        'form.label_url_cible': 'Target URL',
        'form.placeholder_url_cible': 'https://www.example.com/page-to-analyze',
        'form.aide_url_cible': 'Page to compare against competitors',
        'form.label_mot_cle': 'Main keyword',
        'form.placeholder_mot_cle': 'e.g.: running shoes',
        'form.aide_mot_cle': 'Target Google query',
        'form.label_nb_termes': 'Nb terms',
        'form.aide_nb_termes': '10 to 200',
        'form.label_urls_manuelles': 'Competitor URLs (one per line, max 10)',
        'form.placeholder_urls_manuelles': 'https://www.competitor1.com/page\nhttps://www.competitor2.com/page\nhttps://www.competitor3.com/page',
        'form.aide_urls_manuelles': 'Paste competitor page URLs to compare with your target page',

        // Boutons
        'btn.lancer': '<i class="bi bi-play-fill me-1"></i>Start Analysis',
        'btn.arreter': '<i class="bi bi-stop-fill me-1"></i>Stop',
        'btn.csv': '<i class="bi bi-download me-1"></i>CSV',

        // Progression
        'status.initialisation': 'Initializing…',

        // KPI
        'kpi.couverture': 'Semantic Coverage',
        'kpi.termes_ok': 'Terms OK',
        'kpi.a_renforcer': 'To Strengthen',
        'kpi.a_ajouter': 'To Add',
        'kpi.concurrents': 'Competitors Analyzed',

        // Tableau
        'table.titre': '<i class="bi bi-table me-2"></i>Semantic Gap',
        'table.filtre_tous': 'All',
        'table.filtre_ajouter': 'To Add',
        'table.filtre_renforcer': 'To Strengthen',
        'table.filtre_ok': 'OK',
        'table.placeholder_recherche': 'Filter terms…',
        'table.col_terme': 'Term',
        'table.col_score_concurrents': 'Competitor Score',
        'table.col_score_cible': 'Target Score',
        'table.col_ratio': 'Ratio',
        'table.col_balises_cible': 'Target Tags',
        'table.col_balises_concurrents': 'Competitor Tags',
        'table.col_recommandation': 'Recommendation',
        'table.aucun_resultat': 'No results',
        'table.info_pages': '{debut}–{fin} of {total} terms',

        // CSV
        'csv.terme': 'Term',
        'csv.score_concurrents': 'Competitor Score',
        'csv.score_cible': 'Target Score',
        'csv.ratio': 'Ratio',
        'csv.balises_cible': 'Target Tags',
        'csv.balises_concurrents': 'Competitor Tags',
        'csv.recommandation': 'Recommendation',
        'csv.nb_concurrents': 'Nb Competitors',

        // Recommandations
        'reco.ok': 'OK',
        'reco.a_renforcer': 'To Strengthen',
        'reco.a_ajouter': 'To Add',

        // Messages
        'error.url_cible_vide': 'Please fill in the target URL.',
        'error.mot_cle_vide': 'Please fill in the keyword in automatic mode.',
        'error.urls_manuelles_vide': 'Please provide at least one competitor URL.',
        'error.connexion': 'Connection error.',
        'error.connexion_serveur': 'Connection closed by server.',
        'error.connexion_interrompue': 'Connection to server interrupted.',
        'status.analyse_annulee': 'Analysis cancelled.',
        'status.analyse_terminee': 'Analysis complete — {nb} terms analyzed out of {total} significant terms.',
        'error.quota_epuise': 'Monthly quota exhausted.'
    }
};
