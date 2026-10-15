<?php

namespace App\Services\Report;

use ZipArchive;

final class IxbrlReportPackage
{
    /** ZIP / unconstrained Report Package 1.0, containing exactly one Inline XBRL report. */
    public function render(string $xhtml, array $evidence): string
    {
        $path = tempnam(sys_get_temp_dir(), 'i4s-report-package-');
        if ($path === false) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_package_failed');
        chmod($path, 0600);
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_package_failed');
            $files = [
                'candidate/META-INF/reportPackage.json' => json_encode([
                    'documentInfo' => ['documentType' => 'https://xbrl.org/report-package/2023'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'candidate/reports/candidate.xhtml' => $xhtml,
                'candidate/evidence.json' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            foreach ($files as $name => $bytes) {
                if (! $zip->addFromString($name, $bytes) || ! $zip->setMtimeName($name, 315532800)) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_package_failed');
                }
            }
            if (! $zip->close()) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_package_failed');
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_package_failed');
            return $bytes;
        } finally {
            if ($zip->status === ZipArchive::ER_OK) {
                // close() may already have completed successfully.
                try { $zip->close(); } catch (\ValueError) {}
            }
            @unlink($path);
        }
    }
}
