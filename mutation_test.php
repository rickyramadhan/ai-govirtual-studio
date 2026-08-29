<?php
// Mutation test — create/edit/duplicate/generate/seedContent/saveContent/delete via include index.php.
// Touches only temp data, cleans up after. Real tours id 1-3 untouched (generate on id=1 rewrites its own package).
$php = 'C:\\xampp\\php\\php.exe';
$fails = 0;

function run(array $get, array $post = [], string $method = 'GET'): string {
    $payload = json_encode(['get' => $get, 'post' => $post, 'method' => $method]);
    $cmd = 'C:\\xampp\\php\\php.exe runner_mut.php ' . base64_encode($payload);
    exec($cmd, $out, $rc);
    return trim(implode("\n", $out));
}

function check(string $label, string $r, bool $expectOk = true): void {
    global $fails;
    $ok = ($expectOk && strpos($r, 'OK|') === 0) || (!$expectOk && strpos($r, 'ERR|') === 0);
    if (!$ok) $fails++;
    echo str_pad($label, 28) . ': ' . ($ok ? 'OK' : 'FAIL') . '  ' . substr($r, 0, 90) . "\n";
}

// DB helper to fetch temp tour id
function tempTourId(string $slug): int {
    $pdo = new PDO('sqlite:storage/app.sqlite');
    $s = $pdo->prepare('SELECT id FROM tours WHERE slug=?');
    $s->execute([$slug]);
    return (int)$s->fetchColumn();
}

// 1. generate on id=3 (no export path) → expect graceful: no ERR, renders list page with error card
$r = run(['a'=>'generate','id'=>3]);
check('generate id=3 (no export)', $r, true);
if (strpos($r, 'OK|') === 0 && strpos($r, 'ERROR') === false) echo "  (graceful: no export handled, list rendered with error card)\n";

// 2. generate on id=1 (has export + parsed) → expect OK
check('generate id=1 (real)', run(['a'=>'generate','id'=>1]), true);

// 3. saveAs from id=1 → temp tour
$r = run(['a'=>'saveAs','id'=>1], ['new_name'=>'Temp Mutation Test','new_slug'=>'temp-mutation-test','new_location'=>'Test','new_export'=>'','copy_parsed'=>'1'], 'POST');
check('saveAs id=1', $r, true);
$tempId = tempTourId('temp-mutation-test');
echo "  temp tour id=$tempId\n";

if ($tempId > 0) {
    // 4. edit temp tour
    $r = run(['a'=>'edit','id'=>$tempId], [], 'GET');
    check("edit id=$tempId", $r, true);

    // 5. seedContent on temp tour
    $r = run(['a'=>'seedContent','id'=>$tempId], [], 'POST');
    check("seedContent id=$tempId", $r, true);

    // 6. saveContent curated on temp tour
    $r = run(['a'=>'saveContent','id'=>$tempId,'name'=>'curated-answers'], ['content'=>'{"intents":[],"tourId":"temp-mutation-test","tourName":"Temp Mutation Test"}'], 'POST');
    check("saveContent id=$tempId", $r, true);

    // 7. view temp tour (overview render)
    $r = run(['a'=>'view','id'=>$tempId], [], 'GET');
    check("view id=$tempId", $r, true);

    // 8. save edit (name change) via POST save route
    $r = run(['a'=>'save'], ['id'=>$tempId,'name'=>'Temp Mutation Test Edited','slug'=>'temp-mutation-test','location'=>'Test','export_path'=>''], 'POST');
    check("save id=$tempId", $r, true);

    // 9. delete temp tour
    $r = run(['a'=>'delete','id'=>$tempId], [], 'GET');
    check("delete id=$tempId", $r, true);
    $gone = tempTourId('temp-mutation-test');
    echo "  tour gone after delete: " . ($gone === 0 ? 'YES' : 'NO') . "\n";
    if ($gone !== 0) $fails++;
} else {
    $fails++;
    echo "  SKIP: temp tour not created\n";
}

echo "\nFAILS: $fails\n";
exit($fails > 0 ? 1 : 0);