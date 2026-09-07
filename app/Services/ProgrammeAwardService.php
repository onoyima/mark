<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Maps a programme / course-of-study name to its degree award using the
 * Programme_award.txt reference (Programme | Program_award_combined |
 * award_short_title). Values are matched tolerantly so messy inputs such as
 * "Bsc Accounting", "B.ENG.Computer Engineering" or "Mass Communication
 * B. Sc." still resolve to the canonical programme entry.
 */
class ProgrammeAwardService
{
    /**
     * Parsed entries: canonical programme name => award data.
     *
     * @var array<string, array{programme:string, award_combined:string, award_short:string}>
     */
    protected $entries;

    /**
     * Lazy-loaded from the reference file.
     *
     * @return array<string, array{programme:string, award_combined:string, award_short:string}>
     */
    protected function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $this->entries = [];
        $path = base_path('programme_award.txt');

        if (is_file($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    if (!str_contains($line, '|')) {
                        continue;
                    }
                    $parts = array_map('trim', explode('|', $line));
                    $parts = array_values(array_filter($parts, function ($p) {
                        return $p !== '';
                    }));
                    if (count($parts) < 3) {
                        continue;
                    }
                    // Skip the markdown table header / separator lines.
                    if (strtolower($parts[0]) === 'programme' || str_contains($parts[0], '------')) {
                        continue;
                    }
                    // Only accept lines whose third cell carries a degree
                    // suffix; this guards against stray text appended after
                    // the table (e.g. a CSV header pasted at the end of the
                    // file).
                    if (!preg_match('/^(?:b\.?s\.?c\.?|b\.?a\.?|b\.?e\.?d\.?|b\.?e\.?ng\.?|b\.?n\.?sc\.?|bb\.?a\.?|ll\.?b\.?|mbbs|pharm\.?d\.?|b\.?ph\.?il\.?|b\.?ph\.?|b\.?th\.?|bmls)$/i', $parts[2])) {
                        continue;
                    }
                    $this->entries[$parts[0]] = [
                        'programme' => $parts[0],
                        'award_combined' => $parts[1],
                        'award_short' => $parts[2],
                    ];
                }
            }
        }

        if (empty($this->entries)) {
            Log::warning('Programme_award.txt was not parsed; falling back to empty award map', [
                'path' => $path
            ]);
        }

        return $this->entries;
    }

    /**
     * Resolve a programme/course-of-study value to its canonical award data.
     *
     * @param string|null $value
     * @return array|null Array with keys programme, award_title,
     *                    award_short_title, programme_award_combined,
     *                    programme_category; null when nothing matches.
     */
    public function resolve(?string $value): ?array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $normalized = $this->normalizeProgramme($raw);
        if ($normalized === '') {
            return null;
        }

        $entries = $this->entries();

        // 1) Exact match against normalized canonical names.
        $canonicalByNormalized = [];
        foreach ($entries as $programme => $data) {
            $canonicalByNormalized[$this->normalizeProgramme($programme)] = $programme;
        }
        if (isset($canonicalByNormalized[$normalized])) {
            return $this->awardFor($entries[$canonicalByNormalized[$normalized]]);
        }

        // 2) Canonical name is contained in the value, or value in canonical.
        $candidates = [];
        foreach ($entries as $programme => $data) {
            $p = $this->normalizeProgramme($programme);
            if ($p === '') {
                continue;
            }
            if ($normalized !== $p
                && (str_contains($normalized, $p) || str_contains($p, $normalized))
                && strlen($p) >= 4) {
                $candidates[] = $programme;
            }
        }
        if (count($candidates) === 1) {
            return $this->awardFor($entries[$candidates[0]]);
        }
        if (count($candidates) > 1) {
            usort($candidates, function ($a, $b) {
                return strlen($b) <=> strlen($a);
            });
            return $this->awardFor($entries[$candidates[0]]);
        }

        // 3) Leading-word fallback (e.g. value "Computer Science Education").
        if (preg_match('/^([a-z]+)/', $normalized, $m)) {
            $firstWord = $m[1];
            $wordMatches = [];
            foreach ($entries as $programme => $data) {
                if (preg_match('/^' . preg_quote($firstWord, '/') . '\b/', $this->normalizeProgramme($programme))) {
                    $wordMatches[] = $programme;
                }
            }
            if (count($wordMatches) === 1) {
                return $this->awardFor($entries[$wordMatches[0]]);
            }
        }

        return null;
    }

    /**
     * Normalize a programme value so messy variants map to one canonical form:
     * lowercased, punctuation removed, degree prefixes/suffixes stripped,
     * whitespace collapsed.
     */
    protected function normalizeProgramme(string $value): string
    {
        $text = mb_strtolower(trim($value));

        // Degree prefixes: "B.sc ", "Bsc ", "B.ENG ", "LLB ", "MBBS ", etc.
        $prefixPattern = '/^(?:b\.?s\.?c\.?|b\.?a\.?|b\.?e\.?d\.?|b\.?e\.?ng\.?|b\.?mls\.?|b\.?n\.?sc\.?|bb\.?a\.?|ll\.?b\.?|mbbs|pharm\.?d\.?|b\.?ph\.?il\.?|b\.?ph\.?|b\.?th\.?)\b[\s\.\_\-]*/i';
        $text = preg_replace($prefixPattern, '', $text);

        // Degree suffixes: " B. Sc.", " Bsc" at the end.
        $suffixPattern = '/[\s\.\_\-]*(?:b\.?s\.?c\.?|b\.?a\.?|b\.?e\.?d\.?|b\.?e\.?ng\.?|b\.?mls\.?|b\.?n\.?sc\.?|ll\.?b\.?|mbbs|pharm\.?d\.?)\b\.?$/i';
        $text = preg_replace($suffixPattern, '', $text);

        // Remove punctuation, collapse whitespace.
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $text;
    }

    /**
     * Derive the full award field set from one parsed entry.
     *
     * @param array{programme:string, award_combined:string, award_short:string} $entry
     * @return array
     */
    protected function awardFor(array $entry): array
    {
        $combined = $entry['award_combined'];
        $short = $entry['award_short'];

        // award_title = the degree title without the programme in brackets,
        // e.g. "Bachelor of Science (Accounting)" => "Bachelor of Science".
        $title = $combined;
        if (preg_match('/^(.*?)\s*\(.*\)\s*$/', $combined, $m) && trim($m[1]) !== '') {
            $title = trim($m[1]);
        }

        return [
            'programme' => $entry['programme'],
            'award_title' => $title,
            'award_short_title' => $short,
            'programme_award_combined' => $combined,
            'programme_category' => $this->categoryForShortTitle($short),
        ];
    }

    /**
     * Derive a programme category from the award short title.
     *
     * The category is the LEVEL of the award, not its faculty:
     *  - Bachelor degrees (B.Sc, B.A, B.Ed, B.Eng, LL.B, MBBS, ...) -> Undergraduate
     *  - Postgraduate degrees (M.Sc, M.A, P.G.D., Ph.D, ...)        -> Postgraduate
     *  - Diplomas / national diplomas (ND, HND, Diploma, NCE)       -> Diploma
     */
    protected function categoryForShortTitle(string $short): ?string
    {
        // Normalise (lowercase, dots/spaces removed) for prefix matching.
        $compact = str_replace(['. ', '.', ' '], '', mb_strtolower($short));

        // Diploma-level signals.
        foreach (['nd', 'hnd', 'nce'] as $p) {
            if (str_starts_with($compact, $p)) {
                return 'Diploma';
            }
        }
        if (str_contains($compact, 'diploma')) {
            return 'Diploma';
        }

        // Bachelor degrees that do not begin with 'B':
        // LL.B (Law) and MBBS (Medicine & Surgery) are undergraduates.
        foreach (['llb', 'mbbs'] as $b) {
            if (str_starts_with($compact, $b)) {
                return 'Undergraduate';
            }
        }

        // Master's / doctorate -> Postgraduate.
        foreach (['phd', 'pgd', 'mba', 'msc', 'ma', 'meng', 'mtech', 'mllb', 'med'] as $prefix) {
            if (str_starts_with($compact, $prefix)) {
                return 'Postgraduate';
            }
        }

        // Everything with a bachelor 'B' prefix (B.Sc, B.A, B.Eng, BN.Sc, BMLS, ...).
        if (str_starts_with($compact, 'b')) {
            return 'Undergraduate';
        }

        return null;
    }
}