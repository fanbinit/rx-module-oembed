<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;
use Rhymix\Modules\Oembed\Models\RemoteFetcher;

/**
 * 치지직(CHZZK) 클립 임베드.
 *
 * 클립 URL 을 감지해 공식 임베드 플레이어(chzzk.naver.com/embed/clip/{id})
 * 로 변환한다. live / video / channel URL 은 iframe 임베드 방식이 아닌
 * 프리뷰 카드로 제공돼야 하므로 OG 카드 경로(registry miss fallback)에서
 * 처리된다.
 *
 * 섬네일은 Chzzk 클립 공개 API
 * (https://api.chzzk.naver.com/service/v1/clips/{id}/detail)
 * 에서 가져온다 — 인증 불필요.
 */
class Chzzk extends Provider
{
  public string $name = 'Chzzk';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['chzzk.naver.com', 'm.chzzk.naver.com'];
  public array $patterns = [
    '~(?:https?:)?//(?:m\.)?chzzk\.naver\.com/clips/(\w+)~i' => ['clip_id'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $clipId = $matchData['captures']['clip_id'] ?? '';
    if ($clipId === '') {
      return '';
    }
    [$w, $h] = $this->getDimensions($width, $height);
    $src = 'https://chzzk.naver.com/embed/clip/' . rawurlencode($clipId);
    $ratio = $h > 0 ? (string) round($w / $h, 4) : '1.7778';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" scrolling="no" allow="web-share" allowfullscreen loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function fetchInfo(string $url): ?array
  {
    if (!preg_match('~(?:https?:)?//(?:m\.)?chzzk\.naver\.com/clips/(\w+)~i', $url, $m)) {
      return null;
    }
    $clipId = $m[1];
    $apiUrl = 'https://api.chzzk.naver.com/service/v1/clips/' . rawurlencode($clipId) . '/detail';
    $payload = RemoteFetcher::fetchJson($apiUrl);
    if ($payload === null || ($payload['code'] ?? null) !== 200) {
      return null;
    }
    $content = $payload['content'] ?? [];
    $info = [];
    if (!empty($content['thumbnailImageUrl']) && is_string($content['thumbnailImageUrl'])) {
      $info['thumbnail_url'] = $content['thumbnailImageUrl'];
    }
    return $info ?: null;
  }

  public function getEmbedHosts(): array
  {
    // buildEmbed 가 출력하는 iframe 의 src 호스트만 화이트리스트에 필요.
    return ['chzzk.naver.com'];
  }
}
