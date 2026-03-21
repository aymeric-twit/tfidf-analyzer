/**
 * app.js — TF-IDF Analyzer
 * Gestion du formulaire (mode auto/manuel), streaming SSE, affichage et export.
 */

// --- i18n ---
var langueActuelle = (function () {
    if (typeof window.PLATFORM_LANG === 'string' && window.PLATFORM_LANG) return window.PLATFORM_LANG;
    try { var p = new URLSearchParams(window.location.search).get('lg'); if (p) return p; } catch (_) {}
    try { var s = localStorage.getItem('lang'); if (s) return s; } catch (_) {}
    return 'fr';
})();

function t(cle, params) {
    var trad = (typeof TRANSLATIONS !== 'undefined' && TRANSLATIONS[langueActuelle] && TRANSLATIONS[langueActuelle][cle])
        ? TRANSLATIONS[langueActuelle][cle]
        : (typeof TRANSLATIONS !== 'undefined' && TRANSLATIONS.fr && TRANSLATIONS.fr[cle])
            ? TRANSLATIONS.fr[cle]
            : cle;
    if (params) {
        Object.keys(params).forEach(function (k) {
            trad = trad.replace(new RegExp('\\{' + k + '\\}', 'g'), params[k]);
        });
    }
    return trad;
}

function traduirePage() {
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
        el.innerHTML = t(el.getAttribute('data-i18n'));
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
        el.placeholder = t(el.getAttribute('data-i18n-placeholder'));
    });
}

function changerLangue(lng) {
    langueActuelle = lng;
    try { localStorage.setItem('lang', lng); } catch (_) {}
    traduirePage();
}

function initLangueSelect() {
    var select = document.getElementById('lang-select');
    if (!select) return;
    select.value = langueActuelle;
    select.addEventListener('change', function () {
        changerLangue(this.value);
    });
}

if (typeof window !== 'undefined') {
    window.addEventListener('platformLangChange', function (e) {
        if (e.detail && e.detail.lang) changerLangue(e.detail.lang);
    });
}

(function () {
    'use strict';

    // ─── Configuration ───────────────────────────────────────────────────────

    var baseUrl = window.MODULE_BASE_URL || '.';
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

    // i18n au chargement
    traduirePage();
    initLangueSelect();

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
            afficherStatus(t('error.url_cible_vide'), 'error');
            return;
        }

        if (mode === 'auto' && !motCle) {
            afficherStatus(t('error.mot_cle_vide'), 'error');
            return;
        }

        if (mode === 'manuel' && !urlsManuelles) {
            afficherStatus(t('error.urls_manuelles_vide'), 'error');
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
        mettreAJourProgression(0, t('status.initialisation'));

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
        eventSource = new EventSource(baseUrl + '/stream.php?' + params.toString());

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
                afficherStatus(data.message || t('error.connexion'), 'error');
            } else if (eventSource && eventSource.readyState === EventSource.CLOSED) {
                afficherStatus(t('error.connexion_serveur'), 'error');
            } else {
                afficherStatus(t('error.connexion_interrompue'), 'error');
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
        afficherStatus(t('status.analyse_annulee'), 'warning');
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

        // Auto-collapse config
        var configBody = document.getElementById('configBody');
        if (configBody) { bootstrap.Collapse.getOrCreateInstance(configBody, {toggle:false}).hide(); }

        resultats = data.termes || [];

        // Mettre à jour les KPI
        var nbOK = 0, nbRenforcer = 0, nbAjouter = 0;
        resultats.forEach(function (terme) {
            if (terme.recommandation === 'OK') nbOK++;
            else if (terme.recommandation === 'À renforcer') nbRenforcer++;
            else nbAjouter++;
        });

        kpiScore.textContent = data.score_couverture + '%';
        kpiScore.style.color = couleurScore(data.score_couverture);
        kpiOK.textContent = nbOK;
        kpiRenforcer.textContent = nbRenforcer;
        kpiAjouter.textContent = nbAjouter;
        kpiConcurrents.textContent = data.nb_concurrents_ok + '/' + data.nb_concurrents;

        afficherStatus(
            t('status.analyse_terminee', { nb: resultats.length, total: data.nb_termes_analyses }),
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

        resultatsFiltres = resultats.filter(function (terme) {
            if (filtre !== 'tous' && terme.recommandation !== filtre) return false;
            if (recherche && terme.terme.toLowerCase().indexOf(recherche) === -1) return false;
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
        pageItems.forEach(function (terme) {
            html += '<tr>'
                + '<td class="fw-600">' + escHtml(terme.terme) + '</td>'
                + '<td>' + formatScore(terme.score_concurrents) + '</td>'
                + '<td>' + formatScore(terme.score_cible) + '</td>'
                + '<td>' + formatRatio(terme.ratio) + '</td>'
                + '<td>' + formatBalises(terme.balises_cible) + '</td>'
                + '<td>' + formatBalises(terme.balises_concurrents) + '</td>'
                + '<td>' + badgeRecommandation(terme.recommandation) + '</td>'
                + '</tr>';
        });

        corpsTableau.innerHTML = html;

        var total = resultatsFiltres.length;
        if (total === 0) {
            infoPages.textContent = t('table.aucun_resultat');
            pagination.innerHTML = '';
            return;
        }
        infoPages.textContent = t('table.info_pages', { debut: debut + 1, fin: fin, total: total });

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
        var cleTraduction, classe, icone;
        if (reco === 'À renforcer') {
            cleTraduction = 'reco.a_renforcer';
            classe = 'badge-attention';
            icone = 'bi-exclamation-triangle-fill';
        } else if (reco === 'À ajouter') {
            cleTraduction = 'reco.a_ajouter';
            classe = 'badge-erreur';
            icone = 'bi-plus-circle-fill';
        } else {
            cleTraduction = 'reco.ok';
            classe = 'badge-succes';
            icone = 'bi-check-circle-fill';
        }
        return '<span class="' + classe + '"><i class="bi ' + icone + ' me-1"></i>' + escHtml(t(cleTraduction)) + '</span>';
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
            t('csv.terme'),
            t('csv.score_concurrents'),
            t('csv.score_cible'),
            t('csv.ratio'),
            t('csv.balises_cible'),
            t('csv.balises_concurrents'),
            t('csv.recommandation'),
            t('csv.nb_concurrents')
        ];
        var lignes = [colonnes.join(';')];

        resultatsFiltres.forEach(function (terme) {
            lignes.push([
                '"' + terme.terme.replace(/"/g, '""') + '"',
                terme.score_concurrents,
                terme.score_cible,
                terme.ratio,
                '"' + (terme.balises_cible || []).join(', ') + '"',
                '"' + (terme.balises_concurrents || []).join(', ') + '"',
                '"' + terme.recommandation + '"',
                terme.nb_concurrents
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
