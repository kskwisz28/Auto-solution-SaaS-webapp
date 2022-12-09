<?php

namespace App\Services;

use App\Services\DataForSeo\Request as DataForSeoRequest;
use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RelevanceCalculator
{
    protected int $score    = 0;
    protected int $maxScore = 25 + 50 + 20 + 100;

    /**
     * @param $rank
     *
     * @return void
     */
    public function rank($rank): void
    {
        $this->score += (100 - min((int)$rank, 100)) / 4;
    }

    /**
     * @param string $keyword
     * @param string $url
     *
     * @return void
     */
    public function pageContent(string $keyword, string $url): void
    {
        try {
            $this->score += Cache::remember("relevance_score.{$keyword}.{$url}", now()->addWeek(), static function () use ($url, $keyword) {
                $pageBody = Cache::remember("page_content.{$url}", now()->addHour(), static function () use ($url) {
                    return Http::get($url)->body();
                });

                $count     = Str::substrCount($pageBody, $keyword);
                $tempScore = max($count * 5, 50);

                // Load HTML to DOM object
                $dom = new DOMDocument();
                @$dom->loadHTML($pageBody);
                $nodes = $dom->getElementsByTagName('title');
                $title = $nodes->item(0)->nodeValue;

                $tempScore += Str::contains($title, $keyword) ? 20 : 0;

                return $tempScore;
            });
        } catch (\Throwable $e) {
            Log::debug('Failed to fetch url when calculating score', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param mixed  $keyword
     * @param string $domain
     * @param string $market
     *
     * @return void
     */
    public function keywordExistsInList(mixed $keyword, string $domain, string $market): void
    {
        $key = "relevance_score.{$domain}.{$market}";

        $this->score += Cache::remember($key, now()->addWeek(), static function () use ($domain, $market, $keyword) {
            $data = app(DataForSeoRequest::class)
                ->requestType(DataForSeoRequest::TYPE_GOOGLE_ADS_KEYWORDS_FOR_SITE)
                ->params(['target' => $domain], $market)
                ->fetch()
                ->rawResult();

            return collect($data)->pluck('keyword')->contains($keyword) ? 100 : 0;
        });
    }

    /**
     * @return float
     */
    public function getResult(): float
    {
        return round(($this->score / $this->maxScore) * 100);
    }
}