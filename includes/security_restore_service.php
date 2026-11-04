<?php
/**
 * CampusIQ Security Backend Restore & Integrity Service
 * 
 * Verifies SHA-256 integrity of hashing/security backend files,
 * detects tampering, and provides one-click restoration from pristine baselines.
 */

class CampusSecurityRestoreService
{
    private string $rootDir;
    private string $manifestPath;

    public function __construct()
    {
        $this->rootDir = realpath(__DIR__ . '/..');
        $this->manifestPath = $this->rootDir . '/storage/security_baseline/manifest.json';
    }

    /**
     * Check integrity of monitored security files.
     */
    public function checkIntegrity(): array
    {
        if (!file_exists($this->manifestPath)) {
            return [
                'status' => 'uninitialized',
                'all_intact' => false,
                'files' => []
            ];
        }

        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        $results = [];
        $allIntact = true;

        foreach ($manifest['files'] as $relativePath => $fileInfo) {
            $fullPath = $this->rootDir . '/' . $relativePath;
            $exists = file_exists($fullPath);
            $currentHash = $exists ? strtoupper(hash_file('sha256', $fullPath)) : 'FILE_NOT_FOUND';
            $baselineHash = strtoupper($fileInfo['sha256']);
            $isIntact = ($currentHash === $baselineHash);

            if (!$isIntact) {
                $allIntact = false;
            }

            $results[$relativePath] = [
                'exists' => $exists,
                'current_hash' => $currentHash,
                'baseline_hash' => $baselineHash,
                'is_intact' => $isIntact,
                'last_modified' => $exists ? date('Y-m-d H:i:s', filemtime($fullPath)) : 'N/A',
                'critical_functions' => $fileInfo['critical_functions'] ?? []
            ];
        }

        return [
            'status' => $allIntact ? 'intact' : 'compromised',
            'all_intact' => $allIntact,
            'files' => $results,
            'manifest_version' => $manifest['version'] ?? '1.0.0',
            'manifest_created' => $manifest['created_at'] ?? 'Unknown'
        ];
    }

    /**
     * Restore a specific security file or all files from baseline.
     */
    public function restoreFiles(?string $targetFile = null): array
    {
        if (!file_exists($this->manifestPath)) {
            return ['success' => false, 'message' => 'Security baseline manifest not found.'];
        }

        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        $restored = [];

        foreach ($manifest['files'] as $relativePath => $fileInfo) {
            if ($targetFile !== null && $targetFile !== $relativePath) {
                continue;
            }

            $baselinePath = $this->rootDir . '/' . $fileInfo['baseline_file'];
            $destPath = $this->rootDir . '/' . $relativePath;

            if (!file_exists($baselinePath)) {
                return ['success' => false, 'message' => "Baseline copy for {$relativePath} not found on disk."];
            }

            $copied = copy($baselinePath, $destPath);
            if (!$copied) {
                return ['success' => false, 'message' => "Failed to restore {$relativePath}. Check disk permissions."];
            }

            $verifiedHash = strtoupper(hash_file('sha256', $destPath));
            $restored[$relativePath] = [
                'restored' => true,
                'sha256' => $verifiedHash,
                'verified' => ($verifiedHash === strtoupper($fileInfo['sha256']))
            ];
        }

        return [
            'success' => true,
            'restored' => $restored,
            'message' => 'Hashing backend files restored and verified against baseline SHA-256 signatures!'
        ];
    }

    /**
     * Simulate file tampering for demonstration purposes during academic presentation.
     */
    public function simulateTampering(string $targetFile = 'includes/security.php'): array
    {
        $fullPath = $this->rootDir . '/' . $targetFile;
        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => "File {$targetFile} not found."];
        }

        // Append a safe tamper marker comment at the bottom
        $tamperComment = "\n// [ASSIGNMENT_DEMO_TAMPER_TEST: " . date('Y-m-d H:i:s') . "] Simulated file alteration for integrity check\n";
        file_put_contents($fullPath, $tamperComment, FILE_APPEND);

        $newHash = strtoupper(hash_file('sha256', $fullPath));

        return [
            'success' => true,
            'target' => $targetFile,
            'new_hash' => $newHash,
            'message' => "Simulated tampering applied to {$targetFile}. SHA-256 checksum has changed!"
        ];
    }
}
