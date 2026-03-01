/**
 * app.js — TF-IDF Analyzer
 * Gestion du formulaire (mode auto/manuel), streaming SSE, affichage et export.
 */
(function () {
    'use strict';

    // ─── Configuration ───────────────────────────────────────────────────────

    var BASE_URL = (window.MODULE_BASE_URL || '.');
    var PAGE_SIZE = 25;

    // ─── État ────────────────────────────────────────────────────────────────

    var resultats = [];
    var resultatsFiltres = [];
    var pageCourante = 1;
    var colonneTri = 'score_concurrents';
    var directionTri = 'desc';
    var eventSource = null;

    // ─── Éléments DOM ────────────────────────────────────────────────────────

    var formulaire         = document.getElementById('formAnalyse');
    var btnLancer          = document.getElementById('btnLancer');
    var btnArreter         = document.getElementById('btnArreter');
    var carteProgression   = document.getElementById('carteProgression');
    var barreProgression   = document.getElementById('barreProgression');
    var messageProgression = document.getElementById('messageProgression');
    var statusMsg          = document.getElementById('statusMsg');
    var sectionResultats   = document.getElementById('sectionResultats');
    var corpsTableau       = document.getElementById('corpsTableau');
    var champRecherche     = document.getElementById('champRecherche');
    var filtreRecommandation = document.getElementById('filtreRecommandation');
    var btnExportCSV       = document.getElementById('btnExportCSV');
    var pagination         = document.getElementById('pagination');
    var infoPages          = document.getElementById('infoPages');

    // Mode
    var modeAuto           = document.getElementById('modeAuto');
    var modeManuel         = document.getElementById('modeManuel');
    var champMotCle        = document.getElementById('champMotCle');
    var zoneUrlsManuelles  = document.getElementById('zoneUrlsManuelles');
    var inputMotCle        = document.getElementById('motCle');
    var inputUrlsManuelles = document.getElementById('urlsManuelles');

    // KPI
    var kpiScore       = document.getElementById('kpiScore');
    var kpiOK          = document.getElementById('kpiOK');
    var kpiRenforcer   = document.getElementById('kpiRenforcer');
    var kpiAjouter     = document.getElementById('kpiAjouter');
    var kpiConcurrents = document.getElementById('kpiConcurrents');

    // ─── Initialisation ──────────────────────────────────────────────────────

    formulaire.addEventListener('submit', lancerAnalyse);
    btnArreter.addEventListener('click', arreterAnalyse);
    champRecherche.addEventListener('input', appliquerFiltres);
    filtreRecommandation.addEventListener('change', appliquerFiltres);
    btnExportCSV.addEventListener('click', exporterCSV);

    // Switch mode auto/manuel
    modeAuto.addEventListener('change', basculerMode);
    modeManuel.addEventListener('change', basculerMode);

    // Tri des colonnes
    document.querySelectorAll('.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            trierResultats(this.getAttribute('data-sort'));
        });
    });

    function basculerMode() {
        var estAuto = modeAuto.checked;
        champMotCle.classList.toggle('d-none', !estAuto);
        zoneUrlsManuelles.classList.toggle('d-none', estAuto);

        // Rendre le mot-clé requis uniquement en mode auto
        inputMotCle.required = estAuto;
    }

    // Init : mot-clé requis par défaut
    inputMotCle.required = true;

    // ─── Lancement de l'analyse ──────────────────────────────────────────────

    function lancerAnalyse(e) {
        e.preventDefault();

        var urlCible = document.getElementById('urlCible').value.trim();
        var motCle = inputMotCle.value.trim();
        var nbResultats = document.getElementById('nbResultats').value;
        var mode = modeAuto.checked ? 'auto' : 'manuel';
        var urlsManuelles = inputUrlsManuelles.value.trim();

        if (!urlCible) {
            afficherStatus('Veuillez remplir l\'URL cible.', 'error');
            return;
        }

        if (mode === 'auto' && !motCle) {
            afficherStatus('Veuillez remplir le mot-clé en mode automatique.', 'error');
            return;
        }

        if (mode === 'manuel' && !urlsManuelles) {
            afficherStatus('Veuillez fournir au moins une URL concurrente.', 'error');
            return;
        }

        // Réinitialiser l'interface
        resultats = [];
        resultatsFiltres = [];
        pageCourante = 1;
        masquerStatus();
        sectionResultats.classList.add('d-none');
        carteProgression.classList.remove('d-none');
        btnLancer.disabled = true;
        btnArreter.classList.remove('d-none');
        mettreAJourProgression(0, 'Initialisation…');

        // Construire les paramètres
        var params = new URLSearchParams({
            url_cible: urlCible,
            mot_cle: motCle,
            nb_resultats: nbResultats,
            mode: mode
        });

        if (mode === 'manuel') {
            params.set('urls_manuelles', urlsManuelles);
        }

        // Ouvrir la connexion SSE
        eventSource = new EventSource(BASE_URL + '/stream.php?' + params.toString());

        eventSource.addEventListener('log', function (e) {
            var data = JSON.parse(e.data);
            mettreAJourProgression(data.percent || 0, data.message || '');
        });

        eventSource.addEventListener('done', function (e) {
            eventSource.close();
            eventSource = null;
            finAnalyse(JSON.parse(e.data));
        });

        eventSource.addEventListener('error', function (e) {
            if (e.data) {
                var data = JSON.parse(e.data);
                afficherStatus(data.message || 'Erreur de connexion.', 'error');
            } else if (eventSource && eventSource.readyState === EventSource.CLOSED) {
                afficherStatus('Connexion terminée par le serveur.', 'error');
            } else {
                afficherStatus('Connexion interrompue avec le serveur.', 'error');
            }

            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
            resetUI();
        });
    }

    function arreterAnalyse() {
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }
        afficherStatus('Analyse annulée.', 'warning');
        resetUI();
    }

    function resetUI() {
        carteProgression.classList.add('d-none');
        btnLancer.disabled = false;
        btnArreter.classList.add('d-none');
    }

    // ─── Progression ─────────────────────────────────────────────────────────

    function mettreAJourProgression(percent, message) {
        barreProgression.style.width = percent + '%';
        barreProgression.textContent = percent + '%';
        barreProgression.setAttribute('aria-valuenow', percent);
        messageProgression.textContent = message;
    }

    // ─── Fin d'analyse ───────────────────────────────────────────────────────

    function finAnalyse(data) {
        resetUI();

        if (data.erreur) {
            afficherStatus(data.erreur, 'error');
            return;
        }

        resultats = data.termes || [];

        // Mettre à jour les KPI
        var nbOK = 0, nbRenforcer = 0, nbAjouter = 0;
        resultats.forEach(function (t) {
            if (t.recommandation === 'OK') nbOK++;
            else if (t.recommandation === 'À renforcer') nbRenforcer++;
            else nbAjouter++;
        });

        kpiScore.textContent = data.score_couverture + '%';
        kpiScore.style.color = couleurScore(data.score_couverture);
        kpiOK.textContent = nbOK;
        kpiRenforcer.textContent = nbRenforcer;
        kpiAjouter.textContent = nbAjouter;
        kpiConcurrents.textContent = data.nb_concurrents_ok + '/' + data.nb_concurrents;

        afficherStatus(
            'Analyse terminée — ' + resultats.length + ' termes analysés sur '
            + data.nb_termes_analyses + ' termes significatifs.',
            'success'
        );

        sectionResultats.classList.remove('d-none');
        champRecherche.value = '';
        filtreRecommandation.value = 'tous';
        colonneTri = 'score_concurrents';
        directionTri = 'desc';
        appliquerFiltres();
    }

    // ─── Filtres et tri ──────────────────────────────────────────────────────

    function appliquerFiltres() {
        var recherche = champRecherche.value.trim().toLowerCase();
        var filtre = filtreRecommandation.value;

        resultatsFiltres = resultats.filter(function (t) {
            if (filtre !== 'tous' && t.recommandation !== filtre) return false;
            if (recherche && t.terme.toLowerCase().indexOf(recherche) === -1) return false;
            return true;
        });

        trierSansInversion(colonneTri, directionTri);
        pageCourante = 1;
        afficherPage(1);
    }

    function trierResultats(colonne) {
        if (colonneTri === colonne) {
            directionTri = directionTri === 'asc' ? 'desc' : 'asc';
        } else {
            colonneTri = colonne;
            directionTri = colonne === 'terme' || colonne === 'recommandation' ? 'asc' : 'desc';
        }

        trierSansInversion(colonne, directionTri);

        document.querySelectorAll('.sortable').forEach(function (th) {
            th.classList.remove('sort-asc', 'sort-desc');
        });
        var thActif = document.querySelector('[data-sort="' + colonne + '"]');
        if (thActif) thActif.classList.add('sort-' + directionTri);

        pageCourante = 1;
        afficherPage(1);
    }

    function trierSansInversion(colonne, direction) {
        resultatsFiltres.sort(function (a, b) {
            var va = a[colonne], vb = b[colonne];
            if (va == null) return 1;
            if (vb == null) return -1;
            if (typeof va === 'string') va = va.toLowerCase();
            if (typeof vb === 'string') vb = vb.toLowerCase();
            return direction === 'asc' ? (va > vb ? 1 : va < vb ? -1 : 0)
                                        : (va < vb ? 1 : va > vb ? -1 : 0);
        });
    }

    // ─── Rendu du tableau ────────────────────────────────────────────────────

    function afficherPage(page) {
        pageCourante = page;
        var debut = (page - 1) * PAGE_SIZE;
        var fin = Math.min(debut + PAGE_SIZE, resultatsFiltres.length);
        var pageItems = resultatsFiltres.slice(debut, fin);

        var html = '';
        pageItems.forEach(function (t) {
            html += '<tr>'
                + '<td class="fw-600">' + escHtml(t.terme) + '</td>'
                + '<td>' + formatScore(t.score_concurrents) + '</td>'
                + '<td>' + formatScore(t.score_cible) + '</td>'
                + '<td>' + formatRatio(t.ratio) + '</td>'
                + '<td>' + formatBalises(t.balises_cible) + '</td>'
                + '<td>' + formatBalises(t.balises_concurrents) + '</td>'
                + '<td>' + badgeRecommandation(t.recommandation) + '</td>'
                + '</tr>';
        });

        corpsTableau.innerHTML = html;

        var total = resultatsFiltres.length;
        if (total === 0) {
            infoPages.textContent = 'Aucun résultat';
            pagination.innerHTML = '';
            return;
        }
        infoPages.textContent = (debut + 1) + '–' + fin + ' sur ' + total + ' termes';

        var nbPages = Math.ceil(total / PAGE_SIZE);
        construirePagination(nbPages, page);
    }

    function construirePagination(nbPages, pageCourante) {
        if (nbPages <= 1) {
            pagination.innerHTML = '';
            return;
        }

        var html = '';
        html += '<button class="pg-btn me-1" ' + (pageCourante <= 1 ? 'disabled' : '')
            + ' onclick="window.__tfidf_page(' + (pageCourante - 1) + ')">&laquo;</button>';

        var debut = Math.max(1, pageCourante - 2);
        var fin = Math.min(nbPages, pageCourante + 2);

        if (debut > 1) {
            html += '<button class="pg-btn me-1" onclick="window.__tfidf_page(1)">1</button>';
            if (debut > 2) html += '<span class="px-1 text-muted">…</span>';
        }

        for (var i = debut; i <= fin; i++) {
            html += '<button class="pg-btn me-1 ' + (i === pageCourante ? 'pg-active' : '')
                + '" onclick="window.__tfidf_page(' + i + ')">' + i + '</button>';
        }

        if (fin < nbPages) {
            if (fin < nbPages - 1) html += '<span class="px-1 text-muted">…</span>';
            html += '<button class="pg-btn me-1" onclick="window.__tfidf_page(' + nbPages + ')">'
                + nbPages + '</button>';
        }

        html += '<button class="pg-btn" ' + (pageCourante >= nbPages ? 'disabled' : '')
            + ' onclick="window.__tfidf_page(' + (pageCourante + 1) + ')">&raquo;</button>';

        pagination.innerHTML = html;
    }

    window.__tfidf_page = function (page) {
        afficherPage(page);
        document.getElementById('tableauResultats').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    // ─── Formatage ───────────────────────────────────────────────────────────

    function formatScore(score) {
        if (score === 0 || score == null) return '<span class="text-muted">0</span>';
        return score.toFixed(4);
    }

    function formatRatio(ratio) {
        if (ratio == null) return '—';
        var pct = (ratio * 100).toFixed(0);
        var couleur = ratio >= 0.8 ? 'var(--score-high)' : ratio >= 0.3 ? 'var(--score-mid)' : 'var(--score-low)';
        return '<span style="color:' + couleur + ';font-weight:600;">' + pct + '%</span>';
    }

    function formatBalises(balises) {
        if (!balises) return '<span class="text-muted">—</span>';
        // Garantir un tableau (PHP peut sérialiser [] en {} dans certains cas)
        if (!Array.isArray(balises)) {
            balises = Object.values(balises);
        }
        if (balises.length === 0) return '<span class="text-muted">—</span>';
        return balises.map(function (b) {
            var cls = 'badge-zone badge-zone-' + b.replace(/[^a-z]/g, '');
            return '<span class="' + cls + '">' + escHtml(b) + '</span>';
        }).join(' ');
    }

    function badgeRecommandation(reco) {
        var classe = 'badge-succes';
        var icone = 'bi-check-circle-fill';
        if (reco === 'À renforcer') {
            classe = 'badge-attention';
            icone = 'bi-exclamation-triangle-fill';
        } else if (reco === 'À ajouter') {
            classe = 'badge-erreur';
            icone = 'bi-plus-circle-fill';
        }
        return '<span class="' + classe + '"><i class="bi ' + icone + ' me-1"></i>' + escHtml(reco) + '</span>';
    }

    function couleurScore(score) {
        if (score >= 70) return 'var(--score-high)';
        if (score >= 40) return 'var(--score-mid)';
        return 'var(--score-low)';
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ─── Status ──────────────────────────────────────────────────────────────

    function afficherStatus(message, type) {
        statusMsg.textContent = message;
        statusMsg.className = 'status-msg status-' + type + ' mb-4';
        statusMsg.classList.remove('d-none');
    }

    function masquerStatus() {
        statusMsg.classList.add('d-none');
    }

    // ─── Export CSV ──────────────────────────────────────────────────────────

    function exporterCSV() {
        if (!resultatsFiltres.length) return;

        var colonnes = [
            'Terme',
            'Score concurrents',
            'Score cible',
            'Ratio',
            'Balises cible',
            'Balises concurrents',
            'Recommandation',
            'Nb concurrents'
        ];
        var lignes = [colonnes.join(';')];

        resultatsFiltres.forEach(function (t) {
            lignes.push([
                '"' + t.terme.replace(/"/g, '""') + '"',
                t.score_concurrents,
                t.score_cible,
                t.ratio,
                '"' + (t.balises_cible || []).join(', ') + '"',
                '"' + (t.balises_concurrents || []).join(', ') + '"',
                '"' + t.recommandation + '"',
                t.nb_concurrents
            ].join(';'));
        });

        var blob = new Blob(['\uFEFF' + lignes.join('\n')], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'gap-semantique-tfidf.csv';
        a.click();
        URL.revokeObjectURL(url);
    }

})();
