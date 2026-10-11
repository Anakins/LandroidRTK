/* Onglet "Carte" du plugin Landroid RTK.
 *
 * La carte est dessinée côté navigateur, en SVG, à partir de la géométrie
 * RTK téléchargée par le démon (zones, exclusions, station), de la position
 * du robot et de la trace du jour. Aucun fond de carte externe n'est chargé :
 * rien ne quitte votre Jeedom.
 *
 * Les fonctions de dessin (LandroidRTKMap_buildSvg & co) sont pures, sans
 * accès au DOM, pour pouvoir être testées hors navigateur.
 */

var LandroidRTKMap = (function () {
    var BASE_W = 900, BASE_H = 620, BASE_PAD = 48;
    var COMPACT_SIDE = 520, COMPACT_PAD = 22;
    var TRAIL_MAX_GAP_S = 300;          // coupure de la trace après 5 min sans point
    var TRAIL_MAX_SEGMENT_M = 35;       // ... ou saut de position de plus de 35 m
    var TRAIL_MIN_STEP_M = 0.25;
    var TRAIL_MARGIN_M = 12;            // points hors du jardin (+ marge) ignorés
    var SWATH_MIN_PX = 6, SWATH_MAX_PX = 64, SWATH_FACTOR = 2;
    var FADE_S = 12 * 3600;
    var OP_MAX = 0.58, OP_MIN = 0.30;

    function pair(pt) {
        if (!Array.isArray(pt) || pt.length < 2) { return null; }
        var a = parseFloat(pt[0]), b = parseFloat(pt[1]);
        return (isFinite(a) && isFinite(b)) ? [a, b] : null;
    }

    function contourPoints(c) {
        var out = [];
        var pts = (c && Array.isArray(c.points)) ? c.points : [];
        for (var i = 0; i < pts.length; i++) {
            var p = pair(pts[i]);
            if (p) { out.push(p); }
        }
        return out;
    }

    function layer(map, name) {
        var l = map && map.layers && map.layers[name];
        return Array.isArray(l) ? l : [];
    }

    /* Liste de {kind: 'zone'|'exclusion', outer: [...], holes: [[...]]} */
    function shapes(map) {
        var out = [];
        var b = layer(map, 'boundaries');
        for (var i = 0; i < b.length; i++) {
            var zs = (b[i] && Array.isArray(b[i].zones)) ? b[i].zones : [];
            for (var j = 0; j < zs.length; j++) {
                var cs = (zs[j] && Array.isArray(zs[j].contours)) ? zs[j].contours : [];
                for (var k = 0; k < cs.length; k++) { addShape(out, 'zone', cs[k]); }
            }
        }
        var ex = layer(map, 'exclusions');
        for (var e = 0; e < ex.length; e++) {
            var ecs = (ex[e] && Array.isArray(ex[e].contours)) ? ex[e].contours : [];
            for (var m = 0; m < ecs.length; m++) { addShape(out, 'exclusion', ecs[m]); }
        }
        return out;
    }

    function addShape(out, kind, contour) {
        var outer = contourPoints(contour);
        if (!outer.length) { return; }
        var holes = [];
        var ch = (contour && Array.isArray(contour.children)) ? contour.children : [];
        for (var i = 0; i < ch.length; i++) {
            var h = contourPoints(ch[i]);
            if (h.length) { holes.push(h); }
        }
        out.push({kind: kind, outer: outer, holes: holes});
    }

    function stations(map) {
        var out = [];
        var mk = layer(map, 'markers');
        for (var i = 0; i < mk.length; i++) {
            var r = mk[i] && mk[i].record;
            if (!r) { continue; }
            var p = pair([r.latitude, r.longitude]);
            if (p) { out.push(p); }
        }
        return out;
    }

    function distanceM(a, b) {
        var mean = ((a[0] + b[0]) / 2) * Math.PI / 180;
        var x = (b[1] - a[1]) * 111320 * Math.max(Math.cos(mean), 0.1);
        var y = (b[0] - a[0]) * 110540;
        return Math.sqrt(x * x + y * y);
    }

    function bounds(points) {
        if (!points.length) { return null; }
        var b = {minLat: Infinity, maxLat: -Infinity, minLon: Infinity, maxLon: -Infinity};
        for (var i = 0; i < points.length; i++) {
            b.minLat = Math.min(b.minLat, points[i][0]); b.maxLat = Math.max(b.maxLat, points[i][0]);
            b.minLon = Math.min(b.minLon, points[i][1]); b.maxLon = Math.max(b.maxLon, points[i][1]);
        }
        return b;
    }

    function inBounds(p, b, marginM) {
        var lonScale = Math.max(Math.cos(((b.minLat + b.maxLat) / 2) * Math.PI / 180), 0.1);
        var dLat = marginM / 110540, dLon = marginM / (111320 * lonScale);
        return p[0] >= b.minLat - dLat && p[0] <= b.maxLat + dLat &&
            p[1] >= b.minLon - dLon && p[1] <= b.maxLon + dLon;
    }

    function extentM(b) {
        var lonScale = Math.max(Math.cos(((b.minLat + b.maxLat) / 2) * Math.PI / 180), 0.1);
        return {w: Math.max((b.maxLon - b.minLon) * 111320 * lonScale, 1), h: Math.max((b.maxLat - b.minLat) * 110540, 1)};
    }

    function projector(points, W, H, PAD) {
        var b = bounds(points);
        var lonScale = Math.max(Math.cos(((b.minLat + b.maxLat) / 2) * Math.PI / 180), 0.1);
        var wM = Math.max((b.maxLon - b.minLon) * 111320 * lonScale, 1);
        var hM = Math.max((b.maxLat - b.minLat) * 110540, 1);
        var scale = Math.min((W - PAD * 2) / wM, (H - PAD * 2) / hM);
        var offX = (W - wM * scale) / 2, offY = (H - hM * scale) / 2;
        return {
            scale: scale,
            xy: function (p) {
                return [offX + (p[1] - b.minLon) * 111320 * lonScale * scale,
                        offY + (b.maxLat - p[0]) * 110540 * scale];
            }
        };
    }

    function f(n) { return (Math.round(n * 100) / 100).toString(); }

    function path(points, proj, close) {
        if (!points.length) { return ''; }
        var s = '';
        for (var i = 0; i < points.length; i++) {
            var xy = proj.xy(points[i]);
            s += (i === 0 ? 'M' : 'L') + f(xy[0]) + ' ' + f(xy[1]) + ' ';
        }
        return s + (close ? 'Z' : '');
    }

    /* Trace du jour -> segments [[ts, lat, lon], ...] sans trou ni saut. */
    function trailSegments(trail, bnd) {
        var segs = [], cur = [], prev = null;
        function flush() { if (cur.length > 1) { segs.push(cur); } cur = []; prev = null; }
        for (var i = 0; i < (trail || []).length; i++) {
            var t = trail[i];
            if (!Array.isArray(t) || t.length < 3) { continue; }
            var ts = parseFloat(t[0]), p = pair([t[1], t[2]]);
            if (!p || !isFinite(ts)) { continue; }
            if (bnd && !inBounds(p, bnd, TRAIL_MARGIN_M)) { flush(); continue; }
            if (prev) {
                var d = distanceM([prev[1], prev[2]], p);
                if (d < TRAIL_MIN_STEP_M) { continue; }
                if (ts - prev[0] > TRAIL_MAX_GAP_S || d > TRAIL_MAX_SEGMENT_M) { flush(); }
            }
            var pt = [ts, p[0], p[1]];
            cur.push(pt);
            prev = pt;
        }
        flush();
        return segs;
    }

    function robotSvg(x, y, heading) {
        var rot = (heading === null || heading === undefined || !isFinite(heading)) ? '' : ' rotate(' + f(heading) + ')';
        var body = '<ellipse cx="2" cy="4" rx="17" ry="20" fill="#000" opacity=".35"/>' +
            '<rect x="-13" y="-17" width="26" height="34" rx="9" fill="#f47b20" stroke="#fff7ed" stroke-width="2"/>' +
            '<rect x="-17" y="-12" width="5" height="24" rx="2" fill="#161b1f"/>' +
            '<rect x="12" y="-12" width="5" height="24" rx="2" fill="#161b1f"/>' +
            '<circle cx="0" cy="6" r="4.5" fill="#f8fafc"/>';
        var nose = (rot !== '') ? '<path d="M -7 -19 L 0 -31 L 7 -19 Z" fill="#fff7ed" stroke="#f47b20" stroke-width="1.5" stroke-linejoin="round"/>' : '';
        return '<g class="robot" transform="translate(' + f(x) + ' ' + f(y) + ')' + rot + '">' + body + nose + '</g>';
    }

    function stationSvg(x, y) {
        return '<g class="station" transform="translate(' + f(x) + ' ' + f(y) + ')">' +
            '<circle r="17" fill="#70380f" stroke="#fff" stroke-width="2"/>' +
            '<path d="M 3 -11 L -7 2 H 0 L -4 12 L 8 -4 H 2 Z" fill="#fff"/></g>';
    }

    function placeholder(message, W, H) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + W + ' ' + H + '" width="100%" role="img">' +
            '<rect width="100%" height="100%" fill="#101412"/>' +
            '<text x="50%" y="50%" fill="#f8faf4" font-size="24" font-family="Arial,sans-serif" text-anchor="middle">' +
            String(message).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</text></svg>';
    }

    /* data = réponse de l'action ajax getMapData. Retourne {svg, info}.
     * opts.compact : vignette recadrée sur le jardin (zones + station) avec
     * une petite marge, au lieu de la grande carte de l'onglet. */
    function build(data, opts) {
        data = data || {};
        opts = opts || {};
        var W = BASE_W, H = BASE_H, PAD = BASE_PAD;
        var map = data.map;
        var info = {zones: 0, exclusions: 0, trailPoints: 0, hasMap: false};
        if (!map || typeof map !== 'object') {
            return {svg: placeholder('Aucune carte RTK reçue pour l\'instant', W, H), info: info};
        }
        var sh = shapes(map);
        var st = stations(map);
        info.hasMap = true;
        for (var i = 0; i < sh.length; i++) { if (sh[i].kind === 'zone') { info.zones++; } else { info.exclusions++; } }

        var geo = [];
        sh.forEach(function (s) { geo = geo.concat(s.outer); });
        geo = geo.concat(st);
        var bnd = bounds(geo);

        var pos = null, heading = null;
        if (data.pos && pair([data.pos.lat, data.pos.lon])) {
            pos = pair([data.pos.lat, data.pos.lon]);
            heading = (data.pos.heading === null || data.pos.heading === undefined) ? null : parseFloat(data.pos.heading);
            // À la station, le signal RTK dérive de quelques mètres : on
            // dessine le robot sur le repère de la station.
            if (data.pos.docked && st.length) { pos = st[0]; }
        }

        var segs = bnd ? trailSegments(data.trail, bnd) : [];
        var all = geo.slice();
        if (pos) { all.push(pos); }
        segs.forEach(function (sg) { sg.forEach(function (p) { all.push([p[1], p[2]]); info.trailPoints++; }); });
        if (!all.length) {
            return {svg: placeholder('La carte RTK ne contient aucun point', W, H), info: info};
        }

        var fitPoints = all;
        if (opts.compact && geo.length) {
            // Fenêtre = emprise du jardin seul ; le format suit celui du jardin.
            fitPoints = geo;
            var ext = extentM(bounds(geo));
            var k = (COMPACT_SIDE - 2 * COMPACT_PAD) / Math.max(ext.w, ext.h);
            PAD = COMPACT_PAD;
            W = Math.round(ext.w * k + 2 * PAD);
            H = Math.round(ext.h * k + 2 * PAD);
        }
        var proj = projector(fitPoints, W, H, PAD);
        var swath = Math.max(SWATH_MIN_PX, Math.min(SWATH_MAX_PX, (parseFloat(data.cutting_width_m) || 0.18) * SWATH_FACTOR * proj.scale));
        var out = [];
        out.push('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + W + ' ' + H + '" width="100%" role="img" style="background:#101412;border-radius:6px;' + (opts.compact ? 'max-height:260px;' : '') + '">');

        // Zones à tondre : intérieur vert, contour rouge ; trous (îlots) en clair.
        var clip = '';
        sh.forEach(function (s) {
            if (s.kind !== 'zone') { return; }
            var d = path(s.outer, proj, true);
            s.holes.forEach(function (h) { d += path(h, proj, true); });
            clip += '<path d="' + d + '" fill-rule="evenodd" clip-rule="evenodd"/>';
            out.push('<path d="' + d + '" fill="#008b3a" fill-rule="evenodd" stroke="#ff6045" stroke-width="5" stroke-linejoin="round"/>');
        });
        sh.forEach(function (s) {
            if (s.kind === 'zone') { return; }
            out.push('<path d="' + path(s.outer, proj, true) + '" fill="#b66b36" stroke="#b66b36" stroke-width="3" opacity=".95"/>');
        });

        // Trace du jour (bandes tondues), estompée avec l'ancienneté.
        if (segs.length) {
            var now = parseFloat(data.now) || (Date.now() / 1000);
            var buckets = {};
            segs.forEach(function (sg) {
                for (var k = 1; k < sg.length; k++) {
                    var age = Math.max(now - sg[k][0], 0);
                    var fade = Math.min(age / FADE_S, 1);
                    var op = OP_MAX - (OP_MAX - OP_MIN) * fade;
                    var key = (Math.round(op * 20) / 20).toFixed(2);
                    var a = proj.xy([sg[k - 1][1], sg[k - 1][2]]), b = proj.xy([sg[k][1], sg[k][2]]);
                    buckets[key] = (buckets[key] || '') + 'M' + f(a[0]) + ' ' + f(a[1]) + 'L' + f(b[0]) + ' ' + f(b[1]);
                }
            });
            out.push('<defs><clipPath id="lrtk-clip" clipPathUnits="userSpaceOnUse">' + clip + '</clipPath></defs>');
            out.push('<g clip-path="url(#lrtk-clip)" fill="none" stroke="#005726" stroke-linecap="round" stroke-linejoin="round" stroke-width="' + f(swath) + '">');
            Object.keys(buckets).forEach(function (key) {
                out.push('<path d="' + buckets[key] + '" opacity="' + key + '"/>');
            });
            out.push('</g>');
        }

        st.forEach(function (p) { var xy = proj.xy(p); out.push(stationSvg(xy[0], xy[1])); });
        if (pos) { var rxy = proj.xy(pos); out.push(robotSvg(rxy[0], rxy[1], (data.pos && data.pos.docked) ? null : heading)); }
        out.push('</svg>');
        return {svg: out.join(''), info: info};
    }

    return {build: build, trailSegments: trailSegments, distanceM: distanceM, shapes: shapes, stations: stations};
}());

if (typeof module !== 'undefined' && module.exports) {
    module.exports = LandroidRTKMap;
}

/* ------------------------------------------------------------------ */
/* Partie navigateur (jQuery / Jeedom) — ignorée hors navigateur       */
/* ------------------------------------------------------------------ */
if (typeof $ !== 'undefined' && typeof document !== 'undefined' && $('#maptab').length) {
    (function () {
        var timer = null;
        var AJAX = 'plugins/LandroidRTK/core/ajax/LandroidRTK.ajax.php';

        function eqId() { return $('.eqLogicAttr[data-l1key="id"]').first().value(); }

        function ago(sec) {
            if (sec === null || sec === undefined) { return '?'; }
            sec = Math.max(0, Math.round(sec));
            if (sec < 90) { return sec + ' s'; }
            if (sec < 5400) { return Math.round(sec / 60) + ' min'; }
            return Math.round(sec / 3600) + ' h';
        }

        var idTries = 0;
        function load() {
            var id = eqId();
            if (!id) {
                // Après F5, la fiche de l'équipement se remplit en différé : on réessaie un moment.
                if (idTries++ < 40) { setTimeout(function () { if ($('#maptab').is(':visible')) { load(); } }, 500); }
                return;
            }
            idTries = 0;
            $.ajax({
                type: 'POST',
                url: AJAX,
                data: {action: 'getMapData', id: id, apikey: LandroidRTKApikey},
                dataType: 'json',
                error: function (request, status, error) { handleAjaxError(request, status, error); },
                success: function (res) {
                    if (res.state != 'ok') {
                        $('#lrtk_map_status').removeClass('alert-info alert-warning').addClass('alert-danger').text(res.result);
                        return;
                    }
                    render(res.result);
                }
            });
        }

        function render(d) {
            var built = LandroidRTKMap.build(d);
            $('#lrtk_map_container').html(built.svg);

            var parts = [];
            var warn = false;
            if (!d.daemon_ok) {
                parts.push('{{Le démon est arrêté : la carte et la position ne sont pas mises à jour.}}');
                warn = true;
            }
            var md = d.map_diag || {};
            if (!built.info.hasMap) {
                parts.push('{{Aucune carte reçue. Elle est téléchargée par le démon dans les secondes qui suivent son démarrage (puis toutes les 30 minutes) : redémarrez le démon après une mise à jour du plugin.}}');
                warn = true;
                parts.push(d.map_status
                    ? ('{{diagnostic du démon}} : ' + $('<div>').text(d.map_status).html() + (d.map_status_ts ? ' ({{il y a}} ' + ago(d.now - d.map_status_ts) + ')' : ''))
                    : '{{le démon n\'a transmis aucun diagnostic de carte : il n\'a probablement pas été redémarré depuis la mise à jour}}');
            } else {
                parts.push(built.info.zones + ' {{zone(s)}}, ' + built.info.exclusions + ' {{exclusion(s)}}');
                if (d.map_fetched_at) { parts.push('{{carte reçue il y a}} ' + ago(d.now - d.map_fetched_at)); }
                if (built.info.zones === 0) {
                    // Carte reçue mais sans zone exploitable : on affiche sa structure (sans coordonnées).
                    parts.push('{{la carte reçue ne contient aucune zone reconnue}} — {{clés}} : ' +
                        $('<div>').text((md.keys || []).join(', ') || '{{aucune}}').html() +
                        ' ; {{couches}} : ' + $('<div>').text(JSON.stringify(md.layers || {})).html());
                    warn = true;
                }
            }
            if (md.source) {
                parts.push('{{stockage}} : ' + (md.source === 'file' ? '{{fichier}}' : '{{cache Jeedom}}') + ' (' + Math.round((md.bytes || 0) / 1024) + ' {{Ko}})');
            }
            if (d.pos) {
                parts.push('{{robot}} : ' + (d.pos.status ? $('<div>').text(d.pos.status).html() : '?') + ' ({{position il y a}} ' + ago(d.now - d.pos.ts) + ')');
            } else {
                parts.push('{{position du robot inconnue}}');
            }
            var td = d.trail_diag || {};
            var trailTxt = '{{trace du jour}} : ' + built.info.trailPoints + ' {{points affichés}}';
            if (td.stored !== undefined) {
                trailTxt += ' / ' + td.stored + ' {{enregistrés}}';
                if (td.stored === 0 && !td.error) {
                    trailTxt += ' ({{la trace se construit à partir des positions reçues : elle apparaît après quelques déplacements du robot ; rien n\'est repris avant le démarrage du démon}})';
                } else if (td.stored > 0 && built.info.trailPoints === 0) {
                    trailTxt += ' ({{points enregistrés mais hors de la carte ou trop espacés pour être tracés}})';
                }
                if (td.error) { trailTxt += ' — {{erreur}} : ' + $('<div>').text(td.error).html(); warn = true; }
            }
            parts.push(trailTxt);
            $('#lrtk_map_status')
                .removeClass('alert-info alert-warning alert-danger')
                .addClass(warn ? 'alert-warning' : 'alert-info')
                .html(parts.join(' · '));
        }

        function start() {
            stop();
            load();
            if ($('#cb_lrtkMapAuto').is(':checked')) {
                timer = setInterval(function () {
                    if (!$('#maptab').is(':visible')) { stop(); return; }
                    load();
                }, 15000);
            }
        }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }

        $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            if ($(e.target).attr('href') === '#maptab') { start(); } else { stop(); }
        });
        // Après F5, l'onglet peut être déjà affiché avant que ce script soit prêt
        // (l'événement « shown » est alors passé) : on vérifie nous-mêmes.
        function startIfVisible() { if ($('#maptab').is(':visible') && !timer) { start(); } }
        setTimeout(startIfVisible, 300);
        setTimeout(startIfVisible, 1500);
        $('#bt_lrtkMapRefresh').off('click').on('click', function () { load(); });
        $('#cb_lrtkMapAuto').off('change').on('change', function () {
            if ($('#maptab').is(':visible')) { start(); }
        });

        $('#bt_lrtkMapPng').off('click').on('click', function () {
            var id = eqId();
            if (!id) { return; }
            $.ajax({
                type: 'POST',
                url: AJAX,
                data: {action: 'saveMapPng', id: id, apikey: LandroidRTKApikey},
                dataType: 'json',
                error: handleAjaxError,
                success: function (res) {
                    var $box = $('#lrtk_diag_result');
                    if (res.state != 'ok' || !res.result || !res.result.success) {
                        var msg = (res.result && res.result.message) ? res.result.message : '{{Échec (voir les logs LandroidRTK).}}';
                        $box.removeClass('alert-success').addClass('alert-danger').text('{{Image non créée}} : ' + msg).show();
                        return;
                    }
                    var link = AJAX + '?action=downloadMapPng&id=' + encodeURIComponent(id);
                    $box.removeClass('alert-danger').addClass('alert-success').empty()
                        .append($('<div>').text('{{Image créée}} : ' + res.result.file))
                        .append($('<a class="btn btn-sm btn-default" style="margin-top:6px;">').attr('href', link).html('<i class="fas fa-download"></i> {{Télécharger}}'))
                        .show();
                }
            });
        });

        $('#bt_lrtkDiag').off('click').on('click', function () {
            var $bt = $(this);
            var id = eqId();
            if (!id) { return; }
            $bt.find('i').removeClass('fa-file-archive').addClass('fa-spinner fa-spin');
            $.ajax({
                type: 'POST',
                url: AJAX,
                data: {action: 'dumpDiagnostic', id: id, apikey: LandroidRTKApikey},
                dataType: 'json',
                error: function (request, status, error) {
                    handleAjaxError(request, status, error);
                    $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-file-archive');
                },
                success: function (res) {
                    $bt.find('i').removeClass('fa-spinner fa-spin').addClass('fa-file-archive');
                    var $box = $('#lrtk_diag_result');
                    if (res.state != 'ok' || !res.result || !res.result.success) {
                        var msg = (res.result && res.result.message) ? res.result.message : '{{Échec de l\'export (voir les logs LandroidRTK).}}';
                        $box.removeClass('alert-success').addClass('alert-danger').text(msg).show();
                        return;
                    }
                    var r = res.result;
                    var link = AJAX + '?action=downloadDiagnostic&file=' + encodeURIComponent(r.name);
                    $box.removeClass('alert-danger').addClass('alert-success').empty()
                        .append($('<div>').text('{{Export créé}} : ' + r.file))
                        .append($('<div>').text(r.fallback ? '{{Le dossier data/ du plugin n\'était pas inscriptible : le fichier est dans le dossier temporaire de Jeedom.}}' : ''))
                        .append($('<a class="btn btn-sm btn-default" style="margin-top:6px;">').attr('href', link).html('<i class="fas fa-download"></i> {{Télécharger}}'))
                        .show();
                }
            });
        });
    }());
}
