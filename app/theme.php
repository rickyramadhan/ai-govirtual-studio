<?php

declare(strict_types=1);

/**
 * Tema AI Guide per kota, dengan override per tour.
 *
 * PENTING: tema bawaan = tampilan AI Guide yang sudah ada (Semarang dan lainnya). Selama sebuah kota/tour tidak
 * diatur, `config/theme.css` hanya berisi komentar dan tampilan TIDAK berubah. Hanya token terbatas yang bisa
 * diubah (warna aksen, warna panel, font, radius) dan semuanya divalidasi, jadi tema tidak bisa menyisipkan CSS
 * sembarang. Pewarisan: bawaan → kota → override tour.
 */

/** Pilihan font. 'import' kosong = font bawaan runtime (sudah dimuat guide.css). */
function theme_fonts(): array
{
    return [
        'sora' => ['label' => 'Sora (bawaan)', 'css' => "'Sora', system-ui, -apple-system, sans-serif", 'import' => '', 'role' => 'body'],
        'inter' => ['label' => 'Inter', 'css' => "'Inter', system-ui, -apple-system, sans-serif", 'import' => 'Inter:wght@400;500;600;700', 'role' => 'body'],
        'poppins' => ['label' => 'Poppins', 'css' => "'Poppins', system-ui, sans-serif", 'import' => 'Poppins:wght@400;500;600;700', 'role' => 'body'],
        'nunito' => ['label' => 'Nunito', 'css' => "'Nunito', system-ui, sans-serif", 'import' => 'Nunito:wght@400;500;600;700', 'role' => 'body'],
        'cormorant' => ['label' => 'Cormorant Garamond (bawaan)', 'css' => "'Cormorant Garamond', Georgia, serif", 'import' => '', 'role' => 'display'],
        'playfair' => ['label' => 'Playfair Display', 'css' => "'Playfair Display', Georgia, serif", 'import' => 'Playfair+Display:wght@600;700', 'role' => 'display'],
        'lora' => ['label' => 'Lora', 'css' => "'Lora', Georgia, serif", 'import' => 'Lora:wght@600;700', 'role' => 'display'],
        'montserrat' => ['label' => 'Montserrat', 'css' => "'Montserrat', system-ui, sans-serif", 'import' => 'Montserrat:wght@600;700', 'role' => 'display'],
    ];
}

/** Nilai bawaan = persis tampilan AI Guide saat ini. */
function theme_defaults(): array
{
    return ['accent' => '#c9a96e', 'surface' => '#1e1b17', 'font_body' => 'sora', 'font_display' => 'cormorant', 'radius' => 16];
}

/** Bersihkan input tema: nilai tidak valid diganti bawaan. Hanya kunci yang dikenal yang lolos. */
function theme_sanitize(array $in): array
{
    $d = theme_defaults(); $fonts = theme_fonts(); $out = [];
    $hex = fn($v, $def) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $def;
    $out['accent'] = $hex($in['accent'] ?? null, $d['accent']);
    $out['surface'] = $hex($in['surface'] ?? null, $d['surface']);
    $fb = (string)($in['font_body'] ?? ''); $out['font_body'] = isset($fonts[$fb]) && $fonts[$fb]['role'] === 'body' ? $fb : $d['font_body'];
    $fd = (string)($in['font_display'] ?? ''); $out['font_display'] = isset($fonts[$fd]) && $fonts[$fd]['role'] === 'display' ? $fd : $d['font_display'];
    $out['radius'] = max(0, min(32, (int)($in['radius'] ?? $d['radius'])));
    return $out;
}

/** Hanya kunci yang diisi — untuk override parsial. */
function theme_pick(array $in): array
{
    $out = [];
    foreach (array_keys(theme_defaults()) as $k) if (isset($in[$k]) && $in[$k] !== '') $out[$k] = $in[$k];
    return $out;
}

function theme_hex_rgb(string $hex): array
{
    $h = ltrim($hex, '#');
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

function theme_luminance(array $rgb): float
{
    $c = array_map(function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, $rgb);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** Rasio kontras WCAG antara dua warna hex (1–21). */
function theme_contrast(string $a, string $b): float
{
    $la = theme_luminance(theme_hex_rgb($a)); $lb = theme_luminance(theme_hex_rgb($b));
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Tema efektif untuk sebuah tour: bawaan ← kota ← override tour. */
function theme_resolve(array $tour): array
{
    $theme = theme_defaults();
    $city = city_row_by_id((int)($tour['city_id'] ?? 0));
    if ($city && trim((string)$city['theme']) !== '') $theme = array_merge($theme, theme_pick((array)json_decode((string)$city['theme'], true)));
    $ov = read_json(tour_dir((string)$tour['slug']) . '/theme.json', []);
    if (!empty($ov['overrides']) && is_array($ov['overrides'])) $theme = array_merge($theme, theme_pick($ov['overrides']));
    return theme_sanitize($theme);
}

/** URL font yang perlu dimuat untuk sebuah tema (kosong bila memakai font bawaan). */
function theme_font_urls(array $theme): array
{
    $t = theme_sanitize($theme); $fonts = theme_fonts(); $urls = [];
    foreach (['font_body', 'font_display'] as $k) if ($fonts[$t[$k]]['import'] !== '') $urls[] = 'https://fonts.googleapis.com/css2?family=' . $fonts[$t[$k]]['import'] . '&display=swap';
    return $urls;
}

/** CSS tema. $scope ':root' untuk produksi; selector lain (mis. '#themePreview') untuk pratinjau di Studio. Tema bawaan → hanya komentar. */
function theme_css(array $theme, string $scope = ':root'): string
{
    $t = theme_sanitize($theme);
    if ($t === theme_defaults()) return "/* Tema bawaan AI Guide — tidak ada override. */\n";
    $fonts = theme_fonts(); $d = theme_defaults();
    $css = '';
    foreach (theme_font_urls($t) as $u) $css .= "@import url('$u');\n";
    $vars = [];
    if ($t['accent'] !== $d['accent']) {
        [$r, $g, $b] = theme_hex_rgb($t['accent']);
        $vars['--ai-accent'] = $t['accent'];
        $vars['--ai-accent-rgb'] = "$r, $g, $b";
        $vars['--ai-accent-warm'] = sprintf('#%02x%02x%02x', (int)round($r * 0.91), (int)round($g * 0.87), (int)round($b * 0.82));
        $vars['--ai-border-rgb'] = sprintf('%d, %d, %d', (int)min(255, round($r + (255 - $r) * 0.10)), (int)min(255, round($g + (255 - $g) * 0.10)), (int)min(255, round($b + (255 - $b) * 0.10)));
        $vars['--ai-on-accent'] = theme_luminance([$r, $g, $b]) > 0.4 ? '#1a1409' : '#ffffff';
    }
    if ($t['surface'] !== $d['surface']) {
        [$r, $g, $b] = theme_hex_rgb($t['surface']);
        $r2 = (int)round($r * 0.6); $g2 = (int)round($g * 0.6); $b2 = (int)round($b * 0.6);
        $vars['--ai-glass-bg'] = "linear-gradient(135deg, rgba($r, $g, $b, 0.86), rgba($r2, $g2, $b2, 0.78))";
        $vars['--ai-bg-strong'] = "rgba($r, $g, $b, 0.90)";
        $vars['--ai-surface'] = sprintf('rgba(%d, %d, %d, 0.55)', min(255, $r + 14), min(255, $g + 13), min(255, $b + 11));
    }
    if ($t['font_body'] !== $d['font_body']) $vars['--ai-font-body'] = $fonts[$t['font_body']]['css'];
    if ($t['font_display'] !== $d['font_display']) $vars['--ai-font-display'] = $fonts[$t['font_display']]['css'];
    if ($t['radius'] !== $d['radius']) {
        $vars['--ai-radius'] = $t['radius'] . 'px';
        $vars['--ai-radius-sm'] = (int)round($t['radius'] * 0.625) . 'px';
        $vars['--ai-radius-xs'] = (int)round($t['radius'] * 0.375) . 'px';
    }
    $css .= "/* Tema AI Guide (dibuat Studio). Menimpa variabel di guide.css. */\n$scope {\n";
    foreach ($vars as $k => $v) $css .= "  $k: $v;\n";
    return $css . "}\n";
}

/** Tema kota (kolom cities.theme), selalu lengkap dan tersanitasi. */
function city_theme(array $cityRow): array
{
    return theme_sanitize(array_merge(theme_defaults(), theme_pick((array)json_decode((string)$cityRow['theme'], true))));
}

/** Simpan tema kota: hanya nilai yang BERBEDA dari bawaan yang disimpan, jadi kota yang tidak diatur tetap kosong. */
function city_theme_save(int $cityId, array $input): void
{
    $t = theme_sanitize($input); $d = theme_defaults();
    $diff = array_filter($t, fn($v, $k) => $v !== $d[$k], ARRAY_FILTER_USE_BOTH);
    db()->prepare('UPDATE cities SET theme=?, updated_at=? WHERE id=?')->execute([$diff ? json_encode($diff) : '', date('c'), $cityId]);
}

/** Halaman Tema: ?a=theme&city=slug (tema kota) atau ?a=theme&id=ID (override untuk satu tour). */
function render_theme_page(string $flash = ''): void
{
    $tour = isset($_GET['id']) ? get_tour((int)$_GET['id']) : null;
    $city = $tour ? city_row_by_id((int)($tour['city_id'] ?? 0)) : city_row_by_slug((string)($_GET['city'] ?? ''));
    if (!$city) throw new RuntimeException('Kota tidak ditemukan.');
    $fonts = theme_fonts();
    if ($tour) {
        $ov = read_json(tour_dir((string)$tour['slug']) . '/theme.json', []);
        $hasOverride = !empty($ov['overrides']);
        $theme = theme_resolve($tour);
    } else { $hasOverride = false; $theme = city_theme($city); }

    $title = $tour ? 'Tema tour — ' . $tour['name'] : 'Tema kota — ' . $city['name'];
    $opt = function (string $role, string $cur) use ($fonts): string {
        $o = ''; foreach ($fonts as $k => $f) if ($f['role'] === $role) $o .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . h($f['label']) . '</option>';
        return $o;
    };
    $q = $tour ? 'id=' . (int)$tour['id'] : 'city=' . urlencode((string)$city['slug']);
    $nTours = (int)db()->query('SELECT COUNT(*) FROM tours WHERE city_id=' . (int)$city['id'])->fetchColumn();
    $sub = $tour
        ? 'Tour ini mewarisi tema kota <b>' . h((string)$city['name']) . '</b>. Centang di bawah untuk memakai tema khusus hanya untuk tour ini.'
        : 'Berlaku untuk <b>semua tour</b> di kota ' . h((string)$city['name']) . ' (' . $nTours . ' tour). Tour bisa menimpanya satu per satu. Selama tidak diatur, tampilan AI Guide <b>tetap seperti sekarang</b>.';
    $body = ($flash !== '' ? '<div class="card" style="border-color:var(--success)"><p class="ok">✓ ' . h($flash) . '</p></div>' : '')
        . '<div class="card"><h2 style="margin-top:0">' . h($title) . '</h2><p class="muted">' . $sub . '</p>'
        . '<form method="post" action="?a=saveTheme&' . $q . '" id="themeForm" style="display:grid;grid-template-columns:minmax(260px,380px) 1fr;gap:24px;align-items:start">'
        . '<div style="display:grid;gap:10px">'
        . ($tour ? '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="override" value="1" id="thOverride"' . ($hasOverride ? ' checked' : '') . '> Pakai tema khusus untuk tour ini</label>' : '')
        . '<fieldset id="thFields" style="border:0;padding:0;margin:0;display:grid;gap:10px">'
        . '<label>Warna aksen <span class="muted" style="font-size:12px">(tombol, judul, garis)</span></label><div style="display:flex;gap:8px"><input type="color" name="accent" value="' . h($theme['accent']) . '" style="width:56px;padding:2px"><input type="text" id="thAccentTxt" value="' . h($theme['accent']) . '" maxlength="7" style="max-width:110px"></div>'
        . '<label>Warna panel <span class="muted" style="font-size:12px">(latar kaca panel chat)</span></label><div style="display:flex;gap:8px"><input type="color" name="surface" value="' . h($theme['surface']) . '" style="width:56px;padding:2px"><input type="text" id="thSurfaceTxt" value="' . h($theme['surface']) . '" maxlength="7" style="max-width:110px"></div>'
        . '<label>Font isi</label><select name="font_body">' . $opt('body', $theme['font_body']) . '</select>'
        . '<label>Font judul</label><select name="font_display">' . $opt('display', $theme['font_display']) . '</select>'
        . '<label>Kebulatan sudut: <b id="thRadiusVal">' . (int)$theme['radius'] . '</b> px</label><input type="range" name="radius" min="0" max="32" value="' . (int)$theme['radius'] . '">'
        . '</fieldset><p id="thContrast" class="muted" style="font-size:12px;margin:0"></p>'
        . '<p style="margin:6px 0 0;display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary" type="submit" name="do" value="save">Simpan tema</button><button class="btn btn-ghost" type="submit" name="do" value="reset" onclick="return confirm(\'Kembalikan ke tampilan bawaan?\')">Reset ke bawaan</button></p>'
        . '<p class="muted" style="font-size:12px;margin:0">Tema masuk ke paket saat <b>Generate</b>, dan aktif di publik setelah <b>Deploy</b> ulang tour terkait. Tour yang <i>belum</i> memakai runtime terbaru (<code>guide.css</code>) akan mengabaikan tema sampai runtime-nya disinkronkan.</p></div>'
        . '<div><div id="themePreview" style="background:#0b0f14;border-radius:14px;padding:22px;min-height:360px;position:relative;overflow:hidden">'
        . '<div style="background:var(--ai-glass-bg,linear-gradient(135deg,rgba(30,27,23,.86),rgba(18,17,16,.78)));border:1px solid rgba(var(--ai-border-rgb,212,175,120),.25);border-radius:var(--ai-radius,16px);padding:18px;max-width:340px;color:#fdfbf5;font-family:var(--ai-font-body,Sora,system-ui,sans-serif);box-shadow:0 12px 32px rgba(0,0,0,.4)">'
        . '<div style="font-family:var(--ai-font-display,Georgia,serif);font-size:26px;font-weight:700;color:var(--ai-accent,#c9a96e)">Selamat datang</div>'
        . '<p style="font-size:13px;line-height:1.5;margin:8px 0 14px;color:#ede8de">Saya pemandu virtual Anda. Tanyakan apa saja tentang tempat ini.</p>'
        . '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px"><span style="border:1px solid rgba(var(--ai-accent-rgb,201,169,110),.4);background:rgba(var(--ai-accent-rgb,201,169,110),.12);color:var(--ai-accent,#c9a96e);padding:5px 11px;border-radius:999px;font-size:12px">Sejarah tempat ini</span><span style="border:1px solid rgba(var(--ai-border-rgb,212,175,120),.25);padding:5px 11px;border-radius:999px;font-size:12px">Jam buka</span></div>'
        . '<div style="display:flex;gap:8px"><div style="flex:1;border:1px solid rgba(var(--ai-border-rgb,212,175,120),.3);border-radius:var(--ai-radius-sm,10px);padding:9px 12px;font-size:12px;color:#d9d3c7">Ketik pertanyaan…</div><div style="background:var(--ai-accent,#c9a96e);color:var(--ai-on-accent,#1a1409);border-radius:var(--ai-radius-sm,10px);padding:9px 14px;font-size:12px;font-weight:700">Kirim</div></div></div>'
        . '<div style="position:absolute;right:0;top:44%;background:var(--ai-glass-bg,linear-gradient(135deg,rgba(30,27,23,.86),rgba(18,17,16,.78)));border:1px solid rgba(var(--ai-border-rgb,212,175,120),.3);border-right:0;border-radius:999px 0 0 999px;padding:11px 18px;color:#fdfbf5;font:600 12.5px var(--ai-font-body,Sora,system-ui,sans-serif);display:flex;gap:8px;align-items:center"><span style="color:var(--ai-accent,#c9a96e)">✦</span> AI Guide</div></div>'
        . '<p class="muted" style="font-size:12px;margin:8px 0 0">Pratinjau perkiraan; tampilan akhir mengikuti widget AI Guide yang sebenarnya.</p></div></form></div>'
        . '<style id="themePreviewCss">' . theme_css($theme, '#themePreview') . '</style>'
        . '<script>(function(){var f=document.getElementById("themeForm"),st=document.getElementById("themePreviewCss"),ov=document.getElementById("thOverride"),fs=document.getElementById("thFields"),rv=document.getElementById("thRadiusVal"),ct=document.getElementById("thContrast"),t;'
        . 'function sync(){if(ov)fs.disabled=!ov.checked;}function pull(){var d=new FormData(f),p=new URLSearchParams();["accent","surface","font_body","font_display","radius"].forEach(function(k){p.set(k,d.get(k));});p.set("a","themeCss");return p;}'
        . 'function refresh(){var p=pull();rv.textContent=p.get("radius");document.getElementById("thAccentTxt").value=p.get("accent");document.getElementById("thSurfaceTxt").value=p.get("surface");'
        . 'fetch("?"+p.toString(),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){st.textContent=d.css;(d.fonts||[]).forEach(function(u){if(!document.querySelector(\'link[data-th="\'+u+\'"]\')){var l=document.createElement("link");l.rel="stylesheet";l.href=u;l.dataset.th=u;document.head.appendChild(l);}});ct.textContent=d.contrast<3?"⚠ Kontras aksen terhadap panel rendah ("+d.contrast.toFixed(1)+":1). Teks aksen bisa sulit dibaca.":"Kontras aksen terhadap panel: "+d.contrast.toFixed(1)+":1 (baik).";});}'
        . 'function sched(){clearTimeout(t);t=setTimeout(refresh,120);}'
        . 'f.addEventListener("input",sched);f.addEventListener("change",function(){sync();sched();});'
        . '[["thAccentTxt","accent"],["thSurfaceTxt","surface"]].forEach(function(x){var txt=document.getElementById(x[0]),col=f.elements[x[1]];txt.addEventListener("input",function(){if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){col.value=txt.value;sched();}});});'
        . 'sync();refresh();})();</script>';
    layout($title, $body);
}

/** POST saveTheme: simpan tema kota atau override tour. Mengembalikan pesan sukses. */
function process_save_theme(): string
{
    $reset = (string)($_POST['do'] ?? '') === 'reset';
    $tour = isset($_GET['id']) ? get_tour((int)$_GET['id']) : null;
    if ($tour) {
        $file = tour_dir((string)$tour['slug']) . '/theme.json';
        if ($reset || empty($_POST['override'])) { if (is_file($file)) write_json($file, ['overrides' => []]); audit_write('themeReset', ['tour_id' => (int)$tour['id']]); return 'Tour ini kembali memakai tema kota.'; }
        // Simpan hanya nilai yang berbeda dari tema kota, agar perubahan tema kota tetap mengalir ke tour ini.
        $cityRow = city_row_by_id((int)($tour['city_id'] ?? 0)); $base = $cityRow ? city_theme($cityRow) : theme_defaults();
        $t = theme_sanitize($_POST); $diff = array_filter($t, fn($v, $k) => $v !== $base[$k], ARRAY_FILTER_USE_BOTH);
        write_json($file, ['overrides' => $diff]);
        audit_write('themeSave', ['tour_id' => (int)$tour['id']]);
        return 'Tema khusus tour disimpan. Generate lalu Deploy ulang tour ini agar aktif.';
    }
    $city = city_row_by_slug(city_slugify((string)($_GET['city'] ?? ''))) ?? throw new RuntimeException('Kota tidak ditemukan.');
    if ($reset) { city_theme_save((int)$city['id'], theme_defaults()); audit_write('themeReset', ['city' => (string)$city['slug']]); return 'Tema kota dikembalikan ke bawaan (tampilan AI Guide tidak berubah).'; }
    city_theme_save((int)$city['id'], $_POST);
    audit_write('themeSave', ['city' => (string)$city['slug']]);
    return 'Tema kota disimpan. Generate lalu Deploy ulang tour di kota ini agar aktif.';
}
