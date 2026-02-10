<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Address_Intelligence
{
    /**
     * @var array<string, string>
     */
    private array $abbreviation_dictionary = [
        'jl' => 'jalan',
        'jln' => 'jalan',
        'kec' => 'kecamatan',
        'kab' => 'kabupaten',
        'kel' => 'kelurahan',
        'ds' => 'desa',
        'no' => 'nomor',
        'rt' => 'rt',
        'rw' => 'rw',
        'dki' => 'jakarta',
    ];

    /**
     * @var array<int, array<string, string>>
     */
    private array $region_dataset = [
        ['province' => 'DKI Jakarta', 'city' => 'Jakarta Selatan', 'district' => 'Kebayoran Baru', 'postal_code' => '12130'],
        ['province' => 'DKI Jakarta', 'city' => 'Jakarta Barat', 'district' => 'Grogol Petamburan', 'postal_code' => '11450'],
        ['province' => 'Jawa Barat', 'city' => 'Bandung', 'district' => 'Coblong', 'postal_code' => '40132'],
        ['province' => 'Jawa Barat', 'city' => 'Bandung', 'district' => 'Sukajadi', 'postal_code' => '40162'],
        ['province' => 'Jawa Timur', 'city' => 'Surabaya', 'district' => 'Tegalsari', 'postal_code' => '60261'],
        ['province' => 'Jawa Timur', 'city' => 'Surabaya', 'district' => 'Wonokromo', 'postal_code' => '60243'],
        ['province' => 'DI Yogyakarta', 'city' => 'Sleman', 'district' => 'Depok', 'postal_code' => '55281'],
    ];

    /**
     * @return array{raw: string, normalized: string, tokens: array<int, string>}
     */
    public function preprocess(string $raw_address): array
    {
        $trimmed = trim($raw_address);
        $normalized = strtolower($trimmed);

        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u',
            'ç' => 'c',
        ]);

        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;

        $tokens = explode(' ', trim($normalized));
        $tokens = array_values(array_filter(array_map(function (string $token): string {
            return $this->abbreviation_dictionary[$token] ?? $token;
        }, $tokens), static fn(string $token): bool => $token !== ''));

        return [
            'raw' => $raw_address,
            'normalized' => implode(' ', $tokens),
            'tokens' => $tokens,
        ];
    }

    /**
     * @return array{normalized: string, confidence: int, ambiguous: bool, warning: string, suggestions: array<int, array<string, mixed>>}
     */
    public function suggest(string $raw_address): array
    {
        $preprocessed = $this->preprocess($raw_address);
        $tokens = $preprocessed['tokens'];

        $scored = [];
        foreach ($this->region_dataset as $region) {
            $region_text = strtolower($region['district'] . ' ' . $region['city'] . ' ' . $region['province'] . ' ' . $region['postal_code']);
            $region_tokens = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', $region_text) ?: $region_text)));

            $token_score = $this->token_similarity($tokens, $region_tokens);
            $fuzzy_score = $this->fuzzy_similarity($preprocessed['normalized'], $region_text);
            $confidence = (int) round(($token_score * 0.65) + ($fuzzy_score * 0.35));

            $scored[] = [
                'district' => $region['district'],
                'city' => $region['city'],
                'province' => $region['province'],
                'postal_code' => $region['postal_code'],
                'confidence' => max(0, min(100, $confidence)),
            ];
        }

        usort($scored, static fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        $top = $scored[0] ?? ['confidence' => 0];
        $second = $scored[1] ?? ['confidence' => 0];
        $gap = (int) (($top['confidence'] ?? 0) - ($second['confidence'] ?? 0));
        $ambiguous = ($top['confidence'] ?? 0) < 75 || $gap <= 8;

        return [
            'normalized' => $preprocessed['normalized'],
            'confidence' => (int) ($top['confidence'] ?? 0),
            'ambiguous' => $ambiguous,
            'warning' => $ambiguous ? 'Alamat ambigu. Konfirmasi kecamatan/kode pos sebelum ongkir final dihitung.' : '',
            'suggestions' => array_slice($scored, 0, 5),
        ];
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     */
    private function token_similarity(array $left, array $right): int
    {
        $left = array_values(array_unique($left));
        $right = array_values(array_unique($right));

        if ($left === [] || $right === []) {
            return 0;
        }

        $intersection = array_intersect($left, $right);
        $union = array_unique(array_merge($left, $right));

        return (int) round((count($intersection) / max(1, count($union))) * 100);
    }

    private function fuzzy_similarity(string $source, string $target): int
    {
        if ($source === '' || $target === '') {
            return 0;
        }

        similar_text($source, $target, $percent);
        $lev = levenshtein($source, $target);
        $len = max(strlen($source), strlen($target));
        $lev_score = $len > 0 ? (1 - min($lev, $len) / $len) * 100 : 0;

        return (int) round(($percent * 0.5) + ($lev_score * 0.5));
    }
}
