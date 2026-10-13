<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/../storage/tours';
const DB = __DIR__ . '/../storage/app.sqlite';
const SLUGS = ['tour-20260828100342','rs-columbia','menarasiger','pulausebuku','pulauumang','loempialien'];

function load_json(string $path): array {
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    return is_array($data) ? $data : [];
}
function clean(string $text): string { return trim((string)preg_replace('/\s+/u', ' ', strip_tags($text))); }
function generic(string $text): bool {
    return $text === '' || preg_match('/(silakan|template|perlu diisi|belum ada deskripsi|belum tersedia knowledge|gunakan highlights)/i', $text) === 1;
}
function add(array &$items, string $q, string $a, array $aliases, array $keywords): void {
    $q=clean($q); $a=clean($a); if($q===''||$a==='') return;
    foreach($items as $item) if(mb_strtolower($item['question'])===mb_strtolower($q)) return;
    $items[]=['id'=>'QA'.str_pad((string)(count($items)+1),2,'0',STR_PAD_LEFT),'question'=>$q,'answer'=>$a,'aliases'=>array_values(array_unique(array_map('clean',$aliases))),'keywords'=>array_values(array_unique(array_map('clean',$keywords)))];
}

$db=new PDO('sqlite:'.DB); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$stmt=$db->prepare('SELECT slug,name,location,export_path FROM tours WHERE slug=?');
foreach(SLUGS as $slug){
    $stmt->execute([$slug]); $tour=$stmt->fetch(); if(!$tour) throw new RuntimeException("Tour tidak ada: $slug");
    $dir=ROOT.'/'.$slug; $knowledge=load_json($dir.'/knowledge.json'); $identity=load_json($dir.'/scene-identity.json');
    $facts=[];
    foreach(($knowledge['entries']??[]) as $e){
        $title=clean((string)($e['title']??'')); $content=clean((string)($e['content']??''));
        if($title===''||generic($content)||mb_strlen($content)<45) continue;
        $facts[]=['title'=>$title,'answer'=>$content];
    }
    $scenes=[];
    foreach(($identity['scenes']??[]) as $s){
        $name=clean((string)($s['displayName']??$s['name']??$s['id']??'')); if($name==='')continue;
        $ctx=is_array($s['leftContext']??null)?$s['leftContext']:[];
        $desc=clean((string)($ctx['describeHere']??$s['description']??''));
        if(generic($desc)) $desc="$name merupakan salah satu titik yang dapat dijelajahi dalam tur virtual {$tour['name']}. Gunakan hotspot yang tersedia untuk berpindah ke area terhubung.";
        $scenes[$name]=['name'=>$name,'answer'=>$desc];
    }
    $scenes=array_values($scenes); $items=[];
    $intro="{$tour['name']} adalah lokasi di {$tour['location']} yang dapat dijelajahi melalui tur virtual ini.";
    add($items,"Apa yang bisa saya jelajahi di {$tour['name']}?",$intro.' Tur menampilkan sejumlah area dan titik pandang yang dapat dibuka melalui navigasi hotspot.',["Ada apa di {$tour['name']}?","Gambaran tur {$tour['name']}"],[$tour['name'],'tur virtual','jelajah']);
    add($items,"Di kota atau wilayah mana {$tour['name']} berada?","{$tour['name']} tercatat berada di {$tour['location']}.",["Lokasi {$tour['name']}","{$tour['name']} ada di mana?"],[$tour['name'],$tour['location'],'lokasi']);
    add($items,"Bagaimana cara berpindah area dalam tur {$tour['name']}?","Gunakan hotspot navigasi pada panorama untuk berpindah ke area yang terhubung. Nama area aktif membantu memastikan posisi saat menjelajah.",["Cara navigasi {$tour['name']}","Pindah panorama bagaimana?"],[$tour['name'],'hotspot','navigasi']);
    add($items,"Apakah tur {$tour['name']} dapat dijelajahi tanpa datang langsung?","Ya. Tur virtual menampilkan panorama dan area {$tour['name']} secara digital. Informasi operasional kunjungan fisik harus dikonfirmasi melalui kanal resmi lokasi.",["Bisa lihat {$tour['name']} secara virtual?","Tur online {$tour['name']}"],[$tour['name'],'virtual','online']);
    $factTemplates=[
        ['Apa yang perlu diketahui tentang %s?','Jelaskan %s.','Informasi %s'],
        ['Apa fungsi atau makna %s di %s?','Fungsi %s','Tentang %s'],
        ['Mengapa %s menarik untuk diperhatikan?','Keunikan %s','Hal menarik %s'],
        ['Bisakah kamu menceritakan %s secara singkat?','Cerita %s','Deskripsi %s'],
    ];
    foreach($facts as $i=>$f){$tpl=$factTemplates[$i%count($factTemplates)];$q=sprintf($tpl[0],$f['title'],$tour['name']);add($items,$q,$f['answer'],[sprintf($tpl[1],$f['title']),sprintf($tpl[2],$f['title'])],[$tour['name'],$f['title'],'informasi']);if(count($items)>=50)break;}
    $sceneTemplates=[
        ['Apa yang dapat dilihat di area %s?','Ada apa di %s?','Jelaskan area %s'],
        ['Saya sedang berada di %s, area apa ini?','Di mana saya sekarang di %s?','Tentang lokasi %s'],
        ['Apa peran %s dalam tur virtual %s?','Fungsi area %s','Mengapa ada %s?'],
        ['Bagaimana cara menjelajahi %s?','Navigasi di %s','Cara melihat %s'],
        ['Apa hal utama yang perlu diperhatikan di %s?','Yang menarik di %s','Sorotan %s'],
    ];
    $round=0;
    while(count($items)<50 && $scenes){foreach($scenes as $s){$tpl=$sceneTemplates[$round%count($sceneTemplates)];$q=sprintf($tpl[0],$s['name'],$tour['name']);$answer=$s['answer'];if(str_starts_with($tpl[0],'Bagaimana'))$answer.=' Gunakan hotspot pada panorama untuk melanjutkan ke titik yang terhubung.';add($items,$q,$answer,[sprintf($tpl[1],$s['name']),sprintf($tpl[2],$s['name'])],[$tour['name'],$s['name'],'area']);if(count($items)>=50)break;}$round++;if($round>20)break;}
    $fallbacks=[
        ["Apa manfaat melihat {$tour['name']} lewat tur virtual?","Tur virtual membantu mengenali susunan area, titik yang tersedia, dan konteks lokasi sebelum kunjungan langsung.",['Manfaat tur virtual','Kenapa melihat tur ini'],['manfaat','tur virtual']],
        ["Apakah semua informasi kunjungan fisik tersedia di tur ini?","Tidak selalu. Jam operasional, harga, reservasi, dan kebijakan terbaru perlu dikonfirmasi melalui kanal resmi {$tour['name']}.",['Info kunjungan fisik','Apakah data operasional lengkap'],['operasional','konfirmasi']],
        ["Apa yang harus dilakukan jika nama area pada panorama kurang jelas?","Periksa nama scene aktif dan hotspot terhubung. Gunakan keduanya sebagai panduan orientasi tanpa menganggap nama teknis sebagai informasi sejarah.",['Nama panorama kurang jelas','Cara mengenali area'],['scene','orientasi']],
        ["Bisakah saya kembali ke area sebelumnya saat menjelajah?","Bisa jika panorama menyediakan hotspot kembali ke area tersebut. Ikuti koneksi yang terlihat pada scene aktif.",['Kembali ke panorama sebelumnya','Navigasi balik'],['hotspot','kembali']],
        ["Apakah isi tur virtual sama dengan kondisi lokasi saat ini?","Tur merekam kondisi saat materi dibuat. Tata ruang, layanan, atau aturan lokasi dapat berubah, jadi informasi terbaru perlu dicek ke pengelola.",['Apakah tur masih terbaru','Kondisi lokasi sekarang'],['pembaruan','kondisi']],
        ["Bagaimana cara memperoleh gambaran umum {$tour['name']} dengan cepat?","Mulai dari panorama pembuka, baca nama area, lalu ikuti hotspot utama sebelum membuka titik yang lebih khusus.",['Mulai tur dari mana','Cara melihat tur dengan cepat'],['mulai','gambaran umum']],
        ["Apakah saya boleh memakai tur ini untuk merencanakan kunjungan?","Ya, tur dapat membantu mengenali area secara awal. Tetap periksa akses, jadwal, dan kebijakan kunjungan melalui sumber resmi.",['Rencana kunjungan','Persiapan sebelum datang'],['rencana','kunjungan']],
        ["Mengapa beberapa panorama memiliki nama yang mirip?","Beberapa panorama dapat mewakili sudut pandang berbeda pada area fisik yang sama. Nama scene dan hubungan hotspot membantu membedakannya.",['Panorama namanya sama','Scene mirip'],['panorama','sudut pandang']],
        ["Apa beda informasi area dan petunjuk navigasi?","Informasi area menjelaskan identitas atau fungsi tempat, sedangkan petunjuk navigasi hanya menunjukkan hubungan perpindahan antarpanorama.",['Beda deskripsi dan navigasi','Arti hotspot'],['informasi','navigasi']],
        ["Apakah hotspot membuktikan suatu objek terlihat dari posisi saya?","Tidak. Hotspot menunjukkan jalur navigasi. Keberadaan atau detail objek harus didukung deskripsi dan data lokasi.",['Arti koneksi hotspot','Objek terlihat dari sini'],['hotspot','objek']],
        ["Bagaimana jika saya membutuhkan bantuan langsung dari pengelola?","Gunakan kanal resmi {$tour['name']} untuk pertanyaan operasional, reservasi, aksesibilitas, atau kebutuhan khusus.",['Kontak pengelola','Butuh bantuan lokasi'],['pengelola','bantuan']],
        ["Apa cara terbaik menikmati tur {$tour['name']}?","Jelajahi panorama secara berurutan, perhatikan nama tiap area, dan buka titik informasi yang relevan dengan minat Anda.",['Tips menikmati tur','Urutan menjelajah'],['tips','jelajah']],
    ];
    foreach($fallbacks as $f){if(count($items)>=50)break;add($items,$f[0],$f[1],$f[2],array_merge([$tour['name']],$f[3]));}
    if(count($items)<50)throw new RuntimeException("Q&A $slug hanya ".count($items));
    $data=['version'=>'1.0','tourId'=>$slug,'description'=>'Curated Q&A database - team-editable. Matched BEFORE AI pipeline.','items'=>array_slice($items,0,50)];
    $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $targets=[$dir.'/curated-qa.json',$dir.'/package/govirtual-ai/data/curated-qa.json'];
    $export=rtrim($tour['export_path'],'/\\').'/govirtual-ai/data/curated-qa.json'; if(is_dir(dirname($export)))$targets[]=$export;
    if($slug==='tour-20260828100342'&&is_dir($dir.'/staging-voice/govirtual-ai/data'))$targets[]=$dir.'/staging-voice/govirtual-ai/data/curated-qa.json';
    foreach($targets as $target){if(!is_dir(dirname($target)))mkdir(dirname($target),0777,true);if(file_put_contents($target,$json,LOCK_EX)===false)throw new RuntimeException("Gagal menulis $target");}
    echo "$slug: 50 Q&A, ".count($targets)." target\n";
}
