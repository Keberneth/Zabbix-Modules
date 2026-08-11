<?php
/**
 * Regression test for repository identity.
 *
 * The production case this protects: three Veeam servers mounting one physical
 * repository disk, each reporting its full capacity. Merging them is required
 * (or capacity triples); merging two servers' separate local D:\Backups is
 * forbidden (or capacity is understated).
 *
 * Every case is run through every permutation of its input order, because the
 * capacity split used to depend on which row arrived first.
 *
 * Run: php tests/RepositoryGroupingTest.php
 */
require __DIR__.'/../helpers/BackupTypeClassifier.php';
require __DIR__.'/../helpers/ReportDataHelper.php';

use Modules\VeeamBackupReport\Helpers\ReportDataHelper;

$helper = new ReportDataHelper();
$ref = new ReflectionClass($helper);
$group = $ref->getMethod('buildRepositoryGroups'); $group->setAccessible(true);
$summary = $ref->getMethod('buildStorageSummary'); $summary->setAccessible(true);
$keyer = $ref->getMethod('repositoryGroupKey'); $keyer->setAccessible(true);

function row(string $host, string $name, string $path, ?float $cap, float $written): array {
    return [
        'entity_id' => $host.'|'.$name, 'hostid' => (string) crc32($host), 'host' => $host,
        'repository' => $name, 'repo_type' => 'Local', 'path' => $path,
        'group_key' => '', 'metric_start' => 0.0, 'metric_end' => $written, 'metric_change' => 0.0,
        'metric_avg' => 0.0, 'metric_peak' => 0.0, 'days' => 7, 'spark' => [], 'files_31d' => 1.0,
        'capacity_gb' => $cap, 'used_gb' => $cap === null ? null : $cap * 0.5,
        'free_gb' => $cap === null ? null : $cap * 0.5, 'free_pct' => 50.0,
        'online' => true, 'out_of_date' => false, 'state' => 'ok', 'last_clock' => 1000
    ];
}
function withKeys(array $rows, $keyer, $helper): array {
    foreach ($rows as $i => $r) { $rows[$i]['group_key'] = $keyer->invoke($helper, $r); }
    return $rows;
}
function fingerprint(array $groups): string {
    $out = [];
    foreach ($groups as $g) {
        $hosts = $g['hosts']; sort($hosts);
        $out[] = sprintf('%s[cap=%s hosts=%s written=%.0f]', $g['repository'],
            $g['capacity_gb'] === null ? 'null' : number_format($g['capacity_gb'], 1),
            implode('+', $hosts), $g['written_period']);
    }
    sort($out);
    return implode(' ', $out);
}

$cases = [
    'shared disk, identical capacity (must merge)' => [
        row('vbr-a', 'REPO-SHARED', '/mnt/veeam', 122880.0, 300.0),
        row('vbr-b', 'REPO-SHARED', '/mnt/veeam', 122880.0, 200.0),
        row('vbr-c', 'REPO-SHARED', '/mnt/veeam', 122880.0, 100.0),
    ],
    'same path, different names (must NOT merge)' => [
        row('vbr-a', 'REPO-STO-LOCAL', 'D:\\Backups', 40960.0, 300.0),
        row('vbr-b', 'REPO-MMO-LOCAL', 'D:\\Backups', 20480.0, 200.0),
    ],
    'same name+path, slightly stale readings (must merge)' => [
        row('vbr-a', 'SAN', '\\\\nas\\backups', 100.0, 300.0),
        row('vbr-b', 'SAN', '\\\\nas\\backups', 101.0, 200.0),
        row('vbr-c', 'SAN', '\\\\nas\\backups', 100.5, 100.0),
    ],
    'same name+path, clearly different disks (must split)' => [
        row('vbr-a', 'SAN', '\\\\nas\\backups', 100.0, 300.0),
        row('vbr-b', 'SAN', '\\\\nas\\backups', 400.0, 200.0),
    ],
    'straddling the tolerance (order must not change the answer)' => [
        row('vbr-a', 'SAN', '\\\\nas\\backups', 100.0, 300.0),
        row('vbr-b', 'SAN', '\\\\nas\\backups', 101.0, 200.0),
        row('vbr-c', 'SAN', '\\\\nas\\backups', 103.0, 100.0),
    ],
    'null capacity mixed in' => [
        row('vbr-a', 'SAN', '/mnt/x', null, 300.0),
        row('vbr-b', 'SAN', '/mnt/x', 500.0, 200.0),
    ],
];

$fail = 0;
foreach ($cases as $label => $rows) {
    $rows = withKeys($rows, $keyer, $helper);
    $perms = [];
    $n = count($rows);
    // Every ordering of the input must produce the same grouping.
    $idx = range(0, $n - 1);
    $orders = [];
    $permute = function(array $cur, array $rest) use (&$permute, &$orders) {
        if ($rest === []) { $orders[] = $cur; return; }
        foreach ($rest as $i => $v) {
            $next = $rest; unset($next[$i]);
            $permute(array_merge($cur, [$v]), array_values($next));
        }
    };
    $permute([], $idx);

    foreach ($orders as $order) {
        $ordered = [];
        foreach ($order as $i) { $ordered[] = $rows[$i]; }
        $perms[fingerprint($group->invoke($helper, $ordered))] = true;
    }

    $stable = count($perms) === 1;
    $first = array_key_first($perms);
    $groups = $group->invoke($helper, $rows);
    $sum = $summary->invoke($helper, $groups);

    printf("%-52s orders=%2d stable=%s groups=%d capacity=%s\n    %s\n",
        $label, count($orders), $stable ? 'YES' : 'NO !!', count($groups),
        $sum['capacity_gb'] === null ? 'null' : number_format($sum['capacity_gb'], 1), $first);
    if (!$stable) { $fail++; }
}

echo $fail === 0 ? "\nALL ORDERINGS STABLE\n" : "\n$fail UNSTABLE CASES\n";
exit($fail === 0 ? 0 : 1);
