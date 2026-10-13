// apply-video-fixes.js — Terapkan SEMUA patch video teaser ke root export mana pun.
// Urutan idempoten:
//   1) fix-skip-video.js      -> /*FIX-SKIP-STOP*/ pada handler skip
//   2) upgrade-skip-stop.js   -> versi kuat (pause+mute+reset, retry 200/800ms)
//   3) fix-single-video.js    -> /*FIX-SINGLE-VIDEO*/ saat teaser mulai
//   4) upgrade-single-video.js-> versi berbasis lebar viewer
//   5) fix-hidden-video.js    -> ggInitMedia('') di cabang hidden (unload device non-aktif)
//   6) remove-video-guard.js  -> hapus /*FIX-VIDEO-GUARD*/ (redundan, berat)
//   7) fix-explore-hide.js    -> sembunyikan overlay teaser saat Explore/Skip (cegah "turun")
//   8) fix-menu-anim.js       -> matikan animasi transisi menu skin mobile (cegah "turun")
// Pakai: node tools/apply-video-fixes.js [root ...]
const { execFileSync } = require('child_process');
const path = require('path');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const tools = [
  'fix-skip-video.js',
  'upgrade-skip-stop.js',
  'fix-single-video.js',
  'upgrade-single-video.js',
  'fix-hidden-video.js',
  'remove-video-guard.js',
  'fix-explore-hide.js',
  'fix-menu-anim.js',
];

let fails = 0;
for (const root of roots) {
  console.log('\n================ ROOT: ' + root + ' ================');
  for (const t of tools) {
    try {
      const out = execFileSync('node', [path.join(__dirname, t), root], { encoding: 'utf8' });
      console.log('--- ' + t + ' ---\n' + out.trim());
    } catch (e) {
      fails++;
      console.log('--- ' + t + ' FAIL ---\n' + ((e.stdout || '') + (e.stderr || e.message || '')));
    }
  }
}
console.log('\n' + (fails ? 'SELESAI dengan ' + fails + ' kegagalan.' : 'SELESAI: semua patch berhasil.'));
