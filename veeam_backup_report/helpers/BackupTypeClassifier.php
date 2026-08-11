<?php declare(strict_types = 1);

namespace Modules\VeeamBackupReport\Helpers;

/**
 * Turns Veeam's raw platform/type strings into the workload categories people
 * actually talk about ("VMware VM", "SQL database", "File share").
 *
 * Two rules keep this honest:
 *
 *  - Nothing is ever hidden. An unrecognised platform/type pair is passed
 *    through under its own name rather than swept into "Other", so a workload
 *    the classifier has never seen still appears in the report and the filter.
 *  - The category list is derived from the data that is actually present, never
 *    from this table. If no PostgreSQL object is protected, PostgreSQL is not
 *    offered as a filter option.
 */
class BackupTypeClassifier {

    /**
     * Ordered matcher list. First hit wins, so the more specific database
     * platforms are tested before the generic OS platforms.
     *
     * needle => [stable key, display label, sort weight]
     */
    private const PLATFORM_RULES = [
        'vmware' => ['vmware', 'VMware VM', 10],
        'vsphere' => ['vmware', 'VMware VM', 10],
        'esxi' => ['vmware', 'VMware VM', 10],
        'hyper-v' => ['hyperv', 'Hyper-V VM', 20],
        'hyperv' => ['hyperv', 'Hyper-V VM', 20],
        'nutanix' => ['ahv', 'Nutanix AHV VM', 30],
        'ahv' => ['ahv', 'Nutanix AHV VM', 30],
        'proxmox' => ['proxmox', 'Proxmox VM', 35],
        'olvm' => ['olvm', 'Oracle Linux VM', 36],
        'rhv' => ['rhv', 'Red Hat Virtualization VM', 37],
        'sql server' => ['mssql', 'SQL database', 40],
        'mssql' => ['mssql', 'SQL database', 40],
        'oracle' => ['oracle', 'Oracle database', 50],
        'postgre' => ['postgresql', 'PostgreSQL database', 60],
        'mysql' => ['mysql', 'MySQL database', 65],
        'mongo' => ['mongodb', 'MongoDB database', 66],
        'sap hana' => ['saphana', 'SAP HANA database', 67],
        'unstructured' => ['fileshare', 'File share (NAS)', 70],
        'file share' => ['fileshare', 'File share (NAS)', 70],
        'fileshare' => ['fileshare', 'File share (NAS)', 70],
        'nas ' => ['fileshare', 'File share (NAS)', 70],
        'object storage' => ['objectstorage', 'Object storage', 75],
        'amazon' => ['cloud', 'Cloud VM', 80],
        'aws' => ['cloud', 'Cloud VM', 80],
        'azure' => ['cloud', 'Cloud VM', 80],
        'google cloud' => ['cloud', 'Cloud VM', 80],
        'microsoft 365' => ['m365', 'Microsoft 365', 85],
        'office 365' => ['m365', 'Microsoft 365', 85],
        'windows' => ['windows', 'Windows agent', 90],
        'linux' => ['linux', 'Linux agent', 100],
        'unix' => ['unix', 'Unix agent', 105],
        'aix' => ['unix', 'Unix agent', 105],
        'solaris' => ['unix', 'Unix agent', 105],
        'mac' => ['mac', 'macOS agent', 106]
    ];

    /**
     * Object "type" refinements applied on top of a generic OS platform, so a
     * SQL database protected through the Windows agent is still a database.
     */
    private const TYPE_RULES = [
        'sqlserver' => ['mssql', 'SQL database', 40],
        'sql' => ['mssql', 'SQL database', 40],
        'oracle' => ['oracle', 'Oracle database', 50],
        'postgre' => ['postgresql', 'PostgreSQL database', 60],
        'mysql' => ['mysql', 'MySQL database', 65],
        'mongo' => ['mongodb', 'MongoDB database', 66],
        'hana' => ['saphana', 'SAP HANA database', 67],
        'fileshare' => ['fileshare', 'File share (NAS)', 70],
        'fileserver' => ['fileshare', 'File share (NAS)', 70],
        'nasbackup' => ['fileshare', 'File share (NAS)', 70]
    ];

    /**
     * Classify one protected object.
     *
     * @return array{key:string,label:string,weight:int}
     */
    public function classify(string $platform, string $type = ''): array {
        $platform = trim($platform);
        $type = trim($type);

        $type_hit = $this->match(strtolower($type), self::TYPE_RULES);
        if ($type_hit !== null) {
            return $type_hit;
        }

        $platform_hit = $this->match(strtolower($platform), self::PLATFORM_RULES);
        if ($platform_hit !== null) {
            return $platform_hit;
        }

        // Unknown workload: keep it visible under its own name rather than
        // folding it into a bucket where nobody would look for it.
        if ($platform !== '' && $type !== '' && strcasecmp($platform, $type) !== 0) {
            $label = $platform.' '.$type;
        }
        elseif ($platform !== '') {
            $label = $platform;
        }
        elseif ($type !== '') {
            $label = $type;
        }
        else {
            return ['key' => 'unclassified', 'label' => _('Unclassified'), 'weight' => 900];
        }

        return [
            'key' => 'x-'.substr(md5(strtolower($label)), 0, 10),
            'label' => $label,
            'weight' => 500
        ];
    }

    /**
     * @param array<string,array{0:string,1:string,2:int}> $rules
     * @return array{key:string,label:string,weight:int}|null
     */
    private function match(string $haystack, array $rules): ?array {
        if ($haystack === '') {
            return null;
        }

        foreach ($rules as $needle => $hit) {
            if (strpos($haystack, $needle) !== false) {
                return ['key' => $hit[0], 'label' => $hit[1], 'weight' => $hit[2]];
            }
        }

        return null;
    }
}
