<?php

namespace Rhymix\Modules\Oembed\Providers;

use Rhymix\Modules\Oembed\Models\Provider;
use Rhymix\Modules\Oembed\Models\RemoteFetcher;

/**
 * Dailymotion 동영상 / 플레이리스트 임베드.
 *
 * 공식 임베드 플레이어: geo.dailymotion.com/player.html?video={id}
 *                       geo.dailymotion.com/player.html?playlist={id}
 *
 * 동영상의 경우 Dailymotion 공개 API
 * (https://api.dailymotion.com/video/{id}?fields=thumbnail_url,width,height)
 * 로 섬네일 및 실제 비율을 조회한다. 플레이리스트 API 는 인증이 필요하므로
 * 플레이리스트는 기본 16:9 비율로 표시한다.
 *
 * dai.ly 단축 URL 도 동영상 ID 만 추출해 동일하게 처리한다.
 */
class Dailymotion extends Provider
{
  public string $name = 'Dailymotion';
  public string $type = self::TYPE_MULTIMEDIA;
  public bool $oembed = false;
  public array $hosts = ['www.dailymotion.com', 'dailymotion.com', 'dai.ly'];
  // 유형이 명시된 패턴(video|playlist)을 먼저 두고, 그 외 단축형을 아래에 배치.
  public array $patterns = [
    '~(?:https?:)?//(?:www\.)?dailymotion\.com/(video|playlist)/([-_0-9a-zA-Z]+)~i' => ['type', 'id'],
    '~(?:https?:)?//(?:www\.)?dailymotion\.com/([-_0-9a-zA-Z]+)~i' => ['id'],
    '~(?:https?:)?//dai\.ly/([-_0-9a-zA-Z]+)~i' => ['id'],
  ];

  public function buildEmbed(array $matchData, ?int $width = null, ?int $height = null): string
  {
    $id = $matchData['captures']['id'] ?? '';
    if ($id === '') {
      return '';
    }
    // 'type' 캡처가 없는 경우(단축형 패턴) 기본값은 'video'.
    $type = ($matchData['captures']['type'] ?? '') ?: 'video';
    [$w, $h] = $this->getDimensions($width, $height);

    if ($type === 'playlist') {
      $src = 'https://geo.dailymotion.com/player.html?playlist=' . rawurlencode($id) . '&mute=true';
    } else {
      $src = 'https://geo.dailymotion.com/player.html?video=' . rawurlencode($id) . '&mute=true';
    }
    $ratio = $h > 0 ? (string) round($w / $h, 4) : '1.7778';
    return sprintf(
      '<iframe src="%s" width="%d" height="%d" style="max-width:100%%;aspect-ratio:%s;height:auto;" frameborder="0" allowfullscreen allow="fullscreen; picture-in-picture; web-share" loading="lazy"></iframe>',
      htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
      $w,
      $h,
      $ratio
    );
  }

  public function fetchInfo(string $url): ?array
  {
    // fetchInfo 는 URL 문자열만 받으므로 video ID 재추출이 필요.
    // playlist URL 은 Dailymotion 공개 API 가 인증을 요구하므로 스킵하고 null 반환.
    if (!preg_match('~(?:https?:)?//(?:www\.)?(?:dailymotion\.com/(?:video/)?|dai\.ly/)([-_0-9a-zA-Z]+)~i', $url, $m)) {
      return null;
    }
    $videoId = $m[1];
    $apiUrl = 'https://api.dailymotion.com/video/' . rawurlencode($videoId) . '?fields=thumbnail_url,width,height';
    $payload = RemoteFetcher::fetchJson($apiUrl);
    if ($payload === null || isset($payload['error'])) {
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
    return ['geo.dailymotion.com'];
  }
}
