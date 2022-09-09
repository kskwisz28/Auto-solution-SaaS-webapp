<?php

namespace App\Services\DataForSeo\Requests;

class DomainSearch extends AbstractRequest
{
    /**
     * @return array
     */
    public function fetch(): array
    {
        return array_merge(
            $this->domainBySearchEngineRanking(
                $this->ensureCorrectDomainFormat($this->params->query)
            ),
            $this->domainByWebsiteContent(
                $this->ensureCorrectDomainWithOptionalSubdomainFormatForRequest($this->params->query)
            ),
        );
    }

    /**
     * @param string $domain
     * @param int    $limit
     *
     * @return array
     */
    private function domainBySearchEngineRanking(string $domain, int $limit = 300): array
    {
        $params[] = [
            'target'        => $domain,
            'location_name' => $this->params->getLocation(),
            'language_name' => $this->params->getLanguage(),
            'limit'         => $limit,
            'order_by'      => ['keyword_data.keyword_info.cpc,desc', 'keyword_data.keyword_info.search_volume,desc'],
            'filters'       => [
                ['ranked_serp_element.serp_item.rank_group', '>', 10],
                'and',
                ['ranked_serp_element.serp_item.rank_group', '<', 70],
                'and',
                ['keyword_data.keyword_info.search_volume', '>', 50],
                'and',
                ['keyword_data.keyword_info.search_volume', '<', 10000],

                // legacy
                //['ranked_serp_element.serp_item.rank_group', '<', 200],
                //'and',
                //['keyword_data.keyword_info.search_volume', '<', 35000],
            ],
        ];

        $result = $this->client->post('/v3/dataforseo_labs/ranked_keywords/live', $params);
        $items  = data_get($result, 'tasks.0.result.0.items', []);

        return collect($items)
            ->map(function ($item) use ($domain) {
                if ($this->isBrandKeyword($item['keyword_data']['keyword'], $domain)) {
                    return null;
                }

                return [
                    'keyword'       => $item['keyword_data']['keyword'],
                    'search_volume' => $item['keyword_data']['keyword_info']['search_volume'] ?? 0,
                    'cpc'           => $item['keyword_data']['keyword_info']['cpc'] ?? 0,
                    'competition'   => $item['keyword_data']['keyword_info']['competition'] ?? 0,
                    'current_rank'  => $item['ranked_serp_element']['serp_item']['rank_absolute'] ?? 0,
                    'url'           => $item['ranked_serp_element']['serp_item']['url'] ?? '/',
                ];
            })
            ->reject(static fn ($item) => $item === null)
            ->toArray();
    }

    /**
     * @param string $domain
     *
     * @return array
     */
    private function domainByWebsiteContent(string $domain): array
    {
        $params[] = [
            'target'        => $domain,
            'location_name' => $this->params->getLocation(),
            'language_name' => $this->params->getLanguage(),
        ];

        $result = $this->client->post('/v3/keywords_data/google/keywords_for_site/live', $params);
        $items  = data_get($result, 'tasks.0.result', []);

        return collect($items)
            ->map(function ($item) use ($domain) {
                if ($this->isBrandKeyword($item['keyword'], $domain)) {
                    return null;
                }

                return [
                    'keyword'       => $item['keyword'],
                    'search_volume' => (int)($item['search_volume'] ?? 0),
                    'cpc'           => (float)($item['cpc'] ?? 0),
                    'competition'   => (float)($item['competition'] ?? 0),
                    'market'        => $this->params->market,
                ];
            })
            ->filter()
            ->toArray();
    }

    /**
     * @param string $inputDomain
     *
     * @return string
     */
    private function ensureCorrectDomainFormat(string $inputDomain): string
    {
        $inputDomain = str_replace('http://', 'https://', $inputDomain);

        if (!str_contains($inputDomain, 'https://')) {
            $inputDomain = 'https://' . $inputDomain;
        }

        return $this->idnHostToAscii(
            $this->extractDomain($inputDomain)
        );
    }

    /**
     * @param $inputDomain
     *
     * @return string
     */
    private function ensureCorrectDomainWithOptionalSubdomainFormatForRequest($inputDomain): string
    {
        if (!str_contains($inputDomain, 'https://')) {
            $inputDomain = 'https://' . $inputDomain;
        }
        return $this->idnHostToAscii($inputDomain);
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function idnHostToAscii(string $url): string
    {
        $urlArray = parse_url($url);

        // no scheme available? e.g. just "demouser.de" => add scheme
        if (!\array_key_exists('scheme', $urlArray)) {
            $urlArray = parse_url('http://' . $url);
        }

        if ($urlArray === false) {
            return $url;
        }

        if (!\array_key_exists('host', $urlArray)) {
            return $url;
        }

        $originalHost = $urlArray['host'];
        $asciiHost    = \idn_to_ascii($originalHost, 0, \INTL_IDNA_VARIANT_UTS46);

        if ($asciiHost === false) {
            return $url;
        }

        return \str_replace($originalHost, $asciiHost, $url);
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function extractDomain(string $url): string
    {
        $pieces = \parse_url($url);
        $domain = $pieces['host'] ?? '';
        if (preg_match('/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z.]{2,10})$/i', $domain, $matches)) {
            $matches['domain'] = \preg_replace('/^www./', '', $matches['domain']);
            return $matches['domain'];
        }
        return '';
    }

    /**
     * @param string $keyword
     * @param string $domain
     *
     * @return bool
     */
    private function isBrandKeyword(string $keyword, string $domain): bool
    {
        return str_contains($keyword, explode(".", $domain)[0]);
    }
}
