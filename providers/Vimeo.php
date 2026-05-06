<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;
use Rhymix\Modules\Oembed\Models\RemoteFetcher;

/**
 * Vimeo 동영상 임베드.
 *
 * 공식 oEmbed (https://vimeo.com/api/oembed.json?url=…) 로 실제 영상
 * 비율 (width/height) 과 섬네일 URL 을 받아 iframe 비율 보정에 사용한다.
 * Vimeo oEmbed 는 키 발급 없이 사용 가능하다.
 */
class Vimeo extends Provider
{
  public string $name = 'Vimeo';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];
  // vimeo.com 경로에는 #t= 앵커가 올 수 있으므로 ~ delimiter 사용.
  public array $patterns = [
    '~(?:https?:)?//(?:www\.|player\.)?vimeo\.com/(?:(?:channels|event|ondemand)/(?:\w+/)?|(?:album|groups)/[^/]*/videos/|video/|)(\d+)((?:#t=[^&\s]*)?)~i' => ['video_id', 'anchor'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $videoId = $matchData['captures']['video_id'] ?? '';
    if ($videoId === '') {
      return '';
    }
    [$w, $h] = $this->getDimensions($width, $height);
    $src = 'https://player.vimeo.com/video/' . rawurlencode($videoId);
    // #t= 앵커는 player.vimeo.com 에서 타임코드 시작점으로 쓰인다.
    $anchor = $matchData['captures']['anchor'] ?? '';
    if ($anchor !== '') {
      $src .= $anchor;
    }
    $ratio = $h > 0 ? (string) round($w / $h, 4) : '1.7778';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function fetchInfo(string $url): ?array
  {
    // 공식 oEmbed — 키 발급 불필요. 영상마다 다른 실제 비율을 반환한다.
    $endpoint = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode($url);
    $payload = RemoteFetcher::fetchJson($endpoint);
    if ($payload === null) {
      return null;
    }
    $info = [];
    if (isset($payload['width']) && is_numeric($payload['width'])) {
      $info['width'] = (int) $payload['width'];
    }
    if (isset($payload['height']) && is_numeric($payload['height'])) {
      $info['height'] = (int) $payload['height'];
    }
    if (!empty($payload['thumbnail_url']) && is_string($payload['thumbnail_url'])) {
      $info['thumbnail_url'] = $payload['thumbnail_url'];
    }
    return $info ?: null;
  }

  public function getEmbedHosts(): array
  {
    // buildEmbed 가 출력하는 iframe 의 src 호스트만 화이트리스트에 필요.
    return ['player.vimeo.com'];
  }
}
