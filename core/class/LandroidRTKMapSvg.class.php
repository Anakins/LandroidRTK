<?php
/*
 * Dessin de la vignette de carte (SVG) côté serveur, pour le widget du
 * dashboard : une simple image <img>, sans aucun JavaScript dans le widget.
 * Reproduit le rendu « compact » de desktop/js/LandroidRTKMap.js (mêmes
 * règles de recadrage, de trace et de couleurs).
 */
class LandroidRTKMapSvg {

    const COMPACT_SIDE = 520;
    const COMPACT_PAD = 22;
    const TRAIL_MAX_GAP_S = 300;
    const TRAIL_MAX_SEGMENT_M = 35;
    const TRAIL_MIN_STEP_M = 0.25;
    const TRAIL_MARGIN_M = 12;
    const SWATH_MIN_PX = 6;
    const SWATH_MAX_PX = 64;
    const SWATH_FACTOR = 2;
    const FADE_S = 43200;
    const OP_MAX = 0.58;
    const OP_MIN = 0.30;
    // Surface déjà tondue (calculée par Worx) : vert foncé, comme dans l'application.
    const COVER_COLOR = '#00602a';
    const NOVIS_FILL = '#166a62';    // zones sans visibilité (bleu-vert de l'application)
    const NOVIS_STROKE = '#4fc3b4';

    private static function pair($pt) {
        if (!is_array($pt) || count($pt) < 2) {
            return null;
        }
        $pt = array_values($pt);
        if (!is_numeric($pt[0]) || !is_numeric($pt[1])) {
            return null;
        }
        $a = floatval($pt[0]);
        $b = floatval($pt[1]);
        return (is_finite($a) && is_finite($b)) ? array($a, $b) : null;
    }

    private static function contourPoints($c) {
        $out = array();
        $pts = (is_array($c) && isset($c['points']) && is_array($c['points'])) ? $c['points'] : array();
        foreach ($pts as $p) {
            $q = self::pair($p);
            if ($q) {
                $out[] = $q;
            }
        }
        return $out;
    }

    private static function addShape(&$out, $kind, $contour) {
        $outer = self::contourPoints($contour);
        if (!$outer) {
            return;
        }
        $holes = array();
        $ch = (is_array($contour) && isset($contour['children']) && is_array($contour['children'])) ? $contour['children'] : array();
        foreach ($ch as $c) {
            $h = self::contourPoints($c);
            if ($h) {
                $holes[] = $h;
            }
        }
        $out[] = array('kind' => $kind, 'outer' => $outer, 'holes' => $holes);
    }

    private static function layer($map, $name) {
        return (is_array($map) && isset($map['layers'][$name]) && is_array($map['layers'][$name])) ? $map['layers'][$name] : array();
    }

    public static function shapes($map) {
        $out = array();
        foreach (self::layer($map, 'boundaries') as $b) {
            $zs = (is_array($b) && isset($b['zones']) && is_array($b['zones'])) ? $b['zones'] : array();
            foreach ($zs as $z) {
                $cs = (is_array($z) && isset($z['contours']) && is_array($z['contours'])) ? $z['contours'] : array();
                // Zone de type 3 = zone sans visibilité (bleu-vert dans l'application), pas une zone de tonte.
                $kind = (is_array($z) && isset($z['summary']['type']) && intval($z['summary']['type']) === 3) ? 'novis' : 'zone';
                foreach ($cs as $c) {
                    self::addShape($out, $kind, $c);
                }
            }
        }
        foreach (self::layer($map, 'exclusions') as $e) {
            $cs = (is_array($e) && isset($e['contours']) && is_array($e['contours'])) ? $e['contours'] : array();
            foreach ($cs as $c) {
                self::addShape($out, 'exclusion', $c);
            }
        }
        return $out;
    }

    /**
     * Polygones de la surface tondue (réponse « coverage » de Worx : liste de
     * contours {points, children}). Les contours dégénérés (moins de 3 points,
     * nombreux dans la réponse) sont ignorés.
     */
    public static function coverageShapes($coverage) {
        $out = array();
        if (!is_array($coverage)) {
            return $out;
        }
        foreach ($coverage as $c) {
            $outer = self::contourPoints($c);
            if (count($outer) < 3) {
                continue;
            }
            $holes = array();
            $ch = (is_array($c) && isset($c['children']) && is_array($c['children'])) ? $c['children'] : array();
            foreach ($ch as $h) {
                $hp = self::contourPoints($h);
                if (count($hp) >= 3) {
                    $holes[] = $hp;
                }
            }
            $out[] = array('outer' => $outer, 'holes' => $holes);
        }
        return $out;
    }

    public static function stations($map) {
        $out = array();
        foreach (self::layer($map, 'markers') as $m) {
            $r = (is_array($m) && isset($m['record']) && is_array($m['record'])) ? $m['record'] : null;
            if (!$r) {
                continue;
            }
            $p = self::pair(array(isset($r['latitude']) ? $r['latitude'] : null, isset($r['longitude']) ? $r['longitude'] : null));
            if ($p) {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function distanceM($a, $b) {
        $mean = (($a[0] + $b[0]) / 2) * M_PI / 180;
        $x = ($b[1] - $a[1]) * 111320 * max(cos($mean), 0.1);
        $y = ($b[0] - $a[0]) * 110540;
        return sqrt($x * $x + $y * $y);
    }

    public static function bounds($points) {
        if (!$points) {
            return null;
        }
        $b = array('minLat' => INF, 'maxLat' => -INF, 'minLon' => INF, 'maxLon' => -INF);
        foreach ($points as $p) {
            $b['minLat'] = min($b['minLat'], $p[0]);
            $b['maxLat'] = max($b['maxLat'], $p[0]);
            $b['minLon'] = min($b['minLon'], $p[1]);
            $b['maxLon'] = max($b['maxLon'], $p[1]);
        }
        return $b;
    }

    public static function lonScale($b) {
        return max(cos((($b['minLat'] + $b['maxLat']) / 2) * M_PI / 180), 0.1);
    }

    private static function inBounds($p, $b, $marginM) {
        $ls = self::lonScale($b);
        $dLat = $marginM / 110540;
        $dLon = $marginM / (111320 * $ls);
        return $p[0] >= $b['minLat'] - $dLat && $p[0] <= $b['maxLat'] + $dLat
            && $p[1] >= $b['minLon'] - $dLon && $p[1] <= $b['maxLon'] + $dLon;
    }

    private static function f($n) {
        $r = round($n * 100) / 100;
        if ($r == 0) {
            return '0';
        }
        return rtrim(rtrim(sprintf('%.2F', $r), '0'), '.');
    }

    private static function path($points, $proj, $close) {
        if (!$points) {
            return '';
        }
        $s = '';
        foreach ($points as $i => $p) {
            $xy = $proj($p);
            $s .= ($i === 0 ? 'M' : 'L') . self::f($xy[0]) . ' ' . self::f($xy[1]) . ' ';
        }
        return $s . ($close ? 'Z' : '');
    }

    public static function trailSegments($trail, $bnd) {
        $segs = array();
        $cur = array();
        $prev = null;
        $flush = function () use (&$segs, &$cur, &$prev) {
            if (count($cur) > 1) {
                $segs[] = $cur;
            }
            $cur = array();
            $prev = null;
        };
        foreach ((is_array($trail) ? $trail : array()) as $t) {
            if (!is_array($t) || count($t) < 3) {
                continue;
            }
            $t = array_values($t);
            $p = self::pair(array($t[1], $t[2]));
            if (!is_numeric($t[0]) || !$p) {
                continue;
            }
            $ts = floatval($t[0]);
            if ($bnd && !self::inBounds($p, $bnd, self::TRAIL_MARGIN_M)) {
                $flush();
                continue;
            }
            if ($prev) {
                $d = self::distanceM(array($prev[1], $prev[2]), $p);
                if ($d < self::TRAIL_MIN_STEP_M) {
                    continue;
                }
                if ($ts - $prev[0] > self::TRAIL_MAX_GAP_S || $d > self::TRAIL_MAX_SEGMENT_M) {
                    $flush();
                }
            }
            $prev = array($ts, $p[0], $p[1]);
            $cur[] = $prev;
        }
        $flush();
        return $segs;
    }

    private static function robot($x, $y, $heading, $fourWheels = false) {
        $rot = ($heading === null || !is_numeric($heading)) ? '' : ' rotate(' . self::f(floatval($heading)) . ')';
        $body = '<ellipse cx="2" cy="4" rx="17" ry="20" fill="#000" opacity=".35"/>'
            . '<rect x="-13" y="-17" width="26" height="34" rx="9" fill="#f47b20" stroke="#fff7ed" stroke-width="2"/>'
            . ($fourWheels
                ? '<rect x="-17" y="-14" width="5" height="11" rx="2" fill="#161b1f"/>'
                . '<rect x="-17" y="3" width="5" height="11" rx="2" fill="#161b1f"/>'
                . '<rect x="12" y="-14" width="5" height="11" rx="2" fill="#161b1f"/>'
                . '<rect x="12" y="3" width="5" height="11" rx="2" fill="#161b1f"/>'
                : '<rect x="-17" y="-12" width="5" height="24" rx="2" fill="#161b1f"/>'
                . '<rect x="12" y="-12" width="5" height="24" rx="2" fill="#161b1f"/>')
            . '<circle cx="0" cy="6" r="4.5" fill="#f8fafc"/>';
        $nose = ($rot !== '') ? '<path d="M -7 -19 L 0 -31 L 7 -19 Z" fill="#fff7ed" stroke="#f47b20" stroke-width="1.5" stroke-linejoin="round"/>' : '';
        return '<g class="robot" transform="translate(' . self::f($x) . ' ' . self::f($y) . ')' . $rot . '">' . $body . $nose . '</g>';
    }

    private static function station($x, $y) {
        return '<g class="station" transform="translate(' . self::f($x) . ' ' . self::f($y) . ')">'
            . '<circle r="17" fill="#70380f" stroke="#fff" stroke-width="2"/>'
            . '<path d="M 3 -11 L -7 2 H 0 L -4 12 L 8 -4 H 2 Z" fill="#fff"/></g>';
    }

    /**
     * $data = résultat de LandroidRTK::getMapData(). Retourne le SVG, ou
     * null s'il n'y a rien à dessiner (aucune carte reçue / carte vide).
     */
    private static function numFr($n) {
        return number_format(floatval($n), 1, ',', '');
    }

    /** « 1 h 41 min », « 41 min », « 5 min ». */
    public static function fmtRemaining($seconds) {
        $m = max(0, intval(round(floatval($seconds) / 60)));
        $h = intval(floor($m / 60));
        $m = $m % 60;
        return ($h > 0) ? ($h . ' h ' . sprintf('%02d', $m) . ' min') : ($m . ' min');
    }

    /**
     * Lignes de texte affichées dans le coin de la carte (widget, onglet,
     * image PNG) : tonte en cours (surface restante / totale, temps restant)
     * ou « Tonte terminée ». Tableau vide si rien à afficher.
     * Retourne array('lines' => [...], 'done' => bool).
     */
    public static function progressLines($progress) {
        if (!is_array($progress) || !isset($progress['state'])) {
            return array('lines' => array(), 'done' => false);
        }
        if ($progress['state'] === 'done') {
            return array('lines' => array('Tonte terminée'), 'done' => true);
        }
        if ($progress['state'] !== 'active') {
            return array('lines' => array(), 'done' => false);
        }
        $lines = array();
        if (isset($progress['area_left'], $progress['area_total']) && $progress['area_total'] !== null) {
            $lines[] = 'Reste ' . self::numFr($progress['area_left']) . ' / ' . self::numFr($progress['area_total']) . ' m²';
        } elseif (isset($progress['pct_left'])) {
            $lines[] = 'Reste ' . intval($progress['pct_left']) . ' %';
        }
        $second = array();
        if (isset($progress['rtn']) && $progress['rtn'] !== null) {
            $second[] = self::fmtRemaining($progress['rtn']);
        }
        if (isset($progress['pct_left']) && count($lines) && strpos($lines[0], '%') === false) {
            $second[] = intval($progress['pct_left']) . ' %';
        }
        if ($second) {
            $lines[] = implode(' · ', $second);
        }
        return array('lines' => $lines, 'done' => false);
    }

    private static function progressBox($progress, $W) {
        $pl = self::progressLines($progress);
        if (!$pl['lines']) {
            return '';
        }
        $maxc = 0;
        foreach ($pl['lines'] as $l) {
            $maxc = max($maxc, function_exists('mb_strlen') ? mb_strlen($l, 'UTF-8') : strlen($l));
        }
        $w = intval(round($maxc * 8.6 + 20));
        $h = count($pl['lines']) * 20 + 12;
        $o = '<g><rect x="10" y="10" width="' . $w . '" height="' . $h . '" rx="8" fill="#101412" fill-opacity=".82" stroke="#3a4640" stroke-width="1"/>';
        foreach ($pl['lines'] as $i => $l) {
            $o .= '<text x="20" y="' . (10 + 20 + $i * 20 - 3) . '" fill="' . ($pl['done'] ? '#7ddc9a' : '#f1f5f2') . '" font-family="Arial,Helvetica,sans-serif" font-size="14" font-weight="600">'
                . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</text>';
        }
        return $o . '</g>';
    }

    public static function render($data) {
        $map = (is_array($data) && isset($data['map'])) ? $data['map'] : null;
        if (!is_array($map)) {
            return null;
        }
        $sh = self::shapes($map);
        $st = self::stations($map);
        $geo = array();
        foreach ($sh as $s) {
            $geo = array_merge($geo, $s['outer']);
        }
        $geo = array_merge($geo, $st);
        $bnd = self::bounds($geo);
        if (!$bnd) {
            return null;
        }

        $pos = null;
        $heading = null;
        $docked = false;
        if (isset($data['pos']) && is_array($data['pos'])) {
            $pos = self::pair(array(isset($data['pos']['lat']) ? $data['pos']['lat'] : null, isset($data['pos']['lon']) ? $data['pos']['lon'] : null));
            $heading = (isset($data['pos']['heading']) && is_numeric($data['pos']['heading'])) ? floatval($data['pos']['heading']) : null;
            $docked = !empty($data['pos']['docked']);
            if ($pos && $docked && $st) {
                $pos = $st[0];
            }
        }

        // Fenêtre = emprise du jardin seul, format du jardin, petite marge.
        $ls = self::lonScale($bnd);
        $wM = max(($bnd['maxLon'] - $bnd['minLon']) * 111320 * $ls, 1);
        $hM = max(($bnd['maxLat'] - $bnd['minLat']) * 110540, 1);
        $pad = self::COMPACT_PAD;
        $k = (self::COMPACT_SIDE - 2 * $pad) / max($wM, $hM);
        $W = intval(round($wM * $k + 2 * $pad));
        $H = intval(round($hM * $k + 2 * $pad));
        $scale = min(($W - $pad * 2) / $wM, ($H - $pad * 2) / $hM);
        $offX = ($W - $wM * $scale) / 2;
        $offY = ($H - $hM * $scale) / 2;
        $proj = function ($p) use ($bnd, $ls, $scale, $offX, $offY) {
            return array($offX + ($p[1] - $bnd['minLon']) * 111320 * $ls * $scale,
                         $offY + ($bnd['maxLat'] - $p[0]) * 110540 * $scale);
        };

        $segs = self::trailSegments(isset($data['trail']) ? $data['trail'] : array(), $bnd);
        $cw = (isset($data['cutting_width_m']) && is_numeric($data['cutting_width_m']) && $data['cutting_width_m'] > 0) ? floatval($data['cutting_width_m']) : 0.18;
        $swath = max(self::SWATH_MIN_PX, min(self::SWATH_MAX_PX, $cw * self::SWATH_FACTOR * $scale));

        $out = array();
        $out[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '">'
            . '<rect width="' . $W . '" height="' . $H . '" rx="14" fill="#101412"/>';
        $clip = '';
        foreach ($sh as $s) {
            if ($s['kind'] !== 'zone') {
                continue;
            }
            $d = self::path($s['outer'], $proj, true);
            foreach ($s['holes'] as $h) {
                $d .= self::path($h, $proj, true);
            }
            $clip .= '<path d="' . $d . '" fill-rule="evenodd" clip-rule="evenodd"/>';
            $out[] = '<path d="' . $d . '" fill="#008b3a" fill-rule="evenodd" stroke="#ff6045" stroke-width="5" stroke-linejoin="round"/>';
        }
        $cov = self::coverageShapes(isset($data['coverage']) ? $data['coverage'] : null);
        if ($cov) {
            // Surface tondue selon Worx : remplissage vert foncé, limité à la pelouse.
            // Un tracé par polygone : deux polygones qui se chevauchent ne se creusent pas l'un l'autre.
            $out[] = '<defs><clipPath id="lrtk-clip" clipPathUnits="userSpaceOnUse">' . $clip . '</clipPath></defs>';
            $out[] = '<g clip-path="url(#lrtk-clip)" fill="' . self::COVER_COLOR . '" fill-rule="evenodd" class="coverage">';
            foreach ($cov as $c) {
                $d = self::path($c['outer'], $proj, true);
                foreach ($c['holes'] as $h) {
                    $d .= self::path($h, $proj, true);
                }
                $out[] = '<path d="' . $d . '"/>';
            }
            $out[] = '</g>';
        }
        foreach ($sh as $s) {
            if ($s['kind'] !== 'novis') {
                continue;
            }
            $out[] = '<path d="' . self::path($s['outer'], $proj, true) . '" fill="' . self::NOVIS_FILL . '" stroke="' . self::NOVIS_STROKE . '" stroke-width="2" stroke-dasharray="6 4" class="novis"/>';
        }
        foreach ($sh as $s) {
            if ($s['kind'] !== 'exclusion') {
                continue;
            }
            $out[] = '<path d="' . self::path($s['outer'], $proj, true) . '" fill="#b66b36" stroke="#b66b36" stroke-width="3" opacity=".95"/>';
        }

        if ($segs && !$cov) {
            $now = (isset($data['now']) && is_numeric($data['now']) && $data['now'] > 0) ? floatval($data['now']) : time();
            $buckets = array();
            foreach ($segs as $sg) {
                for ($i = 1; $i < count($sg); $i++) {
                    $age = max($now - $sg[$i][0], 0);
                    $fade = min($age / self::FADE_S, 1);
                    $op = self::OP_MAX - (self::OP_MAX - self::OP_MIN) * $fade;
                    $key = sprintf('%.2F', round($op * 20) / 20);
                    $a = $proj(array($sg[$i - 1][1], $sg[$i - 1][2]));
                    $b = $proj(array($sg[$i][1], $sg[$i][2]));
                    if (!isset($buckets[$key])) {
                        $buckets[$key] = '';
                    }
                    $buckets[$key] .= 'M' . self::f($a[0]) . ' ' . self::f($a[1]) . 'L' . self::f($b[0]) . ' ' . self::f($b[1]);
                }
            }
            $out[] = '<defs><clipPath id="lrtk-clip" clipPathUnits="userSpaceOnUse">' . $clip . '</clipPath></defs>';
            $out[] = '<g clip-path="url(#lrtk-clip)" fill="none" stroke="#005726" stroke-linecap="round" stroke-linejoin="round" stroke-width="' . self::f($swath) . '">';
            foreach ($buckets as $key => $d) {
                $out[] = '<path d="' . $d . '" opacity="' . $key . '"/>';
            }
            $out[] = '</g>';
        }

        foreach ($st as $p) {
            $xy = $proj($p);
            $out[] = self::station($xy[0], $xy[1]);
        }
        if ($pos) {
            $xy = $proj($pos);
            $out[] = self::robot($xy[0], $xy[1], $docked ? null : $heading, isset($data['model_type']) && $data['model_type'] === 'vision_4wd');
        }
        $out[] = self::progressBox(isset($data['progress']) ? $data['progress'] : null, $W);
        $out[] = '</svg>';
        return implode('', $out);
    }
}
