<?php
/*
 * Export de contrôle de la carte RTK : dessine TOUS les points enregistrés par
 * Worx pour chaque contour (zone de tonte, zones interdites, zones sans
 * visibilité), dans l'ordre où ils sont reliés, avec le point de départ, une
 * numérotation régulière et les anomalies repérées (pic, doublon, saut, contour
 * qui se croise, surface différente de celle annoncée par Worx).
 * Lecture seule : ne sert qu'à vérifier ce que Worx a enregistré.
 */
class LandroidRTKMapPoints {
    const WIDTH = 1400;
    const PAD = 50;
    const LINE_H = 20;
    const MAX_CROSS_POINTS = 2000;   // au-delà, la recherche de croisements est sautée
    const DUP_M = 0.01;              // deux points à moins de 1 cm
    const JUMP_MIN_M = 3.0;          // segment long : au moins 3 m...
    const JUMP_FACTOR = 20;          // ...et 20 fois le segment médian (les côtés droits ont peu de points)
    const SPIKE_DEG = 25;            // pic : angle entre les deux segments inférieur à 25°
    const SPIKE_MIN_SEG_M = 0.05;
    const AREA_TOL = 0.05;           // écart de surface toléré : 5 %...
    const AREA_TOL_M2 = 0.5;         // ...et 0,5 m²
    const GAP_MAX_M = 1.0;           // écart entre contours signalé en dessous de 1 m

    private static function pt($p) {
        if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
            return array(floatval($p[0]), floatval($p[1]));
        }
        return null;
    }

    private static function pts($c) {
        $out = array();
        $l = (is_array($c) && isset($c['points']) && is_array($c['points'])) ? $c['points'] : array();
        foreach ($l as $p) {
            $q = self::pt($p);
            if ($q) {
                $out[] = $q;
            }
        }
        return $out;
    }

    /** Liste de contours : {label, kind, ring (points), holes (liste de points), area (m² annoncés ou null)}. */
    public static function contours($map) {
        $out = array();
        $layers = (is_array($map) && isset($map['layers']) && is_array($map['layers'])) ? $map['layers'] : array();
        $bd = (isset($layers['boundaries']) && is_array($layers['boundaries'])) ? $layers['boundaries'] : array();
        foreach ($bd as $b) {
            $zs = (is_array($b) && isset($b['zones']) && is_array($b['zones'])) ? $b['zones'] : array();
            foreach ($zs as $z) {
                $type = (is_array($z) && isset($z['summary']['type'])) ? intval($z['summary']['type']) : 0;
                $kind = ($type === 3) ? 'novis' : 'zone';
                $name = (is_array($z) && isset($z['name']) && $z['name'] !== '') ? (string) $z['name'] : ('zone ' . (isset($z['summary']['id']) ? $z['summary']['id'] : '?'));
                $area = (is_array($z) && isset($z['area']) && is_numeric($z['area'])) ? floatval($z['area']) / 1000000.0 : null;
                $cs = (is_array($z) && isset($z['contours']) && is_array($z['contours'])) ? $z['contours'] : array();
                foreach ($cs as $c) {
                    self::addContour($out, $kind, $name, $c, $area);
                }
            }
        }
        $ex = (isset($layers['exclusions']) && is_array($layers['exclusions'])) ? $layers['exclusions'] : array();
        foreach ($ex as $e) {
            $name = (is_array($e) && isset($e['name']) && $e['name'] !== '') ? (string) $e['name'] : 'zone interdite';
            $area = (is_array($e) && isset($e['area']) && is_numeric($e['area'])) ? floatval($e['area']) / 1000000.0 : null;
            $cs = (is_array($e) && isset($e['contours']) && is_array($e['contours'])) ? $e['contours'] : array();
            foreach ($cs as $c) {
                self::addContour($out, 'exclusion', $name, $c, $area);
            }
        }
        return $out;
    }

    private static function addContour(&$out, $kind, $name, $c, $area) {
        $ring = self::pts($c);
        if (count($ring) < 2) {
            return;
        }
        $holes = array();
        $ch = (is_array($c) && isset($c['children']) && is_array($c['children'])) ? $c['children'] : array();
        foreach ($ch as $h) {
            $hp = self::pts($h);
            if (count($hp) >= 2) {
                $holes[] = $hp;
            }
        }
        $out[] = array('label' => $name, 'kind' => $kind, 'ring' => $ring, 'holes' => $holes, 'area' => $area);
    }

    /** Points en mètres locaux (x vers l'est, y vers le nord) autour d'une origine. */
    private static function toMeters($ring, $lat0, $lon0) {
        $kx = 111320 * cos(deg2rad($lat0));
        $ky = 110540;
        $out = array();
        foreach ($ring as $p) {
            $out[] = array(($p[1] - $lon0) * $kx, ($p[0] - $lat0) * $ky);
        }
        return $out;
    }

    private static function areaM2($m) {
        $s = 0.0;
        $n = count($m);
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $s += $m[$i][0] * $m[$j][1] - $m[$j][0] * $m[$i][1];
        }
        return abs($s) / 2;
    }

    private static function segCross($a, $b, $c, $d) {
        $o = function ($p, $q, $r) {
            return ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
        };
        $d1 = $o($a, $b, $c);
        $d2 = $o($a, $b, $d);
        $d3 = $o($c, $d, $a);
        $d4 = $o($c, $d, $b);
        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    /**
     * Anomalies d'un anneau (points en mètres) : liste de {i, type, text} (i = numéro du point, à partir de 1).
     */
    public static function anomalies($m) {
        $n = count($m);
        $out = array();
        if ($n < 3) {
            return $out;
        }
        $len = array();
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $len[$i] = hypot($m[$j][0] - $m[$i][0], $m[$j][1] - $m[$i][1]);
        }
        $sorted = $len;
        sort($sorted);
        $median = $sorted[intval(count($sorted) / 2)];
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            if ($len[$i] < self::DUP_M) {
                $out[] = array('i' => $j + 1, 'type' => 'doublon', 'text' => sprintf('points %d et %d presque superposés (%.1f cm)', $i + 1, $j + 1, $len[$i] * 100));
            } elseif ($len[$i] > max(self::JUMP_MIN_M, self::JUMP_FACTOR * $median)) {
                $out[] = array('i' => $j + 1, 'type' => 'saut', 'text' => sprintf('segment long entre les points %d et %d : %.2f m (médian %.2f m)', $i + 1, $j + 1, $len[$i], $median));
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $p = ($i + $n - 1) % $n;
            $q = ($i + 1) % $n;
            $l1 = $len[$p];
            $l2 = $len[$i];
            if ($l1 < self::SPIKE_MIN_SEG_M || $l2 < self::SPIKE_MIN_SEG_M) {
                continue;
            }
            $ax = $m[$p][0] - $m[$i][0]; $ay = $m[$p][1] - $m[$i][1];
            $bx = $m[$q][0] - $m[$i][0]; $by = $m[$q][1] - $m[$i][1];
            $cos = max(-1, min(1, ($ax * $bx + $ay * $by) / ($l1 * $l2)));
            $deg = rad2deg(acos($cos));
            if ($deg < self::SPIKE_DEG) {
                $out[] = array('i' => $i + 1, 'type' => 'pic', 'text' => sprintf('pic très aigu au point %d (angle de %d°)', $i + 1, round($deg)));
            }
        }
        if ($n <= self::MAX_CROSS_POINTS) {
            for ($i = 0; $i < $n; $i++) {
                $i2 = ($i + 1) % $n;
                for ($j = $i + 2; $j < $n; $j++) {
                    $j2 = ($j + 1) % $n;
                    if ($j2 === $i) {
                        continue;
                    }
                    if (self::segCross($m[$i], $m[$i2], $m[$j], $m[$j2])) {
                        $out[] = array('i' => $i + 1, 'type' => 'croisement', 'text' => sprintf('le contour se croise : segments %d-%d et %d-%d', $i + 1, $i2 + 1, $j + 1, $j2 + 1));
                    }
                }
            }
        }
        return $out;
    }

    /** Distance d'un point à un segment, et point le plus proche. */
    private static function ptSeg($p, $a, $b) {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $l2 = $dx * $dx + $dy * $dy;
        $t = $l2 > 0 ? max(0, min(1, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $l2)) : 0;
        $q = array($a[0] + $t * $dx, $a[1] + $t * $dy);
        return array(hypot($p[0] - $q[0], $p[1] - $q[1]), $q);
    }

    private static function inside($p, $poly) {
        $in = false;
        $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            if ((($poly[$i][1] > $p[1]) !== ($poly[$j][1] > $p[1]))
                && ($p[0] < ($poly[$j][0] - $poly[$i][0]) * ($p[1] - $poly[$i][1]) / ($poly[$j][1] - $poly[$i][1]) + $poly[$i][0])) {
                $in = !$in;
            }
        }
        return $in;
    }

    /**
     * Écart minimal entre deux anneaux (points en mètres) : array(distance, pointA, pointB, chevauchement).
     */
    public static function gap($a, $b) {
        $best = array(INF, null, null);
        $na = count($a);
        $nb = count($b);
        foreach (array(array($a, $b, false), array($b, $a, true)) as $cfg) {
            list($P, $Q, $swap) = $cfg;
            $nq = count($Q);
            foreach ($P as $p) {
                for ($j = 0; $j < $nq; $j++) {
                    list($d, $q) = self::ptSeg($p, $Q[$j], $Q[($j + 1) % $nq]);
                    if ($d < $best[0]) {
                        $best = $swap ? array($d, $q, $p) : array($d, $p, $q);
                    }
                }
            }
        }
        $cross = false;
        for ($i = 0; $i < $na && !$cross; $i++) {
            for ($j = 0; $j < $nb; $j++) {
                if (self::segCross($a[$i], $a[($i + 1) % $na], $b[$j], $b[($j + 1) % $nb])) {
                    $cross = true;
                    break;
                }
            }
        }
        $inside = self::inside($a[0], $b) || self::inside($b[0], $a);
        return array($best[0], $best[1], $best[2], $cross, $inside);
    }

    private static function esc($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function f($n) {
        return rtrim(rtrim(sprintf('%.2F', $n), '0'), '.');
    }

    /** Retourne array('svg' => ..., 'report' => lignes de texte) ou null sans carte. */
    public static function render($map, $title = '') {
        $cs = self::contours($map);
        if (!$cs) {
            return null;
        }
        $minLat = INF; $maxLat = -INF; $minLon = INF; $maxLon = -INF;
        foreach ($cs as $c) {
            foreach (array_merge(array($c['ring']), $c['holes']) as $r) {
                foreach ($r as $p) {
                    $minLat = min($minLat, $p[0]); $maxLat = max($maxLat, $p[0]);
                    $minLon = min($minLon, $p[1]); $maxLon = max($maxLon, $p[1]);
                }
            }
        }
        $lat0 = ($minLat + $maxLat) / 2;
        $kx = 111320 * cos(deg2rad($lat0));
        $ky = 110540;
        $wM = max(($maxLon - $minLon) * $kx, 1.0);
        $hM = max(($maxLat - $minLat) * $ky, 1.0);
        $availW = self::WIDTH - 2 * self::PAD;
        $scale = $availW / $wM;
        if ($hM * $scale > 1100) {
            $scale = 1100 / $hM;
        }
        $mapW = $wM * $scale;
        $mapH = $hM * $scale;
        $colors = array('zone' => '#3ddc84', 'exclusion' => '#ff9f5a', 'novis' => '#4fc3b4');
        $kinds = array('zone' => 'Zone de tonte', 'exclusion' => 'Zone interdite', 'novis' => 'Zone sans visibilité');

        // rapport
        $report = array();
        $allAnom = array();
        $marks = array();   // anomalies à dessiner : {x, y, n}
        $body = array();
        $proj = function ($p) use ($minLon, $maxLat, $kx, $ky, $scale) {
            return array(self::PAD + ($p[1] - $minLon) * $kx * $scale, self::PAD + ($maxLat - $p[0]) * $ky * $scale);
        };
        $anomN = 0;
        $idx = 0;
        $rings = array();
        $gapLines = array();
        $gapMarks = array();
        foreach ($cs as $c) {
            $idx++;
            $col = $colors[$c['kind']];
            $ring = $c['ring'];
            $n = count($ring);
            $m = self::toMeters($ring, $lat0, ($minLon + $maxLon) / 2);
            $area = self::areaM2($m);
            foreach ($c['holes'] as $h) {
                $area -= self::areaM2(self::toMeters($h, $lat0, ($minLon + $maxLon) / 2));
            }
            $perim = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $j = ($i + 1) % $n;
                $perim += hypot($m[$j][0] - $m[$i][0], $m[$j][1] - $m[$i][1]);
            }
            $an = self::anomalies($m);
            $rings[$idx] = array($m, $c['label'], $c['kind'], $c['holes']);
            $line = sprintf('%d. %s « %s » : %d points, périmètre %.1f m, surface %.1f m²', $idx, $kinds[$c['kind']], $c['label'], $n, $perim, $area);
            if ($c['area'] !== null) {
                $line .= sprintf(' (Worx annonce %.1f m²)', $c['area']);
                if ($c['area'] > 0 && abs($area - $c['area']) / $c['area'] > self::AREA_TOL && abs($area - $c['area']) > self::AREA_TOL_M2 && $c['kind'] !== 'novis') {
                    $an[] = array('i' => 1, 'type' => 'surface', 'text' => sprintf('surface calculée %.1f m² différente de celle annoncée par Worx (%.1f m²)', $area, $c['area']));
                }
            }
            if ($c['holes']) {
                $line .= ', ' . count($c['holes']) . ' îlot(s) intérieur(s)';
            }
            $report[] = $line;
            foreach ($an as $a) {
                $anomN++;
                $report[] = sprintf('   ! %d : %s', $anomN, $a['text']);
                if ($a['type'] !== 'surface') {
                    $xy = $proj($ring[min($a['i'], $n) - 1]);
                    $marks[] = array($xy[0], $xy[1], $anomN);
                }
            }
            // tracé relié
            $d = '';
            foreach ($ring as $i => $p) {
                $xy = $proj($p);
                $d .= ($i === 0 ? 'M' : 'L') . self::f($xy[0]) . ' ' . self::f($xy[1]) . ' ';
            }
            $body[] = '<path d="' . $d . 'Z" fill="' . $col . '" fill-opacity=".10" stroke="' . $col . '" stroke-width="1.2" stroke-opacity=".8"/>';
            foreach ($c['holes'] as $h) {
                $dh = '';
                foreach ($h as $i => $p) {
                    $xy = $proj($p);
                    $dh .= ($i === 0 ? 'M' : 'L') . self::f($xy[0]) . ' ' . self::f($xy[1]) . ' ';
                }
                $body[] = '<path d="' . $dh . 'Z" fill="#101412" stroke="' . $col . '" stroke-width="1" stroke-dasharray="4 3" stroke-opacity=".8"/>';
            }
            // points + numéros réguliers + flèches de sens
            $step = max(1, intval(ceil($n / 25 / 5) * 5));
            if ($n <= 25) {
                $step = 1;
            }
            foreach ($ring as $i => $p) {
                $xy = $proj($p);
                $body[] = '<circle cx="' . self::f($xy[0]) . '" cy="' . self::f($xy[1]) . '" r="2.4" fill="' . $col . '"/>';
                if ($i > 0 && $i % $step === 0) {
                    $body[] = '<text x="' . self::f($xy[0] + 5) . '" y="' . self::f($xy[1] - 5) . '" font-size="10" fill="#d8dedb">' . ($i + 1) . '</text>';
                }
            }
            for ($i = 0; $i < $n; $i += max(1, intval($n / 8))) {
                $a = $proj($ring[$i]);
                $b = $proj($ring[($i + 1) % $n]);
                $dx = $b[0] - $a[0]; $dy = $b[1] - $a[1];
                $l = hypot($dx, $dy);
                if ($l < 4) {
                    continue;
                }
                $ux = $dx / $l; $uy = $dy / $l;
                $mx = ($a[0] + $b[0]) / 2; $my = ($a[1] + $b[1]) / 2;
                $body[] = '<path d="M' . self::f($mx + 7 * $ux) . ' ' . self::f($my + 7 * $uy) . 'L' . self::f($mx - 4 * $ux - 4 * $uy) . ' ' . self::f($my - 4 * $uy + 4 * $ux) . 'L' . self::f($mx - 4 * $ux + 4 * $uy) . ' ' . self::f($my - 4 * $uy - 4 * $ux) . 'Z" fill="#ffffff" fill-opacity=".85"/>';
            }
            // point de départ
            $s = $proj($ring[0]);
            $body[] = '<circle cx="' . self::f($s[0]) . '" cy="' . self::f($s[1]) . '" r="7" fill="none" stroke="#ffffff" stroke-width="2"/>';
            $body[] = '<text x="' . self::f($s[0] + 10) . '" y="' . self::f($s[1] + 14) . '" font-size="12" font-weight="bold" fill="#ffffff">' . $idx . ' : départ (point 1)</text>';
        }
        // plusieurs anomalies au même endroit : un seul repère, avec tous les numéros
        $grouped = array();
        // écarts entre contours (zone bleue / zone interdite / bordure du Jardin)
        $lonMid = ($minLon + $maxLon) / 2;
        $ids = array_keys($rings);
        for ($x = 0; $x < count($ids); $x++) {
            for ($y = $x + 1; $y < count($ids); $y++) {
                $A = $rings[$ids[$x]];
                $B = $rings[$ids[$y]];
                if ($A[2] === 'zone' && $B[2] === 'zone') {
                    continue;
                }
                $g = self::gap($A[0], $B[0]);
                // un contour entièrement dans le Jardin n'est pas un chevauchement à signaler
                $inGarden = ($A[2] === 'zone' || $B[2] === 'zone');
                $over = $inGarden ? $g[3] : ($g[3] || $g[4]);
                if (!$over && $g[0] > self::GAP_MAX_M) {
                    continue;
                }
                $g[3] = $over;
                $names = '« ' . $A[1] . ' » (' . $ids[$x] . ') et « ' . $B[1] . ' » (' . $ids[$y] . ')';
                if ($g[3]) {
                    $gapLines[] = sprintf('   ~ %s : se chevauchent ou se touchent', $names);
                } else {
                    $gapLines[] = sprintf('   ~ %s : écart minimal %.0f cm', $names, $g[0] * 100);
                }
                if ($g[1] && $g[2]) {
                    $gapMarks[] = array($g[1], $g[2], $g[0], $g[3]);
                }
            }
        }
        foreach ($gapMarks as $gm) {
            $pa = $proj(array($lat0 + $gm[0][1] / $ky, $lonMid + $gm[0][0] / $kx));
            $pb = $proj(array($lat0 + $gm[1][1] / $ky, $lonMid + $gm[1][0] / $kx));
            $body[] = '<path d="M' . self::f($pa[0]) . ' ' . self::f($pa[1]) . 'L' . self::f($pb[0]) . ' ' . self::f($pb[1]) . '" stroke="#ffd60a" stroke-width="2"/>';
            if (!$gm[3]) {
                $body[] = '<text x="' . self::f(($pa[0] + $pb[0]) / 2 + 6) . '" y="' . self::f(($pa[1] + $pb[1]) / 2 - 6) . '" font-size="12" font-weight="bold" fill="#ffd60a">' . round($gm[2] * 100) . ' cm</text>';
            }
        }
        if ($gapLines) {
            $report[] = 'Écarts entre contours (traits jaunes sur la carte) :';
            foreach ($gapLines as $gl) {
                $report[] = $gl;
            }
        }
        foreach ($marks as $mk) {
            $key = round($mk[0]) . ',' . round($mk[1]);
            if (!isset($grouped[$key])) {
                $grouped[$key] = array($mk[0], $mk[1], array());
            }
            $grouped[$key][2][] = '!' . $mk[2];
        }
        foreach ($grouped as $mk) {
            $body[] = '<circle cx="' . self::f($mk[0]) . '" cy="' . self::f($mk[1]) . '" r="9" fill="none" stroke="#ff3b30" stroke-width="2.5"/>';
            $body[] = '<text x="' . self::f($mk[0] + 11) . '" y="' . self::f($mk[1] - 8) . '" font-size="13" font-weight="bold" fill="#ff3b30">' . implode(' ', $mk[2]) . '</text>';
        }
        $report[] = $anomN ? sprintf('%d anomalie(s) repérée(s) : voir les repères rouges sur la carte. Un pic ou un doublon isolé est souvent un simple bruit du GPS ; un saut ou un croisement est plus probablement une vraie erreur.', $anomN) : 'Aucune anomalie repérée.';

        $head = array_merge(array(($title !== '' ? $title . ' - ' : '') . 'Points de la carte enregistrée par Worx', 'Chaque rond est un point enregistré, reliés dans l\'ordre ; un cercle blanc marque le point 1 de chaque contour, les flèches le sens.'), $report);
        $headH = self::PAD + count($head) * self::LINE_H;
        $H = intval($headH + $mapH + self::PAD + 10);
        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::WIDTH . ' ' . $H . '" width="' . self::WIDTH . '" height="' . $H . '" font-family="DejaVu Sans, Arial, sans-serif">'
            . '<rect width="' . self::WIDTH . '" height="' . $H . '" fill="#101412"/>';
        foreach ($head as $i => $t) {
            $isA = (strpos($t, '   ! ') === 0);
            $isG = (strpos($t, '   ~ ') === 0 || strpos($t, 'Écarts entre') === 0);
            $out .= '<text x="' . self::PAD . '" y="' . (self::PAD / 2 + 14 + $i * self::LINE_H) . '" font-size="' . ($i === 0 ? 17 : 13) . '"' . ($i === 0 ? ' font-weight="bold"' : '') . ' fill="' . ($isA ? '#ff6b60' : ($isG ? '#ffd60a' : '#e8ece9')) . '" xml:space="preserve">' . self::esc($t) . '</text>';
        }
        $out .= '<g transform="translate(0,' . self::f($headH - self::PAD / 2) . ')">' . implode('', $body) . '</g></svg>';
        return array('svg' => $out, 'report' => $report);
    }
}
