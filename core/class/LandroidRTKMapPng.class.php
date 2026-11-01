<?php
/*
 * Dessin de la grande carte en PNG (extension PHP GD, déjà requise par
 * Jeedom), pour joindre l'image aux notifications. Mêmes règles de
 * recadrage, de trace et de couleurs que la carte SVG du widget.
 */
class LandroidRTKMapPng {

    const SIDE = 900;
    const PAD = 30;
    const SS = 2;

    private static function poly($im, $pts, $col) {
        $n = intval(count($pts) / 2);
        if ($n < 3) {
            return;
        }
        if (PHP_VERSION_ID >= 80000) {
            imagefilledpolygon($im, $pts, $col);
        } else {
            imagefilledpolygon($im, $pts, $n, $col);
        }
    }

    // Trait d'épaisseur $w avec extrémités rondes (polygone + disques).
    private static function line($im, $a, $b, $w, $col) {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len = sqrt($dx * $dx + $dy * $dy);
        $r = $w / 2;
        if ($len > 0.01) {
            $nx = -$dy / $len * $r;
            $ny = $dx / $len * $r;
            self::poly($im, array($a[0] + $nx, $a[1] + $ny, $b[0] + $nx, $b[1] + $ny, $b[0] - $nx, $b[1] - $ny, $a[0] - $nx, $a[1] - $ny), $col);
        }
        $d = max(1, intval(round($w)));
        imagefilledellipse($im, intval(round($a[0])), intval(round($a[1])), $d, $d, $col);
        imagefilledellipse($im, intval(round($b[0])), intval(round($b[1])), $d, $d, $col);
    }

    private static function outline($im, $pts, $w, $col) {
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            self::line($im, $pts[$i], $pts[($i + 1) % $n], $w, $col);
        }
    }

    private static function flat($pts) {
        $o = array();
        foreach ($pts as $p) {
            $o[] = $p[0];
            $o[] = $p[1];
        }
        return $o;
    }

    private static function rgb($im, $hex, $alpha = 0) {
        return imagecolorallocatealpha($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)), $alpha);
    }

    private static function roundRect($x, $y, $w, $h, $r) {
        $pts = array();
        $corners = array(array($x + $w - $r, $y + $r, -90), array($x + $w - $r, $y + $h - $r, 0), array($x + $r, $y + $h - $r, 90), array($x + $r, $y + $r, 180));
        foreach ($corners as $c) {
            for ($i = 0; $i <= 6; $i++) {
                $a = ($c[2] + $i * 15) * M_PI / 180;
                $pts[] = array($c[0] + $r * cos($a), $c[1] + $r * sin($a));
            }
        }
        return $pts;
    }

    // Transforme des points locaux : rotation (degrés, sens horaire), échelle, translation.
    private static function xf($pts, $x, $y, $deg, $s) {
        $a = $deg * M_PI / 180;
        $c = cos($a);
        $si = sin($a);
        $o = array();
        foreach ($pts as $p) {
            $px = $p[0] * $s;
            $py = $p[1] * $s;
            $o[] = array($x + $px * $c - $py * $si, $y + $px * $si + $py * $c);
        }
        return $o;
    }

    private static function drawStation($im, $x, $y, $s) {
        $d = 2 * 18 * $s;
        imagefilledellipse($im, intval(round($x)), intval(round($y)), intval(round($d)), intval(round($d)), self::rgb($im, '#ffffff'));
        $d = 2 * 16 * $s;
        imagefilledellipse($im, intval(round($x)), intval(round($y)), intval(round($d)), intval(round($d)), self::rgb($im, '#70380f'));
        $bolt = self::xf(array(array(3, -11), array(-7, 2), array(0, 2), array(-4, 12), array(8, -4), array(2, -4)), $x, $y, 0, $s);
        self::poly($im, self::flat($bolt), self::rgb($im, '#ffffff'));
    }

    private static function drawRobot($im, $x, $y, $heading, $s, $fourWheels = false) {
        $deg = $heading === null ? 0 : $heading;
        $f = function ($pts) use ($x, $y, $deg, $s) {
            return self::flat(self::xf($pts, $x, $y, $deg, $s));
        };
        // ombre
        $sh = array();
        for ($i = 0; $i < 24; $i++) {
            $a = $i * 15 * M_PI / 180;
            $sh[] = array(2 + 17 * cos($a), 4 + 20 * sin($a));
        }
        self::poly($im, $f($sh), self::rgb($im, '#000000', 95));
        self::poly($im, $f(self::roundRect(-14, -18, 28, 36, 10, 0)), self::rgb($im, '#fff7ed'));
        self::poly($im, $f(self::roundRect(-13, -17, 26, 34, 9, 0)), self::rgb($im, '#f47b20'));
        if ($fourWheels) {
            foreach (array(array(-17, -14), array(-17, 3), array(12, -14), array(12, 3)) as $w) {
                self::poly($im, $f(self::roundRect($w[0], $w[1], 5, 11, 2)), self::rgb($im, '#161b1f'));
            }
        } else {
            self::poly($im, $f(self::roundRect(-17, -12, 5, 24, 2)), self::rgb($im, '#161b1f'));
            self::poly($im, $f(self::roundRect(12, -12, 5, 24, 2)), self::rgb($im, '#161b1f'));
        }
        $c = array();
        for ($i = 0; $i < 16; $i++) {
            $a = $i * 22.5 * M_PI / 180;
            $c[] = array(4.5 * cos($a), 6 + 4.5 * sin($a));
        }
        self::poly($im, $f($c), self::rgb($im, '#f8fafc'));
        if ($heading !== null) {
            self::poly($im, $f(array(array(-8, -19), array(0, -33), array(8, -19))), self::rgb($im, '#f47b20'));
            self::poly($im, $f(array(array(-6, -19), array(0, -29.5), array(6, -19))), self::rgb($im, '#fff7ed'));
        }
    }

    /**
     * Écrit le PNG dans $path. Retourne array('ok'=>bool, 'reason'=>string).
     * $data = résultat de LandroidRTK::getMapData().
     */
    private static function fontFile() {
        $cands = array(
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        );
        foreach ($cands as $f) {
            if (is_file($f) && is_readable($f)) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Texte de l'en-tête ajouté au-dessus de la carte : date et heure, puis
     * « Tonte terminée » (vert) ou, si le robot n'a pas fini, la surface et
     * le temps restants (ambre). Chaque ligne : array(texte, couleur).
     */
    private static function bannerLines($data) {
        $now = (isset($data['now']) && is_numeric($data['now']) && $data['now'] > 0) ? intval($data['now']) : time();
        $lines = array(array(date('d/m/Y à H:i', $now), 'w'));
        $pl = LandroidRTKMapSvg::progressLines(isset($data['progress']) ? $data['progress'] : null);
        foreach ($pl['lines'] as $l) {
            $lines[] = array($l, $pl['done'] ? 'g' : 'a');
        }
        return $lines;
    }

    /** Accents et signes absents de la police GD intégrée (jeu ISO-8859-2). */
    private static function asciiFold($t) {
        return strtr($t, array('à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '²' => '2', '·' => '-', '€' => 'EUR'));
    }

    /**
     * Mise en page de l'en-tête : police TrueType si elle existe, sinon police
     * intégrée de GD agrandie ×2 (toujours lisible, jamais d'échec).
     */
    private static function bannerPlan($W, $lines) {
        $n = count($lines);
        $font = (function_exists('imagettftext') && function_exists('imagettfbbox')) ? self::fontFile() : null;
        if ($font !== null) {
            $size = max(15, min(24, intval(round($W * 0.04))));
            $lh = intval(round($size * 1.75));
            $pad = intval(round($size * 0.8));
            return array('font' => $font, 'size' => $size, 'lh' => $lh, 'pad' => $pad, 'h' => $n * $lh + 2 * $pad);
        }
        $fh = imagefontheight(5);
        $lh = ($fh + 4) * 2;
        return array('font' => null, 'size' => 0, 'lh' => $lh, 'pad' => 10, 'h' => $n * $lh + 20);
    }

    private static function drawBanner($out, $W, $lines, $plan) {
        $cols = array(
            'w' => array(241, 245, 242),
            'g' => array(110, 231, 150),
            'a' => array(255, 200, 87),
        );
        if ($plan['font'] !== null) {
            foreach ($lines as $i => $l) {
                $c = $cols[$l[1]];
                $col = imagecolorallocate($out, $c[0], $c[1], $c[2]);
                imagettftext($out, $plan['size'], 0, intval(round($plan['size'] * 0.9)), $plan['pad'] + $i * $plan['lh'] + intval(round($plan['lh'] * 0.72)), $col, $plan['font'], $l[0]);
            }
            return;
        }
        $fw = imagefontwidth(5);
        $fh = imagefontheight(5);
        $tw = max(1, intval(floor($W / 2)));
        $th = count($lines) * ($fh + 4) + 10;
        $t = imagecreatetruecolor($tw, $th);
        imagefilledrectangle($t, 0, 0, $tw, $th, imagecolorallocate($t, 16, 20, 18));
        foreach ($lines as $i => $l) {
            $c = $cols[$l[1]];
            imagestring($t, 5, 8, 5 + $i * ($fh + 4), self::asciiFold($l[0]), imagecolorallocate($t, $c[0], $c[1], $c[2]));
        }
        imagecopyresized($out, $t, 0, 0, 0, 0, $tw * 2, $th * 2, $tw, $th);
        imagedestroy($t);
    }

    public static function render($data, $path) {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return array('ok' => false, 'reason' => 'extension PHP GD absente');
        }
        $map = (is_array($data) && isset($data['map'])) ? $data['map'] : null;
        if (!is_array($map)) {
            return array('ok' => false, 'reason' => 'aucune carte reçue');
        }
        $sh = LandroidRTKMapSvg::shapes($map);
        $st = LandroidRTKMapSvg::stations($map);
        $geo = array();
        foreach ($sh as $s) {
            $geo = array_merge($geo, $s['outer']);
        }
        $geo = array_merge($geo, $st);
        $bnd = LandroidRTKMapSvg::bounds($geo);
        if (!$bnd) {
            return array('ok' => false, 'reason' => 'carte vide');
        }

        $pos = null;
        $heading = null;
        $docked = false;
        if (isset($data['pos']) && is_array($data['pos']) && isset($data['pos']['lat'], $data['pos']['lon'])
            && is_numeric($data['pos']['lat']) && is_numeric($data['pos']['lon'])) {
            $pos = array(floatval($data['pos']['lat']), floatval($data['pos']['lon']));
            $heading = (isset($data['pos']['heading']) && is_numeric($data['pos']['heading'])) ? floatval($data['pos']['heading']) : null;
            $docked = !empty($data['pos']['docked']);
            if ($docked && $st) {
                $pos = $st[0];
            }
        }

        $ss = self::SS;
        $ls = LandroidRTKMapSvg::lonScale($bnd);
        $wM = max(($bnd['maxLon'] - $bnd['minLon']) * 111320 * $ls, 1);
        $hM = max(($bnd['maxLat'] - $bnd['minLat']) * 110540, 1);
        $pad = self::PAD;
        $k = (self::SIDE - 2 * $pad) / max($wM, $hM);
        $W = intval(round($wM * $k + 2 * $pad));
        $H = intval(round($hM * $k + 2 * $pad));
        $SW = $W * $ss;
        $SH = $H * $ss;
        $scale = min(($W - $pad * 2) / $wM, ($H - $pad * 2) / $hM) * $ss;
        $offX = ($SW - $wM * $scale) / 2;
        $offY = ($SH - $hM * $scale) / 2;
        $proj = function ($p) use ($bnd, $ls, $scale, $offX, $offY) {
            return array($offX + ($p[1] - $bnd['minLon']) * 111320 * $ls * $scale,
                         $offY + ($bnd['maxLat'] - $p[0]) * 110540 * $scale);
        };
        $projAll = function ($pts) use ($proj) {
            return array_map($proj, $pts);
        };

        $im = imagecreatetruecolor($SW, $SH);
        if (!$im) {
            return array('ok' => false, 'reason' => 'création de l\'image impossible');
        }
        imagealphablending($im, true);
        imageantialias($im, false);
        $bg = self::rgb($im, '#101412');
        $green = self::rgb($im, '#008b3a');
        $red = self::rgb($im, '#ff6045');
        $brown = self::rgb($im, '#b66b36');
        imagefilledrectangle($im, 0, 0, $SW - 1, $SH - 1, $bg);

        // masque des zones (blanc dans la pelouse, noir dans les trous)
        $mask = imagecreatetruecolor($SW, $SH);
        $mb = imagecolorallocate($mask, 0, 0, 0);
        $mw = imagecolorallocate($mask, 255, 255, 255);
        imagefilledrectangle($mask, 0, 0, $SW - 1, $SH - 1, $mb);

        foreach ($sh as $s) {
            if ($s['kind'] !== 'zone') {
                continue;
            }
            $outer = $projAll($s['outer']);
            self::poly($im, self::flat($outer), $green);
            self::poly($mask, self::flat($outer), $mw);
            foreach ($s['holes'] as $h) {
                $hp = $projAll($h);
                self::poly($im, self::flat($hp), $bg);
                self::poly($mask, self::flat($hp), $mb);
            }
            self::outline($im, $outer, 5 * $ss, $red);
            foreach ($s['holes'] as $h) {
                self::outline($im, $projAll($h), 5 * $ss, $red);
            }
        }
        // Surface tondue selon Worx (comme l'application) : polygones remplis en vert foncé, limités à la pelouse.
        $cov = LandroidRTKMapSvg::coverageShapes(isset($data['coverage']) ? $data['coverage'] : null);
        if ($cov) {
            $cm = imagecreatetruecolor($SW, $SH);
            $cb = imagecolorallocate($cm, 0, 0, 0);
            $cw_ = imagecolorallocate($cm, 255, 255, 255);
            imagefilledrectangle($cm, 0, 0, $SW - 1, $SH - 1, $cb);
            $minX = $SW;
            $minY = $SH;
            $maxX = 0;
            $maxY = 0;
            $holesAll = array();
            foreach ($cov as $c) {
                $o = $projAll($c['outer']);
                self::poly($cm, self::flat($o), $cw_);
                foreach ($o as $pt) {
                    $minX = min($minX, $pt[0]);
                    $maxX = max($maxX, $pt[0]);
                    $minY = min($minY, $pt[1]);
                    $maxY = max($maxY, $pt[1]);
                }
                foreach ($c['holes'] as $h) {
                    $holesAll[] = $projAll($h);
                }
            }
            foreach ($holesAll as $hp) {
                self::poly($cm, self::flat($hp), $cb);
            }
            $x0 = max(0, intval(floor($minX)) - 1);
            $x1 = min($SW - 1, intval(ceil($maxX)) + 1);
            $y0 = max(0, intval(floor($minY)) - 1);
            $y1 = min($SH - 1, intval(ceil($maxY)) + 1);
            $dark = self::rgb($im, LandroidRTKMapSvg::COVER_COLOR);
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = $x0; $x <= $x1; $x++) {
                    if ((imagecolorat($cm, $x, $y) & 0xFF) >= 128 && (imagecolorat($mask, $x, $y) & 0xFF) >= 128) {
                        imagesetpixel($im, $x, $y, $dark);
                    }
                }
            }
            imagedestroy($cm);
        }

        $tealFill = self::rgb($im, LandroidRTKMapSvg::NOVIS_FILL);
        $tealLine = self::rgb($im, LandroidRTKMapSvg::NOVIS_STROKE);
        foreach ($sh as $s) {
            if ($s['kind'] !== 'novis') {
                continue;
            }
            $o = $projAll($s['outer']);
            self::poly($im, self::flat($o), $tealFill);
            self::outline($im, $o, 2 * $ss, $tealLine);
        }
        foreach ($sh as $s) {
            if ($s['kind'] !== 'exclusion') {
                continue;
            }
            $o = $projAll($s['outer']);
            self::poly($im, self::flat($o), $brown);
            self::outline($im, $o, 3 * $ss, $brown);
        }

        // trace : traits opaques en niveaux de gris (1 niveau = 1 opacité) puis mélange
        // (seulement si Worx n'a pas fourni la surface tondue)
        $segs = $cov ? array() : LandroidRTKMapSvg::trailSegments(isset($data['trail']) ? $data['trail'] : array(), $bnd);
        if ($segs) {
            $now = (isset($data['now']) && is_numeric($data['now']) && $data['now'] > 0) ? floatval($data['now']) : time();
            $cw = (isset($data['cutting_width_m']) && is_numeric($data['cutting_width_m']) && $data['cutting_width_m'] > 0) ? floatval($data['cutting_width_m']) : 0.18;
            $swath = max(LandroidRTKMapSvg::SWATH_MIN_PX * $ss, min(LandroidRTKMapSvg::SWATH_MAX_PX * $ss, $cw * LandroidRTKMapSvg::SWATH_FACTOR * $scale));
            $layer = imagecreatetruecolor($SW, $SH);
            imagefilledrectangle($layer, 0, 0, $SW - 1, $SH - 1, imagecolorallocate($layer, 0, 0, 0));
            $buckets = array();
            foreach ($segs as $sg) {
                for ($i = 1; $i < count($sg); $i++) {
                    $age = max($now - $sg[$i][0], 0);
                    $fade = min($age / LandroidRTKMapSvg::FADE_S, 1);
                    $op = LandroidRTKMapSvg::OP_MAX - (LandroidRTKMapSvg::OP_MAX - LandroidRTKMapSvg::OP_MIN) * $fade;
                    $idx = intval(round($op * 20));
                    $buckets[$idx][] = array($proj(array($sg[$i - 1][1], $sg[$i - 1][2])), $proj(array($sg[$i][1], $sg[$i][2])));
                }
            }
            $table = array();
            $minX = $SW;
            $minY = $SH;
            $maxX = 0;
            $maxY = 0;
            $n = 0;
            foreach ($buckets as $idx => $lines) {
                $n++;
                $g = 25 * $n;
                $table[$g] = $idx / 20;
                $col = imagecolorallocate($layer, $g, $g, $g);
                foreach ($lines as $l) {
                    self::line($layer, $l[0], $l[1], $swath, $col);
                    $minX = min($minX, $l[0][0], $l[1][0]);
                    $maxX = max($maxX, $l[0][0], $l[1][0]);
                    $minY = min($minY, $l[0][1], $l[1][1]);
                    $maxY = max($maxY, $l[0][1], $l[1][1]);
                }
            }
            $x0 = max(0, intval(floor($minX - $swath)));
            $x1 = min($SW - 1, intval(ceil($maxX + $swath)));
            $y0 = max(0, intval(floor($minY - $swath)));
            $y1 = min($SH - 1, intval(ceil($maxY + $swath)));
            $cache = array();
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = $x0; $x <= $x1; $x++) {
                    $g = imagecolorat($layer, $x, $y) & 0xFF;
                    if ($g === 0 || !isset($table[$g]) || (imagecolorat($mask, $x, $y) & 0xFF) < 128) {
                        continue;
                    }
                    if (!isset($cache[$g])) {
                        $o = $table[$g];
                        $cache[$g] = imagecolorallocate($im, intval(round(0x00 * (1 - $o) + 0x00 * $o)), intval(round(0x8b * (1 - $o) + 0x57 * $o)), intval(round(0x3a * (1 - $o) + 0x26 * $o)));
                    }
                    imagesetpixel($im, $x, $y, $cache[$g]);
                }
            }
            imagedestroy($layer);
        }
        imagedestroy($mask);

        $es = 1.3 * $ss;
        foreach ($st as $p) {
            $xy = $proj($p);
            self::drawStation($im, $xy[0], $xy[1], $es);
        }
        if ($pos) {
            $xy = $proj($pos);
            self::drawRobot($im, $xy[0], $xy[1], $docked ? null : $heading, $es, isset($data['model_type']) && $data['model_type'] === 'vision_4wd');
        }

        imageantialias($im, true);
        // En-tête au-dessus de la carte (date, heure, tonte terminée ou restante) :
        // même fond que la carte, pour ne rien masquer du dessin.
        $lines = self::bannerLines($data);
        $plan = self::bannerPlan($W, $lines);
        $out = imagecreatetruecolor($W, $H + $plan['h']);
        imagefilledrectangle($out, 0, 0, $W, $H + $plan['h'], imagecolorallocate($out, 16, 20, 18));
        imagecopyresampled($out, $im, 0, $plan['h'], 0, 0, $W, $H, $SW, $SH);
        imagedestroy($im);
        self::drawBanner($out, $W, $lines, $plan);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            imagedestroy($out);
            return array('ok' => false, 'reason' => 'dossier non créé : ' . $dir);
        }
        $tmp = $path . '.tmp';
        $ok = @imagepng($out, $tmp, 6);
        imagedestroy($out);
        if (!$ok || !@rename($tmp, $path)) {
            @unlink($tmp);
            return array('ok' => false, 'reason' => 'écriture impossible : ' . $path);
        }
        return array('ok' => true, 'reason' => '');
    }
}
