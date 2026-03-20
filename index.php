<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TF-IDF Analyzer — Gap sémantique SERP</title>
    <!-- CDN (standalone uniquement, ignorés par extractParts en embedded) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- CSS local -->
    <link rel="stylesheet" href="styles.css">
</head>
<body>

<!-- Navbar (supprimée automatiquement en mode embedded) -->
<nav class="navbar mb-4">
    <div class="container-fluid px-lg-4 d-flex justify-content-between align-items-center">
        <span class="navbar-brand mb-0 h1">
            <i class="bi bi-bar-chart-steps me-2"></i><span data-i18n="nav.titre">TF-IDF Analyzer</span>
            <span class="d-block d-sm-inline ms-sm-2" data-i18n="nav.soustitre">Gap sémantique SERP</span>
        </span>
        <?php if (!defined('PLATFORM_EMBEDDED')): ?>
        <select id="lang-select" class="form-select form-select-sm" style="width:auto; background-color:rgba(255,255,255,0.15); color:#fff; border-color:rgba(255,255,255,0.3); font-size:0.8rem;">
            <option value="fr">FR</option>
            <option value="en">EN</option>
        </select>
        <?php endif; ?>
    </div>
</nav>

<div class="container-fluid px-lg-4 py-4">

    <!-- Formulaire de saisie -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0" data-i18n="form.titre"><i class="bi bi-search me-2"></i>Paramètres d'analyse</h6>
        </div>
        <div class="card-body">
            <form id="formAnalyse" method="POST">

                <!-- Sélection du mode -->
                <div class="mb-3">
                    <label class="form-label" data-i18n="form.label_source">Source des concurrents</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="modeAuto" value="auto" checked>
                            <label class="form-check-label" for="modeAuto" data-i18n="form.mode_auto">
                                <i class="bi bi-search me-1"></i>Automatique (Google SERP)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="modeManuel" value="manuel">
                            <label class="form-check-label" for="modeManuel" data-i18n="form.mode_manuel">
                                <i class="bi bi-list-ul me-1"></i>Manuel (URLs fournies)
                            </label>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="urlCible" class="form-label" data-i18n="form.label_url_cible">URL cible</label>
                        <input type="url" class="form-control" id="urlCible" name="url_cible"
                               placeholder="https://www.example.com/page-a-analyser"
                               data-i18n-placeholder="form.placeholder_url_cible" required>
                        <div class="form-text" data-i18n="form.aide_url_cible">Page à comparer aux concurrents</div>
                    </div>
                    <div class="col-md-4" id="champMotCle">
                        <label for="motCle" class="form-label" data-i18n="form.label_mot_cle">Mot-clé principal</label>
                        <input type="text" class="form-control" id="motCle" name="mot_cle"
                               placeholder="ex : chaussures running"
                               data-i18n-placeholder="form.placeholder_mot_cle">
                        <div class="form-text" data-i18n="form.aide_mot_cle">Requête Google cible</div>
                    </div>
                    <div class="col-md-2">
                        <label for="nbResultats" class="form-label" data-i18n="form.label_nb_termes">Nb termes</label>
                        <input type="number" class="form-control" id="nbResultats" name="nb_resultats"
                               value="50" min="10" max="200">
                        <div class="form-text" data-i18n="form.aide_nb_termes">10 à 200</div>
                    </div>
                </div>

                <!-- Zone URLs manuelles (masquée par défaut) -->
                <div class="mt-3 d-none" id="zoneUrlsManuelles">
                    <label for="urlsManuelles" class="form-label" data-i18n="form.label_urls_manuelles">URLs concurrentes (une par ligne, max 10)</label>
                    <textarea class="form-control" id="urlsManuelles" name="urls_manuelles" rows="6"
                              placeholder="https://www.concurrent1.com/page&#10;https://www.concurrent2.com/page&#10;https://www.concurrent3.com/page"
                              data-i18n-placeholder="form.placeholder_urls_manuelles"></textarea>
                    <div class="form-text" data-i18n="form.aide_urls_manuelles">Collez les URLs des pages concurrentes à comparer avec votre page cible</div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary" id="btnLancer" data-i18n="btn.lancer">
                        <i class="bi bi-play-fill me-1"></i>Lancer l'analyse
                    </button>
                    <button type="button" class="btn btn-outline-secondary ms-2 d-none" id="btnArreter" data-i18n="btn.arreter">
                        <i class="bi bi-stop-fill me-1"></i>Arrêter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Barre de progression -->
    <div class="card mb-4 d-none" id="carteProgression">
        <div class="card-body">
            <div class="d-flex align-items-center mb-2">
                <div class="spinner-border spinner-border-sm text-primary me-2" id="spinnerProgression"></div>
                <span id="messageProgression" class="text-secondary" style="font-size:0.85rem;" data-i18n="status.initialisation">Initialisation…</span>
            </div>
            <div class="progress">
                <div class="progress-bar" id="barreProgression" role="progressbar"
                     style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
            </div>
        </div>
    </div>

    <!-- Message de statut -->
    <div class="status-msg d-none mb-4" id="statusMsg"></div>

    <!-- Résultats -->
    <div class="d-none" id="sectionResultats">

        <!-- KPI -->
        <div class="kpi-row mb-4" id="kpiRow">
            <div class="kpi-card kpi-dark">
                <div class="kpi-value" id="kpiScore">—</div>
                <div class="kpi-label" data-i18n="kpi.couverture">Couverture sémantique</div>
            </div>
            <div class="kpi-card kpi-green">
                <div class="kpi-value" id="kpiOK">—</div>
                <div class="kpi-label" data-i18n="kpi.termes_ok">Termes OK</div>
            </div>
            <div class="kpi-card kpi-gold">
                <div class="kpi-value" id="kpiRenforcer">—</div>
                <div class="kpi-label" data-i18n="kpi.a_renforcer">À renforcer</div>
            </div>
            <div class="kpi-card kpi-red">
                <div class="kpi-value" id="kpiAjouter">—</div>
                <div class="kpi-label" data-i18n="kpi.a_ajouter">À ajouter</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value" id="kpiConcurrents">—</div>
                <div class="kpi-label" data-i18n="kpi.concurrents">Concurrents analysés</div>
            </div>
        </div>

        <!-- Contrôles tableau -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0" data-i18n="table.titre"><i class="bi bi-table me-2"></i>Gap sémantique</h6>
                <div class="d-flex align-items-center gap-2">
                    <!-- Filtre recommandation -->
                    <select class="form-select form-select-sm" id="filtreRecommandation" style="width:auto;">
                        <option value="tous" data-i18n="table.filtre_tous">Tous</option>
                        <option value="À ajouter" data-i18n="table.filtre_ajouter">À ajouter</option>
                        <option value="À renforcer" data-i18n="table.filtre_renforcer">À renforcer</option>
                        <option value="OK" data-i18n="table.filtre_ok">OK</option>
                    </select>
                    <!-- Recherche -->
                    <div class="input-group input-group-sm" style="width:220px;">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="champRecherche"
                               placeholder="Filtrer les termes…"
                               data-i18n-placeholder="table.placeholder_recherche">
                    </div>
                    <!-- Export -->
                    <button class="btn btn-outline-secondary btn-sm" id="btnExportCSV" title="Export CSV" data-i18n="btn.csv">
                        <i class="bi bi-download me-1"></i>CSV
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tableauResultats">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="terme" data-i18n="table.col_terme">Terme</th>
                                <th class="sortable" data-sort="score_concurrents" data-i18n="table.col_score_concurrents">Score concurrents</th>
                                <th class="sortable" data-sort="score_cible" data-i18n="table.col_score_cible">Score cible</th>
                                <th class="sortable" data-sort="ratio" data-i18n="table.col_ratio">Ratio</th>
                                <th data-i18n="table.col_balises_cible">Balises cible</th>
                                <th data-i18n="table.col_balises_concurrents">Balises concurrents</th>
                                <th class="sortable" data-sort="recommandation" data-i18n="table.col_recommandation">Recommandation</th>
                            </tr>
                        </thead>
                        <tbody id="corpsTableau"></tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="text-muted" style="font-size:0.82rem;" id="infoPages">—</span>
                <div id="pagination"></div>
            </div>
        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="translations.js"></script>
<script src="app.js"></script>
</body>
</html>
